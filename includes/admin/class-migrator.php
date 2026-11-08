<?php
/**
 * Migration from GistPress: scan, import backups, clean up, convert to blocks.
 *
 * The legacy data layout (`_gist_raw_{md5(json url)}` meta, `gist_html_*`,
 * `gist_files_*` transients, stylesheet options) is based on GistPress 3.0.3 by
 * Brady Vercher and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful\Admin;

use Gistful\Attributes;
use Gistful\Backup;
use Gistful\Conflicts;
use Gistful\Gist_Payload;
use Gistful\Gist_Url;
use Gistful\Prefetch;
use Gistful\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Batched, idempotent migration operations. Nothing here runs by itself:
 * every call is triggered by an explicit, confirmed admin action.
 */
final class Migrator {

	const LEGACY_META               = '_gist_raw_';
	const LEGACY_OPTIONS            = array( 'gistpress_stylesheet', 'blazersix_gist_oembed_stylesheet' );
	const LEGACY_TRANSIENT_PREFIXES = array( 'gist_html_', '_gist_raw_', 'gist_files_' );
	const CONVERT_LIMIT             = 500;

	/**
	 * Read-only summary of what can be migrated.
	 *
	 * @return array{posts_with_gists: int, legacy_meta: int, legacy_transients: int, legacy_option: bool, block_posts_convertible: int}
	 */
	public static function scan(): array {
		global $wpdb;

		$l1 = '%' . $wpdb->esc_like( 'gist.github.com' ) . '%';
		$l2 = '%' . $wpdb->esc_like( '[gist' ) . '%';
		$l3 = '%' . $wpdb->esc_like( 'wp:gistful/gist' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','auto-draft') AND post_status NOT IN ('trash','auto-draft') AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)",
				$l1,
				$l2,
				$l3
			)
		);
		// phpcs:enable

		$legacy_option = false;
		foreach ( self::LEGACY_OPTIONS as $name ) {
			if ( false !== get_option( $name, false ) ) {
				$legacy_option = true;
			}
		}

