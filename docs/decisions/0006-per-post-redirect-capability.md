---
title: "0006: Per-post redirects require an admin capability by default"
description: Writing redirect meta through REST is gated on a filterable capability defaulting to manage_options, not edit_post, so Authors cannot create site-redirects on their own posts.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale (owner), GLM (drafting agent); finding raised by an independent Ollama Cloud GLM-5.3 security audit"
---

## Context

An independent security audit traced a privilege gap: `register_post_meta`'s `auth_callback` was
`current_user_can('edit_post')`, so any Author/Contributor could set `_pv_redirect_to` +
`_pv_redirect_active` on their own post via `POST /wp/v2/posts/<id>`. The map rebuild then publishes
a site-wide 301 from the site's permalink to an attacker-chosen URL — a reputation-damaging open
redirect available to non-admins, while quick redirects correctly require `manage_options`. QPPR
allowed exactly this, so the behavior was inherited parity, not intent. Spec:
[redirects](../specs/redirects.md), plan [pv-redirects-v1](../plans/2026-11-04-pv-redirects-v1.md).

## Options considered

1. **Filterable admin capability (chosen)**: default `manage_options`, filter
   `pv/redirects/post_meta_capability` lets a site restore author access deliberately.
2. **Keep `edit_post` parity**: silent QPPR parity, but ships a privilege escalation relative to
   every other write path in the plugin.
3. **Restrict non-admin destinations to site-relative paths**: still lets authors hijack their own
   permalinks to internal pages; complexity for little gain.

## Decision

We will gate per-post redirect meta writes (the `register_post_meta` `auth_callback` and the
editor sidebar enqueue) on `apply_filters('pv/redirects/post_meta_capability', 'manage_options')`
(option 1), because redirect rules are site infrastructure and only the other write paths
(admin, REST, CLI) already treat them that way.

## Consequences

- Sites that want QPPR-style author-owned redirects add one filter line; the default is safe.
- The editor sidebar only appears for users who can manage options.
- Migration is unaffected (CLI runs with admin context).

## Revisit when

A multi-author product need emerges (e.g. authors redirecting their own retired posts); that is
the moment to add a scoped "redirect own posts" capability instead of loosening the filter.
