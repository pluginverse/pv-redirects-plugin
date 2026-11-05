---
title: "0004: Wildcard matching semantics"
description: Redirect request patterns support * matching a run of non-slash characters, a trailing /* matching the rest of the path, and * in the destination substituted with the matched text; exact matches always win.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale (owner), GLM (drafting agent)"
---

## Context

QPPR matches only exact array keys (its own readme states no regex/wildcard support), which is a
top missing feature for site migrations (moving a whole path tree). PV Redirects adds wildcard
support without paying regex cost on the hot path for non-wildcard rules. Plan:
[pv-redirects-v1](../plans/2026-11-04-pv-redirects-v1.md).

## Options considered

1. **`*` glob semantics (chosen)**: `*` = any run of non-`/` characters; a final `/*` = the entire
   remaining path including slashes; `*` may appear in the destination and is replaced by the
   matched text. Compiled to a regex once at map build; matched in rule order.
2. **Full regex patterns**: maximal power, but easy to catastrophic-backtrack, harder to explain,
   and overkill for redirect trees.
3. **Path-prefix matching on every rule**: simplest, but cannot express mid-segment substitution
   (`/2024/*-report`).

## Decision

We will use glob-style `*` (option 1): it covers the dominant cases (path-tree moves, slug
rewrites), compiles to safe anchored regexes, and stays explainable. Matching order: exact key hit
(including query-string-embedded keys) → wildcard rules in insertion order → miss. First match wins.

## Consequences

- Wildcard rules live in an ordered array in the map; ordering is insertion order and the admin UI
  shows it.
- A wildcard whose destination has more `*`s than the pattern uses leaves stray literals; the
  admin + CLI validate destination `*` count ≤ pattern `*` count.
- No `**` in v1; document that a trailing `/*` already spans slashes.

## Revisit when

Users need multi-segment captures with named groups, or per-rule regex escape hatches.
