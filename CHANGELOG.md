# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Redirect matching through one autoloaded `pv_redirects_map` option: single-round-trip read path, zero DB queries on a cache hit ([ADR-0002](docs/decisions/0002-computed-autoloaded-map.md)).
- Exact matching with query-string-embedded keys, case folding, and trailing-slash tolerance ([ADR-0004](docs/decisions/0004-wildcard-semantics.md)).
- Wildcard redirects with capture substitution (`/old/*` → `/new/*`); ordered, first match wins, exact always beats wildcard.
- Quick redirects storage (`pv_redirects`) and per-post redirect meta (`_pv_redirect_to`, `_pv_redirect_status`, `_pv_redirect_active`) on any public post type.
- Settings (`pv_redirects_settings`): `enabled`, `case_sensitive`, `preserve_qs`, `default_status`.
- Permalink-drift fallback and `redirect_canonical` guard (QPPR parity), plus the `?action=no-redirect` bypass.
- WordPress admin app under the **PV Redirects** menu built with `@wordpress/components` (no jQuery), plus an editor sidebar panel for per-post redirects.
- REST API namespace `pv-redirects/v1` (redirects CRUD, settings); every write route rebuilds the map.
- WP-CLI command set `wp pv-redirects`: `migrate`, `rebuild`, `list`, `create`, `delete`, `match`, `test`.
- One-command, idempotent migration from Quick Page/Post Redirect (QPPR) with `--dry-run` and `--delete-source` (copy-on-migrate, [ADR-0003](docs/decisions/0003-copy-on-migrate.md)).
- Hooks: `pv/redirects/redirect` action and `pv/redirects/destination` filter; plus `pv/redirects/map_rebuilt`, `pv/redirects/settings`, and `pv/redirects/option_store_pre_save` for extending write/build behavior.

### Security

- Per-post redirect meta writes (REST + editor sidebar) now require the filterable capability
  `pv/redirects/post_meta_capability` (default `manage_options`) instead of `edit_post` — Authors
  can no longer publish site-wide redirects from their own posts ([ADR-0006](docs/decisions/0006-per-post-redirect-capability.md)).
- REST/AJAX-served front-end requests are skipped via `rest_route`/`/wp-json/` detection instead
  of the `REST_REQUEST` constant (defined too late at `init` to guard), so admin-created wildcard
  rules can no longer 301 the REST API.
- Redirect requests with more than 8 `*` and destinations with control characters (or >2048
  chars) are rejected at write time (ReDoS bound + junk-data defense).
- Map rebuild triggers now cover first-time meta writes (`added_post_meta`), deletions
  (`deleted_post_meta`), and post status transitions, debounced to one rebuild per request.
- Uninstall cleans options and post meta across all sites on multisite.
