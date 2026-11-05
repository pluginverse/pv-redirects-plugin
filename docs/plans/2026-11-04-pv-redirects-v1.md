# Plan: PV Redirects v1.0.0 — 2026-11-04

Build PV Redirects (Plugin Verse Redirects), a modern replacement for Quick Page/Post Redirect
(QPPR). Goal: lossless migration path, single-round-trip read path, wildcard matching, WP-components
admin, WP-CLI-first operations.

## Identity

| | |
|---|---|
| Plugin name | PV Redirects |
| Slug / text domain | `pv-redirects` |
| Namespace | `PV\Redirects` |
| Hook prefix | `pv/redirects/…` |
| Option prefix | `pv_redirects…` |
| Post meta prefix | `_pv_redirect_…` |
| PHP floor | 8.2 (WP 6.4+) |
| Repo | github.com/pluginverse/pv-redirects-plugin |

Conventions follow `cloudflare-enterprise-cache-wp-plugin`: root file = thin bootstrap in the plugin
namespace with an `spl_autoload_register` classmap (no Composer autoload on the production path),
static-method classes, string-callable hook registration, `wp-phpunit` + phpstan level 9.

## Storage (canonical, written by us)

1. **Quick redirects option** — `pv_redirects` (autoload yes, `map`):
   `request => [ 'to' => string, 'status' => int, ]`
   Request keys stored user-entered (leading `/`); per-row status (301/302/307/308).
2. **Per-post redirects** — post meta on any public post type:
   - `_pv_redirect_to` (string destination)
   - `_pv_redirect_status` (301|302|307|308, default 301)
   - `_pv_redirect_active` ('1'|'')
3. **Settings** — `pv_redirects_settings` (autoload yes, small):
   `['enabled'=>true,'case_sensitive'=>false,'preserve_qs'=>true,'default_status'=>301]`

## Computed map — the read path (core perf feature)

**`pv_redirects_map`** (autoload **yes**) — the ONLY thing the front-end read path loads. One
`get_option`, served from alloptions cache; zero DB on cache hit. Rebuilt by `RedirectMap::rebuild()`
on every write; never read-modify-written by matchers.

```php
[
  'v' => 1,               // schema version
  'built' => 1700000000,  // unix ts
  'settings' => [...],    // snapshot of settings at build time
  'exact' => [
    // normalized (see Matching) request key => rule
    '/old-page/' => ['to'=>'/new-page/','status'=>301,'src'=>'o'],        // src o=option
    '/2019/press/' => ['to'=>'https://ext.example/pr','status'=>302,'src'=>'p','id'=>123],
  ],
  'wildcards' => [        // ordered; first match wins; rebuilt in insertion order
    ['pattern'=>'/old-blog/*','to'=>'/new-blog/*','status'=>301,'src'=>'o'],
  ],
  'posts' => [            // post_id => rule, for permalink drift + unpretty permalinks
    123 => ['to'=>'https://ext.example/pr','status'=>302],
  ],
]
```

Build inputs: `pv_redirects` option + one meta query (`_pv_redirect_to`, active only) resolving each
post's **permalink** as its exact key (the "permalink follows it around" behavior). Rebuild triggers:
option CRUD, `save_post` (when our meta present/changed), migration, import, `wp pv-redirects rebuild`.

## Matching & execution

- **Hook:** `init` priority 1, static `RedirectHandler::init`. Skip when `is_admin()`, `WP_CLI`,
  REST, or cron. Kill-switch: `settings['enabled']===false` → return. `?action=no-redirect` bypass
  (QPPR parity).
- **Normalize:** path only (`$_SERVER['REQUEST_URI']` minus query string), home-URL prefix stripped,
  case-folded when `case_sensitive=false`. Keys normalized identically at build time.
- **Cascade (all in-memory):**
  1. exact hit incl. query string (`/path/?q=1` form, QPPR parity — build indexes these raw)
  2. exact hit on path
  3. exact hit on path with trailing slash toggled
  4. wildcard pass, in order
  5. miss → no redirect
- **Wildcards:** `*` matches a run of non-slash chars; a trailing `/*` matches the entire remaining
  path (incl. slashes). Destination `*` is replaced by the matched text (capture substitution), e.g.
  `/old/*` → `/new/*` maps `/old/a/b` → `/new/a/b`. First match wins; exact always beats wildcard.
