<?php

namespace PV\Redirects\Tests;

use InvalidArgumentException;
use PV\Redirects\PostMetaStore;

use function add_action;
use function add_post_meta;
use function delete_post_meta;
use function get_option;
use function get_post;
use function get_post_meta;
use function has_action;
use function metadata_exists;
use function remove_action;
use function update_post_meta;
use function wp_update_post;

/**
 * Per-post meta store tests: get/set/delete roundtrip, the debounced meta-hook rebuild
 * (added/updated/deleted_post_meta -> ONE rebuild at shutdown), the statusTransitioned()
 * immediate rebuild on trash/untrash/publish, and activeRedirectPosts() filtering.
 *
 * The deferred tests invoke PostMetaStore::rebuildPendingMap() directly — the exact
 * callback the shutdown hook fires — rather than do_action('shutdown'), which would also
 * run core's buffer-flushing shutdown handlers inside PHPUnit.
 */
final class PostMetaStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Flush any rebuild a previous test's meta write left pending, so the dirty flag
		// and the map start clean every test.
		PostMetaStore::rebuildPendingMap();
	}

	// ---------------------------------------------------------------- hook wiring

	public function testRebuildHooksAreWired(): void {
		static::assertNotFalse( has_action( 'added_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated' ) );
		static::assertNotFalse( has_action( 'updated_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated' ) );
		static::assertNotFalse( has_action( 'deleted_post_meta', '\PV\Redirects\PostMetaStore::metaUpdated' ) );
		static::assertNotFalse( has_action( 'transition_post_status', '\PV\Redirects\PostMetaStore::statusTransitioned' ) );

		// The shutdown listener registers lazily with the first dirty mark.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, PostMetaStore::META_TO, '/wired/' );

		static::assertNotFalse(
			has_action( 'shutdown', '\PV\Redirects\PostMetaStore::rebuildPendingMap' ),
			'A meta write must schedule the debounced shutdown rebuild'
		);

		PostMetaStore::rebuildPendingMap();
	}

	// ---------------------------------------------------------------- get/set/delete

	public function testSetGetRoundtrip(): void {
		$post_id = self::factory()->post->create();

		PostMetaStore::set( $post_id, '/new-place/', 307, true );

		static::assertSame(
			[ 'to' => '/new-place/', 'status' => 307, 'active' => true ],
			PostMetaStore::get( $post_id )
		);
		static::assertSame( '307', get_post_meta( $post_id, PostMetaStore::META_STATUS, true ) );
		static::assertSame( '1', get_post_meta( $post_id, PostMetaStore::META_ACTIVE, true ) );
	}

	public function testGetDefaultsWhenNoMeta(): void {
		$post_id = self::factory()->post->create();

		static::assertSame(
			[ 'to' => '', 'status' => 301, 'active' => false ],
			PostMetaStore::get( $post_id )
		);
	}

	public function testSetRejectsInvalidStatus(): void {
		$post_id = self::factory()->post->create();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '301, 302, 307, 308' );

		PostMetaStore::set( $post_id, '/x/', 200, true );
	}

	public function testDeleteRemovesAllMetaKeys(): void {
		$post_id = self::factory()->post->create();

		PostMetaStore::set( $post_id, '/x/', 302, true );
		PostMetaStore::delete( $post_id );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_STATUS ) );
		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_ACTIVE ) );
		static::assertSame(
			[ 'to' => '', 'status' => 301, 'active' => false ],
			PostMetaStore::get( $post_id )
		);
	}

	// ---------------------------------------------------------------- debounced meta hooks

	public function testAddedPostMetaRebuildsAtShutdown(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'added-meta-post' ] );

		// A first-time sidebar write fires added_post_meta (via add_post_meta).
		add_post_meta( $post_id, PostMetaStore::META_TO, '/shutdown-target/' );
		add_post_meta( $post_id, PostMetaStore::META_ACTIVE, '1' );

		static::assertNull(
			self::mapPostRule( $post_id ),
			'The debounced rebuild must not have run before shutdown'
		);

		// The shutdown hook fires exactly this callback.
		PostMetaStore::rebuildPendingMap();

		static::assertSame(
			[ 'to' => '/shutdown-target/', 'status' => 301 ],
			self::mapPostRule( $post_id ),
			'The debounced rebuild must index the post'
		);
	}

	public function testDeletedPostMetaRebuildsAtShutdown(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'deleted-meta-post' ] );

		PostMetaStore::set( $post_id, '/target/', 301, true );
		static::assertNotNull( self::mapPostRule( $post_id ), 'Fixture: the row starts indexed' );

		// Removing the redirect through the meta API (not the store) defers the rebuild.
		delete_post_meta( $post_id, PostMetaStore::META_ACTIVE );

		static::assertNotNull(
			self::mapPostRule( $post_id ),
			'The debounced rebuild must not have run before shutdown'
		);

		// The shutdown hook fires exactly this callback.
		PostMetaStore::rebuildPendingMap();

		static::assertNull(
			self::mapPostRule( $post_id ),
			'The shutdown rebuild must drop the deactivated post from the map'
		);
	}

	public function testManyMetaWritesCostOneShutdownRebuild(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'debounce-post' ] );

		PostMetaStore::set( $post_id, '/first/', 301, true );
		$count_rebuilds = $this->countRebuilds();

		// A sidebar save writes all three keys; every hook marks the same dirty flag.
		update_post_meta( $post_id, PostMetaStore::META_TO, '/second/' );
		update_post_meta( $post_id, PostMetaStore::META_STATUS, '302' );
		update_post_meta( $post_id, PostMetaStore::META_ACTIVE, '1' );

		PostMetaStore::rebuildPendingMap();

		static::assertSame( 1, $count_rebuilds(), 'N meta writes must cost exactly one debounced rebuild' );
		static::assertSame(
			[ 'to' => '/second/', 'status' => 302 ],
			self::mapPostRule( $post_id )
		);
	}

	public function testSetClearsPendingShutdownRebuild(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'immediate-post' ] );

		// set() writes through update_post_meta (hooks fire) but rebuilds immediately and
		// clears the dirty flag: no further rebuild may fire at shutdown.
		PostMetaStore::set( $post_id, '/immediate/', 301, true );
		$count_rebuilds = $this->countRebuilds();

		static::assertSame(
			[ 'to' => '/immediate/', 'status' => 301 ],
			self::mapPostRule( $post_id )
		);

		PostMetaStore::rebuildPendingMap();

		static::assertSame( 0, $count_rebuilds(), 'An immediate rebuild must leave no pending shutdown rebuild' );
	}

	// ---------------------------------------------------------------- statusTransitioned

	public function testTrashTransitionRebuildsImmediatelyForRedirectingPost(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'transition-post' ] );
		PostMetaStore::set( $post_id, '/target/', 301, true );
		static::assertNotNull( self::mapPostRule( $post_id ), 'Fixture: the row starts indexed' );

		$count_rebuilds = $this->countRebuilds();

		// Fires transition_post_status through core, which calls statusTransitioned().
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'trash' ] );

		// Trashed posts leave activeRedirectPosts() ('any' excludes trash), so the map
		// must drop the post WITHOUT waiting for shutdown.
		static::assertNull( self::mapPostRule( $post_id ) );
		static::assertSame( 1, $count_rebuilds(), 'The transition must rebuild immediately' );
	}

	public function testStatusTransitionSkipsPostsWithoutRedirect(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'no-redirect-post' ] );

		$count_rebuilds = $this->countRebuilds();

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'trash' ] );

		static::assertSame( 0, $count_rebuilds(), 'A post without a redirect must not trigger a rebuild' );
	}

	public function testStatusTransitionSkipsUnchangedStatus(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'same-status-post' ] );
		PostMetaStore::set( $post_id, '/target/', 301, true );

		$post = get_post( $post_id );
		static::assertInstanceOf( \WP_Post::class, $post );

		$count_rebuilds = $this->countRebuilds();

		PostMetaStore::statusTransitioned( 'publish', 'publish', $post );

		static::assertSame( 0, $count_rebuilds(), 'A same-status transition must not trigger a rebuild' );
	}

	// ---------------------------------------------------------------- activeRedirectPosts

	public function testActiveRedirectPostsReturnsOnlyActiveWithDestination(): void {
		$active    = self::factory()->post->create( [ 'post_name' => 'active-post' ] );
		$inactive  = self::factory()->post->create( [ 'post_name' => 'inactive-post' ] );
		$empty_to  = self::factory()->post->create( [ 'post_name' => 'empty-to-post' ] );

		PostMetaStore::set( $active, '/target/', 308, true );
		PostMetaStore::set( $inactive, '/target/', 301, false );
		update_post_meta( $empty_to, PostMetaStore::META_TO, '' );
		update_post_meta( $empty_to, PostMetaStore::META_ACTIVE, '1' );

		static::assertSame(
			[ $active => [ 'to' => '/target/', 'status' => 308 ] ],
			PostMetaStore::activeRedirectPosts()
		);
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * The computed map's posts-index rule for one post, or null when the map is absent or
	 * the post is not indexed. get_option() returns false (not an array) for an absent
	 * map, so the raw subscript would warn — assert-friendly instead.
	 *
	 * @return mixed
	 */
	private static function mapPostRule( int $post_id ): mixed {
		$map = get_option( 'pv_redirects_map' );

		return is_array( $map ) ? ( $map['posts'][ $post_id ] ?? null ) : null;
	}

	/**
	 * Install a counter on the pv/redirects/map_rebuilt action. Call the returned closure
	 * (after the behavior under test ran) to read how many rebuilds fired and remove the
	 * counter. Counts instead of comparing `built` timestamps: time() has one-second
	 * granularity, so same-second rebuilds would be indistinguishable.
	 *
	 * @return callable(): int
	 */
	private function countRebuilds(): callable {
		$count    = 0;
		$listener = static function () use ( &$count ): void {
			++$count;
		};
		add_action( 'pv/redirects/map_rebuilt', $listener, 10, 0 );

		return static function () use ( &$count, $listener ): int {
			remove_action( 'pv/redirects/map_rebuilt', $listener, 10 );

			return $count;
		};
	}
}
