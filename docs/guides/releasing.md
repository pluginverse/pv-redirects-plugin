---
title: Releasing PV Redirects
description: Verify a release archive and publish a tagged version to GitHub and WordPress.org.
---

## Prepare

Write release notes under `## [Unreleased]` in `CHANGELOG.md`, then run `python3 scripts/release.py prepare X.Y.Z`. Review the synchronized versions and `readme.txt` changelog, then commit the release. `python3 scripts/release.py notes X.Y.Z` prints the notes used for the GitHub release and annotated tag. Build the committed bundles and run the checks in [Testing](testing.md). Run `npm run package` to create `dist/pv-redirects.zip`; `python3 scripts/package.py --tag vX.Y.Z --verify dist/pv-redirects.zip` verifies its exact contents against the checkout. Inspect and install the archive before release. The archive is reproducible from identical source and excludes listing artwork and development files.

After review, create an annotated tag with `python3 scripts/release.py notes X.Y.Z > /tmp/pv-release-notes.txt` and `git tag -a vX.Y.Z -F /tmp/pv-release-notes.txt`, then push that tag at the release commit. The GitHub release workflow checks version agreement, tests, static analysis, lint, committed build output, and packaging before attaching `pv-redirects.zip` to the release. A mismatch or prerelease version fails. Avoid moving release tags.

## WordPress.org

WordPress.org must first approve the plugin and assign the `pv-redirects` SVN repository. If its assigned slug differs, update the distribution design before publishing. The repository owner **must configure required reviewers** on the GitHub Actions environment named `wordpress-org`; declaring the environment in the workflow alone does not require approval. Add environment secrets `SVN_USERNAME` and `SVN_PASSWORD` with approved WordPress.org credentials. Do not put credentials in the repository.

After the GitHub release is published, manually dispatch **Publish to WordPress.org** with its `vX.Y.Z` tag. It downloads the release ZIP, compares every entry against that tag, and takes listing artwork from that same tag. On environment approval it checks out SVN, replaces trunk and assets, creates `tags/X.Y.Z`, and commits once. Existing SVN tags cause failure; use a new version for corrections. Missing repository access or credentials also stops publication. Artwork lives in `.wordpress-org/` and stays outside the plugin ZIP; screenshot numbers must match the readme captions.

## Composer consumers

No Packagist registration is implied. Consumers can add a VCS repository pointing at `https://github.com/pluginverse/pv-redirects-plugin` and require `spenserhale/pv-redirects-plugin` at a published semantic version. WordPress installations also need `composer/installers` and its plugin authorized in the consumer project’s `config.allow-plugins`; configure `extra.installer-paths` to place `type:wordpress-plugin` packages in the site’s plugins directory. Composer tags become available only after they are published.

## Plugin Check review notes

The installed release ZIP was checked with Plugin Check 2.1.0 on WordPress 7.1.4 and PHP 8.2. The check reports no errors. Warnings remain visible for these intentional cases and should be explained during directory review:

- Public `pv/redirects/*` hooks use the established project prefix and remain compatible with integrations.
- `wp_redirect()` intentionally supports administrator-configured external destinations. Redirects exit immediately; redirect writes require the documented administrator capability.
- Post-meta queries run during migration or map rebuilding, outside the front-end redirect read path.
- Uninstall uses one uncached, prepared DELETE query for the plugin's own metadata. Its placeholder list is generated from a fixed list of three meta keys; static analysis does not recognize those generated placeholders.

Plain exception messages have narrowly scoped scanner annotations because HTML escaping belongs at their output boundary, not in CLI or REST data. No whole checks or files are disabled. Review warnings again for each release rather than assuming these explanations cover new findings.
