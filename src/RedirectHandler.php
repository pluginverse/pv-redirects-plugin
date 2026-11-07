<?php

namespace PV\Redirects;

use WP_Post;

use function apply_filters;
use function do_action;
use function get_queried_object;
use function home_url;
use function is_admin;
use function is_ssl;
use function is_string;
use function wp_parse_url;
use function preg_match;
use function strpos;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;
use function wp_doing_cron;
use function wp_redirect;
use function wp_unslash;

/**
 * Front-end execution, all static entry points and no state:
 * - init (priority 1): the hot-path matcher — one option read, then an in-memory cascade.
 * - canonical: redirect_canonical guard so core canonicalization never preempts us (QPPR parity).
 * - templateRedirect: permalink-drift fallback keyed by post ID (authoritative across renames).
 *
 * @phpstan-import-type Map from RedirectMap
 * @phpstan-import-type ExactRule from RedirectMap
 * @phpstan-import-type WildcardRule from RedirectMap
 * @phpstan-import-type PostRule from RedirectMap
 */
final class RedirectHandler {

	/**
	 * `init` hook, priority 1: match the current request against the map and redirect on hit.
	 */
	public static function init(): void {
		// Read-only request routing; preserve URL characters for matching.
		$rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing does not mutate state; URL characters must be preserved.

		// REST_REQUEST is only defined at parse_request — AFTER init — so it cannot guard
		// this hook: a wildcard like '/wp*' would 301 /wp-json/… and break REST sitewide.
		// The rest_route query param covers ?rest_route= requests; the /wp-json/ prefix
		// check below covers plain-permalink REST requests.
		if (
			is_admin()
			|| $rest_route !== ''
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		if ( self::hasBypassParameter() ) {
			return;
		}

		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 || $map['settings']['enabled'] === false ) {
			return;
		}

		[ $path, $query ] = self::currentRequest();

		if ( str_starts_with( $path, '/wp-json/' ) ) {
			return;
		}

		$match = self::matchPath( $map, $path, $query );

		if ( $match === null ) {
			return;
		}

		self::send( $map, $path, $query, $match['rule'], $match['captures'] );
	}

	/**
	 * `redirect_canonical` filter: when our map matches the requested URL, return our
	 * destination so core canonicalization doesn't preempt the redirect (QPPR parity).
	 * false/null flow through untouched so the filter can never loop on its own.
	 *
	 * @param  mixed  $redirect_url
	 *
	 * @return mixed
	 */
	public static function canonical( mixed $redirect_url, string $requested_url ): mixed {
		if ( $redirect_url === null || $redirect_url === false ) {
			return $redirect_url;
		}

		if ( self::hasBypassParameter() ) {
			return $redirect_url;
		}

		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 || $map['settings']['enabled'] === false ) {
			return $redirect_url;
		}

		// $requested_url is a full URL: home-strip and normalize it exactly like a live
		// request, so subdirectory homes and proxy-scheme mismatches match the same keys.
		[ $path, $query ] = self::normalizeRequestedUrl( $requested_url );

		if ( $path === '' ) {
			return $redirect_url;
		}

		$match = self::matchPath( $map, $path, $query );

		if ( $match === null ) {
			return $redirect_url;
		}

		$url = Matcher::resolveDestination( $match['rule']['to'], $match['captures'], home_url() );

		if ( $map['settings']['preserve_qs'] && $query !== '' ) {
			$url = Matcher::appendQuery( $url, $query );
		}

		do_action( 'pv/redirects/redirect', $path, $match['rule'] );

