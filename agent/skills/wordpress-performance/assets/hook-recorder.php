<?php
/**
 * Plugin Name: Hook Recorder (R&D prototype v0)
 * Description: Records, per request: request type, boot-phase timings/memory/class counts,
 *              hook fire counts (from core's own $wp_actions / $wp_filters), an inventory of
 *              every attached callback (form, identity, whether its class ever loaded,
 *              when it was attached, how often its hook fired after that), classes loaded,
 *              and optionally exact per-callback call counts and timings (wrap mode).
 *
 * Requires: PHP 8.0+ (it uses match, nullsafe and mixed; on PHP 7.x this file is a parse
 * error that takes the site down) and WordPress 6.1+ (for $wp_filters counts).
 *
 * Install: copy into wp-content/mu-plugins/ (on VIP: client-mu-plugins/). Load it as early
 * as possible; mu-plugins load alphabetically, so a name like "000-hook-recorder.php" helps.
 *
 * Config (constants, e.g. in wp-config.php / vip-config.php; or env vars of the same name):
 *   HOOK_RECORDER_ENABLED      bool   master switch (default false)
 *   HOOK_RECORDER_SAMPLE_RATE  float  0..1 fraction of requests recorded (default 1.0)
 *   HOOK_RECORDER_DIR          string directory for NDJSON output (default: system temp dir)
 *   HOOK_RECORDER_FILE         bool   write the NDJSON file (default true); false = New Relic attributes only
 *   HOOK_RECORDER_WRAP         bool   wrap callbacks to count calls and time them (dev only; adds overhead)
 *   HOOK_RECORDER_NAMESPACES   string comma-separated class prefixes that count as "ours"
 *   HOOK_RECORDER_NEWRELIC     bool   also add summary custom attributes to the New Relic transaction
 *
 * Writes: one JSON line per recorded request to HOOK_RECORDER_DIR/hook-recorder-YYYYMMDD.ndjson
 * (file mode 0600; URIs are recorded without their query string, CLI runs as command words only).
 *
 * Design rules: never autoload anything (string callbacks are inspected with
 * class_exists($c, false), never Reflection), never throw, cheap when disabled.
 * Known limits (v0):
 *   - callbacks removed before shutdown are missed by the inventory;
 *   - "fires after attach" is a [lower, upper] bound unless wrap mode counted the calls;
 *   - a callback removed and re-added between two snapshots is not detected (one that is
 *     missing at one snapshot and back at a later one is flagged "volatile");
 *   - wrap mode only wraps callbacks present at the wrap points (plugins_loaded,
 *     after_setup_theme, init, wp_loaded); calls made before a callback was wrapped are not
 *     counted ("calls_complete": false), callbacks added after wp_loaded are never wrapped,
 *     and timings include nested hooks.
 */

declare(strict_types=1);

namespace HookRecorder;

if (!defined('ABSPATH')) {
    return;
}

function config(string $name, mixed $default): mixed
{
    if (defined($name)) {
        return constant($name);
    }
    $env = getenv($name);
    if ($env === false || $env === '') {
        return $default;
    }
    return match (gettype($default)) {
        'boolean' => in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true),
        'double' => (float) $env,
        'integer' => (int) $env,
        default => $env,
    };
}

if (!config('HOOK_RECORDER_ENABLED', false)) {
    return;
}
if (mt_rand() / mt_getrandmax() > (float) config('HOOK_RECORDER_SAMPLE_RATE', 1.0)) {
    return;
}

final class Recorder
{
    private const PHASES = [
        'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded',
        'parse_request', 'rest_api_init', 'admin_init', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'shutdown',
    ];
    /** Points where we snapshot attached callbacks (and, in wrap mode, wrap them). */
    private const SNAPSHOT_PHASES = ['plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded'];
    /** Hot hooks never wrapped (overhead) — counts still come from core. */
    private const NO_WRAP = ['all', 'gettext', 'gettext_with_context', 'ngettext', 'ngettext_with_context', 'esc_html', 'attribute_escape', 'clean_url', 'sanitize_key'];

