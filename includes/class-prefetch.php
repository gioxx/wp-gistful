<?php
/**
 * Save-time prefetch: warms caches and stores last-good backups.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * On save, flush the post's cache and (published posts only) prefetch its gists.
 */
final class Prefetch {

	/**
	 * Total time budget, in seconds, for the remote calls of one save.
	 */
	const BUDGET = 10;

	/**
	 * `save_post` callback.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		Cache::flush_post( $post_id );

		if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return;
		}

		$budget  = (float) apply_filters( 'gistful_prefetch_budget', self::BUDGET );
		$started = microtime( true );
		foreach ( self::find_gists( $post->post_content ) as $raw_attr ) {
			if ( microtime( true ) - $started >= $budget ) {
				break;
			}
			self::prefetch( $post_id, $raw_attr );
		}
	}

	/**
	 * Fetch one gist, store the raw cache and the backup, track the keys.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $raw_attr Raw attributes.
	 * @return void
	 */
	private static function prefetch( int $post_id, array $raw_attr ): void {
		$attr = Attributes::normalize( $raw_attr );
		if ( '' === $attr['id'] ) {
			return;
		}
		$raw_key = Cache::raw_key( $attr['id'], $attr['file'] );
		Cache::track( $post_id, Cache::html_key( $attr ), $raw_key );

		$payload = Source::fetch( $attr['id'], $attr['file'] );
		if ( is_wp_error( $payload ) ) {
			return;
		}
		Cache::set( $raw_key, Backup::pack( $payload ) );
		Backup::maybe_store( $post_id, $attr['id'], $attr['file'], $payload );
	}

	/**
	 * Find the gists of a post content (URLs, shortcodes, blocks), deduplicated.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>> Raw attribute sets.
	 */
	public static function find_gists( string $content ): array {
		$found = array();

		// Own-line URLs, as WP autoembed (optionally wrapped in a paragraph).
		if ( preg_match_all( '#^\s*(?:<p>)?\s*(https?://[^\s"<]+)\s*(?:</p>)?\s*$#im', $content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$found[] = self::attrs_from_url( $url );
			}
		}

		// The gist shortcode.
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'gist' ) ) . '/', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue; // Escaped by doubled brackets.
				}
				$atts    = shortcode_parse_atts( $match[3] );
				$found[] = is_array( $atts ) ? $atts : array();
			}
		}

		// gistful/gist blocks.
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, 'wp:gistful/gist' ) ) {
			self::collect_blocks( parse_blocks( $content ), $found );
		}

		$unique = array();
		foreach ( $found as $raw_attr ) {
			$attr = Attributes::normalize( $raw_attr );
			if ( '' !== $attr['id'] ) {
				$unique[ md5( (string) wp_json_encode( $attr ) ) ] = $raw_attr;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Attributes for a gist URL; empty array when it is not a gist URL.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	private static function attrs_from_url( string $url ): array {
		$parsed = Gist_Url::parse( $url );
		if ( null === $parsed ) {
			return array();
		}
		$file = '';
		if ( '' !== $parsed['anchor'] ) {
			$names = '-' === $parsed['delimiter'] ? Embed::file_names( $parsed['id'] ) : array();
			$file  = Gist_Url::resolve_file( $parsed['anchor'], $parsed['delimiter'], $names );
		}

		return array(
			'id'     => $parsed['id'],
			'file'   => $file,
			'oembed' => true,
		);
	}

	/**
	 * Collect gistful/gist block attributes, recursing into inner blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int, array<string, mixed>> $found  Output.
	 * @return void
	 */
	private static function collect_blocks( array $blocks, array &$found ): void {
		$map = array(
			'linesStart'      => 'lines_start',
			'highlightColor'  => 'highlight_color',
			'showLineNumbers' => 'show_line_numbers',
			'showMeta'        => 'show_meta',
		);
		foreach ( $blocks as $block ) {
			if ( 'gistful/gist' === ( $block['blockName'] ?? '' ) && is_array( $block['attrs'] ?? null ) ) {
				$attrs = array();
				foreach ( $block['attrs'] as $key => $value ) {
					$attrs[ $map[ $key ] ?? $key ] = $value;
				}
				if ( isset( $attrs['url'] ) && is_string( $attrs['url'] ) ) {
					$parsed = Gist_Url::parse( $attrs['url'] );
					if ( $parsed ) {
						$attrs['id'] = $parsed['id'];
					}
				}
				unset( $attrs['url'] );
				$found[] = $attrs;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_blocks( $block['innerBlocks'], $found );
			}
		}
	}
}
