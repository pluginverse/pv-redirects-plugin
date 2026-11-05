# AGENTS.md

## Purpose

PV Redirects (Plugin Verse Redirects) is a WordPress plugin that replaces Quick Page/Post Redirect:
fast redirects via one computed autoloaded map (single read per request, zero DB on cache hit),
wildcard matching, per-post redirects, a WP-components admin, WP-CLI-first operations, and a
one-command migration off QPPR. Deployed from this repo to `pluginverse/pv-redirects-plugin`.

## Quick start

```sh
composer install          # PHP deps (dev only; production autoloads via the plugin's classmap)
composer test             # PHPUnit via wp-env docker (npx @wordpress/env; Docker must be running)
composer phpstan          # static analysis, level 9 (src/)
npm install               # admin UI deps (@wordpress/scripts)
npm run build             # build admin-src/{index,sidebar}.js → build/ (committed)
npm run lint              # lint admin-src
```

`build/` artifacts are committed — production sites never need npm. Tests need Docker running
(`wp-env` pulls images on first run).

## Repo layout

- `pv-redirects.php` — bootstrap: header, autoloader classmap, hook wiring. Thin on purpose.
- `src/` — the engine. Static-method classes; the front-end read path (`RedirectHandler`) may only
  load `pv_redirects_map` (one `get_option`), per `docs/decisions/0002`.
- `admin-src/` — admin React source (WP primitives only, no jQuery).
- `build/` — committed JS build output.
- `tests/` — wp-phpunit suite (`PV\Redirects\Tests`).
- `docs/` — specs, decisions, plans, product (see below).
- `.agents/skills/` — WordPress workflow skills.

## Documentation

Durable project knowledge lives in `docs/` as Markdown with `title` and `description` frontmatter
and no body H1 (Starlight-compatible). Before changing a feature's behavior, read its spec and the
decisions that touch it.

| Kind | Path | Rule |
|---|---|---|
| Specs: what each feature does now | `docs/specs/<feature>.md` | Change in the same commit as the behavior; never ahead of the code |
| Decisions: why | `docs/decisions/NNNN-<title>.md` | New record for every strong decision; supersede, never rewrite |
| Plans: one change, designed and tasked | `docs/plans/YYYY-MM-DD-<topic>.md` | Write and commit before the code |
| Product | `docs/product/vision.md` | Read before scoping a change |
| Guides | `docs/guides/<topic>.md` | See Progressive disclosure |
| Changelog | `CHANGELOG.md` | Add an `## [Unreleased]` line in the same commit as any user-visible change |

Templates: `_template.md` in `docs/specs/`, `docs/decisions/`, and `docs/plans/`. A decision is
strong when it is hard to reverse, adds a dependency or a pattern others will copy, changes a public
interface or data model, touches security, privacy, or pricing, or departs from an accepted decision
(or changes something a spec states that the change did not set out to change). Smaller calls go in
the plan's `## Rulings`. Never deviate silently from a spec or an accepted decision: ask first; only
when no human is in the loop, write the superseding record and call it out in the pull request.

## Progressive disclosure

Before editing, check whether the task touches one of these areas and read the guide first (a TODO
line is a guide not written yet):

- WordPress hook wiring / boot cost: read `.agents/skills/wordpress-performance/SKILL.md` before
  adding hooks on hot paths (`init`, `template_redirect`, `plugins_loaded`).
- WP-CLI / site operations: `.agents/skills/wordpress-cli/SKILL.md`; VIP targets:
  `.agents/skills/wp-vip-cli/SKILL.md`.
- Gutenberg UI: `.agents/skills/wp-components/SKILL.md` before choosing admin components.
- TODO Testing guide (`docs/guides/testing.md`): wp-env workflow, when tests need Docker, how to add
  a suite — write it when a second test suite lands or CI test flakiness appears.

## Skills

Use `.agents/skills/` when the task matches a documented workflow:

- `.agents/skills/wordpress-performance/`: hook-wiring rules and measurement for WP boot cost.
- `.agents/skills/wordpress-cli/`: WP-CLI command reference for site operations.
- `.agents/skills/wp-vip-cli/`: VIP-CLI for VIP-hosted targets.
- `.agents/skills/wp-components/`: @wordpress/components reference for admin UI work.

## Coding standards

1. **YAGNI**: Don't build it until you need it
2. **DRY**: Extract patterns after second duplication, not before
3. **Fail Fast**: Explicit errors beat silent failures
4. **Simple First**: Write the obvious solution, optimize only if needed
5. **Delete Aggressively**: Less code = fewer bugs
6. **Semantic Naming**: Always name variables, parameters, and API endpoints with verbose,
   self-documenting names that optimize for comprehension by both humans and LLMs, not brevity
   (e.g., `wait_until_obs_is_saved=true` vs `wait=true`)

House rules that win over generic style:

- `src/` classes are `final`, static-method, no I/O in constructors, and must stay phpstan level 9
  clean. Mirror `use function` imports as the existing files do.
- The read path (`RedirectHandler` + `Matcher` + `RedirectMap::get`) performs NO database queries
  beyond the single autoloaded `pv_redirects_map` option. Every write path must end in
  `RedirectMap::rebuild()`.
- No jQuery anywhere. Admin UI uses @wordpress/components exclusively.
- User-facing strings use text domain `pv-redirects`.

## Commits

A change starts with its plan, committed before any code together with the decision records made
while designing. After that, one commit is one complete change: the code, its tests, and every doc
it changes (spec, guide, changelog line, decision record). Conventional Commits subject
(`feat(scope): ...`); the body says why and names the docs it touched (`Spec:`, `Decision:`,
`Plan:`).

## Done criteria

Before finishing any task:

- Run `composer test` and `composer phpstan` and fix failures before declaring done
- Behavior changed: the spec, changelog, and any affected guide changed in the same commit
- A strong decision was made: its decision record exists
- If a check was skipped, say why; flag risky assumptions explicitly
