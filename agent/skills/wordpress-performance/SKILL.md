---
name: wordpress-performance
description: "Diagnose and fix server-side WordPress performance: slow uncached
  requests (TTFB in wp-admin, REST, admin-ajax, logged-in pages), plugin boot
  cost, WP-Cron and WP-CLI overhead, and hook wiring (eager vs lazy vs memoized
  callbacks, static vs instance classes, DI container use). Use when the user
  asks why WordPress or PHP is slow, wants to profile or cut CPU or memory per
  request, reviews add_action or add_filter code, or mentions New Relic, Query
  Monitor or OPcache on WordPress, even if they never say 'performance'. Not for
  front-end Core Web Vitals or asset optimization; for vip CLI commands use
  wp-vip-cli."
license: MIT
compatibility: Scripts need PHP 8.1+ CLI. The recorder mu-plugin needs PHP 8.0+
  (a parse error on 7.x) and WordPress 6.1+.
metadata:
  author: spenserhale
  version: "0.1"
---
# WordPress Performance

Every uncached request and every cron or WP-CLI job boots WordPress from scratch, so cost = boot work + Σ(callback cost × hook fires). Measure fire counts and construction cost per request type, then wire each callback so it costs nothing when its hook does not fire and is built at most once when it does.

## Steps

1. **Scope the slow path.** List the request types PHP actually serves (logged-in front end, wp-admin screens, REST routes, admin-ajax actions, cache misses, cron events, WP-CLI) and get a baseline per type: median and p95 wall time, CPU, peak memory, ms to `wp_loaded`. A full-page cache hides most traffic, so anonymous front-end numbers rarely matter. Done when a baseline table per request type exists.
2. **Measure, do not guess.** Install `assets/hook-recorder.php` as the first mu-plugin (named `000-hook-recorder.php`), disable Query Monitor, send at least 30 requests per URL per type, then summarize with `scripts/summarize_recorder.php`. Use wrap mode only in dev to get per-callback calls and first-call cost. Done when you know the hottest hooks, the callbacks that never fire, and the costliest callbacks per type.
3. **Classify each suspect callback** by wiring pattern and apply the rules below. Done when each suspect has a pattern, its fire counts per type, and a proposed change with expected savings.
4. **Fix with the smallest change** that keeps behavior and `remove_filter()` compatibility. Done when tests pass and the callback form is still removable (or the change is called out).
5. **Verify with interleaved A/B** (≥ 200 requests per arm, warm OPcache, report median, p95 and a bootstrap CI of the difference). Done when the measured saving is recorded next to the predicted one. A miss of more than 2x means the model of the problem is wrong; revisit step 2.

## Wiring rules

- **Never rebuild per fire.** A lazy callback that builds its object graph on every call (non-shared container `make()` or `new` inside a static entry point) multiplies construction by fire count. Memoize: static property, singleton binding, or a PHP 8.4 lazy object. This is the costliest pattern on hot hooks.
- **Rarely fired and costly to build: make it lazy.** Register a static entry point (`['Class', 'method']`) that builds on first fire and memoizes, or gate registration behind a hook that only fires in that context (`rest_api_init`, `admin_init`, `cli_init`). Fail open when the request type is uncertain.
- **Always fired: leave it.** Eager and lazy-memoized differ by tenths of a microsecond per fire; churn here buys nothing.
- **Stateless: make it static.** No construction is the cheapest construction.
- **Move I/O out of constructors.** An option read outside alloptions, a query or an HTTP call in a constructor costs more than hundreds of class loads; defer it to first use.
- **Batch background work.** Each cron event is a full boot, so many tiny events cost more than one event (or an Action Scheduler batch) doing the same work.

Callback forms, as WordPress `add_filter()` sees them:

