<?php

namespace PV\Redirects\Tests;

use Closure;
use PV\Redirects\Admin;
use PV\Redirects\PostMetaStore;

use function add_filter;
use function remove_all_filters;
use function wp_set_current_user;

/**
 * Admin shell tests: the `_pv_redirect_*` meta auth callback capability boundary. Writing
 * a redirect points visitors at an arbitrary URL, so the callback must demand
 * `manage_options` (filterable via pv/redirects/post_meta_capability) — NOT edit_post,
 * which would let Authors redirect their own posts to external URLs.
 */
final class AdminTest extends TestCase {

	private static int $author_id = 0;
	private static int $admin_id  = 0;

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		self::$author_id = $factory->user->create( [ 'role' => 'author' ] );
		self::$admin_id  = $factory->user->create( [ 'role' => 'administrator' ] );
	}

	public static function wpTearDownAfterClass(): void {
		self::delete_user( self::$author_id );
		self::delete_user( self::$admin_id );
	}

	protected function tearDown(): void {
		// Clear any capability filter a test installed, so the boundary resets.
		remove_all_filters( 'pv/redirects/post_meta_capability' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Capture the auth callback registerMeta() hands to register_post_meta(), via the
	 * register_meta_args filter — the same closure WP calls on every meta write.
	 *
	 * @return Closure
	 */
	private function captureAuthCallback(): Closure {
		$captured = [];
		$listener = static function ( array $args, array $defaults, string $object_type, string $meta_key ) use ( &$captured ): array {
			$captured[ $meta_key ] = $args;

			return $args;
		};
		add_filter( 'register_meta_args', $listener, 10, 4 );

		Admin::registerMeta();

		remove_filter( 'register_meta_args', $listener, 10 );

		$auth_callback = $captured[ PostMetaStore::META_TO ]['auth_callback'] ?? null;

		static::assertInstanceOf( Closure::class, $auth_callback, 'Fixture: the auth callback must be captured' );

		return $auth_callback;
	}

	public function testAuthCallbackRejectsAuthorOnOwnPost(): void {
		$post_id = self::factory()->post->create( [ 'post_author' => self::$author_id ] );
		$auth    = $this->captureAuthCallback();

		wp_set_current_user( self::$author_id );

		static::assertFalse(
			$auth( true, PostMetaStore::META_TO, $post_id ),
			'An Author can edit_post their own post but must not set a redirect'
		);
	}

	public function testAuthCallbackAcceptsAdmin(): void {
		$post_id = self::factory()->post->create();
		$auth    = $this->captureAuthCallback();

		wp_set_current_user( self::$admin_id );

		static::assertTrue( $auth( true, PostMetaStore::META_TO, $post_id ) );
	}

	public function testCapabilityFilterRestoresQpprStyleAuthorAccess(): void {
		$post_id = self::factory()->post->create( [ 'post_author' => self::$author_id ] );
		$auth    = $this->captureAuthCallback();

		add_filter(
			'pv/redirects/post_meta_capability',
			static fn (): string => 'edit_posts'
		);

		wp_set_current_user( self::$author_id );

		static::assertTrue(
			$auth( true, PostMetaStore::META_TO, $post_id ),
			'Sites must be able to lower the capability via the filter (QPPR parity)'
		);
	}
}
