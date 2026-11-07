---
title: "0009: Preserve old maps and use one release-notes source"
description: Upgrade computed redirect maps without interrupting matching and derive release notes from the changelog.
status: accepted
date: 2026-11-07
decision-makers: "Spenser Hale (request), Codex (draft)"
---

## Context

Case-insensitive query matching changes the keys in the computed map. Updating plugin files does not run the activation hook, so installations can retain older maps. Release metadata and notes also appear in several files and on GitHub.

## Decision

Version the new map, accept older snapshots and match their query keys in memory until an administrative request rebuilds them. The frontend still reads only the computed map. Use `CHANGELOG.md` as the release-notes source: a preparation command moves Unreleased notes into the versioned section and copies them to the WordPress readme; GitHub and annotated tags use extracted notes.

## Alternatives

Requiring an operator to rebuild the map after each update risks losing redirects. Rebuilding on the frontend violates the read-path budget. Maintaining separate manually written release notes risks inconsistent release metadata.

## Consequences

Legacy maps incur a linear in-memory scan for query matching until rebuilt. Normal map writes and admin requests rebuild them. Release preparation changes tracked files but does not create or push a tag; maintainers verify and commit before tagging.

## Links

- [QPPR compatibility and patch releases](../plans/2026-11-07-qppr-compatibility-release.md)
- [Computed autoloaded map](0002-computed-autoloaded-map.md)
- [Versioned release archives](0007-versioned-release-archives.md)
