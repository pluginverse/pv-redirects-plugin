<?php

namespace PV\Redirects;

use function __;
use function add_menu_page;
use function apply_filters;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function filemtime;
use function get_current_screen;
use function get_post_types;
use function in_array;
use function printf;
use function register_post_meta;
use function wp_add_inline_script;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_register_script;
use function wp_script_is;

/**
 * Admin shell: the "PV Redirects" menu page, the React admin app mount point, and
 * defensively-guarded enqueues (nothing enqueues until the JS builds exist, so the plugin
 * works before `build/` lands). The apps themselves are a later wave.
 */
final class Admin {

	private const MENU_SLUG = 'pv-redirects';

	private const SCRIPT_ADMIN   = 'pv-redirects-admin';
	private const SCRIPT_SIDEBAR = 'pv-redirects-sidebar';

	/**
	 * `admin_menu` hook: top-level "PV Redirects" page.
	 */
	public static function registerMenu(): void {
		add_menu_page(
			__( 'PV Redirects', 'pv-redirects' ),
			__( 'PV Redirects', 'pv-redirects' ),
			'manage_options',
			self::MENU_SLUG,
			'\PV\Redirects\Admin::renderApp',
			'dashicons-randomize',
			80
		);
	}

	/**
	 * Menu page callback: the React app mounts on #pv-redirects-app.
	 */
	public static function renderApp(): void {
		echo '<div class="wrap">';

		if ( ! file_exists( PV_REDIRECTS_PATH.'build/index.js' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The admin app build is missing. Redirects can still be managed with WP-CLI and the REST API.', 'pv-redirects' )
			);
		}

		echo '<div id="pv-redirects-app"></div></div>';
	}

	/**
	 * `init` hook (late priority so theme-registered post types exist): exposes the three
	 * `_pv_redirect_*` meta keys over REST for every public post type, so the editor
	 * sidebar can read and write them through the block editor's meta machinery
	 * (underscore-prefixed meta is invisible to REST until registered).
	 *
	 * Writing a redirect points visitors at an arbitrary URL, so the auth callback demands
	 * `manage_options` — NOT edit_post, which would let Authors redirect their own posts
	 * to external URLs. Sites that want QPPR-style author access can lower the capability
	 * via the `pv/redirects/post_meta_capability` filter (Admin::enqueueSidebar gates the
	 * UI with the same filter).
	 */
	public static function registerMeta(): void {
		$auth_callback = static fn ( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can(
			apply_filters( 'pv/redirects/post_meta_capability', 'manage_options' ),
			$post_id
		);

		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
			register_post_meta(
				$post_type,
				PostMetaStore::META_TO,
				[
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'esc_url_raw',
					'auth_callback'     => $auth_callback,
				]
			);

			register_post_meta(
				$post_type,
				PostMetaStore::META_STATUS,
				[
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'validate_callback' => static function ( $value ): bool {
						if ( ! is_scalar( $value ) ) {
							return false;
						}

						return in_array( (int) $value, OptionStore::STATUSES, true );
					},
					'auth_callback'     => $auth_callback,
				]
			);

			register_post_meta(
				$post_type,
				PostMetaStore::META_ACTIVE,
				[
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn ( mixed $value ): string => $value === '1' ? '1' : '',
					'auth_callback'     => $auth_callback,
				]
			);
		}
	}

	/**
	 * Dependency handle providing the automatic JSX runtime the built bundles import
	 * (`window.ReactJSXRuntime`). WP 6.6+ ships it as the `react-jsx-runtime` vendor
	 * script; 6.4/6.5 have no such handle and their `wp.element` does not export jsx,
	 * so an inline alias is registered that implements jsx/jsxs on wp.element.
	 *
	 * @return non-empty-string
	 */
	private static function jsxRuntimeDependency(): string {
		if ( wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			return 'react-jsx-runtime';
		}

		$shim = 'window.ReactJSXRuntime = ( function ( element ) {'
				. ' function jsx ( type, props, key ) {'
				. ' var rest = {};'
				. ' for ( var name in props ) { if ( name !== "children" ) { rest[ name ] = props[ name ]; } }'
				. ' if ( key !== undefined ) { rest.key = key; }'
				. ' return element.createElement( type, rest, props.children );'
				. ' }'
				. ' return { jsx: jsx, jsxs: jsx, Fragment: element.Fragment };'
				. '}( window.wp.element ) );';

		wp_register_script( 'pv-redirects-jsx-runtime', false, [ 'wp-element' ], null, false );
		wp_add_inline_script( 'pv-redirects-jsx-runtime', $shim );

		return 'pv-redirects-jsx-runtime';
	}

	/**
	 * `admin_enqueue_scripts` hook: the admin app, on our page only.
	 */
	public static function enqueueAssets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'toplevel_page_'.self::MENU_SLUG ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );

		$file = PV_REDIRECTS_PATH.'build/index.js';

		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_ADMIN,
			PV_REDIRECTS_URL.'build/index.js',
			[ self::jsxRuntimeDependency(), 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ],
			self::assetVersion( $file ),
			true
		);
	}

	/**
	 * `enqueue_block_editor_assets` hook: the per-post redirect sidebar panel. Only the
	 * block editor's post screen loads it — the Site Editor screen has no `core/editor`
	 * store, so the panel would crash there — and only users who pass the same filterable
	 * capability the meta auth callback enforces.
	 */
	public static function enqueueSidebar(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen === null || $screen->base !== 'post' || ! $screen->is_block_editor ) {
			return;
		}

		if ( ! current_user_can( apply_filters( 'pv/redirects/post_meta_capability', 'manage_options' ) ) ) {
			return;
		}

		$file = PV_REDIRECTS_PATH.'build/sidebar.js';

		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_SIDEBAR,
			PV_REDIRECTS_URL.'build/sidebar.js',
			[ self::jsxRuntimeDependency(), 'wp-element', 'wp-components', 'wp-data', 'wp-plugins', 'wp-edit-post', 'wp-api-fetch' ],
			self::assetVersion( $file ),
			true
		);
	}

	private static function assetVersion( string $file ): string {
		$mtime = filemtime( $file );

		return $mtime === false ? PV_REDIRECTS_VERSION : PV_REDIRECTS_VERSION.'.'.$mtime;
	}
}
