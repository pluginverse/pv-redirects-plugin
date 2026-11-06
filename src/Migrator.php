<?php

namespace PV\Redirects;

use WP_Query;

use function __;
use function add_action;
use function apply_filters;
use function array_flip;
use function array_key_exists;
use function ctype_digit;
use function delete_option;
use function delete_post_meta;
use function get_option;
use function get_post_meta;
use function get_post_types;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function wp_parse_url;
use function preg_match;
use function remove_action;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;
use function update_option;
use function update_post_meta;

/**
 * One-shot migration from Quick Page/Post Redirect (QPPR) into PV Redirects' own storage —
 * copy-on-migrate (docs/decisions/0003): QPPR data is never modified unless --delete-source
 * is passed, and then only what this run copied or found already migrated. Idempotent:
 * rows/posts already present in the PV stores are skipped and counted. Dropped QPPR
 * features (new window, nofollow, link rewriting, meta refresh) surface as warnings,
 * never silently vanishing.
 *
 * Writes bypass the store mutators' per-row rebuilds: one merged option write, direct
 * postmeta writes shaped exactly like PostMetaStore::set(), and a single final
 * RedirectMap::rebuild() on real runs (dry runs write nothing). The meta-write rebuild
 * hooks are detached while post meta is written so N posts cost one rebuild.
 *
 * @phpstan-type QuickReport array{migrated: int, skipped_existing: int, dropped_meta: int}
 * @phpstan-type PostsReport array{migrated: int, skipped_existing: int, inactive_migrated: int, meta_type_downgraded: int, dropped_features: int}
 * @phpstan-type SettingsReport array{case_sensitive: bool|null, default_status: int|null, disabled: bool}
 * @phpstan-type MigrationReport array{quick: QuickReport, posts: PostsReport, settings: SettingsReport, warnings: list<string>, dry_run: bool, rebuilt: bool}
 * @phpstan-type MigrationFlags array{dry_run: bool, delete_source: bool, skip_quick: bool, skip_posts: bool}
 * @phpstan-type SettingsPlan array{report: SettingsReport, partial: array<string, bool|int>, delete: list<string>}
 */
final class Migrator {

	private const PV_OPTION = 'pv_redirects';

	/**
	 * The `_pv_redirect_*` meta hooks PostMetaStore::metaUpdated() is wired to; detached
	 * while this migrator writes post meta so the final rebuild is the only one.
	 *
	 * @var list<string>
	 */
	private const REBUILD_HOOKS = [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ];

	private const QPPR_REDIRECTS_OPTION      = 'quickppr_redirects';
	private const QPPR_REDIRECTS_META_OPTION = 'quickppr_redirects_meta';

	private const QPPR_CASE_OPTION   = 'ppr_override-casesensitive';
	private const QPPR_TYPE_OPTION   = 'ppr_override-redirect-type';
	private const QPPR_ACTIVE_OPTION = 'ppr_override-active';
	private const QPPR_URL_OPTION    = 'ppr_override-URL';

	private const QPPR_META_ACTIVE    = '_pprredirect_active';
	private const QPPR_META_URL       = '_pprredirect_url';
	private const QPPR_META_TYPE      = '_pprredirect_type';
	private const QPPR_META_NEWWINDOW = '_pprredirect_newwindow';
	private const QPPR_META_NOFOLLOW  = '_pprredirect_relnofollow';
	private const QPPR_META_REWRITE   = '_pprredirect_rewritelink';

	/**
	 * QPPR post meta removed by --delete-source once a post's redirect record was migrated.
	 *
	 * @var list<string>
	 */
	private const QPPR_POST_META_KEYS = [
		self::QPPR_META_ACTIVE,
		self::QPPR_META_URL,
		self::QPPR_META_TYPE,
		self::QPPR_META_NEWWINDOW,
		self::QPPR_META_NOFOLLOW,
		self::QPPR_META_REWRITE,
	];

