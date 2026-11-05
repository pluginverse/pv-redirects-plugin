<?php
/**
 * PV Redirects uninstaller.
 *
 * Namespace: PV\Redirects. The plugin classes are autoloaded by the plugin
 * bootstrap; this file is loaded directly by WordPress during uninstall,
 * so it stays procedural on purpose.
 *
 * Removes everything the plugin wrote:
 *
 * - Options: pv_redirects, pv_redirects_map, pv_redirects_settings.
 * - Post meta: _pv_redirect_to, _pv_redirect_status, _pv_redirect_active
 *   across all posts, via one $wpdb query on the postmeta table.
 *
 * Multisite-safe without a blog loop: the postmeta and options tables are
 * site-scoped, so the queries below hit the current site only, and WordPress
 * invokes this file for each site the plugin was active on — the same way
 * core uninstallers behave.
 *
 * @package pv-redirects
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Options: quick redirects, the computed map, and settings.
delete_option( 'pv_redirects' );
delete_option( 'pv_redirects_map' );
delete_option( 'pv_redirects_settings' );

// Per-post redirect meta, across all posts and post types, in one query.
$meta_keys    = array( '_pv_redirect_to', '_pv_redirect_status', '_pv_redirect_active' );
$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $meta_keys
    )
);
