<?php
/**
 * Transient cache helpers.
 *
 * Cache layering is based on the idea of GistPress 3.0.3 by Brady Vercher and
 * Gary Jones. Only plain strings and arrays are ever stored, never objects.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Keys, TTLs, negative cache and per-post key tracking.
 */
final class Cache {

	const PREFIX       = 'gistful_';
	const NEGATIVE_TTL = 600;
	const META_KEYS    = '_gistful_keys';

	/**
	 * Key of the processed-HTML cache entry.
	 *
	 * @param array<string, mixed> $attr Normalized attributes.
	 * @return string
	 */
	public static function html_key( array $attr ): string {
		unset( $attr['oembed'] );
		ksort( $attr );

		return self::PREFIX . 'h_' . md5( (string) wp_json_encode( $attr ) );
	}

	/**
	 * Key of the raw (remote payload) cache entry.
	 *
	 * @param string $id   Gist ID.
	 * @param string $file File name or ''.
	 * @return string
	 */
	public static function raw_key( string $id, string $file ): string {
		return self::PREFIX . 'r_' . md5( "$id|$file" );
	}

	/**
	 * Read an entry.
	 *
	 * @param string $key Key.
	 * @return mixed False when missing.
	 */
	public static function get( string $key ): mixed {
		return get_transient( $key );
	}

	/**
	 * Store an entry. A TTL of zero or less stores nothing (caching disabled).
	 *
	 * @param string   $key Key.
	 * @param mixed    $v   Value (string or array only).
	 * @param int|null $ttl Seconds, or null for the configured default.
	 * @return void
	 */
	public static function set( string $key, mixed $v, ?int $ttl = null ): void {
		if ( null === $ttl ) {
			$ttl = (int) apply_filters( 'gistful_cache_ttl', (int) Settings_Store::get( 'cache_hours' ) * HOUR_IN_SECONDS );
		}
		if ( $ttl <= 0 ) {
			return;
		}
		set_transient( $key, $v, $ttl );
	}

	/**
	 * Remember that fetching this raw key just failed.
	 *
	 * @param string $raw_key Raw cache key.
	 * @return void
	 */
	public static function set_negative( string $raw_key ): void {
		set_transient( $raw_key . '_neg', 1, self::NEGATIVE_TTL );
	}

	/**
	 * Whether a recent fetch of this raw key failed.
	 *
	 * @param string $raw_key Raw cache key.
	 * @return bool
	 */
	public static function is_negative( string $raw_key ): bool {
		return false !== get_transient( $raw_key . '_neg' );
	}

	/**
	 * Record which cache keys a post uses, so they can be flushed on save.
	 *
	 * @param int    $post_id Post ID.
	 * @param string ...$keys Cache keys.
	 * @return void
	 */
	public static function track( int $post_id, string ...$keys ): void {
		$known  = get_post_meta( $post_id, self::META_KEYS, true );
		$known  = is_array( $known ) ? $known : array();
		$merged = array_values( array_unique( array_merge( $known, $keys ) ) );
		if ( $merged !== $known ) {
			update_post_meta( $post_id, self::META_KEYS, $merged );
		}
	}

	/**
	 * Delete the cache entries tracked for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function flush_post( int $post_id ): void {
		$keys = get_post_meta( $post_id, self::META_KEYS, true );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				if ( is_string( $key ) && 0 === strpos( $key, self::PREFIX ) ) {
					delete_transient( $key );
					delete_transient( $key . '_neg' );
				}
			}
		}
		delete_post_meta( $post_id, self::META_KEYS );
	}

	/**
	 * Delete every Gistful transient.
	 *
	 * @return void
	 */
	public static function flush_all(): void {
		global $wpdb;

		// Go through the API so the object cache stays in sync. With an external
		// object cache transients are not in the options table and expire on their own.
		$like  = $wpdb->esc_like( '_transient_' ) . '%' . $wpdb->esc_like( self::PREFIX ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, '_transient_timeout_' . self::PREFIX ) ) {
				delete_option( $name );
			} elseif ( 0 === strpos( $name, '_transient_' . self::PREFIX ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			}
		}
	}
}
