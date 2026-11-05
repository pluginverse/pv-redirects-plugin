<?php

namespace PV\Redirects;

use InvalidArgumentException;

use function apply_filters;
use function array_key_exists;
use function ctype_digit;
use function get_option;
use function in_array;
use function is_array;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr_count;
use function trim;
use function update_option;

/**
 * Canonical storage for quick redirects: option `pv_redirects` (autoload yes), keyed by the
 * user-entered request path with a per-row status. The option is never read by the front
 * end — every mutation rebuilds the computed map (see RedirectMap).
 */
final class OptionStore {

	public const STATUSES = [ 301, 302, 307, 308 ];

	/**
	 * Destinations are emitted into Location headers and anchor hrefs; anything longer can
	 * never be matched against a request path usefully and only bloats the map.
	 */
	private const MAX_DESTINATION_LENGTH = 2048;

	private const OPTION = 'pv_redirects';

	/**
	 * All stored redirects keyed by normalized request. Rows that are structurally invalid
	 * (non-string 'to', out-of-range status) are skipped here but never erased from the
	 * option until overwritten or deleted.
	 *
	 * @return array<string, array{to: string, status: int}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$rows = [];

		foreach ( $stored as $request => $row ) {
			if ( ! is_string( $request ) || ! is_array( $row ) ) {
				continue;
			}

			$sanitized = self::sanitizeRow( $row );

			if ( $sanitized === null ) {
				continue;
			}

			$rows[$request] = $sanitized;
		}

		return $rows;
	}

	/**
	 * One redirect by request key (normalized the same way set() stores it), or null.
	 *
	 * @return array{to: string, status: int}|null
	 */
	public static function get( string $request ): ?array {
		return self::all()[self::normalizeRequest( $request )] ?? null;
	}

	/**
	 * Create or update one redirect. Request keys are stored user-entered: trimmed, with a
	 * leading '/' ensured, otherwise kept as typed (no case folding, no slash munging).
	 *
	 * @throws InvalidArgumentException On a bad status, a pattern past the wildcard cap,
	 *                                  or an invalid destination.
	 */
	public static function set( string $request, string $to, int $status ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid redirect status %d; expected one of: 301, 302, 307, 308.', $status )
			);
		}

		self::assertValidRequestKey( $request );
		self::assertValidDestination( $to );

		$request          = self::normalizeRequest( $request );
		$stored           = get_option( self::OPTION, [] );
		$stored           = is_array( $stored ) ? $stored : [];
		$stored[$request] = [ 'to' => $to, 'status' => $status ];

		self::save( $stored );
	}

	/**
	 * Reject request patterns with more than Matcher::MAX_WILDCARD_STARS wildcard tokens:
	 * each token becomes a regex capture group, and past the cap a pattern is pathological
	 * rather than useful (ReDoS guard). Matcher::compileWildcard never-matches past the
	 * same cap, so legacy/migrated data that skips this store stays bounded too.
	 *
	 * @throws InvalidArgumentException When the pattern carries too many wildcards.
	 */
	public static function assertValidRequestKey( string $request ): void {
		if ( substr_count( $request, '*' ) > Matcher::MAX_WILDCARD_STARS ) {
			throw new InvalidArgumentException(
				sprintf(
					'Invalid redirect request "%1$s"; a wildcard pattern may contain at most %2$d "*".',
					$request,
					Matcher::MAX_WILDCARD_STARS
				)
			);
		}
	}

	/**
	 * Reject destinations carrying control characters or longer than 2048 characters:
	 * either would break the Location header or the admin UI's anchor rendering.
	 *
	 * @throws InvalidArgumentException When the destination carries control characters
	 *                                  or is too long.
	 */
	public static function assertValidDestination( string $to ): void {
		if ( preg_match( '#[\x00-\x1F\x7F]#', $to ) === 1 ) {
			throw new InvalidArgumentException( 'Invalid redirect destination; control characters are not allowed.' );
		}

		if ( strlen( $to ) > self::MAX_DESTINATION_LENGTH ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid redirect destination; it is longer than %d characters.', self::MAX_DESTINATION_LENGTH )
			);
		}
	}

	/**
	 * Delete one redirect by request key. True when a row existed and was removed.
	 */
	public static function delete( string $request ): bool {
		$request = self::normalizeRequest( $request );
		$stored  = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) || ! array_key_exists( $request, $stored ) ) {
			return false;
		}

		unset( $stored[$request] );

		self::save( $stored );

		return true;
	}

	/**
	 * Write through the pre-save filter and rebuild the computed map. Rows are forwarded
	 * as stored: raw option reads carry unknown key types, and this method makes no
	 * per-row shape assumptions.
	 *
	 * @param  array<mixed, mixed>  $rows
	 */
	private static function save( array $rows ): void {
		$filtered = apply_filters( 'pv/redirects/option_store_pre_save', $rows );

		update_option( self::OPTION, is_array( $filtered ) ? $filtered : $rows, true );

		RedirectMap::rebuild();
	}

	private static function normalizeRequest( string $request ): string {
		$request = trim( $request );

		return str_starts_with( $request, '/' ) ? $request : '/'.$request;
	}

	/**
	 * @param  array<mixed, mixed>  $row
	 *
	 * @return array{to: string, status: int}|null
	 */
	private static function sanitizeRow( array $row ): ?array {
		$to = $row['to'] ?? null;

		if ( ! is_string( $to ) ) {
			return null;
		}

		$status = $row['status'] ?? null;

		if ( is_int( $status ) && in_array( $status, self::STATUSES, true ) ) {
			return [ 'to' => $to, 'status' => $status ];
		}

		if ( is_string( $status ) && ctype_digit( $status ) ) {
			$status = (int) $status;

			if ( in_array( $status, self::STATUSES, true ) ) {
				return [ 'to' => $to, 'status' => $status ];
			}
		}

		return null;
	}
}