    private static float $startNs;
    private static array $ru0;
    private static array $phases = [];
    /** @var array<string, array<string,int>> phase => hook => fires-so-far */
    private static array $fireSnapshots = [];
    /** @var array<string, string> "hook|priority|idx" => first phase seen */
    private static array $attachedAt = [];
    /** @var array<string, array{calls:int, first_ns:int, total_ns:int, hook_fires_at_wrap:int}> */
    private static array $wrapStats = [];
    /** @var array<string, true> keys seen at an earlier snapshot but missing at the latest one */
    private static array $missing = [];
    /** @var array<string, true> keys detected as removed and later re-added */
    private static array $volatile = [];
    /** @var array<string, array{resolutions:int, builds:int}> */
    private static array $container = [];
    private static array $classesAtPhase = [];
    private static bool $wrap = false;

    public static function boot(): void
    {
        self::$startNs = hrtime(true);
        self::$ru0 = getrusage();
        self::$wrap = (bool) config('HOOK_RECORDER_WRAP', false);
        self::$phases['recorder_loaded'] = self::mark();

        foreach (self::PHASES as $phase) {
            // PHP_INT_MIN: record when the phase STARTS, before other callbacks run.
            add_action($phase, static function () use ($phase): void {
                if (!isset(self::$phases[$phase])) {
                    self::$phases[$phase] = self::mark();
                }
            }, PHP_INT_MIN);
        }
        foreach (self::SNAPSHOT_PHASES as $phase) {
            // PHP_INT_MAX: snapshot after everyone else registered on this phase.
            add_action($phase, static fn() => self::snapshot($phase), PHP_INT_MAX);
        }
        add_action('shutdown', [self::class, 'finish'], PHP_INT_MAX);
    }

    /** Public hook for container adapters: call on every resolution (built=true when an object was constructed). */
    public static function containerEvent(string $class, bool $built): void
    {
        self::$container[$class] ??= ['resolutions' => 0, 'builds' => 0];
        self::$container[$class]['resolutions']++;
        if ($built) {
            self::$container[$class]['builds']++;
        }
    }

    private static function mark(): array
    {
        return [
            'ms' => round((hrtime(true) - self::$startNs) / 1e6, 3),
            'mem_kb' => (int) (memory_get_usage() / 1024),
            'classes' => count(get_declared_classes()),
        ];
    }

    /** Fires per hook name. Core counts do_action() in $wp_actions and apply_filters() in $wp_filters; a name can be in both. */
    private static function fireCounts(): array
    {
        global $wp_actions, $wp_filters;
        $fires = is_array($wp_actions) ? $wp_actions : [];
        foreach ((is_array($wp_filters) ? $wp_filters : []) as $hook => $n) {
            $fires[$hook] = ($fires[$hook] ?? 0) + $n;
        }
        return $fires;
    }

