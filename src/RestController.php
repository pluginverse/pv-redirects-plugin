<?php

namespace PV\Redirects;

use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function current_user_can;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function rawurldecode;
use function register_rest_route;
use function sprintf;

/**
 * REST API backing the admin app: namespace `pv-redirects/v1`, `manage_options` on every
 * route. Success bodies keep the `{ success, data }` envelope; errors are standard
 * WP_Error responses (top-level `code`, `message`, `data.status`) with `pv_redirects_*`
 * codes, so WP REST clients read them natively. Write routes need no explicit rebuild —
 * the stores rebuild the computed map internally on every mutation.
 */
final class RestController {

	private const API_NAMESPACE = 'pv-redirects/v1';

	/**
	 * `rest_api_init` hook: register all routes.
	 */
	public static function registerRoutes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/redirects',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => '\PV\Redirects\RestController::listRedirects',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => [
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 500,
						],
					],
				],
				[
					'methods'             => 'PUT',
					'callback'            => '\PV\Redirects\RestController::updateCollectionRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => self::requestArg() + [
						'to'     => [ 'type' => 'string', 'required' => true ],
						'status' => [ 'type' => 'integer', 'required' => true ],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => '\PV\Redirects\RestController::deleteCollectionRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => self::requestArg(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => '\PV\Redirects\RestController::createRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => [
						'request' => [
							'type'     => 'string',
							'required' => true,
						],
						'to'      => [
							'type'     => 'string',
							'required' => true,
						],
						// No enum: a bad status reaches the controller so the error contract
						// stays pv_redirects_invalid_status (400) across entry points.
						'status'  => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/redirects/(?P<request>.+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => '\PV\Redirects\RestController::getRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => self::requestArg(),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => '\PV\Redirects\RestController::updateRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => self::requestArg() + [
						'to'     => [
							'type'     => 'string',
							'required' => true,
						],
						// No enum: see the POST /redirects status arg.
						'status' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => '\PV\Redirects\RestController::deleteRedirect',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => self::requestArg(),
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => '\PV\Redirects\RestController::getSettings',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => '\PV\Redirects\RestController::updateSettings',
					'permission_callback' => '\PV\Redirects\RestController::canManage',
					'args'                => [
						'settings' => [
							'type'     => 'object',
							'required' => true,
						],
					],
				],
			]
		);
	}

	/**
	 * GET /redirects — paged list.
	 */
	public static function listRedirects( WP_REST_Request $request ): WP_REST_Response {
		$rows     = OptionStore::all();
		$page     = self::positiveInt( $request->get_param( 'page' ), 1 );
		$per_page = self::positiveInt( $request->get_param( 'per_page' ), 50 );

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => [
					'redirects' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page, true ),
					'total'     => count( $rows ),
					'page'      => $page,
					'per_page'  => $per_page,
				],
			],
			200
		);
	}

	/**
	 * POST /redirects — create (201; 400 pv_redirects_invalid_status on a bad status).
	 */
	public static function createRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::upsert( self::stringParam( $request, 'request' ), $request, 201 );
	}

	/** PUT /redirects — upsert using the literal request key in the body. */
	public static function updateCollectionRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::upsert( self::stringParam( $request, 'request' ), $request, 200 );
	}

	/** DELETE /redirects — delete using the literal request key in the body. */
	public static function deleteCollectionRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::deleteKey( self::stringParam( $request, 'request' ) );
	}

	/**
	 * GET /redirects/{request} — 404 when the key is unknown.
	 */
	public static function getRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = OptionStore::get( self::requestParam( $request ) );

		if ( $row === null ) {
			return self::error( 'pv_redirects_not_found', __( 'Redirect not found.', 'pv-redirects' ), 404 );
		}

		return new WP_REST_Response( [ 'success' => true, 'data' => $row ], 200 );
	}

	/**
	 * PUT /redirects/{request} — upsert by request key.
	 */
	public static function updateRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::upsert( self::requestParam( $request ), $request, 200 );
	}

	/**
	 * DELETE /redirects/{request} — 404 when the key is unknown.
	 */
	public static function deleteRedirect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::deleteKey( self::requestParam( $request ) );
	}

	private static function deleteKey( string $request_key ): WP_REST_Response|WP_Error {
		if ( ! OptionStore::delete( $request_key ) ) {
			return self::error( 'pv_redirects_not_found', __( 'Redirect not found.', 'pv-redirects' ), 404 );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => [ 'request' => $request_key, 'deleted' => true ],
			],
			200
		);
	}

	/**
	 * GET /settings — current settings merged over defaults.
	 */
	public static function getSettings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => Settings::all(),
			],
			200
		);
	}

	/**
	 * POST /settings — merge a partial settings object and rebuild the map.
	 */
	public static function updateSettings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$partial = $request->get_param( 'settings' );

		if ( ! is_array( $partial ) ) {
			return self::error( 'pv_redirects_invalid_settings', __( 'Settings must be an object.', 'pv-redirects' ), 400 );
		}

		try {
			Settings::update( $partial );
		} catch ( InvalidArgumentException ) {
			return self::error( 'pv_redirects_invalid_settings', __( 'Invalid settings value.', 'pv-redirects' ), 400 );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => Settings::all(),
			],
			200
		);
	}

	public static function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create/update one redirect row. Each validation failure maps to its own 400
	 * pv_redirects_* code: invalid_status, invalid_request (wildcard cap), or
	 * invalid_destination (control characters / length).
	 */
	private static function upsert( string $request_key, WP_REST_Request $request, int $status_code ): WP_REST_Response|WP_Error {
		$to     = self::stringParam( $request, 'to' );
		$status = self::intParam( $request, 'status', 0 );

		$error = self::upsertValidationError( $request_key, $to, $status );

		if ( $error !== null ) {
			return $error;
		}

		OptionStore::set( $request_key, $to, $status );

		$row = OptionStore::get( $request_key ) ?? [ 'to' => $to, 'status' => $status ];

		return new WP_REST_Response(
			[
				'success' => true,
				'data'    => [
					'request' => $request_key,
					'to'      => $row['to'],
					'status'  => $row['status'],
				],
			],
			$status_code
		);
	}

	/**
	 * Validate one create/update payload; null when it passes. The checks delegate to
	 * OptionStore (the same rules every entry point enforces), and each failure mode gets
	 * its own pv_redirects_* code so REST clients can distinguish them.
	 */
	private static function upsertValidationError( string $request_key, string $to, int $status ): ?WP_Error {
		if ( ! in_array( $status, OptionStore::STATUSES, true ) ) {
			return self::error(
				'pv_redirects_invalid_status',
				__( 'Invalid redirect status; expected one of: 301, 302, 307, 308.', 'pv-redirects' ),
				400
			);
		}

		try {
			OptionStore::assertValidRequestKey( $request_key );
		} catch ( InvalidArgumentException ) {
			return self::error(
				'pv_redirects_invalid_request',
				sprintf(
					/* translators: %d: maximum number of "*" wildcards. */
					__( 'Invalid redirect request; a wildcard pattern may contain at most %1$d "*".', 'pv-redirects' ),
					Matcher::MAX_WILDCARD_STARS
				),
				400
			);
		}

		try {
			OptionStore::assertValidDestination( $to );
		} catch ( InvalidArgumentException ) {
			return self::error(
				'pv_redirects_invalid_destination',
				__( 'Invalid redirect destination; control characters and destinations longer than 2048 characters are not allowed.', 'pv-redirects' ),
				400
			);
		}

		return null;
	}

	/**
	 * The URL-captured request key. WP hands callbacks the raw, still percent-encoded regex
	 * match (available through get_param() — URL params are part of the request's parameter
	 * set), so it is decoded here — with rawurldecode so literal '+' in keys survives
	 * (urldecode would turn it into a space).
	 */
	private static function requestParam( WP_REST_Request $request ): string {
		$raw = $request->get_param( 'request' );

		return is_string( $raw ) ? rawurldecode( $raw ) : '';
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function requestArg(): array {
		return [
			'request' => [
				'type'     => 'string',
				'required' => true,
			],
		];
	}

	private static function stringParam( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? $value : '';
	}

	private static function intParam( WP_REST_Request $request, string $key, int $default ): int {
		$value = $request->get_param( $key );

		return is_int( $value ) ? $value : $default;
	}

	private static function positiveInt( mixed $value, int $default ): int {
		return is_int( $value ) && $value >= 1 ? $value : $default;
	}

	/**
	 * One error in the standard WP_Error shape: the server renders top-level
	 * `code`, `message`, and `data` (with the HTTP `status`) natively.
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
