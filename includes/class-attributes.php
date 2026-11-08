<?php
/**
 * Shortcode and block attribute normalization.
 *
 * Parsing semantics (boolean strings, line ranges, highlight lists) are
 * based on GistPress by Brady Vercher and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw, untrusted attributes into a validated, fully-keyed array.
 */
final class Attributes {

	/**
	 * Normalize raw attributes.
	 *
	 * @param array<string, mixed> $raw Raw attributes.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$defaults = apply_filters(
			'gistful_shortcode_defaults',
			array(
				'id'                => '',
				'file'              => '',
				'lines'             => '',
				'lines_start'       => 0,
				'highlight'         => '',
				'highlight_color'   => '',
				'show_line_numbers' => Settings_Store::get( 'show_line_numbers' ),
				'show_meta'         => Settings_Store::get( 'show_meta' ),
				'embed_stylesheet'  => Settings_Store::get( 'load_css' ),
				'scheme'            => '',
				'oembed'            => false,
			)
		);
		$atts     = shortcode_atts( $defaults, $raw );

		$scheme = is_scalar( $atts['scheme'] ) ? strtolower( trim( (string) $atts['scheme'] ) ) : '';

		return array(
			'id'                => self::sanitize_id( $atts['id'] ),
			'file'              => self::sanitize_file( $atts['file'] ),
			'lines'             => self::parse_lines( $atts['lines'] ),
			'lines_start'       => absint( is_scalar( $atts['lines_start'] ) ? $atts['lines_start'] : 0 ),
			'highlight'         => self::parse_highlight( $atts['highlight'] ),
			'highlight_color'   => self::sanitize_color( $atts['highlight_color'] ),
			'show_line_numbers' => self::to_bool( $atts['show_line_numbers'] ),
			'show_meta'         => self::to_bool( $atts['show_meta'] ),
			'embed_stylesheet'  => self::to_bool( $atts['embed_stylesheet'] ),
			'scheme'            => in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ? $scheme : '',
			'oembed'            => self::to_bool( $atts['oembed'] ),
		);
	}

	/**
	 * Boolean semantics matching GistPress: false, 0, no, n and empty are false.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	public static function to_bool( mixed $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( ! is_scalar( $v ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $v ) );

		return ! in_array( $v, array( '', 'false', '0', 'no', 'n' ), true );
	}

	/**
	 * Strip non-alphanumerics; invalid when empty or longer than 40.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function sanitize_id( mixed $v ): string {
		$id = preg_replace( '/[^A-Za-z0-9]/', '', is_scalar( $v ) ? (string) $v : '' );

		return preg_match( '/^[A-Za-z0-9]{1,40}$/', (string) $id ) ? $id : '';
	}

	/**
	 * File name: no path separators or control characters.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function sanitize_file( mixed $v ): string {
		$file = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( '' === $file || strlen( $file ) > 255 || preg_match( '#[/\\\\\x00-\x1f]#', $file ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Parse "N" or "N-M" into min/max.
	 *
	 * @param mixed $v Value.
	 * @return array{min:int,max:int}
	 */
	private static function parse_lines( mixed $v ): array {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $v, $m ) ) {
			$a = (int) $m[1];
			$b = (int) $m[2];

			return array(
				'min' => min( $a, $b ),
				'max' => max( $a, $b ),
			);
		}
		if ( preg_match( '/^\d+$/', $v ) ) {
			return array(
				'min' => (int) $v,
				'max' => (int) $v,
			);
		}

		return array(
			'min' => 0,
			'max' => 0,
		);
	}

	/**
	 * Expand "1, 3-5" into a sorted unique list, bounded by gistful_max_lines.
	 *
	 * @param mixed $v Value.
	 * @return int[]
	 */
	private static function parse_highlight( mixed $v ): array {
		$v = is_scalar( $v ) ? (string) $v : '';
		if ( '' === trim( $v ) ) {
			return array();
		}

		$max   = max( 1, (int) apply_filters( 'gistful_max_lines', 5000 ) );
		$found = array();

		foreach ( explode( ',', $v ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $part, $m ) ) {
				$from = min( (int) $m[1], (int) $m[2] );
				$to   = max( (int) $m[1], (int) $m[2] );
				$i    = max( 1, $from );
				$size = count( $found );
				while ( $i <= $to && $size < $max ) {
					if ( ! isset( $found[ $i ] ) ) {
						$found[ $i ] = true;
						++$size;
					}
					++$i;
				}
			} elseif ( preg_match( '/^\d+$/', $part ) && (int) $part > 0 ) {
				$found[ (int) $part ] = true;
			}
			if ( count( $found ) >= $max ) {
				break;
			}
		}

		$lines = array_keys( $found );
		sort( $lines, SORT_NUMERIC );

		return $lines;
	}

	/**
	 * Accept only #rgb, #rrggbb or a short alpha-only colour keyword; else default.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function sanitize_color( mixed $v ): string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v ) ) {
			return $v;
		}

		$default = apply_filters( 'gistful_highlight_color', '#ffc' );
		$default = is_string( $default ) && preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $default ) ? $default : '#ffc';

		return $default;
	}
}
