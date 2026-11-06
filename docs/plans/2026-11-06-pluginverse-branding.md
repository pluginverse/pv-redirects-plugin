---
title: "Plan: Use Pluginverse branding"
description: Replace PR monograms with the canonical standalone symbol and republish the owner-approved 1.0.0 tag.
status: done
date: 2026-11-06
size: bounded
specs: [distribution]
---

## Intent

The owner corrected the brand name to Pluginverse, requested a reference to the branding repository in AGENTS.md, and explicitly authorized replacement of the existing GitHub 1.0.0 tag and release. Use the standalone plug/orbit symbol, not the text logo. Source: pluginverse/branding main at `22c6062499ec2605e63d67b30a4997b1172d8df5`, `assets/logos/svg/pluginverse-symbol-color.svg` and supplied dark-surface variants.

## Design and acceptance

Preserve PV Redirects as the product name and the existing banner/overview composition. Replace PR monograms using the complete supplied symbol with its clear space; use official light/dark variants without altering geometry or recoloring fragments. Align existing marketing colors with the supplied palette. Correct spaced brand references in project prose. Document source provenance and future brand lookup in AGENTS.md and the listing guide. Real admin screenshots contain no PR monograms and remain authentic unchanged captures.

No redirect behavior, API, dependency, plugin version, or SVG wordmark is added. The installable ZIP should remain byte-identical because listing assets and project prose are outside its file selection; verify this before deciding whether WordPress.org needs an updated upload.

## Tasks

1. Terra hand updates `AGENTS.md`, `README.md`, existing product/spec/plan prose, `assets/icon.svg`, `assets/listing/{icon,banner,overview}.svg`, and `.wordpress-org/{icon-128x128,icon-256x256,banner-772x250,banner-1544x500,screenshot-1}.png`. Parent updates shared listing guide and changelog. Verify no PR text in logo sources, correct spelling, PNG dimensions, and visual rendering.
2. Parent runs `composer test`, `composer phpstan`, package verification, and archive comparison; commits the atomic branding change. Push main, move annotated `v1.0.0` with a lease against its known previous tag object, and let the tag push start a new release workflow. Verify the refreshed release asset against source and check the tag's peeled commit.

## Docs impact

- AGENTS.md becomes the project entry point for the canonical branding repository.
- Listing guide records source paths, pinned reference commit, official palette, and unchanged real captures.
- Distribution spec records the use of Pluginverse standalone branding; changelog records this owner-requested release correction.

## Rulings

- [spelling] Use the owner's Pluginverse spelling even while source prose is being updated — cost if wrong: low, prose only.
- [logo] Use the complete standalone plug/orbit symbol; the current source has no separate bare-plug master — cost if wrong: low, asset-only correction.
- [release] This is an explicit owner-authorized replacement of GitHub v1.0.0, not a new default release policy. Previous tag object: `bdafbf84fc7f6f3d0ce54621d85793fe3076c9fd`; previous commit: `672afa668182c403c4d852875a29393a5544c01e`. No SVN tag exists or will be rewritten. Use a new tag-triggered workflow, since rerunning an old run would build the old SHA.

## Verification

- `composer test`: 166 tests, 656 assertions passed; `composer phpstan` passed (existing local herd module warning).
- Five PNGs rendered and visually inspected at their required dimensions. No PR monograms or old spaced brand references remain in project prose or logo sources; real screenshots 2–4 are unchanged.
- Branding refreshed while the owner updated upstream: `a128f2add986d0cb652f4dadf2efaef0ee462f6d` to `22c6062499ec2605e63d67b30a4997b1172d8df5`. The three symbol variants changed only in accessible title spelling; geometry and colors are identical. Local `assets/icon.svg` matches the latest upstream color symbol exactly.
- Packaging and matching v1.0.0 verification passed. The newly generated installable ZIP is byte-identical to the prior GitHub release ZIP, so the pending WordPress.org submission needs no replacement; updated listing artwork is taken from the new Git tag at SVN publication.
- Main and the replacement tag are published after this implementation commit. The new tag-triggered GitHub Actions run is the external release verification; no old run is rerun against an obsolete SHA.
