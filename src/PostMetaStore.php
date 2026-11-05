<?php

namespace PV\Redirects;

use InvalidArgumentException;
use WP_Post;
use WP_Query;

use function array_values;
use function ctype_digit;
use function current_user_can;
use function delete_post_meta;
use function get_post_meta;
use function get_post_types;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sanitize_text_field;
use function sprintf;
use function update_post_meta;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Canonical storage for per-post redirects, on any public post type:
 * - `_pv_redirect_to`     (string destination)
 * - `_pv_redirect_status` (301|302|307|308, default 301)
 * - `_pv_redirect_active` ('1'|'')
 *
 * The map builder consumes activeRedirectPosts(); every mutation rebuilds the computed map.
 */
final class PostMetaStore {

	public const META_TO     = '_pv_redirect_to';
	public const META_STATUS = '_pv_redirect_status';
	public const META_ACTIVE = '_pv_redirect_active';

	private const META_KEYS = [ self::META_TO, self::META_STATUS, self::META_ACTIVE ];

	private const NONCE_ACTION = 'pv_redirects_post';

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
				sprintf( 'Invalid redirect status %d; expected one of: 301, 302, 307, 308.', $status )
			);
		}

		update_post_meta( $post_id, self::META_TO, $to );
		update_post_meta( $post_id, self::META_STATUS, (string) $status );
		update_post_meta( $post_id, self::META_ACTIVE, $active ? '1' : '' );

		RedirectMap::rebuild();
	}

	/**
	 * Remove all three meta keys and rebuild the map.
	 */
	public static function delete( int $post_id ): void {
		foreach ( self::META_KEYS as $key ) {
			delete_post_meta( $post_id, $key );
		}

		RedirectMap::rebuild();
	}

	/**
	 * save_post hook: guard autosave/revision, verify caps and our classic meta-box nonce,
	 * then persist what was posted. Only the nonce-bearing path acts here; REST and
	 * programmatic meta writes are covered by metaUpdated() instead.
	 */
	public static function savePost( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = $_POST['pv_redirects_nonce'] ?? '';

		if ( ! is_string( $nonce ) || $nonce === '' ) {
			return;
		}

		if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) !== 1 ) {
			return;
		}

		$raw_to = $_POST[self::META_TO] ?? '';
		$to     = is_string( $raw_to ) ? sanitize_text_field( wp_unslash( $raw_to ) ) : '';

		if ( $to === '' ) {
			self::delete( $post_id );

			return;
		}

		self::set( $post_id, $to, self::postedStatus(), ( $_POST[self::META_ACTIVE] ?? '' ) === '1' );
	}

	/**
	 * updated_post_meta hook: catch `_pv_redirect_*` writes that bypass save_post with our
	 * nonce (Gutenberg sidebar via REST, programmatic writes) so the map never drifts.
	 */
	public static function metaUpdated( int $meta_id, int $post_id, string $meta_key ): void {
		if ( ! in_array( $meta_key, self::META_KEYS, true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

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

	/**
	 * Status posted from the classic meta box: valid values pass, anything else falls back
	 * to the configured default status.
	 */
	private static function postedStatus(): int {
		$raw = $_POST[self::META_STATUS] ?? '';

		if ( is_int( $raw ) && in_array( $raw, OptionStore::STATUSES, true ) ) {
			return $raw;
		}

		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$status = (int) $raw;

			if ( in_array( $status, OptionStore::STATUSES, true ) ) {
				return $status;
			}
		}

		$default = Settings::get( 'default_status' );

		return is_int( $default ) && in_array( $default, OptionStore::STATUSES, true ) ? $default : 301;
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
