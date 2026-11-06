---
title: "Plan: WordPress.org release preparation"
description: Prepare verified release archives, tag-based automation, and listing assets for review before submission.
status: done
date: 2026-11-06
size: bounded
specs: [redirects, distribution]
---

## Intent

Prepare PV Redirects 1.0.0 for WordPress.org and tagged Composer installation. The owner requested completed code, listing artwork informed by popular plugins, and real wp-env admin/editor screenshots before approving submission. No tag, public release, SVN deployment, or WordPress.org submission happens during this preparation.

## Acceptance criteria

- Production classmap loads migration without Composer; existing redirect tests and PHPStan pass.
- One pinned local wp-env runs start, tests, and stop; CI installs from lockfiles.
- A reproducible packaging command creates an installable `pv-redirects/` ZIP excluding development files, with matching 1.0.0 metadata, license, readme, and built admin bundles. Archive inspection and installed-package smoke checks prove this.
- Tagged GitHub releases attach the verified ZIP; WordPress.org deployment uses that artifact and waits for the protected `wordpress-org` environment. Tag/header/stable version mismatch and existing SVN tags fail clearly.
- Composer advertises `wordpress-plugin`, PHP >=8.2, and documented VCS installation from semantic version tags, with installer configuration explained.
- Listing assets include standard/retina banners and icons, value-proposition screenshot 1, and genuine admin, editor redirect panel, and settings screenshots with matching readme captions.
- Plugin Check runs against the packaged plugin; actionable errors are fixed and remaining intentional findings documented.

## Non-goals

New redirect features, analytics, changing matching semantics, publishing tags/releases, configuring credentials, Packagist registration, and WordPress.org account/submission work. The owner will review finished artifacts first.

## Design

Keep the runtime classmap and correct missing migration registration. Preserve accepted redirect architecture and capability decisions. Pin wp-env 10.38.0, which successfully runs the existing Docker suite, through package-lock and use the same local executable everywhere.

Replace destructive built-branch publishing with explicit archive staging and version validation. Both CI and releases use the same package command. Git tags use `v1.0.0`; plugin/readme/SVN versions use `1.0.0`. A tag workflow validates and produces a GitHub release ZIP; a separate manually dispatched WordPress.org workflow validates the published tag and downloads its release ZIP before the environment-gated SVN deployment. Explicit dispatch avoids exposing credentials on ordinary CI and permits first publication only after WordPress.org grants SVN access. Upload listing artwork separately to SVN's top-level assets directory. Never rewrite an SVN release tag.

Ship full GPL-3.0-or-later license and standard metadata. Remove the ambiguous custom additional-permission paragraph; preserve author attribution. Provide source and build instructions in readme. Composer retains its package name and `wordpress-plugin` type, declares PHP compatibility, and documents consumer-side installer authorization rather than implying the project is already registered on Packagist.

Use the existing amber identity and editable vector artwork for clean typography and redirect diagrams. Hero copy: “Keep your URLs working.” Supporting value: “Fast redirects. Wildcards. Easy migration.” Avoid unsupported speed comparisons and invented performance numbers. Screenshot 1 is a labelled overview graphic; subsequent screenshots are unchanged captures of seeded local WordPress UI, with demo data only. Research references: Redirection, Safe Redirect Manager, and Yoast SEO listings; WordPress.org Plugin Assets and Plugin Readmes handbook pages.

The runner-up, automatically deploying every new tag directly to SVN, loses because initial SVN access is not available yet and release publishing must remain separately reviewable.

## Spec delta

- D1, `docs/specs/redirects.md`: migration is available in the standalone production package without Composer's autoloader.
- D2, new `docs/specs/distribution.md`: version/tag validation, ZIP contents, Composer requirements, and manually dispatched environment-gated WordPress.org publication as described above.
- D3, distribution: `.wordpress-org/` contains directory assets excluded from the installable package; numbered screenshots match readme captions.
- D4, redirects: collection PUT/DELETE accepts the exact request key in JSON data so admin edits/deletes work when the web server rejects encoded slashes. Existing item routes remain supported.

## Docs impact

- README: ZIP installation, Composer VCS example, build/source links and release guide.
- CHANGELOG: Unreleased entries for release packaging, listing, and production migration autoload fix; final 1.0.0 dating waits for release approval.
- New `docs/guides/releasing.md`: tags, verification, environment/secrets setup, SVN first approval, Composer/Packagist distinction, and assets.
- New `docs/guides/testing.md`: pinned wp-env commands and packaged-plugin smoke workflow, justified by the discovered test-runner mismatch and production bootstrap coverage.

## Decisions

- [0007: Publish versioned archives with gated WordPress.org deployment](../decisions/0007-versioned-release-archives.md).
- [0008: Send redirect mutation keys in JSON bodies](../decisions/0008-redirect-keys-in-json.md).

## Tasks

