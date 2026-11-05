<?php

namespace PV\Redirects\Tests;

use PV\Redirects\OptionStore;
use PV\Redirects\RedirectMap;
use PV\Redirects\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTest_Factory;

use function wp_set_current_user;

/**
 * REST contract tests through the in-process REST server (rest_get_server(); no HTTP).
 * Success bodies keep the `{ success, data }` envelope and the payload is asserted here;
 * every error carries the WP_Error JSON contract — top-level `code`, human `message`,
 * and `data.status` — whether this WP returns it as WP_Error or wrapped in
 * WP_REST_Response. Every mutation must leave `pv_redirects_map` rebuilt.
 */
final class RestControllerTest extends TestCase {

	private static int $admin_id = 0;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
	}

	public static function wpTearDownAfterClass(): void {
		self::delete_user( self::$admin_id );
	}

	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::$admin_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ---------------------------------------------------------------- list

	public function testListEmptyReturnsPagedEnvelope(): void {
		$data = $this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/redirects' ),
			200
		);

		static::assertSame( [], $data['redirects'] );
		static::assertSame( 0, $data['total'] );
		static::assertSame( 1, $data['page'] );
		static::assertSame( 50, $data['per_page'] );
	}

	public function testListReturnsSeededRowsAndSlicesPages(): void {
		OptionStore::set( '/one/', '/first/', 301 );
		OptionStore::set( '/two/', '/second/', 302 );
		OptionStore::set( '/three/', '/third/', 308 );

		$data = $this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/redirects', [
				'page'     => 2,
				'per_page' => 2,
			] ),
			200
		);

		static::assertSame( 3, $data['total'] );
		static::assertSame( 2, $data['page'] );
		static::assertSame( 2, $data['per_page'] );
		static::assertSame( [ '/three/' ], array_keys( $data['redirects'] ), 'Page two holds the third seeded row only' );
		static::assertSame( [ 'to' => '/third/', 'status' => 308 ], $data['redirects']['/three/'] );
	}

	// ---------------------------------------------------------------- create

	public function testCreateReturns201WithRowAndRebuildsMap(): void {
		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/redirects', [
				'request' => '/new-page',
				'to'      => '/existing-page',
				'status'  => 301,
			] ),
			201,
			[
				'request' => '/new-page',
				'to'      => '/existing-page',
				'status'  => 301,
			]
		);

		$row = OptionStore::get( '/new-page' );

		static::assertNotNull( $row );
		static::assertSame( '/existing-page', $row['to'] );
		static::assertSame( 301, $row['status'] );

		static::assertSame(
			[ 'to' => '/existing-page', 'status' => 301, 'src' => 'o' ],
			RedirectMap::get()['exact']['/new-page'] ?? null,
			'Creating via REST must rebuild the computed map'
		);
	}

	public function testCreateMissingFieldsReturnsMissingParamError(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/redirects', [
				'request' => '/incomplete',
			] ),
			'rest_missing_callback_param',
			400
		);

		static::assertNull( OptionStore::get( '/incomplete' ), 'A rejected create must not store a row' );
	}

	public function testCreateBadStatusReturnsPluginInvalidStatusError(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/redirects', [
				'request' => '/x',
				'to'      => '/y',
				'status'  => 500,
			] ),
			'pv_redirects_invalid_status',
			400
		);

		static::assertNull( OptionStore::get( '/x' ), 'A rejected create must not store a row' );
		static::assertSame( [], RedirectMap::get()['exact'] ?? [], 'A rejected create must not touch the map' );
	}

	public function testCreateTooManyWildcardsReturnsInvalidRequestError(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/redirects', [
				'request' => '/a/*/*/*/*/*/*/*/*/*',
				'to'      => '/y',
				'status'  => 301,
			] ),
			'pv_redirects_invalid_request',
			400
		);

		static::assertNull( OptionStore::get( '/a/*/*/*/*/*/*/*/*/*' ), 'A rejected create must not store a row' );
	}

	public function testUpdateTooManyWildcardsReturnsInvalidRequestError(): void {
		OptionStore::set( '/old', '/a', 301 );

		$this->assertErrorResponse(
			$this->dispatch( 'PUT', '/pv-redirects/v1/redirects/old', [
				// Route-captured keys arrive percent-encoded; nine '*' exceeds the cap either way.
				'to'     => '/b',
				'status' => 301,
				'request' => '/a/*/*/*/*/*/*/*/*/*',
			] ),
			'pv_redirects_invalid_request',
			400
		);
	}

	public function testCreateControlCharacterDestinationReturnsInvalidDestinationError(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/redirects', [
				'request' => '/x',
				'to'      => "/y\r\nX-Injected: yes",
				'status'  => 301,
			] ),
			'pv_redirects_invalid_destination',
			400
		);

		static::assertNull( OptionStore::get( '/x' ), 'A rejected create must not store a row' );
	}

	// ---------------------------------------------------------------- get

	public function testGetReturnsStoredRow(): void {
		OptionStore::set( '/old-page', '/new-page', 302 );

		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/redirects/old-page' ),
			200,
			[
				'to'     => '/new-page',
				'status' => 302,
			]
		);
	}

	public function testGetDecodesPercentEncodedRequestKey(): void {
		OptionStore::set( '/nested/path/', '/landing/', 301 );

		$route = '/pv-redirects/v1/redirects/'.rawurlencode( '/nested/path/' );

		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, $route ),
			200,
			[
				'to'     => '/landing/',
				'status' => 301,
			]
		);
	}

	public function testGetUnknownRequestKeyReturnsNotFound(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/redirects/nope' ),
			'pv_redirects_not_found',
			404
		);
	}

	// ---------------------------------------------------------------- update

	public function testUpdateReplacesRowAndRebuildsMap(): void {
		OptionStore::set( '/old', '/a', 301 );

		$this->assertSuccessResponse(
			$this->dispatch( 'PUT', '/pv-redirects/v1/redirects/old', [
				'to'     => '/b',
				'status' => 307,
			] ),
			200,
			[
				'request' => 'old',
				'to'      => '/b',
				'status'  => 307,
			]
		);

		static::assertSame(
			[ 'to' => '/b', 'status' => 307, 'src' => 'o' ],
			RedirectMap::get()['exact']['/old'] ?? null,
			'Updating via REST must rebuild the computed map'
		);
	}

	public function testUpdateUpsertsUnknownKey(): void {
		$this->assertSuccessResponse(
			$this->dispatch( 'PUT', '/pv-redirects/v1/redirects/added-via-put', [
				'to'     => '/dest',
				'status' => 308,
			] ),
			200
		);

		static::assertNotNull( OptionStore::get( '/added-via-put' ), 'PUT upserts by request key' );
	}

	public function testUpdateBadStatusReturnsPluginInvalidStatusErrorAndKeepsRow(): void {
		OptionStore::set( '/old', '/a', 301 );

		$this->assertErrorResponse(
			$this->dispatch( 'PUT', '/pv-redirects/v1/redirects/old', [
				'to'     => '/b',
				'status' => 999,
			] ),
			'pv_redirects_invalid_status',
			400
		);

		static::assertSame( '/a', OptionStore::get( '/old' )['to'] ?? null, 'A rejected update must leave the stored row untouched' );
	}

	// ---------------------------------------------------------------- delete

	public function testDeleteRemovesRowAndRebuildsMap(): void {
		OptionStore::set( '/gone', '/somewhere', 301 );

		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::DELETABLE, '/pv-redirects/v1/redirects/gone' ),
			200,
			[
				'request' => 'gone',
				'deleted' => true,
			]
		);

		static::assertNull( OptionStore::get( '/gone' ) );
		static::assertSame( [], RedirectMap::get()['exact'] ?? [], 'Deleting via REST must rebuild the computed map' );
	}

	public function testDeleteUnknownRequestKeyReturnsNotFound(): void {
		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::DELETABLE, '/pv-redirects/v1/redirects/never-there' ),
			'pv_redirects_not_found',
			404
		);
	}

	// ---------------------------------------------------------------- settings

	public function testSettingsRoundtripRebuildsMapSnapshot(): void {
		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/settings' ),
			200,
			[
				'enabled'        => true,
				'case_sensitive' => false,
				'preserve_qs'    => true,
				'default_status' => 301,
			]
		);

		$saved = $this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/settings', [
				'settings' => [
					'enabled'        => false,
					'default_status' => 302,
				],
			] ),
			200
		);

		static::assertFalse( $saved['enabled'] );
		static::assertSame( 302, $saved['default_status'] );
		static::assertTrue( $saved['preserve_qs'], 'POST /settings merges the partial object over current values' );

		$this->assertSuccessResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/settings' ),
			200,
			[
				'enabled'        => false,
				'default_status' => 302,
			]
		);

		static::assertFalse( RedirectMap::get()['settings']['enabled'] ?? true, 'Saving settings must rebuild the map snapshot' );
	}

	public function testSettingsInvalidDefaultStatusReturnsInvalidSettingsError(): void {
		OptionStore::set( '/seed', '/x', 301 );

		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/settings', [
				'settings' => [
					'default_status' => 500,
				],
			] ),
			'pv_redirects_invalid_settings',
			400
		);

		static::assertSame( 301, Settings::all()['default_status'], 'A rejected settings write must not change stored values' );
		static::assertSame( 301, RedirectMap::get()['settings']['default_status'] ?? null, 'A rejected settings write must not rebuild the map' );
		static::assertNotNull( RedirectMap::get()['exact']['/seed'] ?? null, 'A rejected settings write must leave the map rows intact' );
	}

	// ---------------------------------------------------------------- permission

	public function testUnauthenticatedRequestsAreRejectedWith401(): void {
		wp_set_current_user( 0 );

		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::READABLE, '/pv-redirects/v1/redirects' ),
			'rest_forbidden',
			401
		);

		$this->assertErrorResponse(
			$this->dispatch( WP_REST_Server::CREATABLE, '/pv-redirects/v1/settings', [
				'settings' => [ 'enabled' => false ],
			] ),
			'rest_forbidden',
			401
		);
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Dispatch one request through the in-process REST server.
	 *
	 * @param  string               $method HTTP method. WP_REST_Server::EDITABLE is a
	 *                                      comma list ('PATCH, PUT, POST') and must not
	 *                                      be used here — WP_REST_Request expects one verb.
	 * @param  string               $route  Full route, e.g. `/pv-redirects/v1/redirects`.
	 * @param  array<string, mixed> $params Request parameters.
	 *
	 * @return WP_REST_Response|WP_Error The server's dispatch result.
	 */
	private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Assert a success envelope: status code plus `{ success: true, data }`, with the
	 * given key/value pairs asserted inside the `data` payload. Returns the payload —
	 * the same object api.js consumers receive after the client unwraps the envelope.
	 *
	 * @param  array<string, mixed> $expected_data
	 *
	 * @return array<string, mixed>
	 */
	private function assertSuccessResponse( WP_REST_Response|WP_Error $response, int $status, array $expected_data = [] ): array {
		static::assertInstanceOf( WP_REST_Response::class, $response );
		static::assertSame( $status, $response->get_status() );

		$data = $response->get_data();

		static::assertIsArray( $data );
		static::assertSame( true, $data['success'] ?? null, 'Success bodies must carry the { success, data } envelope' );

		$payload = $data['data'] ?? null;

		static::assertIsArray( $payload, 'Success bodies must carry the payload under data' );

		foreach ( $expected_data as $key => $value ) {
			static::assertSame( $value, $payload[ $key ] ?? null, "Response data['{$key}']" );
		}

		return $payload;
	}

	/**
	 * Assert the WP_Error JSON contract — top-level `code`, human `message`, and
	 * `data.status` — accepting both shapes dispatch() produces across WP versions:
	 * a bare WP_Error or a WP_REST_Response wrapping the error payload.
	 */
	private function assertErrorResponse( WP_REST_Response|WP_Error $response, string $code, int $status ): void {
		if ( $response instanceof WP_Error ) {
			static::assertSame( $code, $response->get_error_code() );
			static::assertSame( $status, $response->get_error_data( 'status' ) );
			static::assertNotSame( '', $response->get_error_message(), 'Errors must carry a human message' );

			return;
		}

		static::assertInstanceOf( WP_REST_Response::class, $response );
		static::assertSame( $status, $response->get_status() );

		$data = $response->get_data();

		static::assertIsArray( $data );
		static::assertSame( $code, $data['code'] ?? null );
		static::assertSame( $status, $data['data']['status'] ?? null );
		static::assertNotSame( '', $data['message'] ?? '', 'Errors must carry a human message' );
	}
}