    private static function snapshot(string $phase): void
    {
        global $wp_filter;
        $fires = self::fireCounts();
        self::$fireSnapshots[$phase] = $fires;
        self::$classesAtPhase[$phase] = count(get_declared_classes());
        $present = [];
        foreach ($wp_filter as $hook => $wpHook) {
            if (!$wpHook instanceof \WP_Hook) {
                continue;
            }
            foreach ($wpHook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $idx => $entry) {
                    $key = "{$hook}|{$priority}|{$idx}";
                    $present[$key] = true;
                    if (!isset(self::$attachedAt[$key])) {
                        self::$attachedAt[$key] = $phase;
                    } elseif (isset(self::$missing[$key])) {
                        // Removed at an earlier snapshot and back now: restart its attach window.
                        self::$attachedAt[$key] = $phase;
                        self::$volatile[$key] = true;
                        unset(self::$missing[$key]);
                    }
                    $rewrap = isset(self::$wrapStats[$key]) && !self::isOwn($entry['function']);
                    if ($rewrap) {
                        // Same key but our wrapper is gone: the callback was removed and re-added.
                        self::$volatile[$key] = true;
                    }
                    if (self::$wrap && (!isset(self::$wrapStats[$key]) || $rewrap) && !in_array($hook, self::NO_WRAP, true) && !self::isOwn($entry['function'])) {
                        self::remember($key, $entry['function']);
                        self::$wrapStats[$key] ??= ['calls' => 0, 'first_ns' => 0, 'total_ns' => 0, 'hook_fires_at_wrap' => (int) ($fires[$hook] ?? 0)];
                        $orig = $entry['function'];
                        // Keep the array key ($idx) so remove_filter() still matches the original callback.
                        $wpHook->callbacks[$priority][$idx]['function'] = static function (...$args) use ($orig, $key) {
                            $t = hrtime(true);
                            try {
                                return $orig(...$args);
                            } finally {
                                $d = hrtime(true) - $t;
                                $s = &self::$wrapStats[$key];
                                if ($s['calls'] === 0) {
                                    $s['first_ns'] = $d;
                                }
                                $s['calls']++;
                                $s['total_ns'] += $d;
                            }
                        };
                    }
                }
            }
        }
        foreach (self::$attachedAt as $key => $_) {
            if (!isset($present[$key])) {
                self::$missing[$key] = true;
            }
        }
    }

    private static function isOwn(mixed $cb): bool
    {
        if ($cb instanceof \Closure) {
            $scope = (new \ReflectionFunction($cb))->getClosureScopeClass();
            return $scope !== null && $scope->getName() === self::class;
        }
        return is_array($cb) && ($cb[0] ?? null) === self::class;
    }

    /** Classify a callback WITHOUT triggering autoload. */
    private static function describe(mixed $cb): array
    {
        if (is_string($cb)) {
            if (str_contains($cb, '::')) {
                [$class, $method] = explode('::', $cb, 2);
                return ['form' => 'static_string', 'id' => $cb, 'class' => ltrim($class, '\\')];
            }
            return ['form' => 'function', 'id' => $cb, 'class' => null];
        }
        if ($cb instanceof \Closure) {
            $rf = new \ReflectionFunction($cb);
            $scope = $rf->getClosureScopeClass()?->getName();
            $name = $rf->getName();
            $isAnonymous = str_starts_with($name, '{closure');
            return [
                'form' => $isAnonymous ? 'closure' : 'first_class_callable',
                'id' => $isAnonymous ? sprintf('{closure}@%s:%d', self::relPath((string) $rf->getFileName()), $rf->getStartLine()) : ($scope ? "{$scope}::{$name}" : $name),
                'class' => $scope,
            ];
        }
        if (is_array($cb) && isset($cb[0], $cb[1]) && is_string($cb[1])) {
            if (is_object($cb[0])) {
                $class = get_class($cb[0]);
                $lazy = PHP_VERSION_ID >= 80400 && (new \ReflectionClass($cb[0]))->isUninitializedLazyObject($cb[0]);
                return ['form' => 'array_object', 'id' => "{$class}->{$cb[1]}", 'class' => $class, 'lazy_uninitialized' => $lazy];
            }
            return ['form' => 'array_string', 'id' => ltrim((string) $cb[0], '\\') . "::{$cb[1]}", 'class' => ltrim((string) $cb[0], '\\')];
        }
        if (is_object($cb)) {
            return ['form' => 'invokable', 'id' => get_class($cb) . '->__invoke', 'class' => get_class($cb)];
        }
        return ['form' => 'unknown', 'id' => gettype($cb), 'class' => null];
    }

    private static function relPath(string $file): string
    {
        return str_starts_with($file, ABSPATH) ? substr($file, strlen(ABSPATH)) : $file;
    }

    /**
     * Leading command words of a WP-CLI run, without flags. Two words ("cron event", "option get")
     * so positional values such as emails or IDs are not recorded; four for Cron Control, whose
     * subcommands ("cron-control orchestrate runner-only run" vs "... list-due-batch") matter.
     */
    private static function cliCommand(): ?string
    {
        $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null);
        if (!is_array($argv)) {
            return null;
        }
        $words = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || $arg === '' || $arg[0] === '-') {
                continue;
            }
            $words[] = $arg;
        }
        $keep = ($words[0] ?? '') === 'cron-control' ? 4 : 2;
        return $words ? implode(' ', array_slice($words, 0, $keep)) : null;
    }

    private static function requestType(): array
    {
        $isCli = defined('WP_CLI') && WP_CLI;
        $type = match (true) {
            // DOING_CRON is set by the time shutdown runs, also for WP-CLI cron runs and VIP Cron Control events.
            function_exists('wp_doing_cron') && wp_doing_cron() => 'cron',
            $isCli => 'cli',
            function_exists('wp_doing_ajax') && wp_doing_ajax() => 'ajax',
            defined('REST_REQUEST') && REST_REQUEST => 'rest',
            defined('XMLRPC_REQUEST') && XMLRPC_REQUEST => 'xmlrpc',
            is_admin() => 'admin',
            isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php' => 'login',
            default => 'frontend',
        };
        $subtype = null;
        if ($isCli) {
            // Separates Cron Control events and polling ("cron-control orchestrate runner-only ...") from other commands.
            $subtype = self::cliCommand();
        } elseif ($type === 'frontend' && isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof \WP_Query) {
            $subtype = match (true) {
                is_404() => '404', is_search() => 'search', is_feed() => 'feed', is_front_page() => 'front_page',
                is_home() => 'home', is_singular() => 'singular', is_archive() => 'archive', default => 'other',
            };
        } elseif ($type === 'admin') {
            $subtype = $GLOBALS['pagenow'] ?? null;
        } elseif ($type === 'rest') {
            $subtype = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
        }
        return [
            'type' => $type,
            'subtype' => $subtype,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            // Path only: query strings can carry nonces or tokens.
            'uri' => isset($_SERVER['REQUEST_URI']) && !$isCli ? strtok((string) $_SERVER['REQUEST_URI'], '?') : self::cliCommand(),
            'logged_in' => function_exists('is_user_logged_in') && is_user_logged_in(),
            'blog_id' => function_exists('get_current_blog_id') ? get_current_blog_id() : 1,
        ];
    }

    public static function finish(): void
    {
        try {
            self::write(self::build());
        } catch (\Throwable $e) {
            error_log('[hook-recorder] ' . $e->getMessage());
        }
    }

    private static function build(): array
    {
        global $wp_filter;
        $ru1 = getrusage();
        $cpu = static fn(string $k): float => round((($ru1["ru_{$k}.tv_sec"] - self::$ru0["ru_{$k}.tv_sec"]) * 1e6 + ($ru1["ru_{$k}.tv_usec"] - self::$ru0["ru_{$k}.tv_usec"])) / 1000, 3);
        $fires = self::fireCounts();

        $prefixes = array_values(array_filter(array_map('trim', explode(',', (string) config('HOOK_RECORDER_NAMESPACES', '')))));
        $isOurs = static function (?string $class) use ($prefixes): bool {
            if ($class === null || !$prefixes) {
                return false;
            }
            foreach ($prefixes as $p) {
                if (str_starts_with($class, $p)) {
                    return true;
                }
            }
            return false;
        };

        $snapshotOrder = self::SNAPSHOT_PHASES;
        $callbacks = [];
        foreach ($wp_filter as $hook => $wpHook) {
            if (!$wpHook instanceof \WP_Hook || $hook === 'all') {
                continue;
            }
            foreach ($wpHook->callbacks as $priority => $entries) {
                foreach ($entries as $idx => $entry) {
                    $key = "{$hook}|{$priority}|{$idx}";
                    $fn = $entry['function'];
                    if (isset(self::$wrapStats[$key])) {
                        $fn = null; // wrapped: identity comes from the snapshot below
                    }
                    $d = $fn !== null ? self::describe($fn) : (self::$described[$key] ?? ['form' => 'wrapped', 'id' => $key, 'class' => null]);
                    if (self::isOwn($entry['function']) && !isset(self::$wrapStats[$key])) {
                        continue;
                    }
                    $attached = self::$attachedAt[$key] ?? 'late';
                    $total = (int) ($fires[$hook] ?? 0);
                    $pos = array_search($attached, $snapshotOrder, true);
                    $atAttach = $attached === 'late' ? null : (int) (self::$fireSnapshots[$attached][$hook] ?? 0);
                    $atPrev = ($pos === false || $pos === 0) ? 0 : (int) (self::$fireSnapshots[$snapshotOrder[$pos - 1]][$hook] ?? 0);
                    $row = $d + [
                        'hook' => (string) $hook,
                        'priority' => $priority,
                        'accepted_args' => $entry['accepted_args'],
                        'attached_at' => $attached,
                        'hook_fires_total' => $total,
                        'fires_after_attach_min' => $atAttach === null ? null : $total - $atAttach,
                        'fires_after_attach_max' => $attached === 'late' ? null : $total - $atPrev,
                        'class_loaded' => $d['class'] !== null ? (class_exists($d['class'], false) || interface_exists($d['class'], false)) : null,
                        'ours' => $isOurs($d['class']),
                        'volatile' => isset(self::$volatile[$key]),
                    ];
                    if (isset(self::$wrapStats[$key])) {
                        $s = self::$wrapStats[$key];
                        $row['calls'] = $s['calls'];
                        // False when the hook had already fired before the callback was wrapped, or the callback was re-added.
                        $row['calls_complete'] = $s['hook_fires_at_wrap'] === 0 && !isset(self::$volatile[$key]);
                        $row['first_call_us'] = round($s['first_ns'] / 1000, 2);
                        $row['avg_after_first_us'] = $s['calls'] > 1 ? round(($s['total_ns'] - $s['first_ns']) / ($s['calls'] - 1) / 1000, 2) : null;
                        $row['total_us'] = round($s['total_ns'] / 1000, 2);
                    }
                    $callbacks[] = $row;
                }
            }
        }

        $declared = get_declared_classes();
        $ours = array_values(array_filter($declared, $isOurs));
        arsort($fires);

        self::$phases['finish'] = self::mark();
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        return [
            'v' => 0,
            'ts' => gmdate('c'),
            'php' => PHP_VERSION,
            'wp' => $GLOBALS['wp_version'] ?? null,
            // Observer effects that change what gets autoloaded (skill: references/measurement.md):
            // New Relic in plugin_callbacks/all_callbacks mode resolves every add_filter callback at
            // registration; Query Monitor reflects callbacks at shutdown.
            'env' => [
                'newrelic_loaded' => extension_loaded('newrelic'),
                'newrelic_wp_hooks_options' => extension_loaded('newrelic') ? ini_get('newrelic.framework.wordpress.hooks.options') : null,
                'query_monitor_loaded' => class_exists('QueryMonitor', false) || class_exists('QM_Plugin', false),
                'opcache_enabled' => is_array($opcache) ? (bool) ($opcache['opcache_enabled'] ?? false) : false,
                'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps'),
                'opcache_revalidate_freq' => ini_get('opcache.revalidate_freq'),
                'object_cache' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : null,
            ],
            'request' => self::requestType(),
            'timing' => [
                'since_request_start_ms' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 3) : null,
                'since_recorder_ms' => round((hrtime(true) - self::$startNs) / 1e6, 3),
                'cpu_user_ms' => $cpu('utime'),
                'cpu_sys_ms' => $cpu('stime'),
                'peak_kb' => (int) (memory_get_peak_usage() / 1024),
                'phases' => self::$phases,
            ],
            'hooks' => [
                'distinct_fired' => count($fires),
                'total_fires' => array_sum($fires),
                'fires' => $fires,
            ],
            'callbacks' => $callbacks,
            'classes' => [
                'declared_total' => count($declared),
                'ours_count' => count($ours),
                'ours' => $ours,
                'at_snapshot' => self::$classesAtPhase,
            ],
            'container' => self::$container,
            'wrap_mode' => self::$wrap,
        ];
    }

    /** @var array<string, array> identity of wrapped callbacks, captured before wrapping */
    private static array $described = [];

    private static function write(array $record): void
    {
        if (config('HOOK_RECORDER_FILE', true)) {
            $dir = (string) config('HOOK_RECORDER_DIR', sys_get_temp_dir());
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $file = rtrim($dir, '/') . '/hook-recorder-' . gmdate('Ymd') . '.ndjson';
            if (!file_exists($file) && @touch($file)) {
                @chmod($file, 0600); // records hold request paths and class names; keep them private
            }
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
            if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                error_log('[hook-recorder] failed to append sample to ' . $file);
            }
        }

        if (config('HOOK_RECORDER_NEWRELIC', false) && function_exists('newrelic_add_custom_parameter')) {
            newrelic_add_custom_parameter('hr_request_type', $record['request']['type']);
            newrelic_add_custom_parameter('hr_total_fires', $record['hooks']['total_fires']);
            newrelic_add_custom_parameter('hr_distinct_hooks', $record['hooks']['distinct_fired']);
            newrelic_add_custom_parameter('hr_classes_declared', $record['classes']['declared_total']);
            newrelic_add_custom_parameter('hr_classes_ours', $record['classes']['ours_count']);
            newrelic_add_custom_parameter('hr_ms_to_wp_loaded', $record['timing']['phases']['wp_loaded']['ms'] ?? -1);
        }
    }

    /** Called by snapshot() before wrapping so wrapped callbacks keep their real identity. */
    public static function remember(string $key, mixed $fn): void
    {
        self::$described[$key] ??= self::describe($fn);
    }
}

Recorder::boot();

/** Container adapter entry point: call from your container on each resolution. */
function container_event(string $class, bool $built): void
{
    Recorder::containerEvent($class, $built);
}