Coordinator commits each task; implementers never mutate git state. Disjoint file ownership permits parallel implementation. Full validation runs after integration.

**Verify:** baseline fast `composer phpstan`; full `wp-env run tests-cli --env-cwd=wp-content/plugins/pv-redirects-plugin vendor/bin/phpunit` passed 155 tests/503 assertions. The existing `composer test` uses a mismatched npx version; T2 repairs it. Final required checks: `composer test`, `composer phpstan`, `npm run lint`, `npm run build`, packaging smoke and Plugin Check.

### T1: Production migration bootstrap · design · Terra

**Owns:** `pv-redirects.php`, `tests/ProductionBootstrapTest.php`, `src/Admin.php` only if manifest loading proves necessary.
**Depends on:** none.
**Produces:** standalone migration class loading; regression test must fail before fix and pass afterwards without Composer autoload.
**Tests:** fast PHP lint and regression; existing suite at integration.
**Review:** yes, test must not mask production bug.
**Docs:** coordinator applies D1 and changelog entry when committing.
**Commit:** `fix(bootstrap): load migrator in standalone installations`.

### T2: Package and release automation · design · Terra

**Owns:** `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `.wp-env.json`, `.github/workflows/`, `.deploy.gitignore`, `.distignore` if needed, `.gitignore`, `scripts/` except listing asset sources, `docs/specs/distribution.md`, `docs/guides/releasing.md`, `docs/guides/testing.md`.
**Depends on:** none; package validation runs after T1/T3.
**Produces:** local packaging command, pinned runner, release and manual SVN workflows with version guards and immutable SVN tags.
**Tests:** package rejects mismatched metadata; archive has one pv-redirects root and no vendor/node_modules/tests/docs/dev config; YAML parses; Composer validates; wp-env version agrees. Do not trigger live workflows.
**Review:** yes, artifact integrity and publish permissions.
**Docs:** D2/D3; coordinator applies changelog entry.
**Decision:** 0007.
**Commit:** `build(release): prepare validated archives and gated WordPress.org publishing`.

### T3: Directory metadata and editable listing artwork · design · Terra

**Owns:** `readme.txt`, `LICENSE`, `README.md`, `.wordpress-org/` excluding screenshot captures 2-4, `assets/listing/`.
**Depends on:** none.
**Produces:** accurate readme with captions (1 value overview, 2 redirect manager, 3 editor redirect panel, 4 settings), standard GPL license, clear Composer VCS instructions, code-native SVG/HTML artwork for hero, banner, icon matching existing amber identity. Source must be editable and final PNG exports exact required sizes. Use built-in imagegen only if raster illustration is needed; typography/route shapes can extend existing SVG.
**Tests:** readme length/metadata; PNG dimensions, readable text, consistent identity. No fabricated UI screenshots or unverified claims. Do not invent WordPress.org contributor username; omit Contributors until known. Tested-up-to comes from actual test environment version.
**Review:** batch, visual review by coordinator.
**Docs:** coordinator applies listing changelog entry.
**Commit:** `docs(listing): prepare WordPress.org metadata and artwork`.

### T4: Real UI captures and final integration · design · Terra hand plus coordinator

**Owns:** `.wordpress-org/screenshot-2.png`, `screenshot-3.png`, `screenshot-4.png`; narrowly scoped fixes from Plugin Check assigned explicitly after findings.
**Depends on:** T1, T2, T3.
**Produces:** seeded local admin captures, tested package ZIP, verification record in this plan, final reviewer report.
**Tests:** browser interaction verifies real saved redirects and per-post meta; existing suite/PHPStan/lint/build and Plugin Check package run.
**Review:** yes, final diff by fresh hand.
**Docs:** verification results and any remaining intentional Plugin Check findings in release guide.
**Commit:** `docs(listing): capture redirect management and editor screenshots`.

### T5: Repair admin mutation transport · design · Terra

**Owns:** `src/RestController.php`, `admin-src/api.js`, `tests/RestControllerTest.php`, regenerated `build/`.
**Depends on:** T1.
**Produces:** collection PUT and DELETE taking literal `request` JSON data, with existing capability checks and write-map rebuilds; old item routes remain compatible. Admin edits, renames, and deletes use the collection route.
**Tests:** fail before implementation for collection update/delete; include slash, literal percent escapes, plus, query and wildcard keys; reject missing keys and unauthorized users. Browser edit, rename and delete must persist over actual HTTP.
**Review:** yes, exact key handling and permissions.
**Docs:** coordinator applies D4, changelog fix, and records actual browser verification.
**Decision:** 0008.
**Commit:** `fix(admin): send redirect mutation keys outside URL paths`.

### T6: Preserve WP-CLI migration flags · design · Terra

**Owns:** `src/Cli.php`, `tests/CliTest.php` or a dedicated CLI regression file.
**Depends on:** T1.
**Produces:** documented hyphenated WP-CLI flags map to the migrator's internal underscore keys. `--dry-run` writes nothing; `--delete-source`, `--skip-quick`, and `--skip-posts` retain their documented meanings.
**Tests:** invoke the CLI handler with WP-CLI-shaped hyphenated arguments and a seeded QPPR fixture; assert dry-run preserves all target/source options and meta, honors skip flags, and never deletes source when combined with delete-source. Real installed-ZIP CLI smoke must verify the dry-run leaves no target rule.
**Review:** yes, migration data preservation.
**Docs:** existing spec contract already states this behavior; coordinator records the bug fix in changelog and this plan.
**Commit:** `fix(cli): translate migration flags before invoking engine`.

## Rulings

- [publication] Stop after local reviewable changes and screenshots, as owner explicitly requested — cost if wrong: high, premature publication.
- [Composer] Retain package name; document VCS repository and consumer installer setup without claiming Packagist availability — cost if wrong: low, install documentation changes.
- [license] Standard GPL-3.0-or-later with preserved attribution replaces ambiguous trademark paragraph, for owner review before release — cost if wrong: medium, author can request revised trademark wording.
- [tools] Shared preview and delegation endpoints lack thread credentials; same-provider native hands and dedicated Playwright CLI are available — cost if wrong: low, local tooling only.
- [isolation] Use feature branch in the owner's shared project; no separate checkout needed for disjoint files — cost if wrong: low, changes remain reviewable.
- [scope] Real UI validation found encoded-slash PUT/DELETE rejected by Apache before WordPress; T5 is necessary for usable admin captures and retains old routes — cost if wrong: medium, changes a public interface additively.
- [normalization] WordPress URL parser and unslashed scalar request values preserve URL characters; isolated production coverage and existing matcher tests protect behavior — cost if wrong: medium, redirect matching must remain stable.
- [scope] Installed-ZIP CLI smoke revealed documented hyphenated migration flags were passed unchanged to an engine expecting underscore keys; T6 repairs the existing no-write dry-run contract. Only a disposable fixture was affected and removed — cost if wrong: high, migration must not write during previews.
- [review] Duplicate hyphen/underscore flag aliases are rejected before writes; this prevents a false alias from overriding an enabled preview — cost if wrong: low, callers must pass one flag form.
- [commits] Related runtime fixes T1/T5/T6 share one implementation commit and redirect-spec delta; metadata/artwork/captures T3/T4 share one listing commit; T2 automation and its docs ship together. Each resulting commit carries its corresponding changelog text.

## Verification

- `composer test`: 166 tests, 656 assertions, passed on WordPress 7.1.4/PHP 8.2.35. `composer phpstan`, `composer validate --strict`, `npm run lint`, and `npm run build` passed. Rebuilt bundles match committed output.
- `npm run test:package`: four tests passed, including version mismatch and archive tampering, duplicate-name, extra-file, and traversal rejection. Matching v1.0.0 verification passed; v1.0.1 mismatch was rejected. Archive contains 19 selected files under `pv-redirects/`, including uninstall; no development dependencies or listing assets.
- Installed the final archive without vendor dependencies. A real request to `/old-blog/release-proof/` returned HTTP 301 to `/blog/release-proof/`. Production migration loaded successfully. With a disposable QPPR option, actual `wp pv-redirects migrate --dry-run --delete-source` reported no writes and a SHA-256 snapshot of source, rules, settings, and map remained identical before and after. Fixture was removed and source development plugin reactivated; installed smoke-test copy remains inactive.
- Plugin Check 2.1.0 on the installed ZIP: zero errors, 17 intentional warnings documented in the release guide. No global exclusions. Removed unused Domain Path header because an empty language directory is not distributed; directory language packs use WordPress's standard loading.
- Browser verified add, edit, rename, delete, and saved per-post metadata; manager console clean. Editor showed one core global-styles iframe warning and no errors. All dedicated browser sessions closed. Demo listing rows and post remain in local wp-env.
- Eight final PNGs verified: banners 772×250/1544×500, icons 128×128/256×256, overview 1544×900, real captures 1440×1000. Editable SVG sources and capture instructions are included.
- Fresh release review found no actionable findings; an additional targeted migration review found the duplicate-alias edge, which was reproduced with a failing test and fixed. Local SVN copy experiment verified modified/added trunk files enter the new tag.
- Live GitHub Actions and credentialed SVN publication were not run because publication waits for owner approval. Required-reviewer environment settings and secrets remain owner setup. Actual browser testing used current WordPress; the minimum WordPress 6.4 JSX path is covered by isolated regression stubs, not a separate live minimum-version browser run.

## Deferred

- WordPress.org username, assigned slug, SVN credentials, protected environment configuration, and submission: owner explicitly deferred until approval.
- Packagist registration and first v1.0.0 tag/release: after approval; Composer VCS installs work from the published tag without Packagist.
