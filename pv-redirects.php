<?php
/**
 * Plugin Name: PV Redirects
 * Description: Fast redirects with wildcard support and a single computed autoloaded map. A modern replacement for Quick Page/Post Redirect.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Spenser Hale
 * Author URI: https://www.spenserhale.com/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: pv-redirects
 *
 * PV Redirects
 * Copyright (C) 2026 Spenser Hale
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace PV\Redirects;

use function add_action;
use function add_filter;
use function define;
use function plugin_dir_url;
use function register_activation_hook;
use function spl_autoload_register;
use function str_starts_with;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80200 ) {
	_doing_it_wrong( __FILE__, 'PV Redirects requires PHP 8.2 or higher.', '1.0.0' );

	return;
}

define( 'PV_REDIRECTS_VERSION', '1.0.0' );
define( 'PV_REDIRECTS_PATH', __DIR__.'/' );
define( 'PV_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

function class_autoloader( string $class ): void {
	if ( str_starts_with( $class, __NAMESPACE__ ) ) {
		static $classMap = [
			Admin::class           => __DIR__.'/src/Admin.php',
			Cli::class             => __DIR__.'/src/Cli.php',
			Matcher::class         => __DIR__.'/src/Matcher.php',
			Migrator::class        => __DIR__.'/src/Migrator.php',
			OptionStore::class     => __DIR__.'/src/OptionStore.php',
			PostMetaStore::class   => __DIR__.'/src/PostMetaStore.php',
			RedirectHandler::class => __DIR__.'/src/RedirectHandler.php',
			RedirectMap::class     => __DIR__.'/src/RedirectMap.php',
			RestController::class  => __DIR__.'/src/RestController.php',
			Settings::class        => __DIR__.'/src/Settings.php',
		];

		if ( isset( $classMap[$class] ) ) {
			require_once $classMap[$class];
		}
	}
}

spl_autoload_register( '\PV\Redirects\class_autoloader' );

// Front-end read path: the matcher at init:1, the canonical guard, the permalink-drift fallback.
add_action( 'init', '\PV\Redirects\RedirectHandler::init', 1, 0 );
add_filter( 'redirect_canonical', '\PV\Redirects\RedirectHandler::canonical', 10, 2 );
add_action( 'template_redirect', '\PV\Redirects\RedirectHandler::templateRedirect', 1, 0 );

// Map maintenance: store mutations rebuild internally. The meta hooks catch editor
// sidebar/REST/programmatic writes that bypass the stores — they debounce to ONE rebuild
// at shutdown (first-time writes fire added_post_meta, deletions deleted_post_meta).
// transition_post_status covers trash/untrash/publish, which fire no meta hooks, and
// rebuilds immediately. CLI, REST and the stores themselves always rebuild immediately.
add_action( 'added_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated', 10, 3 );
add_action( 'updated_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated', 10, 3 );
add_action( 'deleted_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated', 10, 3 );
add_action( 'transition_post_status', '\PV\Redirects\PostMetaStore::statusTransitioned', 10, 3 );

// REST API (backs the admin app).
add_action( 'rest_api_init', '\PV\Redirects\RestController::registerRoutes' );

// Admin shell; enqueues are file_exists-guarded so the plugin works before the JS build lands.
add_action( 'admin_menu', '\PV\Redirects\Admin::registerMenu' );
add_action( 'admin_enqueue_scripts', '\PV\Redirects\Admin::enqueueAssets' );
add_action( 'enqueue_block_editor_assets', '\PV\Redirects\Admin::enqueueSidebar' );
add_action( 'init', '\PV\Redirects\Admin::registerMeta', 99 ); // Exposes _pv_redirect_* meta to REST so the editor sidebar can save it.

// WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'pv-redirects', '\PV\Redirects\Cli' );
}

// Warm the computed map on activation.
register_activation_hook( __FILE__, '\PV\Redirects\RedirectMap::rebuild' );
