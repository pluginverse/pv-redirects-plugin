<?php

namespace PV\Redirects;

use function add_option;
use function delete_option;
use function do_action;
use function get_option;
use function get_permalink;
use function home_url;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_ends_with;
use function wp_parse_url;
use function str_contains;
use function strpos;
use function str_starts_with;
use function strtolower;
use function substr;
use function time;
use function update_option;

/**
 * The computed redirect map: option `pv_redirects_map`, autoload yes, the ONLY thing the
 * front-end read path loads (one get_option, served from alloptions; zero DB on cache hit).
 * Derived artifact — rebuilt from the canonical stores on every write, never
 * read-modify-written by matchers. See docs/decisions/0002.
 *
 * @phpstan-import-type WildcardRule from Matcher
 *
 * @phpstan-type ExactRule array{to: string, status: int, src: string, id?: int, resolved_to?: string}
 * @phpstan-type PostRule array{to: string, status: int, resolved_to?: string}
 * @phpstan-type Map array{v: int, built: int, settings: array{enabled: bool, case_sensitive: bool, preserve_qs: bool, default_status: int}, exact: array<string, ExactRule>, wildcards: array<int, WildcardRule>, posts: array<int, PostRule>}
 */
final class RedirectMap {

	private const OPTION = 'pv_redirects_map';

	private const SCHEMA_VERSION = 3;

	/**
	 * Recompute the map from `pv_redirects` + active per-post meta + settings and persist it.
	 * Safe to call repeatedly; fires `pv/redirects/map_rebuilt` afterwards.
	 *
	 * @return Map
	 */
	public static function rebuild(): array {
		$settings  = Settings::all();
		$case_sensitive = $settings['case_sensitive'];
		$exact     = [];
		$wildcards = [];

		foreach ( OptionStore::all() as $request => $row ) {
			if ( str_contains( $request, '*' ) ) {
				$wildcards[] = [
					'regex'  => Matcher::compileWildcard( self::wildcardPattern( $request, $case_sensitive ) ),
					'to'     => $row['to'],
					'status' => $row['status'],
					'src'    => 'o',
				];

				continue;
			}

			$exact[Matcher::normalizeExactKey( $request, $case_sensitive )] = [
				'to'     => $row['to'],
				'status' => $row['status'],
				'src'    => 'o',
			];
		}

		$posts = [];

		foreach ( PostMetaStore::activeRedirectPosts() as $post_id => $rule ) {
			$permalink = get_permalink( $post_id );

			if ( ! is_string( $permalink ) || $permalink === '' ) {
				continue;
			}

			$key = self::permalinkKey( $permalink, $case_sensitive );

			// Option rows win when a user-entered key collides with a permalink.
			if ( ! isset( $exact[$key] ) ) {
				$exact[$key] = [
					'to'     => $rule['to'],
					'status' => $rule['status'],
					'src'    => 'p',
					'id'     => $post_id,
				];
			}

			$posts[$post_id] = [ 'to' => $rule['to'], 'status' => $rule['status'] ];
		}

		// Resolve against the original index so traversal is independent of iteration order.
		$original = $exact;
		$query_paths = [];
		foreach ( $original as $key => $rule ) {
			$position = strpos( $key, '?' );
			if ( $position !== false ) {
				$query_paths[substr( $key, 0, $position )] = true;
			}
		}
		foreach ( $original as $key => $rule ) {
			if ( $rule['status'] !== 301 || str_contains( $key, '?' ) ) {
				continue;
			}
			$destination = self::resolveChain( $key, $rule['to'], $original, $query_paths, $case_sensitive );
			if ( $destination !== null ) {
				$rule['resolved_to'] = $destination;
				$exact[$key] = $rule;
				if ( $rule['src'] === 'p' && isset( $posts[$rule['id']] ) ) {
					$posts[$rule['id']]['resolved_to'] = $destination;
				}
			}
		}

		$map = [
			'v'         => self::SCHEMA_VERSION,
			'built'     => time(),
			'settings'  => $settings,
			'exact'     => $exact,
			'wildcards' => $wildcards,
			'posts'     => $posts,
		];

		self::store( $map );

		do_action( 'pv/redirects/map_rebuilt', $map );

		return $map;
	}

