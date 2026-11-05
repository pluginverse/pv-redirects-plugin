<?php

namespace PV\Redirects\Tests;

use PV\Redirects\OptionStore;
use PV\Redirects\PostMetaStore;
use PV\Redirects\RedirectMap;
use PV\Redirects\Settings;

use function get_option;
use function update_post_meta;
use function wp_load_alloptions;

/**
 * Computed-map tests: rebuild from a seeded option + seeded posts, permalink keys,
 * collision precedence, the posts index, the settings snapshot, forced autoload,
 * and the schema-version guard on get().
 */
final class RedirectMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->set_permalink_structure( '/%postname%/' );
	}

	public function testRebuildBuildsExactRowsFromOption(): void {
		OptionStore::set( '/old-page/', '/new-page/', 301 );
		OptionStore::set( '/2019/press/', 'https://ext.example/pr', 302 );

		$map = RedirectMap::get();

		static::assertSame( 1, $map['v'] );
		static::assertGreaterThan( 0, $map['built'] );
		static::assertSame( [ 'to' => '/new-page/', 'status' => 301, 'src' => 'o' ], $map['exact']['/old-page/'] );
		static::assertSame( [ 'to' => 'https://ext.example/pr', 'status' => 302, 'src' => 'o' ], $map['exact']['/2019/press/'] );
		static::assertSame( [], $map['wildcards'] );
		static::assertSame( [], $map['posts'] );
	}

	public function testRebuildCompilesWildcardRows(): void {
		OptionStore::set( '/old-blog/*', '/new-blog/*', 302 );

		$map = RedirectMap::get();

		static::assertCount( 1, $map['wildcards'] );
		static::assertSame( '#^/old\-blog/(?P<rest>.+)$#', $map['wildcards'][0]['regex'] );
		static::assertSame( '/new-blog/*', $map['wildcards'][0]['to'] );
		static::assertSame( 302, $map['wildcards'][0]['status'] );
		static::assertSame( 'o', $map['wildcards'][0]['src'] );
		static::assertArrayNotHasKey( '/old-blog/*', $map['exact'], 'Wildcard rows must not land in exact' );
	}

	public function testRebuildIndexesActivePostsByPermalinkAndId(): void {
		$active = self::factory()->post->create( [ 'post_name' => 'alpha-post' ] );
		$quiet  = self::factory()->post->create( [ 'post_name' => 'quiet-post' ] );

		update_post_meta( $active, PostMetaStore::META_TO, 'https://ext.example/pr' );
		update_post_meta( $active, PostMetaStore::META_STATUS, '302' );
		update_post_meta( $active, PostMetaStore::META_ACTIVE, '1' );

		update_post_meta( $quiet, PostMetaStore::META_TO, '/somewhere/' );
		update_post_meta( $quiet, PostMetaStore::META_ACTIVE, '' );

		$map = RedirectMap::rebuild();

		static::assertSame(
			[ 'to' => 'https://ext.example/pr', 'status' => 302, 'src' => 'p', 'id' => $active ],
			$map['exact']['/alpha-post/'],
			'The exact key must be the post permalink, home-stripped'
		);
		static::assertSame( [ 'to' => 'https://ext.example/pr', 'status' => 302 ], $map['posts'][ $active ] );
		static::assertArrayNotHasKey( $quiet, $map['posts'], 'Inactive posts must be excluded' );
		static::assertArrayNotHasKey( '/quiet-post/', $map['exact'] );
	}

	public function testOptionRowWinsPermalinkCollision(): void {
		OptionStore::set( '/old-page/', '/option-target/', 301 );

		$post_id = self::factory()->post->create( [ 'post_name' => 'old-page' ] );
		update_post_meta( $post_id, PostMetaStore::META_TO, '/post-target/' );
		update_post_meta( $post_id, PostMetaStore::META_ACTIVE, '1' );

		$map = RedirectMap::rebuild();

		static::assertSame(
			[ 'to' => '/option-target/', 'status' => 301, 'src' => 'o' ],
			$map['exact']['/old-page/'],
			'The option row must win the exact-key collision'
		);
		static::assertSame( '/post-target/', $map['posts'][ $post_id ]['to'], 'The post still lands in the posts index' );
	}

	public function testMapCarriesSettingsSnapshot(): void {
		OptionStore::set( '/a/', '/b/', 301 );

		static::assertSame( Settings::all(), RedirectMap::get()['settings'] );
	}

	public function testMapRowIsAutoloaded(): void {
		global $wpdb;

		RedirectMap::rebuild();

		$autoload = $wpdb->get_var(
			"SELECT autoload FROM {$wpdb->options} WHERE option_name = 'pv_redirects_map'"
		);

		static::assertContains( $autoload, [ 'yes', 'on', 'auto-on' ] );
		static::assertArrayHasKey( 'pv_redirects_map', wp_load_alloptions() );
	}

	public function testStoreReforcesAutoloadOnStaleRow(): void {
		global $wpdb;

		RedirectMap::rebuild();
		$wpdb->query(
			"UPDATE {$wpdb->options} SET autoload = 'off' WHERE option_name = 'pv_redirects_map'"
		);

		// update_option() only touches autoload on an actual value write (an unchanged
		// value short-circuits), so the re-enforcement needs a genuinely different map:
		// adding an option row changes the rebuilt map deterministically.
		OptionStore::set( '/force-write/', '/target/', 301 );

		$autoload = $wpdb->get_var(
			"SELECT autoload FROM {$wpdb->options} WHERE option_name = 'pv_redirects_map'"
		);

		static::assertContains( $autoload, [ 'yes', 'on', 'auto-on' ], 'A non-autoloaded row must be re-stored autoloaded' );
		static::assertArrayHasKey( 'pv_redirects_map', wp_load_alloptions() );
	}

	public function testGetReturnsEmptyOnVersionMismatch(): void {
		update_option( 'pv_redirects_map', [ 'v' => 0, 'exact' => [ '/x/' => [] ] ] );

		static::assertSame( [], RedirectMap::get() );
	}

	public function testResetDropsMapButKeepsCanonicalStores(): void {
		OptionStore::set( '/a/', '/b/', 301 );

		RedirectMap::reset();

		static::assertFalse( get_option( 'pv_redirects_map', false ) );
		static::assertNotFalse( get_option( 'pv_redirects', false ) );
		static::assertSame( [], RedirectMap::get() );
	}
}
