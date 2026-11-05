# WordPress VIP specifics

## Background work: one boot per cron event

- VIP replaces core WP-Cron with Cron Control. A runner polls each site about every 30 s with `wp cron-control orchestrate runner-only list-due-batch`, plus `get-info` about once a minute. Each poll is a WP-CLI command, so each poll is a full WordPress boot.
- Each due event runs through its own `wp cron-control orchestrate runner-only run` command, which is one full WP-CLI plus WordPress boot per event, with no batching. The command arrives as a FastCGI request into PHP-FPM through a bridge script, so OPcache is warm but nothing persists between events.
- Defaults: 10 concurrent events per site, 1 concurrent run per action, several runners.
- `DOING_CRON` is defined only after boot, when the event runs. `wp_doing_cron()` is therefore false during `plugins_loaded` and `init`; classify with `WP_CLI` plus `$_SERVER['argv']`.
- Action Scheduler runs inside cron events (batches of 25 for up to 120 s per event, plus dynamic queues). It amortizes a boot across many actions, so prefer it, or one looping event, over many tiny events.
- Background cost ≈ (events per day + polling boots per day) × boot cost + work. Cutting boot cost multiplies across every event and poll.

## Measurement constraints

- No custom php.ini and no extensions outside VIP's allow-list: no Xdebug, SPX, XHProf, Excimer or OpenTelemetry in production. Only per-request `/tmp` is writable. Ship data out through New Relic attributes, logs (`vip logs` returns up to 5,000 entries per fetch), or HTTP.
- The earliest code hook is `vip-config.php`. It runs before WordPress core (no WordPress functions), but `hrtime()`, `getrusage()` and `spl_autoload_register()` work there. Files in `client-mu-plugins` load after all of VIP's own mu-plugins.
- Hosted environments block `wp eval`, `wp eval-file`, `wp shell`, `wp profile`, `wp package` and `--require`. A CLI collector must be a custom WP-CLI command shipped in the app. Use the `wp-vip-cli` skill for `vip wp` syntax.
- Page-cache bypass for benchmarks: the cookie `vip-go-cb=1`. Logged-in, POST and auth-cookie requests bypass the cache too.

## APM on VIP

- **New Relic** is an opt-in integration.
  - Samples 10% of requests per VIP docs; teams sometimes believe otherwise, so check.
  - On non-production environments, activation auto-expires after 7 days.
  - `?vip-force-trace=1` or the header `x-vip-force-trace: 1` forces a trace. It runs on web and batch containers.
  - Callbacks in `mu-plugins` / `client-mu-plugins` get no plugin attribution in the default mode.
  - The agent's registration-time callback resolution autoloads string-callback classes on monitored requests. Its settings are php.ini-only, so a change is a VIP support request.
- **VIP APM**: free, fixed 10% sample.
  - Traces grouped by work type (db, cache, HTTP, hook, job) and owner (core, plugin, theme, mu-plugin).
  - Covers web, cron and WP-CLI. `apm-force-trace=1` forces a sample.
  - No API or export, so use it for manual cross-checks.

## Local dev-env (`vip dev-env`)

- Docker/Lando, PHP 8.2–8.5, FPM `pm.max_children = 5`.
- Xdebug is installed for step debugging, with argument collection off in the shipped php.ini. `XDEBUG_MODE=trace` or `profile`, set through `vip dev-env envvar set`, is the documented-by-code route to tracing; verify output paths.
- New Relic is not included.
- Query Monitor runs on every request with `SAVEQUERIES`, because local debug mode grants `view_query_monitor` to everyone. Set `QM_DISABLED` while measuring.
- VIP itself says the local environment is not for performance testing. Confirm findings on a non-production VIP environment.

## Platform facts worth knowing

- APCu, igbinary, Memcached and OPcache are available. OPcache settings are not disclosed; read them at runtime with `opcache_get_status(false)` and `ini_get()` from a web request, not WP-CLI.
- Preloading is unavailable: it needs php.ini.
- `memory_limit` is 768 MB. `WP_MEMORY_LIMIT` is 40 MB (single site) or 64 MB (multisite).
- `VIP_GO_APP_ENVIRONMENT` holds the environment type, available from `vip-config.php` on.
