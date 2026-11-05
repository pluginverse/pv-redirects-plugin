---
title: "Redirects"
description: "HTTP redirect matching and execution: storage, the computed map, wildcards, per-post redirects, migration from QPPR, admin, CLI, and REST."
status: current
---

<!-- Living spec: what the feature does NOW. Change it in the same commit as the behavior it describes. Planned behavior belongs in the change's plan (docs/plans/) as a spec delta until it ships. -->

## Intent

PV Redirects moves sites off Quick Page/Post Redirect (QPPR) without downtime: redirects keep
living in an option and in post meta where editors expect them, while the front-end read path
loads exactly one autoloaded, precomputed map per request. For Plugin Verse sites it is the single
redirect manager — CLI-first, no jQuery, wildcard-capable.

## Behavior

### Storage

- Quick redirects live in option `pv_redirects`: `request => ['to' => string, 'status' => int]`,
  per-row status 301/302/307/308. Requests may not contain more than 8 `*` (ReDoS bound);
  destinations are rejected with control characters or over 2048 chars.
- Per-post redirects live in post meta `_pv_redirect_to`, `_pv_redirect_status`, `_pv_redirect_active`
  (`'1'`/`''`), registered for REST on all public post types. Writing them requires the filterable
  capability `pv/redirects/post_meta_capability` (default `manage_options`) — see ADR-0006.
- Settings live in `pv_redirects_settings`: `enabled`, `case_sensitive`, `preserve_qs`,
  `default_status`; filterable via `pv/redirects/settings`.
- The computed map `pv_redirects_map` (autoload yes) is the ONLY read-path artifact: exact rules,
  compiled wildcard rules, a post-ID index, and a settings snapshot. Rebuilt on every write path
  (store mutations immediately; meta-key changes debounced to one rebuild at `shutdown`).
  Deletable and rebuildable at any time (`wp pv-redirects rebuild`).

### Matching and execution

- Hook `init` priority 1 (before the query, so 404-bound requests still redirect), skipped for
  admin/cron/CLI requests, REST requests (`rest_route` query var or a `/wp-json/` path prefix —
  `REST_REQUEST` is defined too late to check at `init`), when `?action=no-redirect` is present,
  or when `enabled` is false.
- Request path is site-relative (home URL and subdirectory prefix stripped). Cascade: exact key
  including embedded query string → exact path → path with trailing slash toggled → wildcards in
  insertion order → no match.
- Case-insensitive by default (keys case-folded at build and match); `case_sensitive` flips both.
- Wildcards: `*` = run of non-slash characters, a final `/*` = the entire remaining path,
  `*` in the destination is substituted with the matched text. Exact always beats wildcard; first
  wildcard wins; the wildcard pass tolerates a trailing-slash difference like exact keys do;
  `**` is supported only in trailing position.
- Destinations: absolute URLs pass through, `/path` gets the home prefix, numeric values become
  `home/?p=N`, anything else is relative to home. Incoming query string is preserved
  (`preserve_qs`): appended with `?` or merged with `&`.
- Execution fires `pv/redirects/redirect`, then the `pv/redirects/destination` filter, then
  `wp_redirect()` with the rule's status and exits.
- `redirect_canonical` guard returns our destination when the map matches, so core
  canonicalization cannot preempt us; `template_redirect` fallback redirects by post ID when a
  permalink changed since the last rebuild (permalink drift).

### Migration (QPPR → PV)

- WP-CLI only: `wp pv-redirects migrate [--dry-run] [--delete-source] [--skip-quick] [--skip-posts]`.
- Copies `quickppr_redirects`, `_pprredirect_*` post meta, and relevant settings into PV stores;
  the source is untouched unless `--delete-source` (which only removes what this run copied or
  verified as migrated). Idempotent: previously migrated keys/posts are counted as
  `skipped_existing`, never duplicated.
- Dropped QPPR features (new-window, nofollow, link rewriting, meta-refresh) are counted and
  warned, never silent: type `meta` and unknown types downgrade to 301 with warnings; request keys
  carrying a URL scheme (e.g. `mailto:…`) are skipped with a review warning — they can never match
  a path key.
- Report ends by instructing the operator to deactivate QPPR (both plugins at `init:1` would
  double-redirect).

### Admin, CLI, REST

- Admin page "PV Redirects": redirects table (add/edit/delete via Modal, wildcard-aware warnings,
  two-step destructive delete) and settings panel — built on @wordpress/components, zero jQuery.
- Editor sidebar panel "Redirect" manages the per-post redirect via REST-registered meta; the
  panel only enqueues for users holding the filterable redirect capability (ADR-0006) and never
  loads outside the post-edit screen.
- CLI (`wp pv-redirects`): `rebuild`, `migrate`, `list --format=…`, `create`, `delete`, `match`,
  `test`. Non-interactive, JSON/CSV output for automation.
- REST `pv-redirects/v1` (capability `manage_options`): redirects CRUD + settings. Success bodies
  are `{success, data}`; errors are standard `WP_Error` bodies with `pv_redirects_*` codes.

## Acceptance criteria

- Matcher golden cases (exact/QS/case/slash/wildcards/capture substitution/metachar safety) hold
  (`tests/MatcherTest.php`)
- Store mutations rebuild the map with correct exact/wildcard/posts indexes
  (`tests/OptionStoreTest.php`, `tests/RedirectMapTest.php`)
- Migration contract: happy path, type mapping, idempotency, dry-run, delete-source scoping,
  settings mapping, wildcard migration (`tests/MigratorTest.php`)
- REST contract incl. error taxonomy and permissions (`tests/RestControllerTest.php`)
- Read path performs no DB beyond the single map option (enforced by review + phpstan, per
  `docs/decisions/0002`)

## Non-goals

- Frontend link rewriting, `rel=nofollow`, new-window behavior, and meta-refresh redirects —
  dropped from QPPR by decision, warned during migration.
- Per-rule hit analytics.
- A custom storage table (revisit at tens of thousands of rules; see ADR-0002).

## Constraints

- WordPress 6.4+, PHP 8.2+. Admin bundles load as `wp.*` externals; a JSX-runtime shim covers
  WP 6.4/6.5 where `react-jsx-runtime` is not registered.
- phpstan level 9 clean; `src/` classes final and static; read path stays allocation-light and
  query-free.

## Decisions

- [0002: Single computed autoloaded map for the redirect read path](../decisions/0002-computed-autoloaded-map.md)
- [0003: Migrate by copying into PV storage, source untouched](../decisions/0003-copy-on-migrate.md)
- [0004: Wildcard matching semantics](../decisions/0004-wildcard-semantics.md)
- [0005: Drop QPPR's jQuery-era features](../decisions/0005-drop-jquery-era-features.md)
- [0006: Per-post redirects require an admin capability by default](../decisions/0006-per-post-redirect-capability.md)
