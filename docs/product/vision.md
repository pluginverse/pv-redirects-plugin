---
title: Vision
description: Who PV Redirects is for, the problem it solves, and what it will not become.
---

## Who it is for

Plugin Verse sites and the people and agents operating them: teams that keep hundreds or thousands of legacy URLs alive across posts and custom post types, and who manage redirects from the terminal — or hand them to an AI agent — rather than clicking through a browser tab. It is also for anyone still running Quick Page/Post Redirect (QPPR) who wants out without downtime.

## The problem

QPPR-era redirect managers do their work on every front-end request: option scans, postmeta queries, per-row lookups — a slow read path that grows with the size of the redirect table. Their admin screens are a jQuery-era relic. And they are effectively unscriptable: no real WP-CLI surface means every redirect change is a hand-edit in a browser, which blocks automation, batch operations, and agents entirely. Migrating off them has been the hard part — the data lives in idiosyncratic options and postmeta that nothing else speaks.

## What it is

PV Redirects is a fast, CLI-first redirect manager for WordPress. All redirect rules — quick redirects, per-post redirects, and settings — compile into one autoloaded map option, `pv_redirects_map`, so the front-end read path is a single `get_option` round-trip with zero DB queries on a cache hit. It supports exact and wildcard matching (with capture substitution), per-redirect statuses (301/302/307/308), a WP-components admin with a per-post editor panel (no jQuery), a REST API, and a full `wp pv-redirects` command set — including one-command, idempotent migration off QPPR with a dry run before anything is written.

## What it is not

Not a link-rewriting plugin: it does not rewrite content, menus, permalinks, or `rel`/target attributes — that jQuery-era surface is dropped on purpose ([ADR-0005](../decisions/0005-drop-jquery-era-features.md)). Not an analytics product: no click counting or reporting. Not a multisite-wide redirect network: data is site-scoped, like core. Not a caching or CDN layer: it handles redirects and nothing else.

## How we know it works

- Front-end requests resolve through one `get_option` — no additional DB queries on a warm cache, at any redirect count.
- `wp pv-redirects migrate --dry-run` on a real QPPR site accounts for every source row (migrated, skipped, or warned) before anything is written, and the live run is safely re-runnable.
- Round-trip scriptability holds: `create` → `match` → `test` → `delete` run unattended, machine-readable, and idempotently.
