---
title: Distribution
description: Versioned plugin archives, Composer installation, and WordPress.org publication.
status: current
---

## Intent

Produce a verifiable installable archive from each release tag and publish that same archive to WordPress.org through a separate, protected workflow.

## Behavior

- `npm run package` creates `dist/pv-redirects.zip` with one `pv-redirects/` root. It includes the bootstrap, `uninstall.php`, `src/`, committed `build/`, `languages/` when present, `readme.txt`, `LICENSE`, and Composer metadata. Development dependencies, tests, source tooling, documentation, and listing artwork are excluded.
- The plugin header, readme stable tag, and package version must all match numeric `X.Y.Z`. If supplied, the Git tag must be `vX.Y.Z` with the same version. Prereleases and mismatches fail.
- A tag workflow runs checks and attaches the verified archive to its GitHub release. A separate manual workflow downloads that release archive, verifies its files against the selected tag, and deploys it to SVN trunk and a new numeric tag. Existing SVN tags are never overwritten.
- The manual workflow publishes tagged `.wordpress-org/` artwork to the SVN assets directory. Numbered screenshots correspond to readme captions and never enter the plugin archive.
- Composer package type is `wordpress-plugin` and requires PHP 8.2 or newer. Installation from a Git tag uses a VCS repository and consumer-configured Composer installer.

## Acceptance criteria

- Package version and tag mismatch checks reject invalid releases (`scripts/tests/test_package.py`).
- The package contains only its selected distribution files and can be compared byte-for-byte against tagged source (`scripts/package.py`).
- Publication uses the `wordpress-org` environment; the owner must configure required reviewers and secrets before dispatch.

## Constraints

- `@wordpress/env` is pinned to 10.38.0 and installed from the npm lockfile.
- SVN access requires WordPress.org approval of the `pv-redirects` slug. Packagist registration is not assumed.

## Decisions

- [0007: Publish versioned archives with gated WordPress.org deployment](../decisions/0007-versioned-release-archives.md)
