<?php
/**
 * Last-good backup of a gist in post meta.
 *
 * The idea of keeping the raw gist in post meta and reading GistPress' legacy
 * `_gist_raw_*` meta is based on GistPress 3.0.3 by Brady Vercher and Gary Jones.
 * Only plain arrays are stored, never PHP objects.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Backup storage and (de)serialization of payloads as plain arrays.
 */
final class Backup {

	/**
	 * Meta key for a gist/file pair.
	 *
	 * @param string $id   Gist ID.
	 * @param string $file File name or ''.
	 * @return string
	 */
	public static function meta_key( string $id, string $file ): string {
		return '_gistful_backup_' . md5( "$id|$file" );
	}

	/**
	 * Read the backup, falling back to the legacy GistPress meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $id      Gist ID.
	 * @param string $file    File name or ''.
	 * @return Gist_Payload|null
	 */
	public static function get( int $post_id, string $id, string $file ): ?Gist_Payload {
		$stored  = get_post_meta( $post_id, self::meta_key( $id, $file ), true );
		$payload = self::unpack( $stored, 'backup' );
		if ( $payload ) {
			return $payload;
		}

		$json_url = "https://gist.github.com/$id.json";
		if ( '' !== $file ) {
			$json_url = add_query_arg( 'file', urlencode( $file ), $json_url ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Same formula as GistPress.
		}
		$legacy = get_post_meta( $post_id, '_gist_raw_' . md5( $json_url ), true );
		if ( is_string( $legacy ) && '' !== $legacy ) {
			return new Gist_Payload( $legacy, array(), 'backup' );
		}

		return null;
	}

	/**
	 * Store a payload unless the stored copy is identical.
	 *
	 * @param int          $post_id Post ID.
	 * @param string       $id      Gist ID.
	 * @param string       $file    File name or ''.
	 * @param Gist_Payload $p       Payload.
	 * @return void
	 */
	public static function maybe_store( int $post_id, string $id, string $file, Gist_Payload $p ): void {
		if ( $p->is_empty() ) {
			return;
		}
		// An API copy has no highlighting: never let it replace a highlighted one (Gistful or GistPress).
		if ( '' === $p->html ) {
			$current = self::get( $post_id, $id, $file );
			if ( $current && '' !== $current->html ) {
				return;
			}
		}
		$data   = self::pack( $p );
		$key    = self::meta_key( $id, $file );
		$stored = get_post_meta( $post_id, $key, true );
		if ( is_array( $stored ) && isset( $stored['hash'] ) && $stored['hash'] === $data['hash'] ) {
			return;
		}
		update_post_meta( $post_id, $key, wp_slash( $data ) );
	}

	/**
	 * Payload as a plain array.
	 *
	 * @param Gist_Payload $p Payload.
	 * @return array{html: string, files: string[], raw_files: array<string, string>, truncated: bool, hash: string}
	 */
	public static function pack( Gist_Payload $p ): array {
		$data = array(
			'html'      => $p->html,
			'files'     => array_values( $p->files ),
			'raw_files' => $p->raw_files,
			'truncated' => $p->truncated,
		);

		$data['hash'] = md5( (string) wp_json_encode( $data ) );

		return $data;
	}

	/**
	 * Rebuild a payload from a stored array; null when the shape is wrong.
	 *
	 * @param mixed  $data   Stored value.
	 * @param string $source Source label for the rebuilt payload.
	 * @return Gist_Payload|null
	 */
	public static function unpack( mixed $data, string $source ): ?Gist_Payload {
		if ( ! is_array( $data )
			|| ! isset( $data['html'], $data['files'], $data['raw_files'], $data['truncated'], $data['hash'] )
			|| ! is_string( $data['html'] ) || ! is_array( $data['files'] ) || ! is_array( $data['raw_files'] )
			|| ! is_bool( $data['truncated'] ) || ! is_string( $data['hash'] ) ) {
			return null;
		}
		foreach ( $data['files'] as $name ) {
			if ( ! is_string( $name ) ) {
				return null;
			}
		}
		foreach ( $data['raw_files'] as $name => $content ) {
			if ( ! is_string( $name ) || ! is_string( $content ) ) {
				return null;
			}
		}
		$p = new Gist_Payload( $data['html'], $data['files'], $source, $data['raw_files'], $data['truncated'] );

		return $p->is_empty() ? null : $p;
	}
}
