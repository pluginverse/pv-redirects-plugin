---
title: "0005: Drop QPPR's jQuery-era features"
description: No frontend link rewriting, rel=nofollow, new-window, meta-refresh redirects, or admin jQuery UI; PV Redirects only issues HTTP redirects and manages them with WP-components admin.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale (owner), GLM (drafting agent)"
---

## Context

QPPR bundles frontend jQuery that rewrites rendered links (target=_blank, rel=nofollow, masked
destinations), a JS/`<meta>` refresh redirect mode, admin pointers, and an AJAX-heavy jQuery admin.
The owner directed dropping all jQuery features and the nofollow/new-window machinery. Plan:
[pv-redirects-v1](../plans/2026-11-04-pv-redirects-v1.md).

## Options considered

1. **Drop entirely (chosen)**: HTTP redirects only (301/302/307/308); admin rebuilt with
   `@wordpress/components`; editor panel for per-post redirects.
2. **Port the features without jQuery**: keeps the features, but they are SEO-questionable
   (nofollow-on-redirect links), accessibility-questionable (new window), and meta-refresh is a
   worse 302.

## Decision

We will drop the features (option 1), because a redirect plugin's job is HTTP redirects, and every
dropped feature removes frontend script weight and maintenance surface.

## Consequences

- Migration counts and warns for every dropped item (`_pprredirect_newwindow`,
  `_pprredirect_relnofollow`, `_pprredirect_rewritelink`, type `meta`, quick-redirect meta rows) so
  nothing disappears silently.
- Status codes gain 308 (QPPR has none).
- Sites that genuinely need meta-refresh must keep it elsewhere.

## Revisit when

A concrete site need appears; a small "extras" module could absorb it without jQuery.
