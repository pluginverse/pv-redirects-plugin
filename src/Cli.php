<?php

namespace PV\Redirects;

use InvalidArgumentException;
use WP_CLI;
use WP_CLI\Utils;

use function __;
use function array_filter;
use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function gettype;
use function home_url;
use function in_array;
use function is_scalar;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function strpos;
use function substr;
use function trim;

/**
 * WP-CLI: `wp pv-redirects <command>` — rebuild, migrate, list, create, delete, match and
 * test. Agent-native: no interactivity or prompts, deterministic output; list output
 * honors --format=table|json|csv and migrate supports --dry-run. The match/test
 * diagnostics run the exact same cascade as the front-end read path via
 * RedirectHandler::matchForPath().
 *
 * @phpstan-import-type MigrationReport from Migrator
 */
final class Cli {

	/**
	 * Recompute the redirect map from the canonical stores.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects rebuild
	 */
	public function rebuild(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$map = RedirectMap::rebuild();

		WP_CLI::success(
			sprintf(
				__( 'Redirect map rebuilt (%1$d rules, %2$d wildcards).', 'pv-redirects' ),
				count( $map['exact'] ),
				count( $map['wildcards'] )
			)
		);
	}

	/**
	 * Copy Quick Page/Post Redirect (QPPR) data into PV Redirects. Idempotent: re-running
	 * skips rows/posts already present in PV storage. Source data is only deleted when
	 * --delete-source is passed, and only what the run copied or found already migrated.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything.
	 *
	 * [--delete-source]
	 * : After a real run, delete the QPPR options, post meta and settings that were migrated. The QPPR global URL override (if set) is kept — see the migration warnings.
	 *
	 * [--skip-quick]
	 * : Do not migrate the `quickppr_redirects` option rows.
	 *
	 * [--skip-posts]
	 * : Do not migrate `_pprredirect_*` post meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects migrate --dry-run
	 *     wp pv-redirects migrate --delete-source
	 *
	 * @param  array<int, mixed>     $args
	 * @param  array<string, mixed>  $assoc_args
	 */
	public function migrate( array $args = [], array $assoc_args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		if ( ! Migrator::hasSource() ) {
			WP_CLI::error( __( 'No Quick Page/Post Redirect data found; nothing to migrate.', 'pv-redirects' ) );
		}

		$report = Migrator::migrate( $args, $assoc_args );

		WP_CLI::log(
			sprintf(
				/* translators: 1: migrated count, 2: skipped count, 3: dropped count. */
				__( 'Quick redirects:  %1$d migrated, %2$d skipped (already present), %3$d dropped new-window/nofollow rows.', 'pv-redirects' ),
				$report['quick']['migrated'],
				$report['quick']['skipped_existing'],
				$report['quick']['dropped_meta']
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: 1: migrated count, 2: inactive count, 3: skipped count, 4: downgraded count, 5: dropped count. */
				__( 'Post redirects:   %1$d migrated (%2$d inactive), %3$d skipped (already present), %4$d meta type(s) downgraded to 301, %5$d post(s) with dropped link features.', 'pv-redirects' ),
				$report['posts']['migrated'],
				$report['posts']['inactive_migrated'],
				$report['posts']['skipped_existing'],
				$report['posts']['meta_type_downgraded'],
				$report['posts']['dropped_features']
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: 1: case sensitive state, 2: default status, 3: all-disabled override state. */
				__( 'Settings:         case sensitive %1$s; default status %2$s; QPPR all-disabled override %3$s.', 'pv-redirects' ),
				self::onOff( $report['settings']['case_sensitive'] ),
				$report['settings']['default_status'] === null ? __( 'unchanged', 'pv-redirects' ) : (string) $report['settings']['default_status'],
				$report['settings']['disabled'] ? __( 'on (PV Redirects disabled to match)', 'pv-redirects' ) : __( 'off', 'pv-redirects' )
			)
		);

		foreach ( $report['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( $report['rebuilt'] ) {
			WP_CLI::log( __( 'Redirect map rebuilt.', 'pv-redirects' ) );
		}

		if ( $report['dry_run'] ) {
			WP_CLI::success( __( 'Dry run complete; nothing was written. Next: run again without --dry-run, then deactivate the Quick Page/Post Redirect plugin to avoid double redirects.', 'pv-redirects' ) );

			return;
		}

		WP_CLI::success( __( 'Migration complete. Next: deactivate the Quick Page/Post Redirect plugin to avoid double redirects.', 'pv-redirects' ) );
	}

	/**
	 * List every effective rule in the computed redirect map: exact rules (option rows and
	 * post permalinks) plus wildcards, in first-match-wins order.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--source=<source>]
	 * : Only rules from one source.
	 * ---
	 * options:
	 *   - option
	 *   - post
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects list --format=json --source=post
	 *
	 * @param  array<int, mixed>     $args
	 * @param  array<string, mixed>  $assoc_args
	 */
	public function list( array $args = [], array $assoc_args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
			return;
		}

		$source = array_key_exists( 'source', $assoc_args ) && is_string( $assoc_args['source'] )
			? strtolower( trim( $assoc_args['source'] ) )
			: '';

		if ( $source !== '' && ! in_array( $source, [ 'option', 'post' ], true ) ) {
			throw new InvalidArgumentException(
				__( 'Invalid --source; expected one of: option, post.', 'pv-redirects' )
			);
		}

		$format = array_key_exists( 'format', $assoc_args ) && is_string( $assoc_args['format'] )
			? strtolower( trim( $assoc_args['format'] ) )
			: 'table';

		if ( ! in_array( $format, [ 'table', 'json', 'csv' ], true ) ) {
			throw new InvalidArgumentException(
				__( 'Invalid --format; expected one of: table, json, csv.', 'pv-redirects' )
			);
		}

		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 ) {
			$map = RedirectMap::rebuild();
		}

