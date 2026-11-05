---
title: "0002: Single computed autoloaded map for the redirect read path"
description: All redirects live in one rebuilt option, `pv_redirects_map`, that the front end loads once per request; writers rebuild it.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale (owner), GLM (drafting agent)"
---

## Context

QPPR supports redirects stored in an option and in post meta, but its read path resolves them with
multiple option reads plus a `postmeta` SQL scan per request. WordPress serves anonymous pages from
the object cache/alloptions, so a single autoloaded option is the cheapest possible read path, while
post-meta scans are per-request DB round trips. Plan: [pv-redirects-v1](../plans/2026-11-04-pv-redirects-v1.md).

## Options considered

1. **Computed map option**: keep canonical data in `pv_redirects` (option) + `_pv_redirect_*` (post
   meta) where users edit it, and maintain `pv_redirects_map` (autoload yes) combining both, rebuilt
   on every write. Front end reads only the map.
2. **Read-through caching**: query canonical stores, cache the result in a transient. Adds cache
   invalidation complexity and still pays a DB read on cold caches.
3. **Custom table**: scalable for huge sets, but adds table management and still needs caching for
   the hot path; overkill for typical redirect counts.

## Decision

We will maintain a single computed option `pv_redirects_map` (option 1), because it gives a
zero-query front-end read path after WordPress's alloptions load while keeping user-visible storage
in the familiar places (option + post meta).

## Consequences

- Any write path (REST, CLI, meta save, migration, import) must call `RedirectMap::rebuild()` or
  redirects drift; the rebuild is idempotent and safe to call repeatedly.
- Permalink-dependent rules (post redirects keyed by permalink) go stale when a post's slug changes
  outside our hooks; a `template_redirect` fallback keyed by post ID covers drift until the next
  rebuild.
- The map is a derived artifact: it can always be rebuilt from canonical stores (`wp pv-redirects
  rebuild`) and is safe to delete.

## Revisit when

Redirect counts grow large enough that an autoloaded map measurably hurts alloptions memory
(tens of thousands of rows), or a need for per-rule analytics emerges.