	/**
	 * Follow only unambiguous local exact 301 hops. A cycle or hop limit keeps the
	 * original destination. Canonical rows and hook-visible rule data stay intact.
	 *
	 * @param array<string, ExactRule> $exact
	 * @param array<string, bool> $query_paths
	 */
	private static function resolveChain( string $source, string $destination, array $exact, array $query_paths, bool $case_sensitive ): ?string {
		$visited = [ $source => true ];
		$original = $destination;
		for ( $hop = 0; $hop < 100; ++$hop ) {
			if ( str_starts_with( strtolower( $destination ), '/wp-json/' ) || str_starts_with( strtolower( $destination ), '/wp-admin/' ) || strtolower( $destination ) === '/wp-admin' || ! str_starts_with( $destination, '/' ) || str_starts_with( $destination, '//' ) || str_contains( $destination, '%' ) || str_contains( $destination, '\\' ) || preg_match( '~[?#*]|(?:^|/)\.{1,2}(?:/|$)~', $destination ) === 1 ) {
				return $destination === $original ? null : $destination;
			}
			$key = Matcher::normalizeKey( $destination, $case_sensitive );
			$toggled = str_ends_with( $key, '/' ) ? substr( $key, 0, -1 ) : $key.'/';
			if ( isset( $query_paths[$key] ) || isset( $query_paths[$toggled] ) ) {
				return $destination === $original ? null : $destination;
			}
			$next_key = isset( $exact[$key] ) ? $key : $toggled;
			if ( ! isset( $exact[$next_key] ) || $exact[$next_key]['status'] !== 301 ) {
				return $destination === $original ? null : $destination;
			}
			if ( isset( $visited[$next_key] ) ) {
				return null;
			}
			$visited[$next_key] = true;
			$destination = $exact[$next_key]['to'];
		}
		return null;
	}

	/**
	 * Read the persisted map; [] when absent or the schema version mismatches.
	 *
	 * @return Map|list<never>
	 */
	public static function get(): array {
		$map = self::getShape();

		return $map['v'] === 0 ? [] : $map;
	}

	/**
	 * Read the persisted map with its shape union intact: absent or schema-mismatched
	 * snapshots read as a version-0 stub instead of [], so typed consumers can narrow on
	 * `v`. (phpstan generalizes get()'s `Map|list<never>` into a shapeless array no
	 * runtime guard can narrow — this is the shape-preserving read.) Read logic is
	 * identical to get().
	 *
	 * @return Map|array{v: 0}
	 */
	public static function getShape(): array {
		$stored = get_option( self::OPTION, [ 'v' => 0 ] );

		if ( ! is_array( $stored ) || ! in_array( $stored['v'] ?? 0, [ 1, 2, self::SCHEMA_VERSION ], true ) ) {
			return [ 'v' => 0 ];
		}

		/** @var Map $stored */
		return $stored;
	}

	/** Upgrade an old snapshot outside the frontend read path. */
	public static function upgrade(): void {
		$map = self::getShape();
		if ( $map['v'] === 1 || $map['v'] === 2 ) {
			self::rebuild();
		}
	}

	/**
	 * Drop the computed map (canonical stores are untouched; rebuild regenerates it).
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Wildcard patterns match paths only: strip any embedded query string and case-fold so
	 * the pattern compares equal to the normalized request path at match time.
	 */
	private static function wildcardPattern( string $request, bool $case_sensitive ): string {
		$qpos = strpos( $request, '?' );
		$path = $qpos === false ? $request : substr( $request, 0, $qpos );

		return $case_sensitive ? $path : strtolower( $path );
	}

	/**
	 * Turn a post permalink into an exact-map key: strip scheme+host and the home path
	 * prefix so keys line up with the home-stripped request paths the matcher sees.
	 */
	private static function permalinkKey( string $permalink, bool $case_sensitive ): string {
		$path  = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$query = (string) wp_parse_url( $permalink, PHP_URL_QUERY );

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( $home_path !== '' && $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( $path === '' || ! str_starts_with( $path, '/' ) ) {
			$path = '/'.$path;
		}

		return Matcher::normalizeExactKey( $query === '' ? $path : $path.'?'.$query, $case_sensitive );
	}

	/**
	 * Persist the map with autoload=yes. The plugin floor is WP 6.4, where update_option()'s
	 * third parameter sets the autoload flag directly — on insert AND on update of an
	 * existing row — so the old SELECT/delete/re-add dance (and its $wpdb lookup) is gone:
	 * a fresh option is added autoloaded, an existing one is updated with autoload=yes.
	 *
	 * @param  Map  $map
	 */
	private static function store( array $map ): void {
		if ( get_option( self::OPTION, false ) === false ) {
			add_option( self::OPTION, $map, '', true );

			return;
		}

		update_option( self::OPTION, $map, true );
	}
}
