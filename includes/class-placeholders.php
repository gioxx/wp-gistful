<?php
/**
 * Keeps gist markup away from the_content text filters.
 *
 * The wptexturize, wpautop and convert_smilies filters run after the oEmbed
 * handler (priority 8) and the block renderer (priority 9) and would corrupt
 * the code. While the_content runs, rendered gists are parked behind an
 * opaque placeholder and put back by a late filter. GistPress solved the same
 * problem by returning a shortcode rendered at priority 11 (based on GistPress
 * by Brady Vercher and Gary Jones).
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder store for the current request.
 */
final class Placeholders {

	/**
	 * Priority on the_content at which placeholders are swapped back.
	 */
	const PRIORITY = 99;

	/**
	 * Per-request secret, so post content cannot forge a placeholder.
	 *
	 * @var string
	 */
	private static string $token = '';

	/**
	 * Parked HTML by placeholder number.
	 *
	 * @var array<int, string>
	 */
	private static array $store = array();

	/**
	 * Last placeholder number used.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Hook the late swap.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'restore' ), self::PRIORITY );
	}

	/**
	 * Park HTML behind a placeholder when it would otherwise go through the
	 * remaining the_content filters; return it unchanged anywhere else (REST
	 * oEmbed proxy, block renderer endpoint, direct calls, late filters).
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public static function protect( string $html ): string {
		if ( '' === $html || ! self::in_early_the_content() ) {
			return $html;
		}
		if ( '' === self::$token ) {
			self::$token = bin2hex( random_bytes( 16 ) );
		}
		$n                 = ++self::$counter;
		self::$store[ $n ] = $html;

		// A block-level element: wpautop leaves it alone, wptexturize skips tags.
		return '<div data-gistful-ph="' . self::$token . '-' . $n . '"></div>';
	}

	/**
	 * Swap placeholders back with the parked HTML.
	 *
	 * @param mixed $content Content.
	 * @return mixed
	 */
	public static function restore( mixed $content ): mixed {
		if ( ! is_string( $content ) || '' === self::$token || array() === self::$store || false === strpos( $content, 'data-gistful-ph=' ) ) {
			return $content;
		}
		$used    = array();
		$pattern = '#(<p>\s*)?<div data-gistful-ph="' . self::$token . '-(\d+)"></div>(\s*</p>)?#';
		$content = (string) preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( &$used ): string {
				$open  = $m[1] ?? '';
				$close = $m[3] ?? '';
				$n     = (int) $m[2];
				$html  = self::$store[ $n ] ?? '';
				if ( '' !== $html ) {
					$used[ $n ] = true;
				}
				// A paragraph wrapped around the placeholder alone goes away with it.
				if ( '' !== $open && '' !== $close ) {
					return $html;
				}

				return $open . $html . $close;
			},
			$content
		);
		foreach ( array_keys( $used ) as $n ) {
			unset( self::$store[ $n ] );
		}

		return $content;
	}

	/**
	 * Whether a the_content callback that runs before the swap is executing.
	 *
	 * @return bool
	 */
	private static function in_early_the_content(): bool {
		global $wp_filter;

		if ( 'the_content' !== current_filter() || ! isset( $wp_filter['the_content'] ) || ! $wp_filter['the_content'] instanceof \WP_Hook ) {
			return false;
		}
		if ( false === has_filter( 'the_content', array( self::class, 'restore' ) ) ) {
			return false;
		}
		$priority = $wp_filter['the_content']->current_priority();

		return false !== $priority && $priority < self::PRIORITY;
	}
}
