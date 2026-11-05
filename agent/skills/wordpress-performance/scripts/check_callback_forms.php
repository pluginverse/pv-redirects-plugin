<?php
/**
 * check_callback_forms.php — verify, on the PHP and WordPress versions you actually run,
 * which hook-callback forms autoload their class at registration vs at first fire, which
 * throw when the hook fires, and which a third party can remove with remove_filter().
 *
 * It loads only WordPress core's hook API (wp-includes/plugin.php + WP_Hook), not the
 * rest of WordPress, and generates throwaway classes through a logging autoloader, so it
 * is safe to run anywhere PHP can read a WordPress install.
 *
 * Usage:  php check_callback_forms.php --wp=/path/to/wordpress [--json]
 *         (or set WP_CORE_DIR instead of --wp)
 * Writes: nothing; Markdown (or JSON) to stdout. Exit 2 on bad input.
 */

declare(strict_types=1);

$opts = getopt('h', ['help', 'wp:', 'json']);
if (isset($opts['h']) || isset($opts['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage: php check_callback_forms.php --wp=/path/to/wordpress [--json]

Probes WordPress's add_filter()/apply_filters()/remove_filter() with every common callback
form and reports, per form: autoload at registration, autoload at fire, error at fire,
and whether a third party can remove it. Also probes is_callable, method_exists,
Reflection, ::class, instanceof, class_exists(..., false) and, on PHP 8.4+, lazy objects.

  --wp=PATH  WordPress root containing wp-includes/plugin.php (or env WP_CORE_DIR)
  --json     machine-readable output

HELP);
    exit(0);
}

$root = rtrim((string) ($opts['wp'] ?? getenv('WP_CORE_DIR') ?: ''), '/');
if ($root === '' || !is_file("{$root}/wp-includes/plugin.php")) {
    fwrite(STDERR, "WordPress root not found: pass --wp=/path/to/wordpress (a directory containing wp-includes/plugin.php).\n");
    exit(2);
}
define('ABSPATH', $root . '/');
require_once "{$root}/wp-includes/plugin.php";

$wpVersion = 'unknown';
if (is_file("{$root}/wp-includes/version.php") && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', (string) file_get_contents("{$root}/wp-includes/version.php"), $m)) {
    $wpVersion = $m[1];
}

$LOG = [];
spl_autoload_register(static function (string $class) use (&$LOG): void {
    if (!str_starts_with($class, 'CheckForms\\')) {
        return;
    }
    $LOG[] = $class;
    $short = substr($class, strlen('CheckForms\\'));
    // Throwaway probe class: one static method, one stateless and one stateful instance method.
    eval("namespace CheckForms; class {$short} {
        public int \$state = 0;
        public static function m(\$v = null) { return \$v; }
        public function i(\$v = null) { return \$v; }
        public function touch(\$v = null) { \$this->state++; return \$v; }
    }");
});

function probe(callable $op): array
{
    global $LOG;
    $LOG = [];
    $error = null;
    try {
        $op();
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    return ['loaded' => array_map(static fn(string $c): string => substr($c, strlen('CheckForms\\')), $LOG), 'error' => $error];
}

$forms = [
    'string'             => ["'Foo::bar'", static fn() => add_filter('h_string', 'CheckForms\\A::m'), static fn() => [add_filter('r_string', 'CheckForms\\RA::m'), remove_filter('r_string', 'CheckForms\\RA::m')][1]],
    'array_string'       => ["['Foo', 'bar']", static fn() => add_filter('h_array_string', ['CheckForms\\B', 'm']), static fn() => [add_filter('r_array_string', ['CheckForms\\RB', 'm']), remove_filter('r_array_string', 'CheckForms\\RB::m')][1]],
    'closure_wrapper'    => ['fn ($v) => Foo::bar($v)', static fn() => add_filter('h_closure_wrapper', static fn($v) => \CheckForms\C::m($v)), static fn() => [add_filter('r_closure_wrapper', static fn($v) => \CheckForms\RC::m($v)), remove_filter('r_closure_wrapper', static fn($v) => \CheckForms\RC::m($v))][1]],
    'eager_instance'     => ["[new Foo(), 'bar']", static fn() => add_filter('h_eager_instance', [new \CheckForms\D(), 'i']), static fn() => [add_filter('r_eager_instance', [new \CheckForms\RD(), 'i']), remove_filter('r_eager_instance', [new \CheckForms\RD(), 'i'])][1]],
    'first_class'        => ['Foo::bar(...)', static fn() => add_filter('h_first_class', \CheckForms\E::m(...)), static fn() => [add_filter('r_first_class', \CheckForms\RE::m(...)), remove_filter('r_first_class', \CheckForms\RE::m(...))][1]],
    'from_callable'      => ["Closure::fromCallable('Foo::bar')", static fn() => add_filter('h_from_callable', Closure::fromCallable('CheckForms\\F::m')), static fn() => [add_filter('r_from_callable', Closure::fromCallable('CheckForms\\RF::m')), remove_filter('r_from_callable', Closure::fromCallable('CheckForms\\RF::m'))][1]],
    'bare_class_name'    => ["'Foo' (class name only)", static fn() => add_filter('h_bare_class_name', 'CheckForms\\G'), null],
    'string_non_static'  => ["'Foo::instanceMethod'", static fn() => add_filter('h_string_non_static', 'CheckForms\\H::i'), null],
];

$rows = [];
foreach ($forms as $id => [$label, $register, $remove]) {
    $rows[$id] = ['form' => $label, 'register' => probe($register)];
}
foreach ($forms as $id => [$label, $register, $remove]) {
    $rows[$id]['fire'] = probe(static fn() => apply_filters("h_{$id}", 'value'));
    $rows[$id]['third_party_remove'] = $remove === null ? null : (bool) $remove();
}

$ops = [
    "is_callable('Foo::bar')"          => probe(static fn() => is_callable('CheckForms\\I::m')),
    "is_callable('Foo::bar', true)"    => probe(static fn() => is_callable('CheckForms\\J::m', true)),
    "has_filter('h', 'Foo::bar')"      => probe(static fn() => has_filter('h_string', 'CheckForms\\K::m')),
    'Foo::class'                       => probe(static fn() => \CheckForms\L::class),
    '$obj instanceof Foo'              => probe(static fn() => (new stdClass()) instanceof \CheckForms\M),
    "class_exists('Foo', false)"       => probe(static fn() => class_exists('CheckForms\\N', false)),
    "method_exists('Foo', 'bar')"      => probe(static fn() => method_exists('CheckForms\\O', 'm')),
    "new ReflectionMethod('Foo','bar')" => probe(static fn() => new ReflectionMethod('CheckForms\\P', 'm')),
];

$lazy = null;
if (PHP_VERSION_ID >= 80400) {
    $inits = ['method_without_state' => 0, 'method_touching_state' => 0];
    $create = probe(static function () use (&$inits): void {
        $rc = new ReflectionClass('CheckForms\\Q');
        add_filter('l_plain', [$rc->newLazyProxy(static function () use (&$inits) { $inits['method_without_state']++; return new \CheckForms\Q(); }), 'i']);
        add_filter('l_touch', [$rc->newLazyProxy(static function () use (&$inits) { $inits['method_touching_state']++; return new \CheckForms\Q(); }), 'touch']);
    });
    apply_filters('l_plain', 'v');
    apply_filters('l_touch', 'v');
    $lazy = ['class_loaded_at_creation' => $create['loaded'] !== [], 'initializer_runs_after_one_fire' => $inits];
}

$report = ['php' => PHP_VERSION, 'wordpress' => $wpVersion, 'callback_forms' => $rows, 'operations' => $ops, 'lazy_proxy' => $lazy];
if (isset($opts['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$yn = static fn(array $p): string => $p['loaded'] ? 'yes' : 'no';
echo "# Callback forms — PHP {$report['php']}, WordPress {$report['wordpress']}\n\n";
echo "| Form | Autoload at add_filter | Autoload at fire | Error at fire | Third party can remove |\n|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $remove = $r['third_party_remove'] === null ? 'n/a' : ($r['third_party_remove'] ? 'yes' : 'no');
    $err = $r['fire']['error'] ?? $r['register']['error'];
    printf("| `%s` | %s | %s | %s | %s |\n", $r['form'], $yn($r['register']), $yn($r['fire']), $err !== null ? str_replace('|', '\\|', $err) : '—', $remove);
}
echo "\n| Operation | Autoloads? |\n|---|---|\n";
foreach ($ops as $label => $p) {
    printf("| `%s` | %s |\n", $label, $yn($p));
}
if ($lazy !== null) {
    printf("\nPHP 8.4+ lazy proxy bound as [\$proxy, 'method']: class loaded at creation: %s; initializer runs after one fire: %s\n",
        $lazy['class_loaded_at_creation'] ? 'yes' : 'no', json_encode($lazy['initializer_runs_after_one_fire']));
}
