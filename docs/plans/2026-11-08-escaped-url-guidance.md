---
title: "Plan: escaped URL guidance"
description: "Warn about HTML-escaped separators without changing literal redirect keys."
status: in-progress
date: 2026-11-08
size: bounded
specs: ["redirects"]
---

## Intent

Prevent accidental HTML-escaped query separators while preserving intentional literal keys.

## Acceptance criteria

Admin warns and offers a visible correction; CLI warns; explicit existing keys remain literal and correction cannot overwrite another rule. Tests and release checks pass.

## Non-goals

Automatic conversion of native redirect keys or deployment to WordPress.org.

## Design

Detect recognized ampersand entities only in source queries. Admin correction is explicit; collision handling must prevent overwrites. CLI warns without changing structured output. Existing migration fix remains limited to QPPR. API retains literal behavior.

## Spec delta

Document input guidance without changing literal key semantics.

## Docs impact

Update changelog and release notes.

## Decisions

ADR-0008 remains binding.

## Tasks

1. Implement and test safe detection and collision handling.
2. Add admin correction and CLI warning, build and verify.
3. Prepare and publish patch release after checks pass.

## Rulings

Explicit correction preserves rare literal URLs; collision prevention protects existing rules.

## Deferred

None.
