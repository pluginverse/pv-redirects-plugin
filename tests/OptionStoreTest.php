<?php

namespace PV\Redirects\Tests;

use InvalidArgumentException;
use PV\Redirects\OptionStore;

use function delete_option;
use function get_option;
use function str_repeat;
use function update_option;

/**
 * Integration tests for the canonical quick-redirect option store. Contract notes taken
 * from the code: request keys are stored user-entered (trimmed, leading slash ensured,
 * NO case folding), and every mutation writes through the pre-save filter and rebuilds
 * the computed map.
 */
final class OptionStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		delete_option( 'pv_redirects' );
		delete_option( 'pv_redirects_map' );
	}

	public function testSetGetRoundtripNormalizesLeadingSlashAndTrim(): void {
		OptionStore::set( 'foo', '/bar/', 301 );

		static::assertSame( [ 'to' => '/bar/', 'status' => 301 ], OptionStore::get( '/foo' ) );
		static::assertSame( [ 'to' => '/bar/', 'status' => 301 ], OptionStore::get( ' foo ' ) );
	}

	public function testKeysAreStoredAsTypedWithoutCaseFolding(): void {
		OptionStore::set( '/Old-Page/', '/new/', 302 );

		static::assertSame(
			[ '/Old-Page/' => [ 'to' => '/new/', 'status' => 302 ] ],
			get_option( 'pv_redirects' )
		);
		static::assertSame( [ 'to' => '/new/', 'status' => 302 ], OptionStore::get( '/Old-Page/' ) );
		static::assertNull( OptionStore::get( '/old-page/' ), 'The store never case-folds keys' );
	}

	public function testSetRejectsInvalidStatus(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '301, 302, 307, 308' );

		OptionStore::set( '/x/', '/y/', 200 );
	}

	public function testSetRejectsRequestWithTooManyWildcards(): void {
		// Eight '*' is the cap (Matcher::MAX_WILDCARD_STARS); nine must throw so a
		// pathological pattern never compiles into the map.
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'wildcard pattern may contain at most 8' );

		OptionStore::set( '/a/*/*/*/*/*/*/*/*/*', '/y/', 301 );
	}

	public function testSetAcceptsRequestAtWildcardCap(): void {
		OptionStore::set( '/a/*/*/*/*/*/*/*/*', '/y/', 301 );

		static::assertSame( [ 'to' => '/y/', 'status' => 301 ], OptionStore::get( '/a/*/*/*/*/*/*/*/*' ) );
	}

	public function testSetRejectsDestinationWithControlCharacters(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'control characters' );

		OptionStore::set( '/x/', "/y/\r\nHeader: injected", 301 );
	}

	public function testSetRejectsDestinationLongerThanCap(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'longer than 2048' );

		OptionStore::set( '/x/', '/'.str_repeat( 'a', 2048 ), 301 );
	}

	public function testDeleteMissingReturnsFalse(): void {
		static::assertFalse( OptionStore::delete( '/missing/' ) );
		static::assertFalse( OptionStore::delete( 'missing' ) );
	}

	public function testDeleteRemovesRowAndRebuildsMap(): void {
		OptionStore::set( '/a/', '/b/', 301 );
		static::assertTrue( OptionStore::delete( '/a/' ) );

		static::assertSame( [], OptionStore::all() );

		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertSame( [], $map['exact'], 'Deleting must rebuild the map without the row' );
	}

	public function testSetRebuildsComputedMap(): void {
		OptionStore::set( '/foo', '/bar/', 302 );

		$map = get_option( 'pv_redirects_map' );

		static::assertIsArray( $map );
		static::assertSame( 3, $map['v'], 'A store mutation must produce a v3 map' );
		static::assertSame( [ 'to' => '/bar/', 'status' => 302, 'src' => 'o' ], $map['exact']['/foo'] );
	}

	public function testAllSkipsStructurallyInvalidRows(): void {
		update_option( 'pv_redirects', [
			'ok'     => [ 'to' => '/x/', 'status' => '301' ],
			'badto'  => [ 'to' => 123, 'status' => 301 ],
			'badst'  => [ 'to' => '/y/', 'status' => 999 ],
			'badrow' => 'nope',
			5        => [ 'to' => '/z/', 'status' => 301 ],
		] );

		static::assertSame( [ 'ok' => [ 'to' => '/x/', 'status' => 301 ] ], OptionStore::all() );
	}
}
