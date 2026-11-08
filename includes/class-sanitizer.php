<?php
/**
 * Sanitizer for remote gist HTML and unsupported-renderer detection.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Allowlist based sanitizing of gist markup (wp_kses, https only).
 */
final class Sanitizer {

	/**
	 * Elements removed together with their content before kses runs, because
	 * kses would otherwise leave their inner text behind.
	 */
	const DROP_WITH_CONTENT = array( 'script', 'style', 'iframe', 'template', 'svg', 'noscript', 'textarea', 'object' );

	/**
	 * Viewer data-type values that need a renderer we do not ship.
	 */
	const VIEWER_TYPES = array( 'ipynb', 'geojson', 'topojson', 'stl' );

	/**
	 * Sanitize gist HTML.
	 *
	 * @param string $html Raw gist HTML.
	 * @return string
	 */
	public static function clean( string $html ): string {
		$names = implode( '|', self::DROP_WITH_CONTENT );
		$html  = (string) preg_replace( '#<(' . $names . ')\b[^>]*>.*?</\1\s*>#is', '', $html );
		return wp_kses( $html, self::allowed_html(), array( 'https' ) );
	}

	/**
	 * Allowed tags and attributes (filter: gistful_allowed_html).
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		$common = array(
			'class' => true,
			'id'    => true,
		);

		$tags = array(
			'div'        => $common + array(
				'data-tagsearch-path' => true,
				'data-tagsearch-lang' => true,
				'role'                => true,
			),
			'span'       => $common,
			'a'          => $common + array(
				'href'  => true,
				'title' => true,
				'rel'   => true,
			),
			'code'       => $common,
			'pre'        => $common,
			'table'      => $common + array( 'data-tab-size' => true ),
			'thead'      => $common,
			'tbody'      => $common,
			'tr'         => $common,
			'td'         => $common + array(
				'data-line-number' => true,
				'colspan'          => true,
				'rowspan'          => true,
			),
			'th'         => $common + array(
				'colspan' => true,
				'rowspan' => true,
				'scope'   => true,
			),
			'colgroup'   => $common,
			'col'        => $common,
			'article'    => $common,
			'p'          => $common,
			'ul'         => $common,
			'ol'         => $common,
			'li'         => $common,
			'blockquote' => $common,
			'em'         => $common,
			'strong'     => $common,
			'hr'         => $common,
			'br'         => array(),
			'img'        => $common + array(
				'src'    => true,
				'alt'    => true,
				'title'  => true,
				'width'  => true,
				'height' => true,
			),
		);

		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $heading ) {
			$tags[ $heading ] = $common + array( 'dir' => true );
		}

		/**
		 * Filter the allowlist used for remote gist HTML.
		 *
		 * @param array $tags Allowed tags and attributes, wp_kses format.
		 */
		return (array) apply_filters( 'gistful_allowed_html', $tags );
	}

	/**
	 * Names of files that GitHub renders with a viewer we cannot show.
	 *
	 * Runs on the raw HTML, before clean().
	 *
	 * @param string $raw_html Raw gist HTML.
	 * @return string[]
	 */
	public static function unsupported_files( string $raw_html ): array {
		if ( '' === trim( $raw_html ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument();
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $raw_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			$xpath = new \DOMXPath( $dom );
			$types = array();
			foreach ( self::VIEWER_TYPES as $type ) {
				$types[] = '@data-type="' . $type . '"';
			}
			$viewer = './/iframe | .//*[' . implode( ' or ', $types ) . ']'
				. ' | .//*[contains(concat(" ", normalize-space(@class), " "), " render-container ")]'
				. ' | .//*[contains(concat(" ", normalize-space(@class), " "), " render-viewer ")]';

			$names = array();
			foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " gist-file ")]' ) as $file ) {
				if ( 0 === $xpath->query( $viewer, $file )->length ) {
					continue;
				}
				$name = self::file_name( $xpath, $file );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
			return $names;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * File name from the .gist-meta block of a .gist-file.
	 *
	 * @param \DOMXPath $xpath XPath for the document.
	 * @param \DOMNode  $file  The .gist-file node.
	 * @return string
	 */
	private static function file_name( \DOMXPath $xpath, \DOMNode $file ): string {
		$links = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " gist-meta ")]/a', $file );
		if ( $links && $links->length > 1 ) {
			$name = trim( (string) $links->item( 1 )->textContent );
			if ( '' !== $name ) {
				return $name;
			}
		}
		if ( $links && $links->length > 0 && $links->item( 0 ) instanceof \DOMElement ) {
			$path = (string) wp_parse_url( $links->item( 0 )->getAttribute( 'href' ), PHP_URL_PATH );
			return rawurldecode( basename( $path ) );
		}
		return '';
	}
}