	/**
	 * Whether any QPPR source data exists at all: quick redirects, per-post meta, or any
	 * of the four settings options. The CLI refuses to run a vacuous migration.
	 */
	public static function hasSource(): bool {
		$quick = get_option( self::QPPR_REDIRECTS_OPTION, [] );

		if ( is_array( $quick ) && $quick !== [] ) {
			return true;
		}

		foreach ( [ self::QPPR_CASE_OPTION, self::QPPR_TYPE_OPTION, self::QPPR_ACTIVE_OPTION, self::QPPR_URL_OPTION ] as $option_name ) {
			if ( get_option( $option_name ) !== false ) {
				return true;
			}
		}

		$query = new WP_Query(
			[
				'post_type'              => self::migratablePostTypes(),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => self::QPPR_META_URL,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		return $query->posts !== [];
	}

	/**
	 * Run the migration and return a report; never echoes — the CLI renders it. With
	 * dry_run nothing is written at all (the report describes what would happen); with
	 * delete_source a REAL run deletes the QPPR option rows, post meta and settings that
	 * were migrated or found already migrated, nothing else.
	 *
	 * @param  array<int, mixed>     $args  Positional arguments (unused; kept for WP-CLI shape).
	 * @param  array<string, mixed>  $assoc Flags: dry_run, delete_source, skip_quick, skip_posts.
	 *
	 * @return MigrationReport
	 */
	public static function migrate( array $args = [], array $assoc = [] ): array {
		$flags    = self::flags( $assoc );
		$warnings = [];

		$quick = [ 'migrated' => 0, 'skipped_existing' => 0, 'dropped_meta' => 0 ];
		$posts = [ 'migrated' => 0, 'skipped_existing' => 0, 'inactive_migrated' => 0, 'meta_type_downgraded' => 0, 'dropped_features' => 0 ];

		// Resolved first: the global QPPR redirect type is the status of migrated quick rows.
		$settingsPlan = self::resolveSettings( $warnings );

		// --- Quick redirects: quickppr_redirects rows -> pv_redirects rows. -----------------

		$pvStored = get_option( self::PV_OPTION, [] );
		$pvRows   = is_array( $pvStored ) ? $pvStored : [];

		$pendingOptionRows = [];
		$deleteQuickKeys   = [];
		$quickTotals       = [ 'rows' => 0, 'handled' => 0, 'option_present' => false ];

		if ( ! $flags['skip_quick'] ) {
			$qpprRows = get_option( self::QPPR_REDIRECTS_OPTION, [] );

			if ( is_array( $qpprRows ) ) {
				$quickTotals['option_present'] = true;

				$statusValue = $settingsPlan['partial']['default_status'] ?? 301;
				$quickStatus = is_int( $statusValue ) && in_array( $statusValue, OptionStore::STATUSES, true ) ? $statusValue : 301;

				$qpprMeta = get_option( self::QPPR_REDIRECTS_META_OPTION, [] );
				$quick['dropped_meta'] = self::countDroppedQuickMeta( is_array( $qpprMeta ) ? $qpprMeta : [], $warnings );

				foreach ( $qpprRows as $key => $value ) {
					++$quickTotals['rows'];

					if ( ! is_string( $key ) || $key === '' || ! is_string( $value ) ) {
						$warnings[] = sprintf(
							/* translators: %s: quick redirect request key. */
							__( 'Skipped quick redirect "%1$s": structurally invalid row in %2$s — fix it in QPPR and re-run.', 'pv-redirects' ),
							(string) $key,
							self::QPPR_REDIRECTS_OPTION
						);
						continue;
					}

					if ( $value === '' ) {
						$warnings[] = sprintf(
							/* translators: %s: quick redirect request key. */
							__( 'Skipped quick redirect "%1$s": empty destination.', 'pv-redirects' ),
							$key
						);
						continue;
					}

					// Map keys are path-only, so a key carrying a URL scheme (mailto:…,
					// https://…) can never match a request — skip it for manual review.
					$key_scheme = wp_parse_url( $key, PHP_URL_SCHEME );

					if ( is_string( $key_scheme ) && $key_scheme !== '' ) {
						$warnings[] = sprintf(
							/* translators: %s: quick redirect request key. */
							__( 'Skipped quick redirect "%1$s": its request key carries a URL scheme, and map keys are path-only, so it can never match a request — review it.', 'pv-redirects' ),
							$key
						);
						continue;
					}

					$request = self::normalizeQuickKey( $key );

					// Present in the PV store: previously migrated or manually created. Never
					// overwrite — skip and count (docs/decisions/0003 idempotency).
					if ( array_key_exists( $request, $pvRows ) || array_key_exists( $request, $pendingOptionRows ) ) {
						++$quick['skipped_existing'];
						$deleteQuickKeys[] = $key;
						++$quickTotals['handled'];
						continue;
					}

					$pendingOptionRows[$request] = [ 'to' => $value, 'status' => $quickStatus ];

					++$quick['migrated'];
					$deleteQuickKeys[] = $key;
					++$quickTotals['handled'];
				}
			}
		}

		// --- Per-post redirects: _pprredirect_* meta -> _pv_redirect_* meta. ----------------

		$deletePostIds = [];

		if ( ! $flags['skip_posts'] ) {
			if ( ! $flags['dry_run'] ) {
				// Detach every `_pv_redirect_*` meta-write hook so N posts cost one rebuild.
				foreach ( self::REBUILD_HOOKS as $hook ) {
					remove_action( $hook, '\PV\Redirects\PostMetaStore::metaUpdated', 10 );
				}
			}

			try {
				self::migratePosts( $flags['dry_run'], $posts, $warnings, $deletePostIds );
			} finally {
				if ( ! $flags['dry_run'] ) {
					foreach ( self::REBUILD_HOOKS as $hook ) {
						add_action( $hook, '\PV\Redirects\PostMetaStore::metaUpdated', 10, 3 );
					}
				}
			}
		}

		// --- Apply writes (real runs only), then rebuild once. ------------------------------

		$rebuilt = false;

		if ( ! $flags['dry_run'] ) {
			if ( $pendingOptionRows !== [] ) {
				// Key-preserving merge: array_merge() would renumber integer-like request
				// keys (option storage can hold them), which would silently rewrite rows.
				$merged = $pvRows;

				foreach ( $pendingOptionRows as $request_key => $row ) {
					$merged[ $request_key ] = $row;
				}

				$filtered = apply_filters( 'pv/redirects/option_store_pre_save', $merged );

				update_option( self::PV_OPTION, is_array( $filtered ) ? $filtered : $merged, true );
			}

			if ( $settingsPlan['partial'] !== [] ) {
				// Settings::update() performs the one final rebuild itself.
				Settings::update( $settingsPlan['partial'] );
			} else {
				RedirectMap::rebuild();
			}

			$rebuilt = true;
		}

		// --- delete_source: only what this run copied or found already migrated. ------------

		if ( $flags['delete_source'] && ! $flags['dry_run'] ) {
			if ( ! $flags['skip_quick'] && $quickTotals['option_present'] && ( $quickTotals['rows'] === 0 || $quickTotals['rows'] === $quickTotals['handled'] ) ) {
				// Every row handled (or none existed): drop both parallel option maps wholesale,
				// the way QPPR's own uninstall does.
				delete_option( self::QPPR_REDIRECTS_OPTION );
				delete_option( self::QPPR_REDIRECTS_META_OPTION );
			} elseif ( $deleteQuickKeys !== [] ) {
				self::deleteQuickRows( $deleteQuickKeys );
			}

			if ( ! $flags['skip_posts'] ) {
				foreach ( $deletePostIds as $post_id ) {
					foreach ( self::QPPR_POST_META_KEYS as $meta_key ) {
						delete_post_meta( $post_id, $meta_key );
					}
				}
			}

			foreach ( $settingsPlan['delete'] as $option_name ) {
				delete_option( $option_name );
			}
		}

		return [
			'quick'    => $quick,
			'posts'    => $posts,
			'settings' => $settingsPlan['report'],
			'warnings' => $warnings,
			'dry_run'  => $flags['dry_run'],
			'rebuilt'  => $rebuilt,
		];
	}

	/**
	 * Migrate every post carrying a non-empty `_pprredirect_url`, any post type (private
	 * and non-public included — the map builder's active-only query must NOT be reused
	 * here), any status short of trash. Inactive redirects keep their inactive flag.
	 *
	 * @param  bool         $dryRun         Write nothing, count everything.
	 * @param  PostsReport  $posts          Counters, mutated.
	 * @param  list<string> $warnings       Warnings, mutated.
	 * @param  list<int>    $deletePostIds  Post IDs whose QPPR meta may be deleted, mutated.
	 */
	private static function migratePosts( bool $dryRun, array &$posts, array &$warnings, array &$deletePostIds ): void {
		$query = new WP_Query(
			[
				'post_type'              => self::migratablePostTypes(),
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => self::QPPR_META_URL,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$metaDowngraded  = 0;
		$unknownType     = 0;
		$droppedFeatures = 0;
		$inactive        = 0;

		$foundPosts = is_array( $query->posts ) ? $query->posts : [];

		foreach ( $foundPosts as $post_id ) {
			if ( ! is_int( $post_id ) ) {
				continue;
			}

			$url = get_post_meta( $post_id, self::QPPR_META_URL, true );

			if ( ! is_string( $url ) || $url === '' ) {
				continue;
			}

			// Already migrated or hand-set in PV: never overwrite (ADR-0003 idempotency).
			$existing = get_post_meta( $post_id, PostMetaStore::META_TO, true );

			if ( is_string( $existing ) && $existing !== '' ) {
				++$posts['skipped_existing'];
				$deletePostIds[] = $post_id;
				continue;
			}

			$activeRaw = get_post_meta( $post_id, self::QPPR_META_ACTIVE, true );
			$active    = $activeRaw === '1' || $activeRaw === 1;

			$resolved = self::resolvePostStatus( get_post_meta( $post_id, self::QPPR_META_TYPE, true ) );

			if ( $resolved['downgraded'] ) {
				++$metaDowngraded;
			}

			if ( $resolved['unknown'] ) {
				++$unknownType;
			}

			if (
				self::featureFlag( get_post_meta( $post_id, self::QPPR_META_NEWWINDOW, true ) )
				|| self::featureFlag( get_post_meta( $post_id, self::QPPR_META_NOFOLLOW, true ) )
				|| self::featureFlag( get_post_meta( $post_id, self::QPPR_META_REWRITE, true ) )
			) {
				++$droppedFeatures;
			}

			if ( ! $dryRun ) {
				// Same keys and value shapes PostMetaStore::set() writes — direct writes so
				// the map is not rebuilt per post.
				update_post_meta( $post_id, PostMetaStore::META_TO, $url );
				update_post_meta( $post_id, PostMetaStore::META_STATUS, (string) $resolved['status'] );
				update_post_meta( $post_id, PostMetaStore::META_ACTIVE, $active ? '1' : '' );
			}

			++$posts['migrated'];

			if ( ! $active ) {
				++$posts['inactive_migrated'];
				++$inactive;
			}

			$deletePostIds[] = $post_id;
		}

		$posts['meta_type_downgraded'] = $metaDowngraded;
		$posts['dropped_features']     = $droppedFeatures;

		if ( $inactive > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of posts. */
				__( '%1$d migrated post redirect(s) were inactive in QPPR and are preserved as inactive in PV Redirects.', 'pv-redirects' ),
				$inactive
			);
		}

		if ( $metaDowngraded > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of posts. */
				__( '%1$d post redirect(s) used the QPPR "meta" refresh type; PV Redirects does not support meta refresh and their status was set to 301.', 'pv-redirects' ),
				$metaDowngraded
			);
		}

		if ( $unknownType > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of posts. */
				__( '%1$d post redirect(s) had an unrecognized "_pprredirect_type" value; their status was set to 301 — review these posts.', 'pv-redirects' ),
				$unknownType
			);
		}

		if ( $droppedFeatures > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of posts. */
				__( '%1$d post redirect(s) used dropped link features (new window, nofollow, or link rewriting); PV Redirects does not support them.', 'pv-redirects' ),
				$droppedFeatures
			);
		}
	}