- **Destination resolution:** absolute URL as-is; leading `/` prefixed with home URL; `?p=123`
  numeric → `home/?p=N` (QPPR parity). Relative `home . '/' . path` fallback.
- **Query string:** when `preserve_qs` and destination lacks `?`, append incoming QS; else merge with `&`.
- **Execution:** `wp_redirect($url, $status); exit;` Status from rule. Fire `do_action('pv/redirects/redirect', $request, $rule)` first. Filter `pv/redirects/destination` last-chance.
- **Canonical guard:** filter `redirect_canonical` — if our map matches the 404'd request, return the redirect destination so core canonicalization doesn't preempt (QPPR parity).
- **Permalink drift fallback:** on `template_redirect`, if queried post ID exists in `map['posts']`,
  redirect (covers renamed permalinks between rebuilds; zero DB).

## Dropped from QPPR (by decision)

jQuery link rewriting, `rel=nofollow`, new-window, meta-refresh (JS or tag), link-URL rewriting in
menus/permalinks, admin pointers, FAQ RSS, redirect columns, hits counting. Migration reports each
dropped item it encounters. See ADR-0005.

## REST API (backs the admin app)

Namespace `pv-redirects/v1`, capability `manage_options`:
- `GET/POST /redirects` — list (paged) / create
- `GET/PUT/DELETE /redirects/(?P<request>…)` — read/update/delete by request key
- `GET/POST /settings`
Every write route rebuilds the map.

## Admin (WP primitives, no jQuery)

- One React app (Gutenberg `@wordpress/components`, `@wordpress/data`, `@wordpress/api-fetch`,
  `@wordpress/element`) under menu **PV Redirects**: redirects table (list, add, edit, delete,
  status select) + settings panel. No custom CSS beyond `wp.components` + `notice` styles.
- Editor sidebar panel (`PluginDocumentSettingPanel`) for per-post redirect (to / status / active),
  saving via `@wordpress/core-data`/`api-fetch` to post meta through REST.
- Source in `admin-src/`, built with `@wordpress/scripts` to `build/` (committed). Enqueue with
  `wp_enqueue_script` deps on `wp-element`, `wp-components`, `wp-data`, `wp-api-fetch`,
  `wp-plugins`, `wp-edit-post` as applicable.

## WP-CLI

`wp pv-redirects <command>` (agent-native: `--format=json|csv|table`, idempotent, dry-runs):
- `migrate [--dry-run] [--delete-source] [--skip-quick] [--skip-posts]` — from QPPR
- `rebuild` — recompute `pv_redirects_map`
- `list [--format=…]`, `create <request> <to> [--status=301]`, `delete <request>`
- `match <path>` — show what the matcher would do (rule + resolved destination)
- `test <url>` — full request simulation (normalize → match → destination)

### Migration mapping (QPPR → PV)

| QPPR source | PV target | Notes |
|---|---|---|
| `quickppr_redirects` map | `pv_redirects` rows | value string → `['to'=>…,'status'=> 301 or global type]` |
| `quickppr_redirects_meta` | — (dropped) | newwindow/nofollow only; count + warn |
| `_pprredirect_active='1'` + `_pprredirect_url` | `_pv_redirect_active`, `_pv_redirect_to` | |
| `_pprredirect_type` 301/302/307 | `_pv_redirect_status` | `meta` type → warn, fall back 301 |
| `_pprredirect_newwindow/_relnofollow/_rewritelink` | — (dropped) | count + warn |
| `ppr_override-casesensitive='1'` | `settings.case_sensitive=true` | |
| `ppr_override-redirect-type` | `settings.default_status` | 'meta' → 301 + warn |
| `ppr_override-active='1'` | `settings.enabled=false` + warn | |

Copy-on-migrate (ADR-0003): source data untouched unless `--delete-source`. Idempotent: re-running
skips already-migrated keys/posts (report counts). `--dry-run` prints the table, writes nothing.
Always ends with `RedirectMap::rebuild()` (except dry-run).

## Testing

wp-phpunit + yoast polyfills (CF-plugin bootstrap pattern). Suites:
- `MatcherTest` — exact, QS-embedded keys, case fold, trailing slash, wildcard `*` vs `/*`,
  capture substitution, ordering, precedence
