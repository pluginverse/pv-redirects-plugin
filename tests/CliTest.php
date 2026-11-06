<?php

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		final class WP_CLI {
			public static array $messages = [];
			public static function log( string $message ): void { self::$messages[] = $message; }
			public static function warning( string $message ): void { self::$messages[] = $message; }
			public static function success( string $message ): void { self::$messages[] = $message; }
			public static function error( string $message ): void { throw new \RuntimeException( $message ); }
		}
	}
}

namespace PV\Redirects\Tests {
	use PV\Redirects\Cli;
	use PV\Redirects\OptionStore;
	use PV\Redirects\PostMetaStore;

	final class CliTest extends TestCase {
		private int $post_id;

		protected function setUp(): void {
			parent::setUp();
			delete_option( 'quickppr_redirects' );
			delete_option( 'quickppr_redirects_meta' );
			$this->post_id = self::factory()->post->create();
			update_option( 'quickppr_redirects', [ '/legacy/' => '/new/' ] );
			update_post_meta( $this->post_id, '_pprredirect_active', '1' );
			update_post_meta( $this->post_id, '_pprredirect_url', '/post-target/' );
			update_post_meta( $this->post_id, '_pprredirect_type', '301' );
		}

		public function testDryRunAndDeleteSourceWriteNothing(): void {
			$map = self::makeMap();
			update_option( 'pv_redirects_map', $map );
			$source = get_option( 'quickppr_redirects' );
			( new Cli() )->migrate( [], [ 'dry-run' => true, 'delete-source' => true ] );
			self::assertFalse( get_option( 'pv_redirects' ) );
			self::assertFalse( get_option( 'pv_redirects_settings' ) );
			self::assertSame( $map, get_option( 'pv_redirects_map' ) );
			self::assertSame( $source, get_option( 'quickppr_redirects' ) );
			self::assertSame( '/post-target/', get_post_meta( $this->post_id, '_pprredirect_url', true ) );
			self::assertFalse( metadata_exists( 'post', $this->post_id, PostMetaStore::META_TO ) );
		}

		public function testDeleteSourceFlagRemovesMigratedSource(): void {
			( new Cli() )->migrate( [], [ 'delete-source' => true ] );
			self::assertFalse( get_option( 'quickppr_redirects' ) );
			self::assertFalse( metadata_exists( 'post', $this->post_id, '_pprredirect_url' ) );
			self::assertNotNull( OptionStore::get( '/legacy/' ) );
		}

		public function testDuplicateFlagAliasesFailBeforeWriting(): void {
			$source = get_option( 'quickppr_redirects' );
			$error = null;
			try {
				( new Cli() )->migrate( [], [ 'dry_run' => true, 'dry-run' => false, 'delete-source' => true ] );
			} catch ( \RuntimeException $exception ) {
				$error = $exception;
			}
			self::assertInstanceOf( \RuntimeException::class, $error );
			self::assertSame( $source, get_option( 'quickppr_redirects' ) );
			self::assertFalse( get_option( 'pv_redirects' ) );
			self::assertSame( '/post-target/', get_post_meta( $this->post_id, '_pprredirect_url', true ) );
		}

		public function testSkipQuickLeavesQuickSourceAndMigratesPosts(): void {
			( new Cli() )->migrate( [], [ 'skip-quick' => true ] );
			self::assertNull( OptionStore::get( '/legacy/' ) );
			self::assertSame( '/post-target/', get_post_meta( $this->post_id, PostMetaStore::META_TO, true ) );
		}

		public function testSkipPostsLeavesPostSourceAndMigratesQuick(): void {
			( new Cli() )->migrate( [], [ 'skip-posts' => true ] );
			self::assertNotNull( OptionStore::get( '/legacy/' ) );
			self::assertFalse( metadata_exists( 'post', $this->post_id, PostMetaStore::META_TO ) );
		}
	}
}
