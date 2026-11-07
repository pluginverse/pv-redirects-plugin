---
title: "0010: Precompute safe permanent redirect chains"
description: Collapse unambiguous local permanent redirect chains during map rebuild.
status: accepted
date: 2026-11-07
decision-makers: "Spenser Hale (request), Codex (draft)"
---

## Context

A per-post redirect followed by a quick redirect currently requires two browser requests. A rebuild already has both indexes and can remove unnecessary hops without frontend database work.

## Decision

During map rebuild, collapse chains of query-free exact 301 rules through local relative paths, including post and quick rules, while preserving canonical stores and first-rule metadata. Refuse ambiguous query-qualified intermediate paths, temporary or method-preserving statuses, wildcard traversal, fragments, dot segments, and loops. Stop at an external endpoint without fetching it. Existing maps remain readable and are rebuilt on the next administrative request or write. Hooks run for the effective first redirect, not for eliminated intermediate redirects.

## Alternatives

Resolving all chains at request time could account for runtime query and wildcard input but adds work to the frontend and changes side effects. Following only provably safe exact paths at rebuild time keeps the existing one-map read path and predictable behavior.

## Consequences

Some chains remain as separate hops where equivalence is uncertain. Changes to intermediate rules trigger rebuilds, and loops remain unoptimized rather than creating a new permanent destination. Integrations observing every intermediate redirect receive fewer hook calls for optimized chains.