- `RedirectMapTest` — build from option+posts, permalink keys, posts index, rebuild triggers
- `MigratorTest` — QPPR fixture arrays → PV stores, idempotency, dry-run, dropped-item warnings
- `RedirectHandlerTest` — WP_UnitTestCase integration: init matching, `redirect_canonical`,
  bypass param
- `CliTest` — command dispatch via WP_CLI test helpers (or skip-ci mark if flaky)

phpstan level 9 (src/), `szepeviktor/phpstan-wordpress`, `phpstan-phpunit`.

## CI

`.github/workflows/ci.yml`: phpunit (wp-env matrix or phpunit directly) + phpstan + admin build
(`npm ci && npm run build`) on push/PR. Separate `deploy` job appending the CF-style `-built` branch
(strip tests/dev, ship `build/`), tagging on `v*`.

## File map

```
pv-redirects.php  uninstall.php  composer.json  package.json  phpunit.xml.dist
phpstan.neon  phpstan.extension.neon  .wp-env.json  .gitignore  .deploy.gitignore
readme.md  assets/icon.svg
src/{OptionStore,PostMetaStore,RedirectMap,Matcher,RedirectHandler,Settings,RestController,Cli,Migrator,Admin}.php
admin-src/{index.js,index.js?… app + store}, admin-src/sidebar.js  →  build/
tests/{bootstrap.php,wp-config.php,MatcherTest,RedirectMapTest,MigratorTest,RedirectHandlerTest}.php
docs/…  AGENTS.md  CLAUDE.md  CHANGELOG.md
```

## Wave plan (implementation order)

1. **Scaffold** — tooling files, docs tree, readme, uninstall, CI. (hand)
2. **Core** — bootstrap, OptionStore, PostMetaStore, RedirectMap, Matcher, RedirectHandler,
   Settings. (hand) ← longest; starts after scaffold lands file map (or in parallel on disjoint files)
3. **Migrator + CLI** (hand) · **REST + admin app** (hand) · **tests** (hand) — parallel, disjoint
4. Review + AGENTS.md/CHANGELOG/readme polish + phpstan/test pass (brain)
5. Create `pluginverse/pv-redirects-plugin`, push main.

## Rulings (made during the build)

- **phpunit ^9.6 + wp-phpunit ^6.7 + polyfills ^4.0** (not 10): polyfills 4.x refuses PHPUnit 10;
  core's bundled test suite is battle-tested on 9. CI keeps a php 8.2/8.3 matrix.
- **`@wordpress/scripts` ^37 + Node 22**: 37 requires node ≥22.22; Node 20 is EOL (Apr 2026). The
  `@wordpress/*` runtime packages are devDependencies for lint/IDE; the build emits externals only.
- **`composer test` cwd is `wp-content/plugins/pv-redirects-plugin`** (directory basename, not the
  slug) — wp-env mounts by folder name.
- **phpstan constants**: `phpstan-bootstrap.php` defines `PV_REDIRECTS_*` for analysis, wired via
  `parameters.bootstrapFiles` (PHPStan 2 moved it under `parameters`); `composer phpstan` carries
  `--memory-limit=512M`.
- **`RedirectMap::getShape()`** was added beside `get()`: phpstan could not narrow the
  `Map|list<never>` union no matter the runtime guard; `get()` keeps its exact legacy behavior for
  tests, new callers narrow via `getShape()`.
- **JSX runtime shim for WP 6.4/6.5**: wp-scripts 37 emits the automatic JSX runtime
  (`react-jsx-runtime`), which WP registers only from 6.6. `Admin::jsxRuntimeDependency()`
  registers a false-src handle with an inline `jsx/jsxs` shim over `wp.element.createElement` when
  core's handle is absent.
- **REST error contract**: errors are standard `WP_Error` bodies (top-level `code`/`message`/
  `data.status`, codes `pv_redirects_*`), success keeps the `{success,data}` envelope. api-fetch
  consumes them natively — no `parse:false` workaround.
- **Wildcard metachar safety**: `compileWildcard()` quotes literals before substituting `*`
  captures, and `**` is documented as trailing-only (mid-pattern `**` yields adjacent captures
  that cannot match — accepted for v1).
- **QPPR `''` redirect type**: QPPR runtime treated empty as 302; migration maps it to 301 with a
  review warning rather than silently choosing either.
