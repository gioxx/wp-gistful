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
class Sanitizer {

	/**
	 * Elements removed together with their content before kses runs, because
	 * kses would otherwise leave their inner text behind.
	 */
	const DROP_WITH_CONTENT = array( 'script', 'style', 'iframe', 'template', 'svg', 'noscript', 'textarea', 'object' );

	/**
	 * Id values GitHub uses in gist markup; others could clobber page DOM.
	 */
	const ID_PATTERN = '/^(?:gist\\d*|file-[A-Za-z0-9_.-]+|LC?\\d+|user-content-[A-Za-z0-9_.:-]+)$/';

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
		$allowed = self::allowed_html();
		if ( '' === trim( $html ) ) {
			return wp_kses( $html, $allowed, array( 'https' ) );
		}

		$prepared = self::prepare( $html );
		if ( null !== $prepared ) {
			$kses  = wp_kses( $prepared, $allowed, array( 'https' ) );
			$final = self::enforce_urls( $kses );
			if ( null !== $final ) {
				return $final;
			}
		}

		// Fail closed: no URLs or ids at all when the DOM passes are unavailable.
		foreach ( $allowed as $tag => $attrs ) {
			unset( $allowed[ $tag ]['href'], $allowed[ $tag ]['src'], $allowed[ $tag ]['id'] );
		}
		return wp_kses( $html, $allowed, array( 'https' ) );
	}

	/**
	 * Parse an HTML fragment (overridable seam for tests).
	 *
	 * @param string $html Fragment.
	 * @return \DOMDocument|null Null when parsing fails.
	 */
	protected static function load_dom( string $html ): ?\DOMDocument {
		return Html_Fragment::load( $html );
	}

	/**
	 * Serialize the fragment held by a DOM built with load_dom().
	 *
	 * @param \DOMDocument $dom Document.
	 * @return string
	 */
	private static function serialize( \DOMDocument $dom ): string {
		return Html_Fragment::serialize( $dom );
	}

	/**
	 * DOM pre-pass: drop non-renderable elements with their content, keep only
	 * https/fragment hrefs, https srcs and known id patterns.
	 *
	 * @param string $html Raw HTML.
	 * @return string|null Null when the DOM could not be used.
	 */
	private static function prepare( string $html ): ?string {
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = static::load_dom( $html );
			if ( null === $dom ) {
				return null;
			}
			$xpath = new \DOMXPath( $dom );

			foreach ( self::DROP_WITH_CONTENT as $tag ) {
				// Snapshot first: removing nodes while iterating a live list skips items.
				foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
					if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
				}
			}

			foreach ( iterator_to_array( $xpath->query( '//*[@id]' ) ) as $el ) {
				if ( $el instanceof \DOMElement && 1 !== preg_match( self::ID_PATTERN, $el->getAttribute( 'id' ) ) ) {
					$el->removeAttribute( 'id' );
				}
			}
			self::filter_urls( $xpath );

			return self::serialize( $dom );
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Authoritative check on the final (post-kses) markup.
	 *
	 * @param string $html Sanitized HTML.
	 * @return string|null Null when the DOM could not be used.
	 */
	private static function enforce_urls( string $html ): ?string {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = static::load_dom( $html );
			if ( null === $dom ) {
				return null;
			}
			self::filter_urls( new \DOMXPath( $dom ) );
			return self::serialize( $dom );
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Drop href unless https:// or a plain #fragment, and src unless https://.
	 *
	 * @param \DOMXPath $xpath XPath for the document.
	 */
	private static function filter_urls( \DOMXPath $xpath ): void {
		foreach ( iterator_to_array( $xpath->query( '//*[@href or @src]' ) ) as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			if ( $el->hasAttribute( 'href' ) ) {
				$href = trim( $el->getAttribute( 'href' ) );
				if ( 1 !== preg_match( '#^(?:https://|\#[^:\s]*$)#i', $href ) ) {
					$el->removeAttribute( 'href' );
				}
			}
			if ( $el->hasAttribute( 'src' ) && 1 !== preg_match( '#^https://#i', trim( $el->getAttribute( 'src' ) ) ) ) {
				$el->removeAttribute( 'src' );
			}
		}
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
			$dom = Html_Fragment::load( $raw_html );
			if ( null === $dom ) {
				return array();
			}
			$xpath = new \DOMXPath( $dom );
			$types = array();
			foreach ( self::VIEWER_TYPES as $type ) {
				$types[] = '@data-type="' . $type . '"';
			}
			$viewer = './/iframe | .//*[' . implode( ' or ', $types ) . ']'
				. ' | .//*[contains(concat(" ", normalize-space(@class), " "), " render-container ")]'
				. ' | .//*[contains(concat(" ", normalize-space(@class), " "), " render-viewer ")]';

			$names = array();
			$index = 0;
			foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " gist-file ")]' ) as $file ) {
				++$index;
				if ( 0 === $xpath->query( $viewer, $file )->length ) {
					continue;
				}
				$name = self::file_name( $xpath, $file );
				if ( '' === $name ) {
					$ids  = $xpath->query( './/*[starts-with(@id, "file-")]', $file );
					$name = ( $ids && $ids->length && $ids->item( 0 ) instanceof \DOMElement )
						? $ids->item( 0 )->getAttribute( 'id' )
						: 'file-' . $index;
				}
				$names[] = $name;
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
	public static function file_name( \DOMXPath $xpath, \DOMNode $file ): string {
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
