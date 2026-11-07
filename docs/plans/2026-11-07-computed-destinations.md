---
title: "Plan: computed redirect destinations"
description: "Reduce safe redirect chains and explain HTTP status choices."
status: in-progress
date: 2026-11-07
size: bounded
specs: ["redirects", "distribution"]
---

## Intent

Reduce browser hops across plugin-managed permanent redirect chains, while preserving complex rules. Provide contextual status guidance and release a minor version. Default 301 already exists and explicit settings must survive.

## Acceptance criteria

- Exact 301 chains spanning post and quick rules resolve to their final destination in the map, without changing source data.
- Query ambiguity, temporary or method-preserving status, loops, wildcard paths and unsafe destination forms do not produce incorrect collapse.
- Old maps remain usable until an admin request or write rebuilds them; frontend reads remain unchanged.
- Status help explains all four options in every status selector, and default remains 301 without replacing configured choices.
- Version 1.1.0 is released with changelog and verified archive.

## Non-goals

Following external HTTP redirects, flattening dynamic wildcard chains, changing configured statuses, or automatically publishing to WordPress.org.

## Design

After building the full exact index, follow plain same-site local path targets through exact 301 rules with slash tolerance. Stop on unsafe forms and any query-qualified key for the target path or slash variant, and leave cyclic chains untouched. Keep original rules and post IDs; apply optimized destination to post fallback only where its permalink rule represents that post. A new schema version triggers existing administrative rebuild while reading old versions. Use a shared accessible help control next to each select. One runner-up is request-time traversal, rejected because it adds frontend work and changes dynamic query semantics.

## Spec delta

- `docs/specs/redirects.md`: describe safe chain flattening, old-map upgrades, 301 default, and status guidance.

## Docs impact

Update changelog, readme release notes and committed builds.

## Decisions

[0010: Precompute safe permanent redirect chains](../decisions/0010-precompute-safe-redirect-chains.md).

## Tasks

1. Write failing regression tests for safe chains, loops, statuses, query ambiguity, post fallback and map upgrade; implement and update spec.
2. Add accessible shared status explanation to three selectors; rebuild assets and lint.
3. Prepare 1.1.0 with release command, run PHP tests, PHPStan, JS lint/build, package checks; commit and publish GitHub release.

## Rulings

- [status] Only 301 chains flatten; preserve configured 302/307/308. Cost if wrong: low, some safe chains remain.
- [query] A query-specific intermediate rule prevents flattening regardless of preserve setting. Cost if wrong: low, optimization is conservative.
- [hooks] Intermediate redirect hooks no longer fire for flattened rules; first rule and its filter remain. Cost if wrong: medium, integrations relying on intermediate events must account for it.
- [upgrade] Legacy map is served unchanged until admin rebuild; no frontend writes. Cost if wrong: low, optimization waits for maintenance.

## Deferred

Dynamic wildcard traversal and mixed-status chain equivalence need separate design.
