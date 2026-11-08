<?php
/**
 * The gistful/gist block.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the dynamic block and renders it through the shared Embed pipeline.
 */
final class Block {

	/**
	 * Register the block type. Runs on init after the stylesheet handle exists.
	 *
	 * @return void
	 */
	public static function register(): void {
		Embed::register_style();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'gistful/gist' ) ) {
			return;
		}
		$type = register_block_type(
			GISTFUL_DIR . 'build/blocks/gist',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
		if ( $type && ! empty( $type->editor_script_handles ) ) {
			foreach ( $type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'gistful' );
			}
		}
	}

	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		$url  = isset( $attributes['url'] ) && is_string( $attributes['url'] ) ? trim( $attributes['url'] ) : '';
		$id   = '';
		$file = isset( $attributes['file'] ) && is_string( $attributes['file'] ) ? $attributes['file'] : '';

		if ( preg_match( '/^[a-z0-9]+$/i', $url ) ) {
			$id = $url;
		} else {
			$parsed = Gist_Url::parse( $url );
			if ( null === $parsed ) {
				return '';
			}
			$id = $parsed['id'];
			if ( '' === $file && '' !== $parsed['anchor'] ) {
				$names = '-' === $parsed['delimiter'] ? Embed::file_names( $id ) : array();
				$file  = Gist_Url::resolve_file( $parsed['anchor'], $parsed['delimiter'], $names );
			}
		}

		$map    = array(
			'lines'          => 'lines',
			'linesStart'     => 'lines_start',
			'highlight'      => 'highlight',
			'highlightColor' => 'highlight_color',
			'scheme'         => 'scheme',
		);
		$mapped = array(
			'id'   => $id,
			'file' => $file,
		);
		foreach ( $map as $from => $to ) {
			if ( isset( $attributes[ $from ] ) && '' !== $attributes[ $from ] ) {
				$mapped[ $to ] = $attributes[ $from ];
			}
		}
		foreach ( array(
			'showLineNumbers' => 'show_line_numbers',
			'showMeta'        => 'show_meta',
			'copy'            => 'copy',
		) as $from => $to ) {
			if ( isset( $attributes[ $from ] ) && is_bool( $attributes[ $from ] ) ) {
				$mapped[ $to ] = $attributes[ $from ] ? 'true' : 'false';
			}
		}

		$post_id = get_the_ID();
		$html    = Embed::render( $mapped, $post_id ? (int) $post_id : null );
		if ( '' === $html ) {
			return '';
		}

		// Wrapper carries alignment / className; the Embed markup stays untouched inside.
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
