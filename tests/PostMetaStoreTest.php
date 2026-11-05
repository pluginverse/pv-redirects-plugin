<?php

namespace PV\Redirects\Tests;

use InvalidArgumentException;
use PV\Redirects\PostMetaStore;

use function get_post;
use function get_post_meta;
use function metadata_exists;
use function update_post_meta;
use function wp_create_nonce;
use function wp_get_post_revisions;
use function wp_set_current_user;
use function wp_update_post;

/**
 * Per-post meta store tests: get/set/delete roundtrip, the savePost guards (autosave,
 * revision, caps, nonce) and activeRedirectPosts() filtering.
 */
final class PostMetaStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $_POST['pv_redirects_nonce'], $_POST[ PostMetaStore::META_TO ], $_POST[ PostMetaStore::META_STATUS ], $_POST[ PostMetaStore::META_ACTIVE ] );
	}

	protected function tearDown(): void {
		unset( $_POST['pv_redirects_nonce'], $_POST[ PostMetaStore::META_TO ], $_POST[ PostMetaStore::META_STATUS ], $_POST[ PostMetaStore::META_ACTIVE ] );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Simulate a classic meta-box submission for PostMetaStore::savePost(). Call it with
	 * the admin user already current so the nonce verifies.
	 */
	private function primeSavePostRequest( string $to, int $status, bool $active ): void {
		$_POST['pv_redirects_nonce']          = wp_create_nonce( 'pv_redirects_post' );
		$_POST[ PostMetaStore::META_TO ]      = $to;
		$_POST[ PostMetaStore::META_STATUS ]  = (string) $status;
		$_POST[ PostMetaStore::META_ACTIVE ]  = $active ? '1' : '0';
	}

	private function becomeAdmin(): int {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		return $admin_id;
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

	// ---------------------------------------------------------------- savePost guards

	public function testSavePostIgnoresRevisions(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => 'original' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => 'changed' ] );

		$revisions = wp_get_post_revisions( $post_id );

		static::assertNotEmpty( $revisions, 'Fixture: the update must have produced a revision' );

		$revision = array_shift( $revisions );

		$this->becomeAdmin();
		$this->primeSavePostRequest( '/should-not-persist/', 302, true );

		PostMetaStore::savePost( $revision->ID, $revision );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ), 'The parent post must stay untouched' );
		static::assertFalse( metadata_exists( 'post', $revision->ID, PostMetaStore::META_TO ) );
	}

	public function testSavePostIgnoresAutosaves(): void {
		$post_id   = self::factory()->post->create();
		$author_id = (int) get_post( $post_id )->post_author;

		$autosave = self::factory()->post->create_and_get( [
			'post_type'   => 'revision',
			'post_parent' => $post_id,
			'post_status' => 'inherit',
			'post_name'   => $post_id . '-autosave',
			'post_author' => $author_id,
		] );

		static::assertTrue( (bool) wp_is_post_autosave( $autosave->ID ), 'Fixture: the revision must register as an autosave' );

		$this->becomeAdmin();
		$this->primeSavePostRequest( '/should-not-persist/', 302, true );

		PostMetaStore::savePost( $autosave->ID, $autosave );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
	}

	public function testSavePostIgnoresMissingNonce(): void {
		$post_id = self::factory()->post->create();

		$this->becomeAdmin();
		$_POST[ PostMetaStore::META_TO ]     = '/nope/';
		$_POST[ PostMetaStore::META_STATUS ] = '302';
		$_POST[ PostMetaStore::META_ACTIVE ] = '1';

		PostMetaStore::savePost( $post_id, get_post( $post_id ) );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
	}

	public function testSavePostIgnoresInvalidNonce(): void {
		$post_id = self::factory()->post->create();

		$this->becomeAdmin();
		$_POST['pv_redirects_nonce']         = 'bogus';
		$_POST[ PostMetaStore::META_TO ]     = '/nope/';
		$_POST[ PostMetaStore::META_STATUS ] = '302';
		$_POST[ PostMetaStore::META_ACTIVE ] = '1';

		PostMetaStore::savePost( $post_id, get_post( $post_id ) );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
	}

	public function testSavePostIgnoresUserWithoutCaps(): void {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->primeSavePostRequest( '/nope/', 302, true );

		PostMetaStore::savePost( $post_id, get_post( $post_id ) );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
	}

	public function testSavePostPersistsWithValidNonceAndCaps(): void {
		$post_id = self::factory()->post->create();

		$this->becomeAdmin();
		$this->primeSavePostRequest( '/posted-target/', 302, true );

		PostMetaStore::savePost( $post_id, get_post( $post_id ) );

		static::assertSame(
			[ 'to' => '/posted-target/', 'status' => 302, 'active' => true ],
			PostMetaStore::get( $post_id )
		);
	}

	public function testSavePostEmptyDestinationRemovesMeta(): void {
		$post_id = self::factory()->post->create();

		PostMetaStore::set( $post_id, '/old/', 301, true );

		$this->becomeAdmin();
		$this->primeSavePostRequest( '', 301, false );

		PostMetaStore::savePost( $post_id, get_post( $post_id ) );

		static::assertFalse( metadata_exists( 'post', $post_id, PostMetaStore::META_TO ) );
		static::assertSame(
			[ 'to' => '', 'status' => 301, 'active' => false ],
			PostMetaStore::get( $post_id )
		);
	}
}
