---
title: "0008: Send redirect mutation keys in JSON bodies"
description: Add collection mutations so Apache encoded-slash handling cannot block redirect edits and deletes.
status: proposed
date: 2026-11-06
decision-makers: "Spenser Hale (review pending), Codex (draft)"
---

## Context

Real wp-env browser validation found PUT `/pv-redirects/v1/redirects/%2Fsummer-sale%2F` returns Apache's HTML 404 before WordPress receives it. The admin client puts encoded request keys into item URL paths for update and delete. Ordinary slash-containing keys therefore fail on standard Apache configurations; redirect renaming also depends on deletion.

## Options considered

1. Add PUT and DELETE handlers to the existing collection route, carrying the exact request key in JSON data. This avoids web-server path decoding and preserves existing item API compatibility.
2. Double-encode URL path keys or require server configuration changes. These depend on intermediaries and risk changing keys containing literal percent escapes.

## Decision

The admin sends request keys in collection mutation JSON bodies. New handlers reuse existing validation, authorization, and map-rebuilding stores while treating body keys literally. Existing item routes remain supported with their existing decoding behavior. No server configuration change is required.

## Consequences

The API gains an additive mutation interface. Tests cover slashes, literal percent escapes, plus, query and wildcard keys, missing parameters, and authorization. Actual browser edit/delete validation complements REST dispatch tests, which cannot reproduce an Apache rejection.

## Revisit when

A versioned API removes legacy item mutations or WordPress introduces a standard opaque identifier for redirect rules.

## Links

- Spec: [Redirects](../specs/redirects.md)
- Plan: [WordPress.org release preparation](../plans/2026-11-06-wordpress-org-release.md)
