---
title: WordPress.org listing assets
description: Maintain the directory artwork and reproduce genuine screenshots from local WordPress.
---

## Files

Directory media lives in `.wordpress-org/` and is published to SVN's top-level `assets/`, outside the installed plugin archive. Banners are 772×250 and 1544×500 PNGs; icons are 128×128 and 256×256 PNGs. Editable vector sources are `assets/listing/banner.svg`, `icon.svg`, and `overview.svg`. Export the source at its target size with a browser, preserving text and aspect ratio.

## Pluginverse brand source

The organization name is **Pluginverse**, one word. [pluginverse/branding](https://github.com/pluginverse/branding) is the canonical source for future brand updates; consult it before changing identity assets. The current standalone plug/orbit symbol comes from `assets/logos/svg/pluginverse-symbol-color.svg` and supplied light-on-dark variants at [commit 22c6062499ec2605e63d67b30a4997b1172d8df5](https://github.com/pluginverse/branding/tree/22c6062499ec2605e63d67b30a4997b1172d8df5/assets/logos/svg). `assets/icon.svg` holds the local standalone symbol; listing sources embed the complete mark rather than a wordmark or PR monogram. Accessible titles use the current Pluginverse spelling.

Keep the supplied geometry, transparent plug cutout, proportions, and clear space. Use the violet `#4326F2` symbol on light surfaces and supplied white/lilac variants on Deep Space `#151127`. Do not extract the plug, retype the wordmark, or recolor individual fragments. Full-symbol placement must meet the source's 48-pixel minimum at the final display size. The existing product name and value-proposition composition remain PV Redirects; the actual admin/editor captures remain unchanged.

`screenshot-1.png` is the 1544×900 value-proposition graphic. Screenshots 2–4 are unchanged 1440×1000 browser captures of the real plugin: redirect manager, expanded post editor Redirect panel, and expanded settings. Their order must match `readme.txt` captions. Do not replace real UI with fabricated screens or alter screenshots to imply features the plugin does not provide.

## Capture setup

Run `composer env:start` and activate the development plugin. The initial captures use WordPress 7.1.4, PHP 8.2, and these disposable local redirects:

| Request | Destination | Status |
| --- | --- | --- |
| `/summer-sale/` | `/offers/` | 302 |
| `/contact-us/` | `/contact/` | 301 |
| `/old-blog/*` | `/blog/*` | 301 |
| `/resources/*` | `/guides/*` | 308 |

Create a demo post titled “A better home for evergreen content”, assign its local administrator as author, and save an active per-post redirect to `/guides/evergreen-content/` with status 301. The original demo is post 4; future environments may assign another ID. Use the real editor controls and confirm saved metadata before capturing. Screenshots contain no real customer data.

Capture the manager at `/wp-admin/admin.php?page=pv-redirects`, then its expanded settings. In the block editor, expand the document sidebar's Redirect panel so the toggle, destination, and status are visible. Dismiss welcome overlays through the normal UI and blur focused controls before capturing. Keep original product styling. Use a fresh browser session and inspect console errors.

The initial browser check verified add, edit, rename, and delete with a temporary `/news-archive/` rule; that temporary rule was deleted afterward. The four listing rules and demo post remain in local wp-env. Browser sessions were closed after capture.

## Research

[Redirection](https://wordpress.org/plugins/redirection/) and [Safe Redirect Manager](https://wordpress.org/plugins/safe-redirect-manager/) show concrete management screens; [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) leads with product value. PV Redirects follows that sequence with its own artwork and real screens. File naming and dimensions follow the [WordPress.org asset handbook](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