| Form | Class loads | Removable by others | Note |
|------|-------------|---------------------|------|
| `['Foo', 'bar']` | at first fire | yes | Preferred lazy form; same removal ID as the string, and about 35 ns per fire cheaper |
| `'Foo::bar'` | at first fire | yes | Method must be static or the first fire throws a `TypeError` |
| `[$object, 'bar']` | at registration | only with that object | Builds the graph at boot |
| `fn () => ...` | at first fire | no | Lazy but not removable |
| `Foo::bar(...)`, `Closure::fromCallable()` | at registration | no | Looks lazy, is not |

**Static vs instance:** decide by state, not call speed (the difference is about 20 ns). Pure logic belongs in static methods. Configured collaborators (HTTP clients built from env and options, middleware stacks) belong in lazily built, memoized instances: same speed, plus composition and resets. Static and singleton state lives for the whole process, and one process can span many sites (`switch_to_blog()`), many Action Scheduler actions, or a long CLI loop. Key such caches by blog ID or reset them on `switch_blog`.

## Gotchas

- New Relic's default WordPress mode resolves every `add_filter()` callback at registration, autoloading the class behind every string or array callback on monitored requests. Do not judge lazy-loading savings from New Relic samples. The fix (`newrelic.framework.wordpress.hooks_skip_filename` or `hooks.options = threshold`) is php.ini only, so on managed hosts it is a support request.
- Query Monitor reflects callbacks at shutdown, which autoloads their classes, and logs every query. Define `QM_DISABLED` while measuring; on WordPress VIP's local dev-env it runs on every request by default.
- `REST_REQUEST` is defined only at `parse_request`, after `init`. `is_admin()` is true and `admin_init` fires on admin-ajax requests. Under WP-CLI cron runs and VIP Cron Control, `wp_doing_cron()` is false during boot, so classify those with `WP_CLI` plus `$_SERVER['argv']`.
- `$wp_actions` and `$wp_filters` count every fire for free (`did_action()`, `did_filter()`); never use the `all` hook for production measurement, because it runs on every fire of every hook.
- `is_callable()`, `method_exists()` and Reflection on callback strings autoload the class. Keep instrumentation passive (`class_exists($class, false)`).
- Composer prepends its autoloader, so an early autoload logger misses later loaders. Diff `get_declared_classes()` between phases instead.
- PHP-FPM status, slowlog and access-log timings stop before shutdown functions, so WordPress's `shutdown` work is invisible there. Take the final sample at `shutdown` priority `PHP_INT_MAX`.
- Core removes and re-adds some callbacks mid-request (`wpautop` on `the_content`), so call counts inferred from hook fire counts are off for those. Wrap mode counts calls exactly only from the point a callback is wrapped (end of `plugins_loaded` onward); the recorder marks rows `calls_complete: false` or `volatile: true` when that does not hold.
- With warm OPcache a class loads in well under a microsecond; with OPcache off or on Docker Desktop bind mounts it is far slower. Calibrate dev numbers against production before trusting absolutes.
- `wp profile` times only top-level hooks, runs without OPcache under the CLI, gives `'Class::method'` callbacks empty names, and is blocked on VIP hosted environments.

## Resources

- Read `references/hook-wiring.md` when choosing or reviewing a wiring pattern or estimating savings; it has the pattern catalog, cost model, measured costs and the decision procedure.
- Read `references/measurement.md` before profiling; it covers the recorder workflow, request-type classification, timing and memory plumbing, A/B method and New Relic queries.
- Read `references/vip.md` when the site is on WordPress VIP; it covers Cron Control boot costs, sampling, observer effects, dev-env limits and blocked commands.
- Read `references/state-lifetime.md` when moving logic between static and instance or memoizing anything that depends on site, user or environment.
- Read `references/sources.md` to check a claim against its source.
- Copy `assets/hook-recorder.php` into the site's mu-plugins; its header lists the constants that configure it.
- Run `php scripts/summarize_recorder.php --help`; it turns recorder NDJSON into per-request-type Markdown tables.
- Run `php scripts/check_callback_forms.php --help`; it verifies on the target PHP and WordPress version which callback forms autoload, which throw and which are removable.
- For `vip dev-env` and `vip wp` commands use the `wp-vip-cli` skill; for general WP-CLI use `wordpress-cli`.