		$items = [];

		foreach ( $map['exact'] as $request => $rule ) {
			$items[] = [
				'request'     => $request,
				'destination' => $rule['to'],
				'status'      => $rule['status'],
				'type'        => 'exact',
				'source'      => ( $rule['id'] ?? null ) !== null ? 'post' : 'option',
				'post_id'     => $rule['id'] ?? null,
			];
		}

		foreach ( $map['wildcards'] as $rule ) {
			$items[] = [
				'request'     => self::wildcardPattern( $rule['regex'] ),
				'destination' => $rule['to'],
				'status'      => $rule['status'],
				'type'        => 'wildcard',
				'source'      => 'option',
				'post_id'     => null,
			];
		}

		if ( $source !== '' ) {
			$items = array_values(
				array_filter(
					$items,
					static fn( array $item ): bool => $item['source'] === $source
				)
			);
		}

		Utils\format_items( $format, $items, [ 'request', 'destination', 'status', 'type', 'source', 'post_id' ] );
	}

	/**
	 * Create one quick redirect row.
	 *
	 * ## OPTIONS
	 *
	 * <request>
	 * : Request path, e.g. /old-page/ — a leading '/' is added when missing.
	 *
	 * <to>
	 * : Destination: an absolute URL, a site-relative path, or a numeric post ID.
	 *
	 * [--status=<status>]
	 * : HTTP status: 301, 302, 307 or 308.
	 * ---
	 * default: 301
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects create /old-page/ /new-page/ --status=302
	 *
	 * @param  array<int, mixed>     $args
	 * @param  array<string, mixed>  $assoc_args
	 *
	 * @throws InvalidArgumentException When arguments are missing or the status is invalid.
	 */
	public function create( array $args = [], array $assoc_args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$request = self::stringArg( $args, __( 'Usage: wp pv-redirects create <request> <to> [--status=301]', 'pv-redirects' ) );
		$to      = self::stringArg( array_slice( $args, 1, 1 ), __( 'Usage: wp pv-redirects create <request> <to> [--status=301]', 'pv-redirects' ) );

		try {
			$status = self::statusArg( $assoc_args );

			OptionStore::set( $request, $to, $status );

			WP_CLI::success(
				sprintf(
					/* translators: 1: request path, 2: destination, 3: status code. */
					__( 'Redirect created: %1$s → %2$s (status %3$d).', 'pv-redirects' ),
					$request,
					$to,
					$status
				)
			);
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Delete one quick redirect row by request key.
	 *
	 * ## OPTIONS
	 *
	 * <request>
	 * : Request path of the row to delete, e.g. /old-page/.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects delete /old-page/
	 *
	 * @param  array<int, mixed>  $args
	 */
	public function delete( array $args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$request = self::stringArg( $args, __( 'Usage: wp pv-redirects delete <request>', 'pv-redirects' ) );

		if ( ! OptionStore::delete( $request ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: request path. */
					__( 'No redirect found for %1$s.', 'pv-redirects' ),
					$request
				)
			);
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: request path. */
				__( 'Redirect deleted: %1$s.', 'pv-redirects' ),
				$request
			)
		);
	}

	/**
	 * Show what the matcher would do for a request path, using the same cascade as the
	 * front-end read path (exact with query string, exact, trailing-slash toggle, wildcard).
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Request path to test, e.g. /old-page/ — a query string is honored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects match /old-page/?utm=x
	 *
	 * @param  array<int, mixed>  $args
	 */
	public function match( array $args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$path = self::stringArg( $args, __( 'Usage: wp pv-redirects match <path>', 'pv-redirects' ) );

		$qpos  = strpos( $path, '?' );
		$query = $qpos === false ? '' : substr( $path, $qpos + 1 );
		$path  = $qpos === false ? $path : substr( $path, 0, $qpos );

		$match = RedirectHandler::matchForPath( $path, $query );

		if ( $match === null ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: request path. */
					__( 'No match for %1$s.', 'pv-redirects' ),
					$path
				)
			);

			return;
		}

		$rule   = $match['rule'];
		$kind   = isset( $rule['regex'] ) ? __( 'wildcard', 'pv-redirects' ) : __( 'exact', 'pv-redirects' );
		$ruleId = $rule['id'] ?? null;
		$src    = $ruleId !== null
			? sprintf(
				/* translators: %d: post ID. */
				__( 'post #%1$d permalink', 'pv-redirects' ),
				$ruleId
			)
			: __( 'option row', 'pv-redirects' );

		WP_CLI::log(
			sprintf(
				/* translators: 1: request path, 2: matched destination, 3: rule kind and source, 4: status code. */
				__( 'Matched: %1$s → %2$s (%3$s, status %4$d).', 'pv-redirects' ),
				$path,
				$rule['to'],
				$kind.', '.$src,
				$rule['status']
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: %s: resolved destination URL. */
				__( 'Destination: %1$s', 'pv-redirects' ),
				Matcher::resolveDestination( $rule['to'], $match['captures'], home_url() )
			)
		);
	}

	/**
	 * Full request simulation: parse an arbitrary site URL, strip the home URL (handling
	 * subdirectory homes), normalize, run the matcher and resolve the destination —
	 * exactly what a front-end request would do, without sending anything.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Absolute site URL or a site-relative path, e.g. https://example.com/old-page/?utm=x
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-redirects test https://example.com/old-page/?utm=x
	 *
	 * @param  array<int, mixed>  $args
	 */
	public function test( array $args = [] ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$url = self::stringArg( $args, __( 'Usage: wp pv-redirects test <url>', 'pv-redirects' ) );

		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url().$url;
		} elseif ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) !== 1 ) {
			$url = 'http://'.$url;
		}

		$host     = (string) parse_url( $url, PHP_URL_HOST );
		$homeHost = (string) parse_url( home_url(), PHP_URL_HOST );

		if ( $host !== '' && $homeHost !== '' && strtolower( $host ) !== strtolower( $homeHost ) ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: URL host, 2: home URL host. */
					__( 'URL host "%1$s" differs from the home URL host "%2$s"; matching the site-relative path anyway.', 'pv-redirects' ),
					$host,
					$homeHost
				)
			);
		}

		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$query = (string) parse_url( $url, PHP_URL_QUERY );

		$homePath = (string) parse_url( home_url(), PHP_URL_PATH );

		if ( $homePath !== '' && $homePath !== '/' && str_starts_with( $path, $homePath ) ) {
			$path = substr( $path, strlen( $homePath ) );
		}

		if ( $path === '' || ! str_starts_with( $path, '/' ) ) {
			$path = '/'.$path;
		}

		$match = RedirectHandler::matchForPath( $path, $query );

		if ( $match === null ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: normalized request path. */
					__( 'No match for %1$s.', 'pv-redirects' ),
					$path
				)
			);

			return;
		}

		$map = RedirectMap::getShape();

		if ( $map['v'] === 0 ) {
			$map = RedirectMap::rebuild();
		}

		$destination = Matcher::resolveDestination( $match['rule']['to'], $match['captures'], home_url() );

		if ( $map['settings']['preserve_qs'] && $query !== '' ) {
			$destination = Matcher::appendQuery( $destination, $query );
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: normalized request path with its query string. */
				__( 'Request:     %1$s', 'pv-redirects' ),
				$query === '' ? $path : $path.'?'.$query
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: %s: resolved destination URL. */
				__( 'Destination: %1$s', 'pv-redirects' ),
				$destination
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: %d: status code. */
				__( 'Status:      %1$d', 'pv-redirects' ),
				$match['rule']['status']
			)
		);
	}

	/**
	 * First positional argument as a non-empty string, or an InvalidArgumentException the
	 * WP-CLI runner renders as an error.
	 *
	 * @param  array<int, mixed>  $args
	 *
	 * @throws InvalidArgumentException When no usable first argument exists.
	 */
	private static function stringArg( array $args, string $usage ): string {
		$value = array_key_exists( 0, $args ) && is_string( $args[0] ) && $args[0] !== ''
			? $args[0]
			: '';

		if ( $value === '' ) {
			throw new InvalidArgumentException( $usage );
		}

		return $value;
	}

	/**
	 * The --status option as an int (the range itself is validated by OptionStore::set).
	 *
	 * @param  array<string, mixed>  $assoc_args
	 *
	 * @throws InvalidArgumentException When the status is not a number.
	 */
	private static function statusArg( array $assoc_args ): int {
		$raw = $assoc_args['status'] ?? 301;

		if ( is_int( $raw ) ) {
			return $raw;
		}

		if ( is_string( $raw ) && trim( $raw ) !== '' && ctype_digit( trim( $raw ) ) ) {
			return (int) $raw;
		}

		throw new InvalidArgumentException(
			sprintf(
				/* translators: %s: raw --status value. */
				__( 'Invalid --status "%1$s"; expected one of: 301, 302, 307, 308.', 'pv-redirects' ),
				is_scalar( $raw ) ? (string) $raw : gettype( $raw )
			)
		);
	}

	/**
	 * @param bool|null $value Null when the QPPR option was absent (nothing changed).
	 */
	private static function onOff( ?bool $value ): string {
		if ( $value === null ) {
			return __( 'not set in QPPR (unchanged)', 'pv-redirects' );
		}

		return $value ? __( 'on', 'pv-redirects' ) : __( 'off', 'pv-redirects' );
	}

	/**
	 * Reverse Matcher::compileWildcard's output back into the user-facing pattern ('#^/'
	 * … '$#' wrapper stripped, named captures back to '*', preg_quote escapes undone) for
	 * display. The map stores the compiled regex only, so this is reconstruction —
	 * faithful for the shapes compileWildcard produces.
	 *
	 * @param string $regex Compiled wildcard regex.
	 */
	private static function wildcardPattern( string $regex ): string {
		$pattern = str_starts_with( $regex, '#^/' ) && str_ends_with( $regex, '$#' )
			? '/'.substr( $regex, 3, -2 )
			: $regex;

		$pattern = (string) preg_replace( '/\(\?P<rest>\.\+\)/', '*', $pattern );

		$pattern = (string) preg_replace( '/\(\?P<s\d+>\[\^\/\]\+\)/', '*', $pattern );

		// preg_quote escapes literal characters in pattern segments ('old-blog' becomes
		// 'old\-blog'); undo those escapes for display.
		return (string) preg_replace( '~\\\\([.+*?\[\]^$(){}=!<>|:\-#])~', '$1', $pattern );
	}
}