		return (string) apply_filters( 'pv/redirects/destination', $url, $match['rule'], $path );
	}

	/**
	 * `template_redirect` hook, priority 1: permalink-drift fallback. The map's exact keys
	 * can go stale when a slug changes outside our hooks, but the `posts` index is keyed by
	 * ID and therefore authoritative — all in-memory, zero DB.
	 */
	public static function templateRedirect(): void {
		if ( self::hasBypassParameter() ) {
			return;
		}

		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 || $map['settings']['enabled'] === false ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$rule = $map['posts'][$post->ID] ?? null;

		if ( $rule === null ) {
			return;
		}

		[ $path, $query ] = self::currentRequest();

		self::send( $map, $path, $query, $rule, [] );
	}

	/**
	 * The plan's matching cascade, entirely in-memory:
	 *  1. exact hit including the query string (`/path/?q=1` form, QPPR parity)
	 *  2. exact hit on the path
	 *  3. exact hit on the path with the trailing slash toggled
	 *  4. wildcard pass in rule order (first match wins), also slash-tolerant
	 *  5. miss → null, no redirect
	 *
	 * @param  Map  $map
	 *
	 * @return array{rule: ExactRule|WildcardRule, captures: array<string, string>}|null
	 */
	private static function matchPath( array $map, string $path, string $query ): ?array {
		$case_sensitive = $map['settings']['case_sensitive'];
		$exact          = $map['exact'];

		if ( $query !== '' ) {
			$rule = $exact[Matcher::normalizeExactKey( $path.'?'.$query, $case_sensitive )] ?? null;

			if ( $map['v'] === 1 && ! $case_sensitive ) {
				$normalized = Matcher::normalizeExactKey( $path.'?'.$query, false );
				foreach ( $exact as $legacy_key => $legacy_rule ) {
					if ( Matcher::normalizeExactKey( $legacy_key, false ) === $normalized ) {
						$rule = $legacy_rule;
					}
				}
			}

			if ( $rule !== null ) {
				return [ 'rule' => $rule, 'captures' => [] ];
			}
		}

		$key = Matcher::normalizeKey( $path, $case_sensitive );

		$rule = $exact[$key] ?? null;

		if ( $rule !== null ) {
			return [ 'rule' => $rule, 'captures' => [] ];
		}

		$toggled = str_ends_with( $key, '/' ) ? substr( $key, 0, -1 ) : $key.'/';

		if ( $toggled !== '' ) {
			$rule = $exact[$toggled] ?? null;

			if ( $rule !== null ) {
				return [ 'rule' => $rule, 'captures' => [] ];
			}
		}

		// Wildcards get the same trailing-slash tolerance as exact keys: try the path
		// as typed, then with the trailing slash toggled (`/wp*` should catch `/wp-foo/`).
		$match = Matcher::matchWildcard( $map['wildcards'], $key );

		if ( $match === null && $toggled !== '' ) {
			$match = Matcher::matchWildcard( $map['wildcards'], $toggled );
		}

		return $match;
	}

	/**
	 * The matching cascade for diagnostics callers (WP-CLI `match`/`test`): loads the
	 * persisted map and runs matchPath() against it. The front-end hooks keep calling
	 * matchPath() with their already-loaded map so the hot path never fetches the option
	 * twice.
	 *
	 * @return array{rule: ExactRule|WildcardRule, captures: array<string, string>}|null
	 */
	public static function matchForPath( string $path, string $query = '' ): ?array {
		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 ) {
			return null;
		}

		return self::matchPath( $map, $path, $query );
	}

	/**
	 * Resolve the destination, append the incoming query string when preserve_qs is on, fire
	 * hooks in the plan's order (action first; the destination filter is the last-chance
	 * mutation), then redirect and stop.
	 *
	 * @param  Map                              $map
	 * @param  ExactRule|WildcardRule|PostRule  $rule
	 * @param  array<string, string>            $captures
	 */
	private static function send( array $map, string $request, string $query, array $rule, array $captures ): void {
		$url = Matcher::resolveDestination( $rule['to'], $captures, home_url() );

		if ( $map['settings']['preserve_qs'] && $query !== '' ) {
			$url = Matcher::appendQuery( $url, $query );
		}

		do_action( 'pv/redirects/redirect', $request, $rule );

		$url = (string) apply_filters( 'pv/redirects/destination', $url, $rule, $request );

		wp_redirect( $url, $rule['status'] );

		exit;
	}

	/** A read-only bypass flag is not a form submission. */
	private static function hasBypassParameter(): bool {
		return isset( $_GET['action'] ) && is_string( $_GET['action'] ) && wp_unslash( $_GET['action'] ) === 'no-redirect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact comparison validates this read-only flag.
	}

	/**
	 * Current request as [path, raw query string], via normalizeRequestedUrl().
	 *
	 * @return array{string, string}
	 */
	private static function currentRequest(): array {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve raw URL path and query for matching.
		$host   = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Host is used only to normalize the request URL.
		$scheme = is_ssl() ? 'https://' : 'http://';

		return self::normalizeRequestedUrl( $scheme.$host.$uri );
	}

	/**
	 * One URL as [path, raw query string], with the home URL stripped from the path —
	 * QPPR's "full address str_replace home_url" approach — plus a scheme/host-mismatch
	 * fallback (proxy https) and a home subdirectory path strip so keys are site-relative.
	 * Shared by the live request path (currentRequest) and the canonical guard, which
	 * receives $requested_url as a full URL.
	 *
	 * @return array{string, string}
	 */
	private static function normalizeRequestedUrl( string $url ): array {
		$path = str_replace( home_url(), '', $url );

		if ( preg_match( '#^https?://[^/]*(?P<rest>.*)$#i', $path, $matches ) === 1 ) {
			$path = $matches['rest'];
		}

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( $home_path !== '' && $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( $path === '' || ! str_starts_with( $path, '/' ) ) {
			$path = '/'.$path;
		}

		$qpos = strpos( $path, '?' );

		if ( $qpos === false ) {
			return [ $path, '' ];
		}

		return [ substr( $path, 0, $qpos ), substr( $path, $qpos + 1 ) ];
	}
}
