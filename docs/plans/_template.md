---
title: "Plan: <change as a short phrase>"
description: "<One line: what this change delivers.>"
status: draft
date: YYYY-MM-DD
size: bounded
specs: ["<feature>"]
---

<!-- One change, from intent to tasks. Copy to the plans folder as YYYY-MM-DD-<topic>.md and commit it, with any decision records written while designing, before any code. Specs change in the same commits as the code; this plan records how and why. status: draft | approved | in-progress | done | abandoned. size: trivial | bounded | architectural. -->

## Intent

The problem, who has it, and the evidence. What success looks like.

## Acceptance criteria

Checkable outcomes for this change; each becomes a test.

- <outcome>

## Non-goals

Reasonable things this change deliberately leaves out.

- <non-goal>

## Design

The approach: components, data flow, error handling, and how it will be tested. Name the runner-up when a real alternative existed, and why it lost.

## Spec delta

Per spec file, what this change adds, modifies, or removes. Each task applies its part in the same commit as its code.

- `docs/specs/<feature>.md`: ADDED <behavior statement>

## Docs impact

Other docs this change makes stale: guides, README, changelog line, pricing page, marketing copy.

- <doc>: <what changes>

## Decisions

Decision records this change creates or supersedes.

- [NNNN: <title>](../decisions/NNNN-<title>.md)

## Tasks

<!-- One block per task: the files it owns, its dependencies, its tests, the docs it changes, and its commit message. -->

## Rulings

<!-- Smaller calls made along the way, one per line: [scope] decision — why — cost if wrong: low|medium|high, what breaks. -->

## Deferred

<!-- Anything blocked or left out, with the reason. -->
