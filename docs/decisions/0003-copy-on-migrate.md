---
title: "0003: Migrate by copying into PV storage, source untouched"
description: Migration from QPPR copies data into PV Redirects' own options and post meta; the legacy data is never modified unless --delete-source is passed.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale (owner), GLM (drafting agent)"
---

## Context

PV Redirects replaces Quick Page/Post Redirect (QPPR) on sites that already run it. QPPR stores
quick redirects in `quickppr_redirects`/`quickppr_redirects_meta` and per-post redirects in
`_pprredirect_*` meta. Migration must be reversible and safe on production sites, triggered via
WP-CLI only (owner's choice). Plan: [pv-redirects-v1](../plans/2026-11-04-pv-redirects-v1.md).

## Options considered

1. **Copy into PV storage**: read QPPR stores, write `pv_redirects` / `_pv_redirect_*`. QPPR data
   untouched; both plugins could even stay installed briefly.
2. **Adopt QPPR's keys in place**: read/write the same option and meta keys. Zero-copy migration and
   instant rollback, but schema constraints (no per-row status in QPPR's quick-redirect value shape),
   collision risk if QPPR is reactivated or updated, and forever carrying a foreign schema.

## Decision

We will copy into our own storage (option 1), because rollback is "deactivate PV Redirects,
reactivate QPPR" and our schema is unconstrained. Idempotent: re-running skips already-migrated
keys/posts. `--dry-run` reports without writing; `--delete-source` is opt-in cleanup after the team
confirms the cutover.

## Consequences

- Dropped QPPR features (nofollow, new-window, meta-refresh, link rewriting) are counted and
  reported as warnings during migration rather than silently vanishing. See ADR-0005.
- Both plugins active simultaneously would double-redirect (QPPR runs at `init` prio 1 too); the
  migration report must tell the operator to deactivate QPPR after migrating.
- Post-meta migration writes to every affected post once, then never touches it again.

## Revisit when

If QPPR stays installed long-term on some sites and data drifts between the two plugin's stores.
