<?php

namespace PV\Redirects\Tests;

use InvalidArgumentException;
use PV\Redirects\OptionStore;
use PV\Redirects\RedirectMap;
use PV\Redirects\Settings;

use function add_filter;
use function get_option;
use function remove_filter;
use function update_option;

/**
 * Settings read/write tests: defaults, normalization, the pv/redirects/settings filter,
 * and update()'s bool casting + status validation + map rebuild.
 */
final class SettingsTest extends TestCase {

	public function testDefaultsOnCleanInstall(): void {
		static::assertSame( [
			'enabled'        => true,
			'case_sensitive' => false,
			'preserve_qs'    => true,
			'default_status' => 301,
		], Settings::all() );
	}

	public function testStoredValuesAreNormalizedOverDefaults(): void {
		update_option( 'pv_redirects_settings', [
			'enabled'        => '0',
			'preserve_qs'    => 1,
			'default_status' => '302',
			'unknown'        => 'dropped',
		] );

		static::assertSame( [
			'enabled'        => false,
			'case_sensitive' => false,
			'preserve_qs'    => true,
			'default_status' => 302,
		], Settings::all() );
		static::assertNull( Settings::get( 'unknown' ) );
	}

	public function testFilterOverridesValues(): void {
		$filter = static function ( array $settings ): array {
			$settings['enabled']        = false;
			$settings['default_status'] = 308;

			return $settings;
		};
		add_filter( 'pv/redirects/settings', $filter );

		try {
			static::assertFalse( Settings::get( 'enabled' ) );
			static::assertSame( 308, Settings::get( 'default_status' ) );
		} finally {
			remove_filter( 'pv/redirects/settings', $filter );
		}
	}

	public function testFilterReturningNonArrayFallsBackToStored(): void {
		update_option( 'pv_redirects_settings', [ 'enabled' => '0' ] );

		$filter = static fn ( mixed $value ): mixed => 'not-an-array';
		add_filter( 'pv/redirects/settings', $filter );

		try {
			static::assertFalse( Settings::get( 'enabled' ) );
		} finally {
			remove_filter( 'pv/redirects/settings', $filter );
		}
	}

	public function testGetUnknownKeyReturnsNull(): void {
		static::assertNull( Settings::get( 'nope' ) );
	}

	public function testUpdateCastsBooleans(): void {
		Settings::update( [
			'enabled'        => 0,
			'case_sensitive' => '1',
			'preserve_qs'    => 'yes',
		] );

		static::assertFalse( Settings::get( 'enabled' ) );
		static::assertTrue( Settings::get( 'case_sensitive' ) );
		static::assertTrue( Settings::get( 'preserve_qs' ) );
		static::assertSame( [
			'enabled'        => false,
			'case_sensitive' => true,
			'preserve_qs'    => true,
			'default_status' => 301,
		], get_option( 'pv_redirects_settings' ) );
	}

	public function testUpdateNullBooleanKeepsCurrent(): void {
		Settings::update( [ 'enabled' => null ] );

		static::assertTrue( Settings::get( 'enabled' ) );
	}

	public function testUpdateAcceptsStringStatus(): void {
		Settings::update( [ 'default_status' => '307' ] );

		static::assertSame( 307, Settings::get( 'default_status' ) );
	}

	public function testUpdateRejectsInvalidStatus(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid default_status' );

		Settings::update( [ 'default_status' => 200 ] );
	}

	public function testUpdateRebuildsMapWithNewSettingsSnapshot(): void {
		// Stored with the user's casing; the map folds it while case_sensitive is off.
		OptionStore::set( '/A/', '/b/', 301 );
		$before = RedirectMap::get();

		static::assertArrayHasKey( '/a/', $before['exact'] );
		static::assertArrayNotHasKey( '/A/', $before['exact'] );

		Settings::update( [ 'case_sensitive' => 'true' ] );

		$map = RedirectMap::get();

		static::assertTrue( $map['settings']['case_sensitive'], 'Settings::update must rebuild the map' );
		static::assertArrayHasKey( '/A/', $map['exact'], 'Exact keys must be rebuilt unfolded' );
		static::assertArrayNotHasKey( '/a/', $map['exact'] );
	}
}
