---
title: "Plan: QPPR query separators"
description: "Normalize HTML-escaped ampersands in migrated QPPR source queries."
status: in-progress
date: 2026-11-08
size: bounded
specs: ["redirects"]
---

## Intent

QPPR source keys can contain HTML-escaped ampersands while HTTP requests use literal separators. Migration must retain working redirects without altering native PV keys or destination URLs.

## Acceptance criteria

- Migration converts escaped ampersands in QPPR source queries before parsing absolute URLs; raw request queries match the migrated rule.
- Paths, percent escapes, other entities and destination strings remain unchanged. Case settings and forwarded query behavior remain intact.
- Dry runs, repeat migration, conflicting rules, and opt-in source cleanup retain existing safety guarantees.
- Previously imported escaped PV keys remain untouched; rerunning migration with QPPR data imports the corrected key unless it conflicts.

## Non-goals

Rewrite existing PV rules automatically or change literal PV source matching and HTTP request parsing.

## Design

At the QPPR input boundary only, replace one layer of numeric decimal 38, numeric hexadecimal 26, and `&amp;` entities in the substring after the first question mark. Run this before URL parsing, which otherwise interprets numeric entities as fragments. Continue using the existing normalized-key collision checks and source-key cleanup. Do not decode other HTML entities, percent escapes, or destinations. Existing migrated rows are not overwritten. The alternative, decoding every PV key at matching time, changes user-defined literal keys and requires an upgrade path for existing maps.

## Spec delta

- `docs/specs/redirects.md`: migrated QPPR source queries normalize HTML-escaped ampersand separators; native PV keys stay literal.

## Docs impact

Record the fix in Unreleased changelog.

## Decisions

Existing ADR-0003 and ADR-0008 remain binding.

## Tasks

1. Reproduce relative and absolute source failures, collisions, dry runs and idempotent cleanup in migration tests.
2. Normalize QPPR source queries before URL parsing; update spec and changelog.
3. Run PHP tests and PHPStan. Leave changes local; deployment is held.

## Rulings

- [scope] Decode only ampersand entities within QPPR query strings, once. Cost if wrong: medium; unusual literal entity-looking query text may be interpreted as a separator during migration.
- [existing imports] Keep existing PV rows untouched and re-run migration to add the corrected key. Cost if wrong: low; a harmless old key can remain and conflicts need review.

## Deferred

Report review awaits access to the user's separate machine or file.
