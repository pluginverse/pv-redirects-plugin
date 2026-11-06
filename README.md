## PV Redirects

PV Redirects (Pluginverse Redirects) is a WordPress redirect manager built for speed and automation: every redirect resolves through one autoloaded map, so the front-end read path is a single `get_option` round-trip with zero DB queries on a cache hit. It is a modern replacement for Quick Page/Post Redirect (QPPR) — wildcard redirects, a WP-components admin (no jQuery), and WP-CLI management including one-command migration off QPPR.

## Features

- **Single-round-trip matching**: all redirects compile into the autoloaded `pv_redirects_map` option (exact, query-string, trailing-slash, and wildcard rules); the matchers never hit the database.
- **Quick redirects**: the `pv_redirects` option, request → destination + status, managed via CLI, REST, or the admin.
- **Per-post redirects**: `_pv_redirect_to`, `_pv_redirect_status`, `_pv_redirect_active` on any public post type; the redirect follows the post's permalink, with a permalink-drift fallback between rebuilds.
- **Wildcards**: `*` matches a run of non-slash characters, trailing `/*` matches the entire remaining path, and a destination `*` is replaced with the matched text (`/old/*` → `/new/*`). Exact always beats wildcard.
- **Status codes 301 / 302 / 307 / 308** per redirect, plus the `?action=no-redirect` bypass (QPPR parity).
- **Settings**: enabled (kill switch), case-sensitive matching, query-string preservation, default status.
- **WP-components admin**: redirects table + settings under the **PV Redirects** menu, and an editor sidebar panel for per-post redirects. No jQuery anywhere.
- **REST API** (`pv-redirects/v1`) backing the admin app; every write rebuilds the map.
- **WP-CLI**: `list`, `create`, `delete`, `match`, `test`, `rebuild`, `migrate` — agent-friendly with `--format=json|csv|table` and dry runs.
- **QPPR migration**: one idempotent command, with `--dry-run` and `--delete-source`.

Requires PHP 8.2+ and WordPress 6.4+.

<!-- GETTING STARTED -->

## Getting Started

### Installation

