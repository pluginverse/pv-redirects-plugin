# Measuring WordPress server-side performance

## Contents

- What to measure
- The recorder
- Classifying the request type
- Timing, CPU and memory plumbing
- Ground truth in dev
- A/B method
- Production through New Relic
- Observer effects

## What to measure

Per request type: median and p95 wall time, CPU (user + sys), peak memory, and ms to `wp_loaded` (boot). Add total hook fires, callbacks attached vs fired, and classes loaded. Measure only what PHP serves: logged-in pages, wp-admin, REST, admin-ajax, login, cache misses, cron events, WP-CLI commands.

## The recorder

`assets/hook-recorder.php` is an mu-plugin.

- **Install** it as the first file in mu-plugins (`000-hook-recorder.php`; on VIP, in `client-mu-plugins/`).
- **Configure** it with constants in `wp-config.php` (or env vars of the same name):

```php
define('HOOK_RECORDER_ENABLED', true);
define('HOOK_RECORDER_DIR', '/tmp/hook-recorder');      // writable dir; on VIP only per-request /tmp is writable
define('HOOK_RECORDER_FILE', true);                     // false: skip the NDJSON file, keep New Relic attributes
define('HOOK_RECORDER_NAMESPACES', 'Acme\\,Other\\');   // class prefixes that count as "ours"
define('HOOK_RECORDER_WRAP', false);                    // true in dev only: exact per-callback calls and timings
define('HOOK_RECORDER_SAMPLE_RATE', 1.0);
define('HOOK_RECORDER_NEWRELIC', false);                // true: add hr_* custom attributes to the transaction
define('QM_DISABLED', true);                            // keep Query Monitor out of the numbers
```

- **What it writes:** one JSON line per request with:
  - the request type and subtype;
  - phase timestamps, memory and class counts;
  - core fire counts per hook;
  - every attached callback: form, identity, whether its class ever loaded, when it was attached, and lower/upper bounds on fires after attach;
  - declared classes;
  - an `env` block that records whether New Relic, Query Monitor and OPcache were active.
- **Container builds:** call `HookRecorder\container_event($class, $built)` from the container's resolve path. In illuminate-style containers, `afterResolving` fires only on builds and `beforeResolving` on every lookup.
- **Summarize:**

```sh
php scripts/summarize_recorder.php /tmp/hook-recorder/hook-recorder-YYYYMMDD.ndjson --ours --top=40 > summary.md
```

- **Cost:** wrap mode adds a few percent in dev. The full callback inventory is roughly 250 KB per request on a vanilla site, so production should use summary attributes only.
- **Hosts with ephemeral disks (WordPress VIP):** `/tmp` survives only the current request, and requests land on different containers, so a local NDJSON file cannot be summarized there. Set `HOOK_RECORDER_FILE` to false and rely on the `hr_*` New Relic attributes. For full records, ship each line to a log pipeline or an HTTP collector the app provides; one record does not fit in a 255-character attribute value.

## Classifying the request type

| Type | Reliable signal | Available from |
|------|-----------------|----------------|
| admin-ajax | `DOING_AJAX` / `wp_doing_ajax()`; `WP_ADMIN` is also defined and `admin_init` fires | before WordPress loads |
| wp-admin | `WP_ADMIN` / `is_admin()` | before WordPress loads |
| REST | `REST_REQUEST` / `wp_is_serving_rest_request()` | `parse_request`, after `init` (before that, guess from the URL) |
| core cron | `DOING_CRON` from `wp-cron.php` | before WordPress loads |
| WP-CLI cron / VIP Cron Control | `WP_CLI` plus `$_SERVER['argv']` | before WordPress loads (`DOING_CRON` only once the event runs) |
| front end | none of the above; template from conditional tags | `wp` / `template_redirect` |

`inpsyde/wp-context` implements early detection with later correction.

## Timing, CPU and memory plumbing

- **Wall time:** `hrtime(true)`. Take the start as early as possible: on VIP that is `vip-config.php`, otherwise `wp-config.php`. `$_SERVER['REQUEST_TIME_FLOAT']` marks the request start.
- **CPU:** `getrusage()` deltas within the request. FPM workers are long-lived, so absolute values are cumulative, and `ru_maxrss` means nothing per request.
- **Memory:** `memory_get_peak_usage()` resets per request. `memory_reset_peak_usage()` (PHP 8.2+) measures one phase.
- **End point:** take the last sample at `shutdown` priority `PHP_INT_MAX`. FPM's own duration, CPU and memory, the slowlog and access logs all stop before shutdown functions run.
- **`fastcgi_finish_request()`** ends TTFB early, but the worker stays busy.

## Ground truth in dev

- Recorder wrap mode gives per-callback calls, first-call cost (load plus build) and steady-state cost. Timings are inclusive of nested hooks.
- Xdebug trace format 1 records every call with time and memory. Turn argument collection on so hook names survive.
- Profile mode (cachegrind) loses hook context: everything sits under `WP_Hook::apply_filters`.

## A/B method

- **Warm first:** at least 10 requests per URL after any change or restart.
- **Interleave arms** (A, B, A, B…), never in blocks, so drift spreads evenly.
- **Sample size:** at least 200 requests per arm. Report median, p95, and a bootstrap 95% CI of the median difference (at least 2,000 resamples).
- **Measure both sides:** in-process (recorder) and end-to-end (`curl -w '%{time_starttransfer}'`).
- **Noise floor:** effects smaller than about twice the arm's own run-to-run noise are inconclusive.

## Production through New Relic

- **No CPU or memory on Transaction events.** CPU is only on traces, and `Memory/Physical` is worker RSS. Add your own custom attributes, for example `php_peak_kb` and `php_cpu_ms`, at shutdown.
- **Limits:** 64 custom attributes per transaction, 255 characters per value. Custom events allow 30,000 per minute.
- **Cron naming:** all VIP cron events share the transaction name `wp-cron`. Add a `cron_hook` attribute to separate them.
- **Example query:**

```sql
SELECT median(duration), percentile(duration, 95), average(hr_ms_to_wp_loaded), average(hr_classes_declared)
FROM Transaction WHERE appName = '<app>' FACET hr_request_type SINCE 1 week ago
```

## Observer effects

| Source | Effect | Workaround |
|--------|--------|------------|
| New Relic agent, WordPress modes `plugin_callbacks` (default) and `all_callbacks` | Calls `zend_is_callable_ex()` on every `add_filter()` callback, which autoloads string and array callback classes at registration on monitored requests | Do not use New Relic samples to judge lazy-loading gains. The fix is php.ini only: `hooks_skip_filename` set to the generated hooks file, or `hooks.options = threshold`. |
| Query Monitor | Reflects callbacks at shutdown (autoloads) and logs every query | Set `QM_DISABLED` |
| Any tool calling `is_callable()`, `method_exists()` or Reflection on callbacks | Autoloads classes | Use `class_exists($class, false)`. `is_callable($cb, true)` checks syntax only. |
| `wp profile` | Top-level hooks only, CLI without OPcache, empty names for `'Class::method'` | Use the recorder |
| Docker Desktop bind mounts | Inflated file-system and class-load costs | Calibrate against Linux or production |
