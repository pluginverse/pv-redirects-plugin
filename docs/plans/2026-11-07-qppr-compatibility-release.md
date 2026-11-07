---
title: "Plan: QPPR compatibility and patch releases"
description: "Preserve query matching and same-site URL sources, and automate release metadata."
status: in-progress
date: 2026-11-07
size: bounded
specs: ["redirects", "distribution"]
---

## Intent

Fix reported QPPR migration regressions and ship a patch. Provide a repeatable release preparation command with one changelog source.

## Acceptance criteria

- Case-insensitive exact matches fold query keys and values; sensitive matches preserve case, including on previously built maps.
- Same-site HTTP(S) source URLs convert to site-relative keys; foreign or invalid URLs remain in QPPR with warnings. Subdirectory boundaries, query bytes, and idempotency are preserved.
- Release preparation updates version metadata and both changelogs consistently; GitHub release notes use the same release text.
- Tests, static analysis, packaging, and release workflow pass.

## Non-goals

- Automatic WordPress.org publication or changes to destination URL behavior.

## Design

Fold whole exact keys on build and lookup. Version computed maps and retain an in-memory fallback for old snapshots, with an admin-only rebuild to upgrade them. No extra front-end option reads. Convert only validated same-host HTTP(S) sources within the home path, retaining query bytes and refusing unsafe or ambiguous sources. Preserve existing PV rows on collisions. A Python release command validates inputs and updates known metadata and changelog sections; the tag workflow extracts GitHub notes from the committed changelog. Explicit release tags remain a separate action after tests and commit. Alternatives: requiring manual map rebuild risks broken upgrades; accepting arbitrary hosts changes rule scope.

## Spec delta

- `docs/specs/redirects.md`: exact query comparisons honor case sensitivity; same-site absolute QPPR sources become relative keys; legacy maps remain usable until rebuilt.
- `docs/specs/distribution.md`: release preparation synchronizes metadata and changelogs; GitHub notes come from changelog.

## Docs impact

Update changelog, readme and release guide with command and patch notes.

## Decisions

Existing ADR-0002, ADR-0003 and ADR-0007 remain binding.

## Tasks

1. Add failing matcher, migration, and upgrade regressions; implement fixes and update redirects spec.
2. Add tested release preparation and release-notes extraction; update distribution spec and release guide.
3. Prepare 1.0.1, run full checks, commit, tag and push release.

## Rulings

- [URL] Accept HTTP and HTTPS for the same host and effective port; reject credentials, fragments and paths outside home. Cost if wrong: medium, some legitimate legacy rules need manual review.
- [upgrade] Scan legacy exact keys only for query lookup until an admin rebuild; no database access on frontend. Cost if wrong: medium, old snapshots have slower query matching until upgraded.
- [release] Keep tag creation explicit after verification; never overwrite tags. Cost if wrong: low, one additional command.

## Deferred

WordPress.org deployment remains gated by its existing workflow and credentials.
