<?php
/**
 * Legacy `gistpress_*` filter aliases.
 *
 * The filter names and arguments mirror GistPress 3.0.3 by Brady Vercher and
 * Gary Jones, so existing customizations keep working.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `gistpress_*` filters (deprecated) at the end of the `gistful_*` ones.
 */
final class Legacy_Hooks {

	const PRIORITY = 99;

	/**
	 * Hook the aliases.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'gistful_html', array( self::class, 'html' ), self::PRIORITY, 4 );
		add_filter( 'gistful_shortcode_defaults', array( self::class, 'stylesheet_default' ), self::PRIORITY - 1 );
		add_filter( 'gistful_shortcode_defaults', array( self::class, 'shortcode_defaults' ), self::PRIORITY );
		add_filter( 'gistful_line_classes', array( self::class, 'line_classes' ), self::PRIORITY );
		add_filter( 'gistful_feed_html', array( self::class, 'feed_html' ), self::PRIORITY );
		add_filter( 'gistful_highlight_color', array( self::class, 'highlight_color' ), self::PRIORITY );
	}

	/**
	 * Alias of gistful_html.
	 *
	 * @param mixed $html    HTML.
	 * @param mixed $url     Gist URL.
	 * @param mixed $attr    Normalized attributes.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public static function html( $html, $url = '', $attr = array(), $post_id = null ) {
		if ( ! has_filter( 'gistpress_html' ) ) {
			return $html;
		}

		return apply_filters_deprecated( 'gistpress_html', array( $html, $url, $attr, $post_id ), '0.1.0', 'gistful_html' );
	}

	/**
	 * Alias of gistful_shortcode_defaults.
	 *
	 * @param mixed $defaults Defaults.
	 * @return mixed
	 */
	public static function shortcode_defaults( $defaults ) {
		if ( ! has_filter( 'gistpress_shortcode_defaults' ) ) {
			return $defaults;
		}

		return apply_filters_deprecated( 'gistpress_shortcode_defaults', array( $defaults ), '0.1.0', 'gistful_shortcode_defaults' );
	}

	/**
	 * Alias of gistpress_stylesheet_default: the default of embed_stylesheet.
	 *
	 * @param mixed $defaults Defaults.
	 * @return mixed
	 */
	public static function stylesheet_default( $defaults ) {
		if ( is_array( $defaults ) && array_key_exists( 'embed_stylesheet', $defaults ) && has_filter( 'gistpress_stylesheet_default' ) ) {
			$defaults['embed_stylesheet'] = apply_filters_deprecated( 'gistpress_stylesheet_default', array( $defaults['embed_stylesheet'] ), '0.1.0', 'gistful_shortcode_defaults' );
		}

		return $defaults;
	}

	/**
	 * Alias of gistful_line_classes (GistPress passed only the classes).
	 *
	 * @param mixed $classes Classes.
	 * @return mixed
	 */
	public static function line_classes( $classes ) {
		if ( ! has_filter( 'gistpress_line_classes' ) ) {
			return $classes;
		}

		return apply_filters_deprecated( 'gistpress_line_classes', array( $classes ), '0.1.0', 'gistful_line_classes' );
	}

	/**
	 * Alias of gistful_feed_html (GistPress passed only the HTML).
	 *
	 * @param mixed $html HTML.
	 * @return mixed
	 */
	public static function feed_html( $html ) {
		if ( ! has_filter( 'gistpress_feed_html' ) ) {
			return $html;
		}

		return apply_filters_deprecated( 'gistpress_feed_html', array( $html ), '0.1.0', 'gistful_feed_html' );
	}

	/**
	 * Alias of gistful_highlight_color.
	 *
	 * @param mixed $color Color.
	 * @return mixed
	 */
	public static function highlight_color( $color ) {
		if ( ! has_filter( 'gistpress_highlight_color' ) ) {
			return $color;
		}

		return apply_filters_deprecated( 'gistpress_highlight_color', array( $color ), '0.1.0', 'gistful_highlight_color' );
	}
}
