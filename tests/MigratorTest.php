<?php

namespace PV\Redirects\Tests;

use PV\Redirects\Migrator;
use PV\Redirects\PostMetaStore;
use PV\Redirects\Settings;

use function delete_option;
use function get_option;
use function get_post_meta;
use function implode;
use function metadata_exists;
use function preg_match;
use function update_option;
use function update_post_meta;

/**
 * Integration tests for the one-shot QPPR migration (copy-on-migrate, ADR-0003). Fixtures
 * mirror real QPPR storage: `quickppr_redirects` as a request-key => destination map,
 * `quickppr_redirects_meta` as a parallel per-key feature-flag map, and per-post
 * `_pprredirect_*` meta on regular posts. Contract notes taken from src/Migrator.php:
 * quick rows land in `pv_redirects` under their QPPR keys (trimmed, leading slash added,
 * query strings kept raw) with the resolved global type as status; per-post redirects
 * land as the exact `_pv_redirect_*` shapes PostMetaStore::set() writes; re-runs skip
 * everything already present; dry runs write nothing; --delete-source removes only what
 * the run copied or found already migrated, and never the ppr_override-URL option.
 */
final class MigratorTest extends TestCase {

	/**
	 * QPPR source options the migrator reads: none of these are cleaned by the shared
	 * base class, so every test starts from an empty legacy state.
	 *
	 * @var list<string>
	 */
	private const QPPR_OPTIONS = [
		'quickppr_redirects',
		'quickppr_redirects_meta',
		'ppr_override-casesensitive',
		'ppr_override-redirect-type',
		'ppr_override-active',
		'ppr_override-URL',
	];

	/**
	 * Every `_pprredirect_*` meta key --delete-source must remove from posts it handled.
	 *
	 * @var list<string>
	 */
	private const QPPR_POST_META_KEYS = [
		'_pprredirect_active',
		'_pprredirect_url',
		'_pprredirect_type',
		'_pprredirect_newwindow',
		'_pprredirect_relnofollow',
		'_pprredirect_rewritelink',
	];

	protected function setUp(): void {
		parent::setUp();

		foreach ( self::QPPR_OPTIONS as $option_name ) {
			delete_option( $option_name );
		}
	}

	// ---------------------------------------------------------------- happy path

	public function testMigrateCopiesQuickRowsAndPostMetaAndRebuildsMap(): void {
		[ $active, $inactive ] = $this->seedLegacyFixtures();

		$report = Migrator::migrate();

		// Report: three quick rows in, one dropped-feature meta row, both posts migrated
		// with the inactive one flagged, no QPPR settings present.
		static::assertSame(
			[ 'migrated' => 3, 'skipped_existing' => 0, 'dropped_meta' => 1 ],
			$report['quick']
		);
		static::assertSame(
			[
				'migrated'             => 2,
				'skipped_existing'     => 0,
				'inactive_migrated'    => 1,
				'meta_type_downgraded' => 0,
				'dropped_features'     => 0,
			],
			$report['posts']
		);
		static::assertSame( [ 'case_sensitive' => null, 'default_status' => null, 'disabled' => false ], $report['settings'] );
		static::assertFalse( $report['dry_run'] );
		static::assertTrue( $report['rebuilt'] );

		// Quick rows stored under their QPPR keys (query strings kept raw) with the
		// global default status 301, destinations copied verbatim including external URLs.
		static::assertSame(
			[
				'/legacy-page/'      => [ 'to' => '/new-home/', 'status' => 301 ],
				'/about.htm'         => [ 'to' => 'https://example.com/about/', 'status' => 301 ],
				'/2024-press/?utm=x' => [ 'to' => '/press/', 'status' => 301 ],
			],
			get_option( 'pv_redirects' )
		);

		// The truthy newwindow flag counts as dropped and is warned about, never silent.
		static::assertStringContainsString( 'open in new window', self::warnings( $report ) );

		// Active post: meta shapes identical to PostMetaStore::set() output.
		static::assertSame( '/target/', get_post_meta( $active, PostMetaStore::META_TO, true ) );
		static::assertSame( '302', get_post_meta( $active, PostMetaStore::META_STATUS, true ) );
		static::assertSame( '1', get_post_meta( $active, PostMetaStore::META_ACTIVE, true ) );

		// Inactive post: migrated with its inactive flag preserved as empty.
		static::assertSame( '/old-target/', get_post_meta( $inactive, PostMetaStore::META_TO, true ) );
		static::assertSame( '', get_post_meta( $inactive, PostMetaStore::META_ACTIVE, true ) );
		static::assertStringContainsString( 'inactive in QPPR', self::warnings( $report ) );

		// One rebuild at the end: quick rows in exact, only the active post indexed.
		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertSame( 1, $map['v'] );
		static::assertSame( [ 'to' => '/new-home/', 'status' => 301, 'src' => 'o' ], $map['exact']['/legacy-page/'] );
		static::assertSame( [ 'to' => 'https://example.com/about/', 'status' => 301, 'src' => 'o' ], $map['exact']['/about.htm'] );
		static::assertSame( [ 'to' => '/press/', 'status' => 301, 'src' => 'o' ], $map['exact']['/2024-press/?utm=x'] );
		static::assertSame( [ 'to' => '/target/', 'status' => 302 ], $map['posts'][$active] );
		static::assertArrayNotHasKey( $inactive, $map['posts'], 'Inactive redirects must stay out of the map posts index' );
		static::assertSame( [], $map['wildcards'] );
	}

