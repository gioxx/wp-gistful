<?php
/**
 * Parse and serialize HTML fragments the same way on every libxml version.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * DOM helpers for HTML fragments.
 *
 * The fragment is parsed inside a private wrapper element and only the
 * wrapper's children are serialized. Two libxml behaviours make this
 * necessary: libxml 2.14+ (HTML5 tokenizer, e.g. Ubuntu 26.04) turns the
 * `<?xml encoding?>` hint into a comment that would pile up at every pass,
 * and with LIBXML_HTML_NOIMPLIED it may drop the nodes after the first
 * top-level element. A custom element name is not closed by stray end tags
 * in the fragment.
 */
final class Html_Fragment {

	/**
	 * Wrapper element name.
	 */
	const WRAPPER = 'gistful-fragment';

	/**
	 * Parse a fragment as UTF-8.
	 *
	 * @param string $html Fragment.
	 * @return \DOMDocument|null Null when parsing fails.
	 */
	public static function load( string $html ): ?\DOMDocument {
		// Parser warnings (unknown wrapper tag, malformed markup) are expected.
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument();
			$ok  = $dom->loadHTML(
				'<?xml encoding="utf-8" ?><' . self::WRAPPER . '>' . $html . '</' . self::WRAPPER . '>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return $ok ? $dom : null;
	}

	/**
	 * Serialize the fragment parsed by load().
	 *
	 * @param \DOMDocument $dom Document.
	 * @return string
	 */
	public static function serialize( \DOMDocument $dom ): string {
		$out = '';
		foreach ( self::top_level( $dom ) as $node ) {
			if ( $node instanceof \DOMElement && self::WRAPPER === strtolower( $node->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$out .= $dom->saveHTML( $child );
				}
				continue;
			}
			// Anything the parser left outside the wrapper is still part of the fragment.
			$out .= $dom->saveHTML( $node );
		}

		return $out;
	}

	/**
	 * Top-level nodes, without the encoding hint (a processing instruction on
	 * older libxml, a comment on newer).
	 *
	 * @param \DOMDocument $dom Document.
	 * @return \DOMNode[]
	 */
	private static function top_level( \DOMDocument $dom ): array {
		$nodes = array();
		foreach ( $dom->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_PI_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
			if ( XML_COMMENT_NODE === $node->nodeType && 0 === strpos( ltrim( (string) $node->nodeValue ), '?xml' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
			$nodes[] = $node;
		}

		return $nodes;
	}
}