	/**
	 * Compute the QPPR settings mapping without writing: the report values, the partial
	 * array for Settings::update(), and the QPPR option names safe to delete afterwards.
	 * The global-URL override is never copied (PV has no such feature) and never deleted
	 * — it only raises a manual-review warning.
	 *
	 * @param  list<string>  $warnings  Warnings, mutated.
	 *
	 * @return SettingsPlan
	 */
	private static function resolveSettings( array &$warnings ): array {
		$report  = [ 'case_sensitive' => null, 'default_status' => null, 'disabled' => false ];
		$partial = [];
		$delete  = [];

		$caseSensitive = get_option( self::QPPR_CASE_OPTION );

		if ( $caseSensitive !== false ) {
			$on = self::truthyFlag( $caseSensitive );

			$report['case_sensitive']  = $on;
			$partial['case_sensitive'] = $on;
			$delete[]                  = self::QPPR_CASE_OPTION;
		}

		$globalType = get_option( self::QPPR_TYPE_OPTION );

		if ( is_string( $globalType ) || is_int( $globalType ) ) {
			$globalType = trim( (string) $globalType );

			if ( $globalType !== '' && $globalType !== '0' ) {
				$status = null;

				if ( ctype_digit( $globalType ) ) {
					$candidate = (int) $globalType;
					$status    = in_array( $candidate, OptionStore::STATUSES, true ) ? $candidate : null;
				}

				if ( $status !== null ) {
					$report['default_status']  = $status;
					$partial['default_status'] = $status;
					$delete[]                  = self::QPPR_TYPE_OPTION;
				} elseif ( $globalType === 'meta' ) {
					$report['default_status']  = 301;
					$partial['default_status'] = 301;
					$delete[]                  = self::QPPR_TYPE_OPTION;
					$warnings[]                = __( 'QPPR global redirect type was "meta" (meta refresh); PV Redirects does not support meta refresh, so the default status was set to 301.', 'pv-redirects' );
				} else {
					$warnings[] = sprintf(
						/* translators: %s: raw ppr_override-redirect-type value. */
						__( 'QPPR global redirect type "%1$s" is not a supported status; the PV default status was left unchanged.', 'pv-redirects' ),
						$globalType
					);
				}
			}
		}

		$allDisabled = get_option( self::QPPR_ACTIVE_OPTION, '0' );

		if ( self::truthyFlag( $allDisabled ) ) {
			$report['disabled']  = true;
			$partial['enabled']  = false;
			$delete[]            = self::QPPR_ACTIVE_OPTION;
			$warnings[]          = __( 'QPPR override "ppr_override-active" was on: all QPPR redirects were disabled. PV Redirects was set to disabled to match — re-enable redirects in the PV Redirects admin when ready.', 'pv-redirects' );
		}

		$globalUrl = get_option( self::QPPR_URL_OPTION );

		if ( is_string( $globalUrl ) && $globalUrl !== '' ) {
			$warnings[] = sprintf(
				/* translators: %s: the global override destination URL. */
				__( 'QPPR global URL override is set ("%1$s"): PV Redirects has no global-override feature, so it was not migrated — review it and remove the option manually (it is kept even with --delete-source).', 'pv-redirects' ),
				$globalUrl
			);
		}

		return [ 'report' => $report, 'partial' => $partial, 'delete' => $delete ];
	}

