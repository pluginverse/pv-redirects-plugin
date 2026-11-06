---
title: "0007: Publish versioned archives with gated WordPress.org deployment"
description: Use semantic Git tags and validated plugin ZIPs, then explicitly approve separate WordPress.org SVN publication.
status: proposed
date: 2026-11-06
decision-makers: "Spenser Hale (review pending), Codex (draft)"
---

## Context

The existing tag workflow force-pushes a built branch and does not create an installable release or publish to WordPress.org. The owner wants semantic tags usable through Composer, directory release automation, and review before initial submission. WordPress.org assigns SVN access only after approval.

## Options considered

1. Validated release ZIP from a semantic Git tag, followed by a separately dispatched environment-gated SVN workflow: one reviewed artifact and explicit publication timing.
2. Automatically deploy every tag to SVN: fewer actions, but couples GitHub releases to directory access and initial publication.

## Decision

Prepare `vX.Y.Z` GitHub releases containing a validated `pv-redirects/` archive. A separate manual WordPress.org workflow selects a published release, verifies version consistency, and deploys that archive to SVN trunk and a new numeric tag only after the `wordpress-org` environment permits it. Listing artwork is maintained outside the plugin package and published to SVN assets. Existing SVN tags are immutable.

Composer package type remains `wordpress-plugin`. PHP requirements are consumer-visible. Consumers configure the standard Composer installer and may use the public Git repository before Packagist registration.

## Consequences

GitHub and WordPress.org releases use the same files. Review and SVN credential setup are explicit. Maintainers perform a separate dispatch after publishing a GitHub release; semantic version metadata must agree across tag, header, package and readme. Environment protection must be configured by the repository owner; declaring an environment in YAML alone does not require approval.

The distributed plugin uses standard GPL-3.0-or-later metadata and a full license copy. The ambiguous bespoke additional-permission paragraph is removed while retaining copyright attribution. This proposed record and that wording remain reviewable before publication.

## Revisit when

The initial directory release is approved and maintainers want to combine publication steps, or the directory assigns a different slug requiring an explicit compatibility plan.

## Links

- Plan: [WordPress.org release preparation](../plans/2026-11-06-wordpress-org-release.md)
- [WordPress.org SVN guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [WordPress.org plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