Download the plugin ZIP asset from a [GitHub release](https://github.com/pluginverse/pv-redirects-plugin/releases), then upload it under **Plugins → Add New → Upload Plugin** and activate it. Releases and the first version tag will be available after publication.

For Composer-managed sites, add the Git repository as a VCS source in the **consumer project's** `composer.json`. This example places PV Redirects in `wp-content/plugins/pv-redirects/` and explicitly permits the installer plugin:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/pluginverse/pv-redirects-plugin.git" }
  ],
  "require": {
    "composer/installers": "^2.0",
    "spenserhale/pv-redirects-plugin": "^1.0"
  },
  "config": {
    "allow-plugins": { "composer/installers": true }
  },
  "extra": {
    "installer-paths": { "wp-content/plugins/pv-redirects/": ["spenserhale/pv-redirects-plugin"] }
  }
}
```

Run `composer install` after the `v1.0.0` tag is published. Adjust the installer path to match your site. This VCS setup does not require Packagist registration.

### Source and builds

The [public source repository](https://github.com/pluginverse/pv-redirects-plugin) includes committed production admin bundles. To reproduce them from the lockfile, run `npm ci` followed by `npm run build`. See the [release guide](docs/guides/releasing.md) for packaging and verification.

#### Configuration

Settings live in the **PV Redirects** admin screen and the REST API (`GET/POST /pv-redirects/v1/settings`):

| Setting | Default | What it does |
|---|---|---|
| `enabled` | `true` | Kill switch — `false` disables every redirect |
| `case_sensitive` | `false` | Case-folds incoming requests and rule keys when off |
| `preserve_qs` | `true` | Carries the incoming query string onto the destination |
| `default_status` | `301` | Status used when a rule does not specify one |

Quick redirects are managed via the admin table, `wp pv-redirects`, or the REST API. Per-post redirects are managed from the editor sidebar panel (**Redirect** in any public post type): destination, status, and an active toggle, saved to post meta via REST.

## CLI Reference

All commands are idempotent where sensible, print stable output (`--format=json|csv|table` on listings), and dry-run before destructive work.

#### wp pv-redirects migrate

Migrate Quick Page/Post Redirect (QPPR) data. Copy-on-migrate: source data is untouched unless `--delete-source`. Re-running skips already-migrated keys/posts and reports the counts. Always rebuilds the redirect map at the end (except with `--dry-run`).

   ```sh
   # Preview what would be written; writes nothing.
   wp pv-redirects migrate --dry-run

   # Migrate everything, leaving QPPR data in place.
   wp pv-redirects migrate

   # Migrate and drop the QPPR source data afterwards.
   wp pv-redirects migrate --delete-source

   # Only per-post redirects.
   wp pv-redirects migrate --skip-quick
   ```

Flags: `--dry-run`, `--delete-source`, `--skip-quick`, `--skip-posts`. Dropped QPPR features (new-window, nofollow, `meta` redirect types, link rewriting) are counted and reported as warnings — never migrated silently.

#### wp pv-redirects rebuild

Recompute `pv_redirects_map` from the current settings, quick redirects, and post meta.

   ```sh
   wp pv-redirects rebuild
   ```

#### wp pv-redirects list

List quick redirects.

   ```sh
   wp pv-redirects list --format=json
   ```

#### wp pv-redirects create

Add a quick redirect. `--status` accepts 301 (default), 302, 307, or 308. Creating an existing request key updates it in place.

   ```sh
   wp pv-redirects create /old-page/ /new-page/
   wp pv-redirects create /2019/press/ https://ext.example/pr --status=302
   ```

#### wp pv-redirects delete

Remove a quick redirect by request key. Idempotent: deleting an unknown key exits cleanly.

   ```sh
   wp pv-redirects delete /old-page/
   ```

#### wp pv-redirects match

Show what the matcher would do for a path: the matched rule and the resolved destination.

   ```sh
   wp pv-redirects match /old-blog/post-1/
   ```

#### wp pv-redirects test

Full request simulation: normalize → match → destination resolution, including query-string handling.

   ```sh
   wp pv-redirects test https://example.com/old-blog/post-1/?utm_source=news
   ```

## Hooks

### Actions

#### pv/redirects/redirect

Fires immediately before the redirect executes (`wp_redirect()` + `exit`). Use it to log, rate-limit, or forward redirect events.

Example
```php
add_action('pv/redirects/redirect', static function(string $request, array $rule) {
    error_log(sprintf('pv-redirects: %s -> %s (%d)', $request, $rule['to'], $rule['status']));
}, 10, 2);
```

### Filters

#### pv/redirects/destination

Last-chance filter on the resolved destination URL, right before execution.

Example
```php
add_filter('pv/redirects/destination', static function(string $destination, array $rule, string $request) {
    // Force https on one legacy host.
    return str_replace('http://legacy.example.com', 'https://legacy.example.com', $destination);
}, 10, 3);
```

## Migration

From Quick Page/Post Redirect (QPPR):

   ```sh
   # 1. Preview: prints the mapping table, writes nothing.
   wp pv-redirects migrate --dry-run

   # 2. Migrate (copy-on-migrate; QPPR data stays in place until you are ready).
   wp pv-redirects migrate

   # 3. Verify the migrated redirects, then deactivate QPPR to avoid competing redirects.
   # Optional: remove copied QPPR data only after taking a backup.
   wp pv-redirects migrate --delete-source
   ```

| QPPR source | PV target | Notes |
|---|---|---|
| `quickppr_redirects` map | `pv_redirects` rows | Value string becomes destination + status (301 or QPPR's global type) |
| `_pprredirect_active` + `_pprredirect_url` | `_pv_redirect_active`, `_pv_redirect_to` | Per-post redirects |
| `_pprredirect_type` (301/302/307) | `_pv_redirect_status` | `meta` type warns and falls back to 301 |
| `ppr_override-casesensitive` | `settings.case_sensitive` | Only when `1` |
| `ppr_override-redirect-type` | `settings.default_status` | `meta` warns and falls back to 301 |
| `ppr_override-active` | `settings.enabled=false` | Warned: redirects stay disabled until re-enabled |

**Dropped from QPPR by design** — each is counted and reported during migration, never migrated: jQuery link rewriting, `rel=nofollow`, new-window targets, meta-refresh (JS or tag), link-URL rewriting in menus/permalinks, admin pointers, FAQ RSS, redirect columns, and hits counting.

## Tests

   ```sh
   composer test
   ```

Requires Docker (wp-env). `composer env:start` / `composer env:stop` manage the test environment.

## License

The PV Redirects plugin is open-sourced software licensed under the [GNU General Public License v3.0 or later](https://spdx.org/licenses/GPL-3.0-or-later.html).
