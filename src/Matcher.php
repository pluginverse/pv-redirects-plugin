<?php

namespace PV\Redirects;

use function array_filter;
use function array_values;
use function count;
use function ctype_digit;
use function explode;
use function implode;
use function wp_parse_url;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function substr_count;
use function strpos;

/**
 * Pure matching primitives: no WordPress functions, every method takes scalars/arrays so
 * the matcher is unit-testable outside a WP install. Wildcard semantics per
 * docs/decisions/0004: '*' matches a run of non-slash characters, a final '/*' matches the
 * entire remaining path including slashes, and '*' tokens in destinations are replaced with
 * the matched text. Exact matches always beat wildcards (see RedirectHandler::matchPath).
 *
 * @phpstan-type WildcardRule array{regex: string, to: string, status: int, src: string}
 * @phpstan-type Captures array<string, string>
 */
final class Matcher {

	/**
	 * Wildcard tokens allowed per pattern (shared with OptionStore::assertValidRequestKey,
	 * which rejects past the cap): each token becomes a regex capture group, so the cap
	 * bounds compiled-regex complexity (ReDoS guard).
	 */
	public const MAX_WILDCARD_STARS = 8;

	/**
	 * Normalize a path-only key: ensure a leading '/' and case-fold when matching is
	 * case-insensitive. Trailing slashes are preserved; keys stay as typed.
	 */
	public static function normalizeKey( string $path, bool $case_sensitive ): string {
		$path = str_starts_with( $path, '/' ) ? $path : '/'.$path;

		return $case_sensitive ? $path : strtolower( $path );
	}

	/**
	 * Normalize an exact-map key, keeping any embedded query string raw (QPPR parity: keys
	 * like '/path/?q=1' are indexed with only their path portion normalized).
	 */
	public static function normalizeExactKey( string $key, bool $case_sensitive ): string {
		$qpos = strpos( $key, '?' );

		if ( $qpos === false ) {
			return self::normalizeKey( $key, $case_sensitive );
		}

		return self::normalizeKey( substr( $key, 0, $qpos ), $case_sensitive ).substr( $key, $qpos );
	}

	/**
	 * Compile a wildcard pattern to an anchored regex. Each '*' becomes an ordered named
	 * capture (s1, s2, …) matching a run of non-slash characters; a FINAL lone '/*' becomes
	 * the 'rest' capture matching the entire remaining path including slashes. Everything
	 * else is preg_quoted. There is no '**' support in v1: a TRAILING '**' collapses into
	 * the rest capture, but '**' anywhere else produces adjacent captures that cannot match
	 * sensible paths.
	 */
	public static function compileWildcard( string $pattern ): string {
		// Defensive cap for the migrator/legacy data path (patterns that skipped
		// OptionStore::assertValidRequestKey): compile to a regex that can never match
		// instead of an ever-wilder capture chain.
		if ( substr_count( $pattern, '*' ) > self::MAX_WILDCARD_STARS ) {
			return '#^(?!)$#';
		}

		$segments = explode( '/', str_starts_with( $pattern, '/' ) ? $pattern : '/'.$pattern );
		$last     = count( $segments ) - 1;
		$captures = 0;
		$parts    = [];

		foreach ( $segments as $i => $segment ) {
			if ( $segment === '' ) {
				continue;
			}

			if ( $i === $last && rtrim( $segment, '*' ) === '' ) {
				$parts[] = '(?P<rest>.+)';
				continue;
			}

			if ( str_contains( $segment, '*' ) ) {
				// Quote the segment first (preg_quote escapes '*' to '\*'), then substitute
				// the escaped asterisks so literal metacharacters around '*' stay literal.
				$parts[] = (string) preg_replace_callback(
					'/\\\\\*+/',
					static function () use ( &$captures ): string {
						++$captures;

						return '(?P<s'.$captures.'>[^/]+)';
					},
					(string) preg_quote( $segment, '#' )
				);
				continue;
			}

			$parts[] = preg_quote( $segment, '#' );
		}

		return '#^/'.implode( '/', $parts ).'$#';
	}

	/**
	 * Match a request path against ordered wildcard rules; first match wins.
	 *
	 * @param  array<int, WildcardRule>  $wildcards
	 *
	 * @return array{rule: WildcardRule, captures: Captures}|null
	 */
	public static function matchWildcard( array $wildcards, string $path ): ?array {
		foreach ( $wildcards as $rule ) {
			if ( preg_match( $rule['regex'], $path, $matches ) !== 1 ) {
				continue;
			}

			$captures = [];

			// Only named captures feed the destination: drop the numeric entries (the full
			// match and unnamed groups) so array_values($captures) stays capture-ordered.
			$named = array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY );

			foreach ( $named as $name => $value ) {
				$captures[$name] = $value;
			}

			return [ 'rule' => $rule, 'captures' => $captures ];
		}

		return null;
	}

	/**
	 * Replace '*' tokens in the destination with captures in order (first '*' consumes
	 * s1, or 'rest' when the pattern ends in '/*'; leftover '*' stay literal), then resolve
	 * to an absolute URL: scheme'd destinations pass through as-is, '/' paths get the home
	 * URL prefix, numeric destinations become home URLs with ?p=N (QPPR parity), anything
	 * else is treated as a path relative to home.
	 *
	 * @param  Captures  $captures
	 */
	public static function resolveDestination( string $to, array $captures, string $home_url ): string {
		$values  = array_values( $captures );
		$capture = 0;
		$dest    = (string) preg_replace_callback(
			'/\*/',
			static function () use ( $values, &$capture ): string {
				$value = $values[$capture] ?? '*';
				++$capture;

				return $value;
			},
			$to
		);

		$scheme = wp_parse_url( $dest, PHP_URL_SCHEME );

		if ( is_string( $scheme ) && $scheme !== '' ) {
			return $dest;
		}

		$home = rtrim( $home_url, '/' );

		if ( str_starts_with( $dest, '/' ) ) {
			return $home.$dest;
		}

		if ( ctype_digit( $dest ) ) {
			return $home.'/?p='.$dest;
		}

		return $home.'/'.$dest;
	}

	/**
	 * Append an incoming query string to a destination: '?' when the destination has none,
	 * '&' when it already carries one (the plan's preserve_qs rule; callers decide whether
	 * to preserve at all).
	 */
	public static function appendQuery( string $destination, string $query_string ): string {
		if ( $query_string === '' ) {
			return $destination;
		}

		return $destination.( str_contains( $destination, '?' ) ? '&' : '?' ).$query_string;
	}
}
