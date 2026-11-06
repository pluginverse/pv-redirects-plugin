<?php

namespace PV\Redirects\Tests;

use PHPUnit\Framework\TestCase;

final class ProductionBootstrapTest extends TestCase {

	public function testProductionAutoloaderLoadsEverySourceClassWithoutComposer(): void {
		$script = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__.'/' );
function plugin_dir_url( $file ) { return 'https://example.test/'; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
require $argv[1].'/pv-redirects.php';
foreach ( glob( $argv[1].'/src/*.php' ) as $file ) {
    $class = 'PV\\Redirects\\'.basename( $file, '.php' );
    if ( ! class_exists( $class ) ) {
        echo $class."\n";
    }
}
PHP;
		self::assertSame( '', $this->runIsolated( $script ), 'Production classes missing from plugin autoloader' );
	}

	public function testAdminScriptsUseManifestDependenciesWithJsxFallback(): void {
		$script = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__.'/' );
function plugin_dir_url( $file ) { return 'https://example.test/'; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function wp_enqueue_style( ...$args ) {}
function wp_script_is( ...$args ) { return false; }
function wp_register_script( ...$args ) {}
function wp_add_inline_script( ...$args ) {}
function get_current_screen() { return (object) [ 'base' => 'post', 'is_block_editor' => true ]; }
function current_user_can( ...$args ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function wp_enqueue_script( $handle, $url, $dependencies, $version, $footer ) {
    global $captured;
    $captured[$handle] = $dependencies;
}
require $argv[1].'/pv-redirects.php';
PV\Redirects\Admin::enqueueAssets( 'toplevel_page_pv-redirects' );
PV\Redirects\Admin::enqueueSidebar();
foreach ( [ 'pv-redirects-admin' => 'index', 'pv-redirects-sidebar' => 'sidebar' ] as $handle => $name ) {
    $manifest = require $argv[1].'/build/'.$name.'.asset.php';
    $expected = array_map( static fn ( $dep ) => $dep === 'react-jsx-runtime' ? 'pv-redirects-jsx-runtime' : $dep, $manifest['dependencies'] );
    if ( $captured[$handle] !== $expected ) { echo $handle." dependencies differ from manifest\n"; }
}
PHP;
		self::assertSame( '', $this->runIsolated( $script ) );
	}

	private function runIsolated( string $script ): string {
		$process = proc_open( [ PHP_BINARY, '--', dirname( __DIR__ ) ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		fwrite( $pipes[0], $script );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		self::assertSame( 0, $status, $error );
		return $output;
	}
}
