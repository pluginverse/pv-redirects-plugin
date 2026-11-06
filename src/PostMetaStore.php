<?php

namespace PV\Redirects;

use InvalidArgumentException;
use WP_Post;
use WP_Query;

use function add_action;
use function array_values;
use function ctype_digit;
use function delete_post_meta;
use function get_post_meta;
use function get_post_types;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function update_post_meta;
use function wp_is_post_autosave;
use function wp_is_post_revision;

/**
 * Canonical storage for per-post redirects, on any public post type:
 * - `_pv_redirect_to`     (string destination)
 * - `_pv_redirect_status` (301|302|307|308, default 301)
 * - `_pv_redirect_active` ('1'|'')
 *
 * The map builder consumes activeRedirectPosts(). Direct mutators (set/delete) and the
 * transition_post_status hook rebuild the computed map immediately; the added/updated/
 * deleted_post_meta hooks debounce to ONE rebuild at shutdown so a sidebar save's burst
 * of meta writes costs a single rebuild per request.
 */
final class PostMetaStore {

	public const META_TO     = '_pv_redirect_to';
	public const META_STATUS = '_pv_redirect_status';
	public const META_ACTIVE = '_pv_redirect_active';

	private const META_KEYS = [ self::META_TO, self::META_STATUS, self::META_ACTIVE ];

	/**
	 * Whether a debounced map rebuild is pending for shutdown. Meta-key hooks can fire
	 * many times per request (three keys per sidebar save); this flag collapses them into
	 * one RedirectMap::rebuild() at shutdown. Immediate rebuilds clear the flag.
	 */
	private static bool $map_rebuild_pending = false;

	/**
	 * The per-post redirect for one post, defaults included.
	 *
	 * @return array{to: string, status: int, active: bool}
	 */
	public static function get( int $post_id ): array {
		$to = get_post_meta( $post_id, self::META_TO, true );

		return [
			'to'     => is_string( $to ) ? $to : '',
			'status' => self::sanitizeStatus( get_post_meta( $post_id, self::META_STATUS, true ) ),
			'active' => get_post_meta( $post_id, self::META_ACTIVE, true ) === '1',
		];
	}

	/**
	 * Store (or overwrite) the per-post redirect and rebuild the map.
	 */
	public static function set( int $post_id, string $to, int $status, bool $active ): void {
		if ( ! in_array( $status, OptionStore::STATUSES, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid redirect status %d; expected one of: 301, 302, 307, 308.', $status ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is handled at its output boundary.
			);
		}

		update_post_meta( $post_id, self::META_TO, $to );
		update_post_meta( $post_id, self::META_STATUS, (string) $status );
		update_post_meta( $post_id, self::META_ACTIVE, $active ? '1' : '' );

		RedirectMap::rebuild();

		// The writes above already fired the meta hooks; the map is current, so drop any
		// pending shutdown rebuild.
		self::$map_rebuild_pending = false;
	}

	/**
	 * Remove all three meta keys and rebuild the map.
	 */
	public static function delete( int $post_id ): void {
		foreach ( self::META_KEYS as $key ) {
			delete_post_meta( $post_id, $key );
		}

		RedirectMap::rebuild();

		// Same as set(): the map is current, so drop any pending shutdown rebuild.
		self::$map_rebuild_pending = false;
	}

	/**
	 * added/updated/deleted_post_meta hook: a `_pv_redirect_*` write that bypasses the
	 * store mutators (Gutenberg sidebar via REST, programmatic writes) marks the map
	 * dirty and schedules ONE rebuild at shutdown — N meta writes per request cost a
	 * single rebuild.
	 *
	 * The first argument is unused — WP passes the meta id for added/updated and the list
	 * of deleted ids for deleted_{$meta_type}_meta — it only matches the hook shape.
	 */
	public static function metaUpdated( mixed $meta_id, int $post_id, string $meta_key ): void {
		if ( ! in_array( $meta_key, self::META_KEYS, true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::scheduleMapRebuild();
	}

	/**
	 * transition_post_status hook: trash/untrash/publish fire no meta hooks, so a post
	 * that carries a redirect must trigger the rebuild itself — immediately, since the
	 * status flip changes what activeRedirectPosts() finds ('any' excludes trash).
	 */
	public static function statusTransitioned( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$to = get_post_meta( $post->ID, self::META_TO, true );

		if ( ! is_string( $to ) || $to === '' ) {
			return;
		}

		RedirectMap::rebuild();

		self::$map_rebuild_pending = false;
	}

	/**
	 * Mark the map dirty and, once per request, schedule the shutdown rebuild that
	 * consumes the flag.
	 */
	private static function scheduleMapRebuild(): void {
		if ( self::$map_rebuild_pending ) {
			return;
		}

		self::$map_rebuild_pending = true;

		add_action( 'shutdown', '\PV\Redirects\PostMetaStore::rebuildPendingMap', 10, 0 );
	}

	/**
	 * shutdown hook for the debounced meta-write rebuild: one rebuild per request, only
	 * when a meta hook actually marked the map dirty.
	 */
	public static function rebuildPendingMap(): void {
		if ( ! self::$map_rebuild_pending ) {
			return;
		}

		self::$map_rebuild_pending = false;

		RedirectMap::rebuild();
	}

	/**
	 * All active per-post redirects keyed by post ID — the map builder's input.
	 *
	 * @return array<int, array{to: string, status: int}>
	 */
	public static function activeRedirectPosts(): array {
		$post_types = array_values( (array) get_post_types( [ 'public' => true ], 'names' ) );

		$query = new WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => [ 'any', 'inherit' ],
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'     => self::META_TO,
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'   => self::META_ACTIVE,
						'value' => '1',
					],
				],
			]
		);

		$redirects = [];

		$foundPosts = is_array( $query->posts ) ? $query->posts : [];

		foreach ( $foundPosts as $post_id ) {
			if ( ! is_int( $post_id ) ) {
				continue;
			}

			$to = get_post_meta( $post_id, self::META_TO, true );

			if ( ! is_string( $to ) || $to === '' ) {
				continue;
			}

			$redirects[$post_id] = [
				'to'     => $to,
				'status' => self::sanitizeStatus( get_post_meta( $post_id, self::META_STATUS, true ) ),
			];
		}

		return $redirects;
	}

	private static function sanitizeStatus( mixed $value ): int {
		if ( is_int( $value ) && in_array( $value, OptionStore::STATUSES, true ) ) {
			return $value;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$status = (int) $value;

			if ( in_array( $status, OptionStore::STATUSES, true ) ) {
				return $status;
			}
		}

		return 301;
	}
}