	/**
	 * Map a raw `_pprredirect_type` value onto a PV status. QPPR stores numeric strings
	 * ('301'|'302'|'307') or 'meta'; anything else in the wild ('0', '', leftovers) falls
	 * back to 301 and is flagged for review.
	 *
	 * @return array{status: int, downgraded: bool, unknown: bool}
	 */
	private static function resolvePostStatus( mixed $raw ): array {
		if ( is_int( $raw ) && in_array( $raw, OptionStore::STATUSES, true ) ) {
			return [ 'status' => $raw, 'downgraded' => false, 'unknown' => false ];
		}

		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );

			if ( ctype_digit( $trimmed ) ) {
				$status = (int) $trimmed;

				if ( in_array( $status, OptionStore::STATUSES, true ) ) {
					return [ 'status' => $status, 'downgraded' => false, 'unknown' => false ];
				}
			}

			if ( $trimmed === 'meta' ) {
				return [ 'status' => 301, 'downgraded' => true, 'unknown' => false ];
			}
		}

		return [ 'status' => 301, 'downgraded' => false, 'unknown' => true ];
	}

	/**
	 * Count `quickppr_redirects_meta` rows that actually used the dropped newwindow/
	 * nofollow features — QPPR writes 0 for every row, so only non-zero rows count.
	 *
	 * @param  array<string, mixed>  $metaRows
	 * @param  list<string>          $warnings  Warnings, mutated.
	 */
	private static function countDroppedQuickMeta( array $metaRows, array &$warnings ): int {
		$dropped = 0;

		foreach ( $metaRows as $row ) {
			if ( is_array( $row ) && ( self::truthyFlag( $row['newwindow'] ?? null ) || self::truthyFlag( $row['nofollow'] ?? null ) ) ) {
				++$dropped;
			}
		}

		if ( $dropped > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of quick redirects. */
				__( '%1$d quick redirect(s) had "open in new window" and/or "nofollow" enabled; PV Redirects does not support these features and they were dropped.', 'pv-redirects' ),
				$dropped
			);
		}

		return $dropped;
	}

	/**
	 * QPPR's own key normalization (parity for idempotency checks): trim, ensure a
	 * leading '/' unless the key already carries a scheme (e.g. 'mailto:…'). Trailing
	 * slashes and query strings stay exactly as stored — Matcher::normalizeExactKey
	 * handles them at map build time.
	 */
	private static function normalizeQuickKey( string $key ): string {
		$key = trim( $key );

		if ( ! str_starts_with( $key, '/' ) && preg_match( '#^[a-z][a-z0-9+.-]*://#i', $key ) !== 1 ) {
			$key = '/'.$key;
		}

		return $key;
	}

	/**
	 * Remove the handled keys from both parallel quick-redirect options, deleting an
	 * option outright once it empties.
	 *
	 * @param  list<string>  $keys  Raw QPPR keys to remove.
	 */
	private static function deleteQuickRows( array $keys ): void {
		$drop = array_flip( $keys );

		foreach ( [ self::QPPR_REDIRECTS_OPTION, self::QPPR_REDIRECTS_META_OPTION ] as $option_name ) {
			$rows = get_option( $option_name, [] );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $key => $value ) {
				if ( is_string( $key ) && array_key_exists( $key, $drop ) ) {
					unset( $rows[$key] );
				}
			}

			if ( $rows === [] ) {
				delete_option( $option_name );
			} else {
				update_option( $option_name, $rows, true );
			}
		}
	}

	/**
	 * Every registered post type except revisions — migration must also see private and
	 * non-public post types QPPR allowed redirects on.
	 *
	 * @return list<string>
	 */
	private static function migratablePostTypes(): array {
		$types = [];

		foreach ( get_post_types( [], 'names' ) as $type ) {
			if ( $type !== 'revision' ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * @param  array<string, mixed>  $assoc
	 *
	 * @return MigrationFlags
	 */
	private static function flags( array $assoc ): array {
		return [
			'dry_run'       => self::flag( $assoc, 'dry_run' ),
			'delete_source' => self::flag( $assoc, 'delete_source' ),
			'skip_quick'    => self::flag( $assoc, 'skip_quick' ),
			'skip_posts'    => self::flag( $assoc, 'skip_posts' ),
		];
	}

	/**
	 * WP-CLI passes flags as '' when present and omits them otherwise; programmatic
	 * callers may pass real booleans.
	 *
	 * @param  array<string, mixed>  $assoc
	 */
	private static function flag( array $assoc, string $key ): bool {
		$value = $assoc[$key] ?? false;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return ! in_array( strtolower( $value ), [ '', '0', 'false', 'no', 'off' ], true );
		}

		return is_int( $value ) && $value !== 0;
	}

	/**
	 * QPPR's stored feature flags are loose: "_blank" for the new window, "1" for nofollow
	 * and link rewriting, and ""/'0' when off. Anything non-empty and non-zero is enabled.
	 */
	private static function featureFlag( mixed $value ): bool {
		return $value !== null && $value !== false && $value !== '' && $value !== '0' && $value !== 0;
	}

	/**
	 * QPPR's stored feature flags: string/int 1 means enabled.
	 */
	private static function truthyFlag( mixed $value ): bool {
		return $value === '1' || $value === 1 || $value === true;
	}
}