		return array(
			'posts_with_gists'        => $posts,
			'legacy_meta'             => self::count_legacy_meta(),
			'legacy_transients'       => count( self::legacy_transient_names( 0 ) ),
			'legacy_option'           => $legacy_option,
			'block_posts_convertible' => count( self::convertible_ids() ),
		);
	}

	/**
	 * Number of legacy backup meta rows.
	 *
	 * @return int
	 */
	private static function count_legacy_meta(): int {
		global $wpdb;

		$like = $wpdb->esc_like( self::LEGACY_META ) . '%';

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(meta_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * GistPress' key for a gist/file pair.
	 *
	 * @param string $id   Gist ID.
	 * @param string $file File name or ''.
	 * @return string
	 */
	public static function legacy_key( string $id, string $file ): string {
		$json_url = "https://gist.github.com/$id.json";
		if ( '' !== $file ) {
			$json_url = add_query_arg( 'file', urlencode( $file ), $json_url ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Same formula as GistPress.
		}

		return self::LEGACY_META . md5( $json_url );
	}

	/**
	 * Copy legacy `_gist_raw_*` rows into Gistful backups (one batch).
	 *
	 * Rows that match no gist of their post are skipped. An existing Gistful
	 * backup is never overwritten.
	 *
	 * @param int $offset Row offset (stable: legacy rows are not deleted here).
	 * @param int $limit  Batch size.
	 * @return array{processed: int, imported: int, skipped: int, next: ?int, total: int}
	 */
	public static function import_backups( int $offset, int $limit = 50 ): array {
		global $wpdb;

		$offset = max( 0, $offset );
		$limit  = max( 1, $limit );
		$like   = $wpdb->esc_like( self::LEGACY_META ) . '%';
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d OFFSET %d", $like, $limit, $offset )
		);

		$maps     = array();
		$imported = 0;
		$skipped  = 0;
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row->post_id;
			if ( ! isset( $maps[ $post_id ] ) ) {
				$post             = get_post( $post_id );
				$maps[ $post_id ] = $post ? self::key_map( $post->post_content ) : array();
			}
			$pair = $maps[ $post_id ][ $row->meta_key ] ?? null;
			if ( $pair && 'imported' === self::import_row( $post_id, $pair[0], $pair[1], (string) $row->meta_value ) ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		$total     = self::count_legacy_meta();
		$processed = count( (array) $rows );
		$next      = $offset + $processed;

		return array(
			'processed' => $processed,
			'imported'  => $imported,
			'skipped'   => $skipped,
			'next'      => $next < $total ? $next : null,
			'total'     => $total,
		);
	}

	/**
	 * Map legacy meta keys of a post content to [id, file] pairs.
	 *
	 * @param string $content Post content.
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function key_map( string $content ): array {
		$map = array();
		foreach ( Prefetch::find_gists( $content ) as $raw_attr ) {
			$attr = Attributes::normalize( $raw_attr );
			if ( '' === $attr['id'] ) {
				continue;
			}
			$map[ self::legacy_key( $attr['id'], $attr['file'] ) ] = array( $attr['id'], $attr['file'] );
		}

		return $map;
	}

	/**
	 * Store one legacy value as a sanitized Gistful backup.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $id      Gist ID.
	 * @param string $file    File name or ''.
	 * @param string $value   Legacy meta value (raw gist div).
	 * @return string 'imported', 'exists' (a Gistful backup is already there) or 'empty'.
	 */
	private static function import_row( int $post_id, string $id, string $file, string $value ): string {
		if ( Backup::unpack( get_post_meta( $post_id, Backup::meta_key( $id, $file ), true ), 'backup' ) ) {
			return 'exists';
		}
		// GistPress stored the div through update_post_meta(), which unslashes: the value is the original.
		// Defence in depth: the renderer sanitizes again, which is idempotent.
		$html = Sanitizer::clean( $value );
		if ( '' === trim( $html ) ) {
			return 'empty';
		}
		Backup::maybe_store( $post_id, $id, $file, new Gist_Payload( $html, array(), 'backup' ) );

		return 'imported';
	}

	/**
	 * Delete GistPress leftovers (one batch). Refuses while GistPress is active,
	 * and while the backup import is unfinished unless forced.
	 *
	 * Rows are deleted as they are processed, so `$offset` is informational;
	 * `next` is `$offset + processed` while anything is left, else null.
	 *
	 * @param int  $offset Progress counter.
	 * @param int  $limit  Batch size per kind (meta rows, transients).
	 * @param bool $force  Skip the "import finished" check.
	 * @return array{processed: int, next: ?int}|\WP_Error
	 */
	public static function cleanup( int $offset, int $limit = 50, bool $force = false ) {
		global $wpdb;

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( Conflicts::gistpress_file() ) ) {
			return new \WP_Error( 'gistful_gistpress_active', __( 'Deactivate GistPress before cleaning up its data.', 'gistful' ), array( 'status' => 409 ) );
		}
		if ( ! $force && ! self::import_complete() ) {
			return new \WP_Error( 'gistful_import_pending', __( 'Import the backups first, or confirm that you want to delete them without importing.', 'gistful' ), array( 'status' => 409 ) );
		}

		$limit     = max( 1, $limit );
		$processed = 0;

		foreach ( self::LEGACY_OPTIONS as $name ) {
			if ( false !== get_option( $name, false ) && delete_option( $name ) ) {
				++$processed;
			}
		}

		$like = $wpdb->esc_like( self::LEGACY_META ) . '%';
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d", $like, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $meta_id ) {
			if ( delete_metadata_by_mid( 'post', (int) $meta_id ) ) {
				++$processed;
			}
		}

		foreach ( self::legacy_transient_names( $limit ) as $name ) {
			// An orphaned timeout row survives delete_transient() when the value row is gone.
			$gone = delete_transient( $name );
			$gone = delete_option( '_transient_timeout_' . $name ) || $gone;
			$gone = delete_option( '_transient_' . $name ) || $gone;
			if ( $gone ) {
				++$processed;
			}
		}

		$left = self::count_legacy_meta() + count( self::legacy_transient_names( 1 ) );
		if ( $left > 0 && 0 === $processed ) {
			return new \WP_Error( 'gistful_cleanup_stalled', __( 'Cleanup could not delete the remaining GistPress data.', 'gistful' ), array( 'status' => 500 ) );
		}

		return array(
			'processed' => $processed,
			'next'      => $left > 0 ? max( 0, $offset ) + $processed : null,
		);
	}

	/**
	 * Whether the import finished, or there is nothing to import.
	 *
	 * @return bool
	 */
	private static function import_complete(): bool {
		if ( 0 === self::count_legacy_meta() ) {
			return true;
		}
		$state = Migration_Controller::state();

		return isset( $state['import'] ) && array_key_exists( 'next', $state['import'] ) && null === $state['import']['next'];
	}

	/**
	 * Names (without the `_transient_` prefix) of legacy transients stored in options.
	 *
	 * @param int $limit Maximum names, 0 for all.
	 * @return string[]
	 */
	private static function legacy_transient_names( int $limit ): array {
		global $wpdb;

		$names = array();
		foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
			foreach ( self::LEGACY_TRANSIENT_PREFIXES as $legacy ) {
				$like = $wpdb->esc_like( $prefix . $legacy ) . '%';
				$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d", $like, $limit > 0 ? $limit : 100000 )
				);
				foreach ( $rows as $option_name ) {
					$names[ substr( $option_name, strlen( $prefix ) ) ] = true;
				}
			}
		}
		$names = array_keys( $names );

		return $limit > 0 ? array_slice( $names, 0, $limit ) : $names;
	}

	/**
	 * Result cap, and scan chunk size, of the convertible list.
	 *
	 * @return int
	 */
	private static function convert_limit(): int {
		/**
		 * Internal, for tests only. Not part of the public API.
		 */
		return max( 1, (int) apply_filters( 'gistful_migration_convert_limit', self::CONVERT_LIMIT ) );
	}

	/**
	 * IDs of block-editor posts that contain convertible gist blocks.
	 *
	 * Scans candidate posts in ID order, chunk by chunk, so already-converted or
	 * non-convertible candidates never hide later posts.
	 *
	 * @return int[]
	 */
	public static function convertible_ids(): array {
		global $wpdb;

		$limit     = self::convert_limit();
		$like_gist = '%' . $wpdb->esc_like( 'gist' ) . '%';
		$like_sc   = '%' . $wpdb->esc_like( '<!-- wp:shortcode' ) . '%';
		$like_emb  = '%' . $wpdb->esc_like( '<!-- wp:embed' ) . '%';
		$last      = 0;
		$out       = array();

		$more = true;
		while ( $more ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('revision','auto-draft') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT %d",
					$last,
					$like_gist,
					$like_sc,
					$like_emb,
					$limit
				)
			);
			foreach ( $ids as $id ) {
				$last = (int) $id;
				$post = get_post( $last );
				if ( $post && has_blocks( $post->post_content ) && self::transform( $post->post_content ) !== $post->post_content ) {
					$out[] = $last;
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
			$more = count( $ids ) >= $limit;
		}

		return $out;
	}

	/**
	 * Preview of a conversion; nothing is saved.
	 *
	 * @param int $post_id Post ID.
	 * @return array{before: string, after: string, hash: string}
	 */
	public static function preview_conversion( int $post_id ): array {
		$post   = get_post( $post_id );
		$before = $post ? $post->post_content : '';
		$after  = $post && has_blocks( $before ) ? self::transform( $before ) : $before;

		return array(
			'before' => $before,
			'after'  => $after,
			'hash'   => md5( $before ),
		);
	}

	/**
	 * Convert a block post: save a revision, then update the content.
	 *
	 * The post is only modified when a revision holding its current content
	 * exists, and (when given) the content still matches the previewed hash.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $hash    md5 of the previewed content, or null to skip the check.
	 * @return bool|\WP_Error True when changed, false when nothing to convert.
	 */
	public static function convert( int $post_id, ?string $hash = null ) {
		$post = get_post( $post_id );
		if ( ! $post || ! has_blocks( $post->post_content ) ) {
			return false;
		}
		if ( null !== $hash && md5( $post->post_content ) !== $hash ) {
			return new \WP_Error( 'gistful_content_changed', __( 'The post changed since the preview. Preview it again.', 'gistful' ), array( 'status' => 409 ) );
		}
		$after = self::transform( $post->post_content );
		if ( $after === $post->post_content ) {
			return false;
		}

		$no_revision = new \WP_Error( 'gistful_no_revision', __( 'Revisions are disabled for this post, so it was not converted.', 'gistful' ), array( 'status' => 409 ) );
		// With a keep limit of 1, the revision made by wp_update_post() would trim the original.
		$keep = wp_revisions_to_keep( $post );
		if ( ! wp_revisions_enabled( $post ) || ( $keep >= 0 && $keep < 2 ) ) {
			return $no_revision;
		}
		wp_save_post_revision( $post_id );
		$latest = null;
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) { // Newest first; autosaves are not recovery points.
			if ( ! wp_is_post_autosave( $revision ) ) {
				$latest = $revision;
				break;
			}
		}
		if ( ! $latest || $latest->post_content !== $post->post_content ) {
			return $no_revision;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $after ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result > 0;
	}

	/**
	 * Rewrite gist embeds and [gist] shortcode blocks as gistful/gist blocks.
	 *
	 * @param string $content Post content.
	 * @return string The same string when nothing converts.
	 */
	public static function transform( string $content ): string {
		$changed = false;
		$blocks  = self::transform_blocks( parse_blocks( $content ), $changed );

		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Recursive block walk.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                             $changed Set when something converted.
	 * @return array<int, array<string, mixed>>
	 */
	private static function transform_blocks( array $blocks, bool &$changed ): array {
		foreach ( $blocks as $i => $block ) {
			$attrs = null;
			$name  = $block['blockName'] ?? '';
			if ( 'core/embed' === $name ) {
				$attrs = self::attrs_from_embed( (array) ( $block['attrs'] ?? array() ), (string) ( $block['innerHTML'] ?? '' ) );
			} elseif ( 'core/shortcode' === $name ) {
				$attrs = self::attrs_from_shortcode( (string) ( $block['innerHTML'] ?? '' ) );
			}
			if ( null !== $attrs ) {
				$blocks[ $i ] = array(
					'blockName'    => 'gistful/gist',
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
				$changed      = true;
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$inner = self::transform_blocks( $block['innerBlocks'], $changed );
				// Keep innerContent placeholders aligned: only the child blocks change.
				$blocks[ $i ]['innerBlocks'] = $inner;
			}
		}

		return $blocks;
	}

	/**
	 * Block attributes for a core/embed block that embeds a gist.
	 *
	 * Captioned embeds and embeds with attributes we cannot carry over are left
	 * untouched; className and align are carried over.
	 *
	 * @param array<string, mixed> $attrs Embed block attributes.
	 * @param string               $html  Block inner HTML.
	 * @return array<string, mixed>|null
	 */
	private static function attrs_from_embed( array $attrs, string $html ): ?array {
		$url = isset( $attrs['url'] ) && is_string( $attrs['url'] ) ? trim( $attrs['url'] ) : '';
		if ( '' === $url || null === Gist_Url::parse( $url ) ) {
			return null;
		}
		if ( array() !== array_diff( array_keys( $attrs ), array( 'url', 'type', 'providerNameSlug', 'responsive', 'className', 'align' ) ) ) {
			return null;
		}
		if ( preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#is', $html, $m ) && '' !== trim( wp_strip_all_tags( $m[1] ) ) ) {
			return null;
		}

		// The block only supports wide/full alignment; anything else would be lost.
		if ( isset( $attrs['align'] ) && '' !== $attrs['align'] && ! in_array( $attrs['align'], array( 'wide', 'full' ), true ) ) {
			return null;
		}

		$out = array( 'url' => $url );
		foreach ( array( 'className', 'align' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && '' !== $attrs[ $key ] ) {
				$out[ $key ] = $attrs[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Block attributes for a core/shortcode block that is exactly one [gist].
	 *
	 * @param string $html Block inner HTML.
	 * @return array<string, mixed>|null
	 */
	private static function attrs_from_shortcode( string $html ): ?array {
		$html = trim( $html );
		if ( ! preg_match( '/^' . get_shortcode_regex( array( 'gist' ) ) . '$/s', $html, $m ) || '[' === $m[1] || '' !== $m[5] ) {
			return null;
		}
		$raw = shortcode_parse_atts( $m[3] );
		$raw = is_array( $raw ) ? $raw : array();
		$id  = Attributes::normalize( $raw )['id'];
		if ( '' === $id ) {
			return null;
		}

		$attrs = array( 'url' => 'https://gist.github.com/' . $id );
		$norm  = Attributes::normalize( $raw );
		if ( '' !== $norm['file'] ) {
			$attrs['file'] = $norm['file'];
		}
		if ( $norm['lines']['max'] > 0 ) {
			$attrs['lines'] = $norm['lines']['min'] === $norm['lines']['max'] ? (string) $norm['lines']['min'] : $norm['lines']['min'] . '-' . $norm['lines']['max'];
		} elseif ( $norm['lines']['min'] > 0 ) {
			// Open-ended range: from min to the end.
			$attrs['lines'] = $norm['lines']['min'] . '-';
		}
		if ( $norm['lines_start'] > 0 ) {
			$attrs['linesStart'] = $norm['lines_start'];
		}
		if ( isset( $raw['highlight'] ) && array() !== $norm['highlight'] ) {
			$attrs['highlight'] = trim( (string) $raw['highlight'] );
		}
		if ( '' !== $norm['highlight_color'] && isset( $raw['highlight_color'] ) ) {
			$attrs['highlightColor'] = $norm['highlight_color'];
		}
		if ( isset( $raw['show_line_numbers'] ) ) {
			$attrs['showLineNumbers'] = $norm['show_line_numbers'];
		}
		if ( isset( $raw['show_meta'] ) ) {
			$attrs['showMeta'] = $norm['show_meta'];
		}
		if ( '' !== $norm['scheme'] ) {
			$attrs['scheme'] = $norm['scheme'];
		}

		return $attrs;
	}
}
