<?php
/**
 * Summarizes hook-recorder NDJSON into Markdown: per request type, the median timings,
 * memory, classes, hook fire totals, callback-inventory stats, phase timeline, and the
 * hottest hooks. Optionally lists "our" callbacks (HOOK_RECORDER_NAMESPACES) with how
 * often their hooks fired, which is the raw input for wiring recommendations.
 *
 * Usage:  php summarize_recorder.php <hook-recorder.ndjson> [--top=20] [--ours] > summary.md
 * Required env: none. Writes: Markdown to stdout.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, <<<'HELP'
Usage: php summarize_recorder.php <hook-recorder.ndjson> [--top=N] [--ours]

Reads the NDJSON written by the hook-recorder mu-plugin (one JSON object per request)
and prints Markdown to stdout:
  - per request type: median wall ms, CPU ms, peak KB, classes declared, distinct hooks
    fired, total hook fires, callbacks attached, callbacks whose hook never fired after
    attach, callbacks whose class never loaded
  - boot phase timeline (median ms since the recorder loaded)
  - callback forms attached (function, static_string, array_string, array_object,
    closure, first_class_callable, invokable)
  - hottest hooks per request type (median fires)

Options:
  --top=N   hooks listed per request type (default 20)
  --ours    also list callbacks whose class matches HOOK_RECORDER_NAMESPACES, with
            median fires (exact in wrap mode, else a min-max bound), % of requests where
            they definitely fired, % where their class loaded, and median first-call
            microseconds (wrap mode only)

Memory: every request's per-hook counts are held in memory for the medians. For files
with tens of thousands of requests, run with `php -d memory_limit=1G` or split the file.

HELP);
    exit($args === [] ? 2 : 0);
}
$file = null;
$top = 20;
$showOurs = false;
foreach ($args as $a) {
    if (str_starts_with($a, '--top=')) {
        $top = (int) substr($a, 6);
    } elseif ($a === '--ours') {
        $showOurs = true;
    } else {
        $file = $a;
    }
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php summarize.php <file.ndjson> [--top=20] [--ours]\n");
    exit(2);
}

$median = static function (array $xs): float {
    $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
    if (!$xs) {
        return NAN;
    }
    sort($xs);
    $n = count($xs);
    return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
};

$groups = [];
$fh = fopen($file, 'rb');
while (($line = fgets($fh)) !== false) {
    $r = json_decode($line, true);
    if (!is_array($r)) {
        continue;
    }
    $req = $r['request'];
    $key = $req['type'] . ($req['subtype'] !== null ? ':' . $req['subtype'] : '');
    $cbs = $r['callbacks'] ?? [];
    $forms = [];
    $neverFired = 0;
    $unloadedLazy = 0;
    foreach ($cbs as $cb) {
        $forms[$cb['form']] = ($forms[$cb['form']] ?? 0) + 1;
        if (($cb['fires_after_attach_max'] ?? null) === 0) {
            $neverFired++;
        }
        if (($cb['class_loaded'] ?? null) === false) {
            $unloadedLazy++;
        }
    }
    $phases = [];
    foreach ($r['timing']['phases'] as $p => $v) {
        $phases[$p] = $v['ms'];
    }
    $groups[$key][] = [
        'ms' => $r['timing']['since_request_start_ms'],
        'cpu' => $r['timing']['cpu_user_ms'] + $r['timing']['cpu_sys_ms'],
        'peak' => $r['timing']['peak_kb'],
        'classes' => $r['classes']['declared_total'],
        'distinct' => $r['hooks']['distinct_fired'],
        'fires' => $r['hooks']['total_fires'],
        'hook_fires' => $r['hooks']['fires'],
        'callbacks' => count($cbs),
        'never_fired' => $neverFired,
        'unloaded' => $unloadedLazy,
        'forms' => $forms,
        'phases' => $phases,
        'ours' => array_values(array_filter($cbs, static fn($c) => $c['ours'] ?? false)),
        'php' => $r['php'],
        'wp' => $r['wp'],
    ];
}
ksort($groups);
$first = reset($groups)[0] ?? ['php' => '?', 'wp' => '?'];

echo "# Hook recorder summary — PHP {$first['php']}, WordPress {$first['wp']}\n\n";
echo "Source: `" . basename($file) . "`. Medians per request type. `never-fired callbacks` = attached callbacks whose hook did not fire after they were attached (upper bound 0).\n\n";
echo "| request type | n | total ms | cpu ms | peak KB | classes | distinct hooks fired | total hook fires | callbacks attached | never-fired callbacks | callbacks w/ class never loaded |\n";
echo "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n";
foreach ($groups as $key => $rows) {
    printf("| %s | %d | %.1f | %.1f | %d | %d | %d | %d | %d | %d | %d |\n", $key, count($rows),
        $median(array_column($rows, 'ms')), $median(array_column($rows, 'cpu')), $median(array_column($rows, 'peak')),
        $median(array_column($rows, 'classes')), $median(array_column($rows, 'distinct')), $median(array_column($rows, 'fires')),
        $median(array_column($rows, 'callbacks')), $median(array_column($rows, 'never_fired')), $median(array_column($rows, 'unloaded')));
}

echo "\n## Boot phase timeline (median ms since the recorder loaded)\n\n";
$phaseNames = ['plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'parse_request', 'rest_api_init', 'admin_init', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'shutdown', 'finish'];
echo '| request type | ' . implode(' | ', $phaseNames) . " |\n|---|" . str_repeat('---:|', count($phaseNames)) . "\n";
foreach ($groups as $key => $rows) {
    $cells = [];
    foreach ($phaseNames as $p) {
        $v = $median(array_map(static fn($r) => $r['phases'][$p] ?? null, $rows));
        $cells[] = is_nan($v) ? '—' : sprintf('%.1f', $v);
    }
    echo "| {$key} | " . implode(' | ', $cells) . " |\n";
}

echo "\n## Callback forms attached (median count per request)\n\n";
$allForms = [];
foreach ($groups as $rows) {
    foreach ($rows as $r) {
        $allForms += array_fill_keys(array_keys($r['forms']), true);
    }
}
$allForms = array_keys($allForms);
sort($allForms);
echo '| request type | ' . implode(' | ', $allForms) . " |\n|---|" . str_repeat('---:|', count($allForms)) . "\n";
foreach ($groups as $key => $rows) {
    $cells = array_map(static fn($f) => (string) $median(array_map(static fn($r) => $r['forms'][$f] ?? 0, $rows)), $allForms);
    echo "| {$key} | " . implode(' | ', $cells) . " |\n";
}

echo "\n## Hottest hooks per request type (median fires per request)\n\n";
foreach ($groups as $key => $rows) {
    // Every request of the type counts; a hook absent from a request fired 0 times there.
    $hooks = [];
    foreach ($rows as $r) {
        $hooks += array_fill_keys(array_keys($r['hook_fires']), true);
    }
    $agg = [];
    foreach (array_keys($hooks) as $hook) {
        foreach ($rows as $r) {
            $agg[$hook][] = $r['hook_fires'][$hook] ?? 0;
        }
    }
    $med = array_map($median, $agg);
    arsort($med);
    $slice = array_slice($med, 0, $top, true);
    echo "### {$key}\n\n" . implode(', ', array_map(static fn($h, $n) => "`{$h}` {$n}", array_keys($slice), $slice)) . "\n\n";
}

if ($showOurs) {
    echo "## Our callbacks (HOOK_RECORDER_NAMESPACES)\n\n";
    foreach ($groups as $key => $rows) {
        $byId = [];
        foreach ($rows as $r) {
            foreach ($r['ours'] as $cb) {
                $id = "{$cb['hook']}|{$cb['priority']}|{$cb['id']}";
                $exact = isset($cb['calls']) && ($cb['calls_complete'] ?? true);
                // Without exact wrap-mode counts, fires after attach are only known as a [min, max] bound.
                $lo = $exact ? $cb['calls'] : ($cb['fires_after_attach_min'] ?? 0);
                $hi = $exact ? $cb['calls'] : ($cb['fires_after_attach_max'] ?? $lo);
                $byId[$id]['form'] = $cb['form'];
                $byId[$id]['lo'][] = (int) $lo;
                $byId[$id]['hi'][] = (int) $hi;
                $byId[$id]['exact'][] = $exact ? 1 : 0;
                $byId[$id]['loaded'][] = $cb['class_loaded'] ? 1 : 0;
                $byId[$id]['first_us'][] = $cb['first_call_us'] ?? null;
            }
        }
        if (!$byId) {
            continue;
        }
        $requests = count($rows);
        echo "### {$key}\n\nPercentages use all {$requests} requests of this type (callbacks registered conditionally count as not fired where absent). `fires` is exact when wrap mode counted every call, otherwise a min–max bound.\n\n";
        echo "| hook | priority | callback | form | median fires | definitely fired in % of requests | class loaded % | median first-call us |\n|---|---:|---|---|---:|---:|---:|---:|\n";
        ksort($byId);
        foreach ($byId as $id => $d) {
            [$hook, $prio, $cb] = explode('|', $id, 3);
            $firedPct = 100 * count(array_filter($d['lo'], static fn($x) => $x > 0)) / $requests;
            $lo = $median($d['lo']);
            $hi = $median($d['hi']);
            $fires = array_sum($d['exact']) === count($d['exact']) || $lo === $hi ? (string) $lo : "{$lo}–{$hi}";
            $first = $median($d['first_us']);
            printf("| `%s` | %s | `%s` | %s | %s | %.0f%% | %.0f%% | %s |\n", $hook, $prio, $cb, $d['form'], $fires, $firedPct,
                100 * array_sum($d['loaded']) / $requests, is_nan($first) ? '—' : sprintf('%.1f', $first));
        }
        echo "\n";
    }
}