	// ---------------------------------------------------------------- type mapping

	/**
	 * @return iterable<string, array{string, string, int, string|null}>
	 */
	public static function providePostTypeValues(): iterable {
		yield 'numeric 307 is kept' => [ '307', '307', 0, null ];

		yield 'meta refresh downgrades to 301' => [ 'meta', '301', 1, 'refresh type' ];

		yield 'junk type falls back to 301' => [ 'Quick', '301', 0, 'unrecognized' ];

		yield "unset '0' falls back to 301" => [ '0', '301', 0, 'unrecognized' ];
	}

	/**
	 * @dataProvider providePostTypeValues
	 */
	public function testPostTypeMapsToStatusWithWarnings( string $raw_type, string $expected_status, int $expected_downgraded, ?string $warning_needle ): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'typed-post' ] );

		update_post_meta( $post_id, '_pprredirect_active', '1' );
		update_post_meta( $post_id, '_pprredirect_url', '/target/' );
		update_post_meta( $post_id, '_pprredirect_type', $raw_type );

		$report = Migrator::migrate();

		static::assertSame( 1, $report['posts']['migrated'] );
		static::assertSame( $expected_status, get_post_meta( $post_id, PostMetaStore::META_STATUS, true ) );
		static::assertSame( $expected_downgraded, $report['posts']['meta_type_downgraded'] );

		if ( $warning_needle === null ) {
			static::assertSame( [], $report['warnings'], 'A recognized type must not warn' );
		} else {
			static::assertStringContainsString( $warning_needle, self::warnings( $report ) );
		}
	}

	// ---------------------------------------------------------------- idempotency

	public function testSecondRunSkipsEverythingAlreadyMigrated(): void {
		[ $active, $inactive ] = $this->seedLegacyFixtures();

		Migrator::migrate();
		$second = Migrator::migrate();

		static::assertSame(
			[ 'migrated' => 0, 'skipped_existing' => 3, 'dropped_meta' => 1 ],
			$second['quick']
		);
		static::assertSame( 0, $second['posts']['migrated'] );
		static::assertSame( 2, $second['posts']['skipped_existing'] );

		// No duplicates and no rewrites: rows and meta are byte-identical to run one.
		static::assertSame(
			[
				'/legacy-page/'      => [ 'to' => '/new-home/', 'status' => 301 ],
				'/about.htm'         => [ 'to' => 'https://example.com/about/', 'status' => 301 ],
				'/2024-press/?utm=x' => [ 'to' => '/press/', 'status' => 301 ],
			],
			get_option( 'pv_redirects' )
		);
		static::assertSame( '/target/', get_post_meta( $active, PostMetaStore::META_TO, true ) );
		static::assertSame( '302', get_post_meta( $active, PostMetaStore::META_STATUS, true ) );
		static::assertSame( '/old-target/', get_post_meta( $inactive, PostMetaStore::META_TO, true ) );
		static::assertSame( '', get_post_meta( $inactive, PostMetaStore::META_ACTIVE, true ) );
	}

	// ---------------------------------------------------------------- dry run

	public function testDryRunReportsWithoutWritingAnything(): void {
		[ $active, $inactive ] = $this->seedLegacyFixtures();

		$report = Migrator::migrate( [], [ 'dry_run' => true ] );

		static::assertTrue( $report['dry_run'] );
		static::assertFalse( $report['rebuilt'], 'A dry run must not rebuild the map' );

		// Counts still describe what WOULD migrate — identical to the real run's numbers.
		static::assertSame(
			[ 'migrated' => 3, 'skipped_existing' => 0, 'dropped_meta' => 1 ],
			$report['quick']
		);
		static::assertSame( 2, $report['posts']['migrated'] );
		static::assertSame( 1, $report['posts']['inactive_migrated'] );

		// Nothing written: no pv option, no rebuilt map, no PV post meta.
		static::assertFalse( get_option( 'pv_redirects' ) );
		static::assertFalse( get_option( 'pv_redirects_map' ) );
		static::assertFalse( metadata_exists( 'post', $active, PostMetaStore::META_TO ) );
		static::assertFalse( metadata_exists( 'post', $active, PostMetaStore::META_ACTIVE ) );
		static::assertFalse( metadata_exists( 'post', $inactive, PostMetaStore::META_TO ) );

		// Dropped features still surface so the operator sees them before committing.
		static::assertStringContainsString( 'open in new window', self::warnings( $report ) );
		static::assertStringContainsString( 'inactive in QPPR', self::warnings( $report ) );
	}

	// ---------------------------------------------------------------- delete-source

	public function testDeleteSourceRemovesOnlyWhatThisRunHandled(): void {
		[ $active, $inactive ] = $this->seedLegacyFixtures();

		// A post QPPR leaves with an active flag but no destination is never migrated,
		// so copy-on-migrate must not touch its meta either.
		$orphan = self::factory()->post->create( [ 'post_name' => 'legacy-orphan' ] );
		update_post_meta( $orphan, '_pprredirect_active', '1' );
		update_post_meta( $orphan, '_pprredirect_url', '' );

		update_option( 'ppr_override-casesensitive', '1' );
		update_option( 'ppr_override-URL', 'https://example.org/landing/' );

		$report = Migrator::migrate( [], [ 'delete_source' => true ] );

		// Every quick row was handled, so both parallel options drop wholesale.
		static::assertFalse( get_option( 'quickppr_redirects' ) );
		static::assertFalse( get_option( 'quickppr_redirects_meta' ) );

		// Mapped settings are deleted; the global-URL override never is.
		static::assertFalse( get_option( 'ppr_override-casesensitive' ) );
		static::assertSame( 'https://example.org/landing/', get_option( 'ppr_override-URL' ) );
		static::assertStringContainsString( 'global URL override', self::warnings( $report ) );

		// Migrated posts lose every QPPR meta key.
		foreach ( [ $active, $inactive ] as $post_id ) {
			foreach ( self::QPPR_POST_META_KEYS as $meta_key ) {
				static::assertFalse( metadata_exists( 'post', $post_id, $meta_key ) );
			}
		}

		// The orphan kept its legacy meta: it was not part of what this run copied.
		static::assertSame( '1', get_post_meta( $orphan, '_pprredirect_active', true ) );
		static::assertTrue( metadata_exists( 'post', $orphan, '_pprredirect_url' ) );
	}

	public function testDeleteSourceSkipsPostMetaUnderSkipPostsAndCleansFoundPostsLater(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'legacy-skip' ] );
		update_post_meta( $post_id, '_pprredirect_active', '1' );
		update_post_meta( $post_id, '_pprredirect_url', '/target/' );
		update_post_meta( $post_id, '_pprredirect_type', '301' );
		update_option( 'quickppr_redirects', [ '/only/' => '/new-home/' ] );

		// First run migrates everything but keeps the sources (copy-on-migrate).
		Migrator::migrate();

		// --skip-posts: quick options are cleaned, post meta stays untouched.
		$skipping = Migrator::migrate( [], [ 'delete_source' => true, 'skip_posts' => true ] );

		static::assertSame( 0, $skipping['posts']['migrated'] );
		static::assertFalse( get_option( 'quickppr_redirects' ) );
		static::assertSame( '/target/', get_post_meta( $post_id, '_pprredirect_url', true ) );
		static::assertSame( '1', get_post_meta( $post_id, '_pprredirect_active', true ) );

		// A later run without --skip-posts deletes the QPPR meta of the post it found
		// already migrated (skipped_existing still counts as handled).
		$later = Migrator::migrate( [], [ 'delete_source' => true ] );

		static::assertSame( 1, $later['posts']['skipped_existing'] );
		static::assertFalse( metadata_exists( 'post', $post_id, '_pprredirect_url' ) );
		static::assertFalse( metadata_exists( 'post', $post_id, '_pprredirect_active' ) );
	}

	// ---------------------------------------------------------------- settings mapping

	public function testSettingsMappingAppliesOverridesAndWarns(): void {
		update_option( 'ppr_override-casesensitive', '1' );
		update_option( 'ppr_override-active', '1' );

		$report = Migrator::migrate();

		static::assertSame( [ 'case_sensitive' => true, 'default_status' => null, 'disabled' => true ], $report['settings'] );
		static::assertTrue( Settings::get( 'case_sensitive' ) );
		static::assertFalse( Settings::get( 'enabled' ), 'ppr_override-active forces PV redirects disabled to match QPPR' );
		static::assertStringContainsString( 'ppr_override-active', self::warnings( $report ) );

		// The rebuilt map bakes the migrated settings in.
		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertTrue( $map['settings']['case_sensitive'] );
		static::assertFalse( $map['settings']['enabled'] );
	}

	/**
	 * @return iterable<string, array{string, int, string|null}>
	 */
	public static function provideGlobalTypes(): iterable {
		yield 'numeric 302 becomes default status' => [ '302', 302, null ];

		yield 'meta becomes 301 with warning' => [ 'meta', 301, 'meta refresh' ];
	}

	/**
	 * @dataProvider provideGlobalTypes
	 */
	public function testGlobalRedirectTypeBecomesDefaultStatusAndQuickRowStatus( string $global_type, int $expected_status, ?string $warning_needle ): void {
		update_option( 'ppr_override-redirect-type', $global_type );
		update_option( 'quickppr_redirects', [ '/legacy/' => '/new-home/' ] );

		$report = Migrator::migrate();

		static::assertSame( $expected_status, $report['settings']['default_status'] );
		static::assertSame( $expected_status, Settings::get( 'default_status' ) );

		// The global type resolved first is the status of migrated quick rows.
		static::assertSame(
			[ '/legacy/' => [ 'to' => '/new-home/', 'status' => $expected_status ] ],
			get_option( 'pv_redirects' )
		);

		if ( $warning_needle === null ) {
			static::assertSame( [], $report['warnings'] );
		} else {
			static::assertStringContainsString( $warning_needle, self::warnings( $report ) );
		}
	}

	public function testSettingsStayUntouchedWhenNoQpprSettingsExist(): void {
		update_option( 'quickppr_redirects', [ '/legacy/' => '/new-home/' ] );

		$report = Migrator::migrate();

		static::assertSame( [ 'case_sensitive' => null, 'default_status' => null, 'disabled' => false ], $report['settings'] );
		static::assertFalse( get_option( 'pv_redirects_settings' ), 'No QPPR settings means the PV settings option is never created' );
	}

	// ---------------------------------------------------------------- wildcards

	public function testWildcardQuickRowLandsCompiledInMapWildcards(): void {
		update_option( 'quickppr_redirects', [ '/old-blog/*' => '/new-blog/*' ] );

		Migrator::migrate();

		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertCount( 1, $map['wildcards'] );

		$rule = $map['wildcards'][0];

		static::assertSame( '/new-blog/*', $rule['to'] );
		static::assertSame( 301, $rule['status'] );
		static::assertSame( 'o', $rule['src'] );
		static::assertSame( 1, preg_match( $rule['regex'], '/old-blog/x/y' ), 'A trailing /* must match the entire remaining path including slashes' );
		static::assertSame( 0, preg_match( $rule['regex'], '/old-blog/' ), 'The rest capture requires a non-empty remainder' );

		// The wildcard lives in the canonical store like any quick row.
		static::assertSame( [ '/old-blog/*' => [ 'to' => '/new-blog/*', 'status' => 301 ] ], get_option( 'pv_redirects' ) );
	}

	// ---------------------------------------------------------------- empty state

	public function testEmptySourceProducesAnAllZeroReport(): void {
		$report = Migrator::migrate();

		static::assertSame( [ 'migrated' => 0, 'skipped_existing' => 0, 'dropped_meta' => 0 ], $report['quick'] );
		static::assertSame(
			[
				'migrated'             => 0,
				'skipped_existing'     => 0,
				'inactive_migrated'    => 0,
				'meta_type_downgraded' => 0,
				'dropped_features'     => 0,
			],
			$report['posts']
		);
		static::assertSame( [ 'case_sensitive' => null, 'default_status' => null, 'disabled' => false ], $report['settings'] );
		static::assertSame( [], $report['warnings'] );
		static::assertFalse( $report['dry_run'] );
		static::assertTrue( $report['rebuilt'] );
		static::assertFalse( get_option( 'pv_redirects' ), 'No quick rows means the pv_redirects option is never created' );

		// The map still rebuilds — to an empty v1 snapshot.
		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertSame( 1, $map['v'] );
		static::assertSame( [], $map['exact'] );
		static::assertSame( [], $map['wildcards'] );
		static::assertSame( [], $map['posts'] );
	}

	// ---------------------------------------------------------------- fixtures & helpers

	/**
	 * The canonical legacy fixture set: three quick rows (internal, external, and
	 * query-string-keyed destinations), one dropped-feature meta row, one active and one
	 * inactive per-post redirect. Returns the created post IDs.
	 *
	 * @return array{int, int}
	 */
	private function seedLegacyFixtures(): array {
		update_option( 'quickppr_redirects', [
			'/legacy-page/'      => '/new-home/',
			'/about.htm'         => 'https://example.com/about/',
			'/2024-press/?utm=x' => '/press/',
		] );

		update_option( 'quickppr_redirects_meta', [
			'/legacy-page/' => [ 'newwindow' => 1, 'nofollow' => 0 ],
		] );

		$active   = self::factory()->post->create( [ 'post_name' => 'legacy-active' ] );
		$inactive = self::factory()->post->create( [ 'post_name' => 'legacy-inactive' ] );

		update_post_meta( $active, '_pprredirect_active', '1' );
		update_post_meta( $active, '_pprredirect_url', '/target/' );
		update_post_meta( $active, '_pprredirect_type', '302' );

		update_post_meta( $inactive, '_pprredirect_active', '' );
		update_post_meta( $inactive, '_pprredirect_url', '/old-target/' );
		update_post_meta( $inactive, '_pprredirect_type', '301' );

		return [ $active, $inactive ];
	}

	/**
	 * Join a report's warnings into one string so needles can be asserted with
	 * assertStringContainsString regardless of warning order.
	 */
	private static function warnings( array $report ): string {
		return implode( "\n", $report['warnings'] );
	}
}
