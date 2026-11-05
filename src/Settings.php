<?php

namespace PV\Redirects;

use InvalidArgumentException;

use function apply_filters;
use function array_key_exists;
use function ctype_digit;
use function get_option;
use function in_array;
use function is_array;
use function strtolower;
use function update_option;

/**
 * Plugin settings: option `pv_redirects_settings` (autoload yes), always merged over the
 * defaults so the read path never needs isset() dances. Filterable via
 * `pv/redirects/settings` (the map snapshot bakes the filtered values in at build time).
 *
 * @phpstan-type SettingsShape array{enabled: bool, case_sensitive: bool, preserve_qs: bool, default_status: int}
 */
final class Settings {

	private const OPTION = 'pv_redirects_settings';

	/**
	 * Stored values merged over defaults, passed through the `pv/redirects/settings` filter.
	 *
	 * @return SettingsShape
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$result = self::normalize( is_array( $stored ) ? $stored : [] );

		$filtered = apply_filters( 'pv/redirects/settings', $result );

		return is_array( $filtered ) ? self::normalize( $filtered ) : $result;
	}

	/**
	 * One setting by key, null when unknown.
	 */
	public static function get( string $key ): mixed {
		return self::all()[$key] ?? null;
	}

	/**
	 * Merge a partial settings array over the current values and rebuild the map. Booleans
	 * are cast from common truthy/falsy forms; an invalid default_status throws. Keys are
	 * read by name only, so arrays with unknown key types (JSON bodies, filtered option
	 * reads) are safe.
	 *
	 * @param  array<mixed, mixed>  $partial
	 */
	public static function update( array $partial ): void {
		$current = self::all();
		$next    = $current;

		foreach ( [ 'enabled', 'case_sensitive', 'preserve_qs' ] as $key ) {
			if ( array_key_exists( $key, $partial ) ) {
				$next[$key] = self::toBool( $partial[$key], $current[$key] );
			}
		}

		if ( array_key_exists( 'default_status', $partial ) ) {
			$status = self::toStatus( $partial['default_status'], 0 );

			if ( ! in_array( $status, OptionStore::STATUSES, true ) ) {
				throw new InvalidArgumentException( 'Invalid default_status; expected one of: 301, 302, 307, 308.' );
			}

			$next['default_status'] = $status;
		}

		update_option( self::OPTION, $next, true );

		RedirectMap::rebuild();
	}

	/**
	 * @param  array<mixed, mixed>  $raw
	 *
	 * @return SettingsShape
	 */
	private static function normalize( array $raw ): array {
		return [
			'enabled'        => self::toBool( $raw['enabled'] ?? null, true ),
			'case_sensitive' => self::toBool( $raw['case_sensitive'] ?? null, false ),
			'preserve_qs'    => self::toBool( $raw['preserve_qs'] ?? null, true ),
			'default_status' => self::toStatus( $raw['default_status'] ?? null, 301 ),
		];
	}

	private static function toBool( mixed $value, bool $default ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( $value === null ) {
			return $default;
		}

		if ( is_int( $value ) ) {
			return $value !== 0;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ '1', 'true', 'on', 'yes' ], true );
		}

		return $default;
	}

	private static function toStatus( mixed $value, int $default ): int {
		if ( is_int( $value ) && in_array( $value, OptionStore::STATUSES, true ) ) {
			return $value;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$status = (int) $value;

			if ( in_array( $status, OptionStore::STATUSES, true ) ) {
				return $status;
			}
		}

		return $default;
	}
}
