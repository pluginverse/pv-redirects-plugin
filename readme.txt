=== PV Redirects ===
Tags: redirects, redirect, wildcard, migration, seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage fast redirects, wildcards, and per-post redirects, with WP-CLI migration from Quick Page/Post Redirect.

== Description ==

Keep your URLs working with a redirect manager for WordPress. PV Redirects compiles rules into one autoloaded map for the front-end read path, and provides an admin screen, editor sidebar, REST API, and WP-CLI commands.

* Create quick redirects and set redirects on public posts and pages.
* Match wildcard paths and carry matched text into destinations.
* Choose 301, 302, 307, or 308 status codes.
* Control case sensitivity, query-string preservation, and the default status.
* Preview and migrate supported Quick Page/Post Redirect (QPPR) data with WP-CLI.

[Source code](https://github.com/pluginverse/pv-redirects-plugin) is public. Production admin bundles are included; reproduce them from the source lockfile with `npm ci` followed by `npm run build`.

== Installation ==

1. Upload the plugin ZIP under Plugins > Add New > Upload Plugin, or install it in `wp-content/plugins/`.
2. Activate PV Redirects through the Plugins screen.
3. Open PV Redirects to add redirects and adjust settings.

== Frequently Asked Questions ==

= How do I migrate from Quick Page/Post Redirect? =

With WP-CLI installed, run `wp pv-redirects migrate --dry-run` to preview the migration, then `wp pv-redirects migrate` to copy supported data. Review the warnings and verify your redirects. Deactivate Quick Page/Post Redirect after migration so the two plugins do not compete. Source data remains in place unless you explicitly use `--delete-source`; back up first. Some features, including meta refresh, link rewriting, new-window targets, and nofollow, are not migrated.

= What do wildcards match? =

An asterisk matches characters within a path segment. A trailing `/*` matches the remaining path; a destination asterisk receives the matched text. Exact rules take precedence over wildcard rules.

= Can I redirect an individual post? =

Yes. Use the Redirect panel in the editor for a public post type. By default, managing this panel requires the `manage_options` capability.

== Screenshots ==

1. Overview of PV Redirects and its redirect features.
2. Redirect manager for creating and editing quick redirects.
3. Redirect panel in the post editor.
4. Redirect settings.

== Changelog ==

= 1.0.2 =
Fixed:

* Match query strings without regard to case when case-sensitive matching is disabled, including on existing installations.
* Import same-site full URL sources from Quick Page/Post Redirect as site-relative paths, preserving their query strings and warning about conflicting or unsupported sources.

Added:

* Add a release preparation command that synchronizes version metadata and changelogs, and uses the same notes for GitHub releases.

= 1.0.1 =
Fixed:

* Match query strings without regard to case when case-sensitive matching is disabled, including on existing installations.
* Import same-site full URL sources from Quick Page/Post Redirect as site-relative paths, preserving their query strings and warning about conflicting or unsupported sources.

Added:

* Add a release preparation command that synchronizes version metadata and changelogs, and uses the same notes for GitHub releases.

= 1.0.0 =
* Initial release with quick and per-post redirects, wildcards, WP-CLI, and QPPR migration.
