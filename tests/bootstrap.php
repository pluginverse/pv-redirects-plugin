<?php
/**
 * PHPUnit bootstrap file
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available
require_once __DIR__ . '/../vendor/autoload.php';

// Give access to tests_add_filter() function.
$_phpunit_dir = getenv('WP_PHPUNIT__DIR') ?: '../vendor/wp-phpunit/wp-phpunit/';

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

require_once $_phpunit_dir.'/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function() {
    require_once __DIR__.'/../pv-redirects.php';
} );

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__.'/wp-config.php');

// Start up the WP testing environment.
require $_phpunit_dir.'/includes/bootstrap.php';
