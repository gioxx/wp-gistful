<?php
/**
 * Gist renderer: line ranges, highlight, line numbers, a11y, wrapper, fallbacks.
 *
 * Based on GistPress by Brady Vercher and Gary Jones: line range, highlight and
 * line-number semantics derive from GistPress 3.0.3 (GPL-2.0-or-later).
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a Gist_Payload plus normalized attributes into final HTML.
 *
 * Output of this class is never passed back through Sanitizer: the inline
 * highlight style and a11y attributes are added after sanitization, from
 * validated values only.
 */
final class Renderer {

	/**
	 * Render a payload.
	 *
	 * @param Gist_Payload         $p    Payload.
	 * @param array<string, mixed> $attr Normalized attributes.
	 * @return string Wrapped HTML, or '' when the payload is empty.
	 */
	public static function render( Gist_Payload $p, array $attr ): string {
		if ( $p->is_empty() ) {
			return '';
		}
		$id = (string) ( $attr['id'] ?? '' );

		if ( $p->truncated ) {
			return self::link( $id );
		}

		if ( '' === $p->html ) {
			return self::wrap( self::render_plain( $p->raw_files, $attr ), $attr );
		}

		$unsupported = Sanitizer::unsupported_files( $p->html );
		$clean       = Sanitizer::clean( $p->html );
		$notices     = array();
		if ( array() !== $unsupported ) {
			list( $clean, $notices ) = self::mark_unsupported( $clean, $unsupported, $id );
		}

		$out = self::process( $clean, $attr );
		foreach ( $notices as $marker => $notice ) {
			$out = str_replace( $marker, $notice, $out );
		}

		return self::wrap( $out, $attr );
	}

	/**
	 * Line logic, meta/line-number removal and a11y attributes.
	 *
	 * @param string               $clean_html Sanitized gist HTML.
	 * @param array<string, mixed> $attr       Normalized attributes.
	 * @return string
	 */
	public static function process( string $clean_html, array $attr ): string {
		if ( '' === trim( $clean_html ) ) {
			return $clean_html;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$dom = self::load( $clean_html );
			if ( null === $dom ) {
				return $clean_html;
			}
			$xpath = new \DOMXPath( $dom );

			if ( empty( $attr['show_meta'] ) ) {
				foreach ( iterator_to_array( $xpath->query( '//*[' . self::has_class( 'gist-meta' ) . ']' ) ) as $meta ) {
					$meta->parentNode->removeChild( $meta ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}

			foreach ( iterator_to_array( $xpath->query( '//table[.//td[' . self::has_class( 'blob-code' ) . ']]' ) ) as $table ) {
				self::process_table( $xpath, $table, $attr );
			}

			self::add_a11y( $xpath );

			return self::serialize( $dom );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Fallback for API-only payloads: escaped code in gist-like table markup.
	 *
	 * @param array<string, string> $raw_files File name => content.
	 * @param array<string, mixed>  $attr      Normalized attributes.
	 * @return string
	 */
	public static function render_plain( array $raw_files, array $attr ): string {
		$id   = (string) ( $attr['id'] ?? '' );
		$html = '<div class="gist">';
		foreach ( $raw_files as $name => $content ) {
			$name    = (string) $name;
			$rows    = '';
			$n       = 0;
			$content = preg_replace( '/\R\z/', '', (string) $content, 1 );
			foreach ( preg_split( '/\R/', (string) $content ) as $line ) {
				++$n;
				$rows .= '<tr><td class="blob-num js-line-number" data-line-number="' . $n . '"></td>'
					. '<td class="blob-code blob-code-inner js-file-line"><pre><code>' . self::text( $line ) . '</code></pre></td></tr>';
			}
			$base  = 'https://gist.github.com/' . $id;
			$html .= '<div class="gist-file"><div class="gist-data"><div class="file"><div class="blob-wrapper data">'
				. '<table class="highlight tab-size js-file-line-container"><tbody>' . $rows . '</tbody></table>'
				. '</div></div></div><div class="gist-meta">'
				. '<a href="' . esc_url( $base . '/raw/' . rawurlencode( $name ) ) . '">' . esc_html__( 'view raw', 'gistful' ) . '</a> '
				. '<a href="' . esc_url( $base ) . '">' . self::text( $name ) . '</a>'
				. '</div></div>';
		}
		$html .= '</div>';

		return self::process( $html, $attr );
	}

	/**
	 * Notice shown instead of a file GitHub renders with an unsupported viewer.
	 *
	 * @param string $id   Gist id.
	 * @param string $file File name (or fallback id) as detected.
	 * @return string
	 */
	public static function unsupported_notice( string $id, string $file ): string {
		return '<div class="gistful-unsupported"><span class="gistful-unsupported__name">' . esc_html( $file ) . '</span> '
			. '<a href="' . esc_url( 'https://gist.github.com/' . $id ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'View this file on GitHub', 'gistful' ) . '</a></div>';
	}

	/**
	 * Plain link to a gist (used for oversized gists).
	 *
	 * @param string $id Gist id.
	 * @return string
	 */
	public static function link( string $id ): string {
		return make_clickable( 'https://gist.github.com/' . $id );
	}

	/**
	 * Escape plain text for HTML: keeps entities literal, substitutes invalid UTF-8.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function text( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Wrap output in the scheme container.
	 *
	 * @param string               $html HTML.
	 * @param array<string, mixed> $attr Attributes.
	 * @return string
	 */
	private static function wrap( string $html, array $attr ): string {
		$scheme = (string) ( $attr['scheme'] ?? '' );
		if ( '' === $scheme ) {
			$scheme = (string) Settings_Store::get( 'scheme' );
		}
		if ( ! in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ) {
			$scheme = 'auto';
		}

		return '<div class="gistful" data-scheme="' . esc_attr( $scheme ) . '">' . $html . '</div>';
	}

	/**
	 * Replace unsupported .gist-file nodes with comment markers.
	 *
	 * @param string   $clean       Sanitized HTML.
	 * @param string[] $unsupported Names from Sanitizer::unsupported_files().
	 * @param string   $id          Gist id.
	 * @return array{0:string,1:array<string,string>} HTML and marker => notice map.
	 */
	private static function mark_unsupported( string $clean, array $unsupported, string $id ): array {
		$previous = libxml_use_internal_errors( true );
		try {
			$dom = self::load( $clean );
			if ( null === $dom ) {
				return array( $clean, array() );
			}
			$xpath   = new \DOMXPath( $dom );
			$notices = array();
			$index   = 0;
			$token   = wp_generate_uuid4();
			foreach ( iterator_to_array( $xpath->query( '//*[' . self::has_class( 'gist-file' ) . ']' ) ) as $file ) {
				++$index;
				$name = Sanitizer::file_name( $xpath, $file );
				if ( '' === $name ) {
					$ids  = $xpath->query( './/*[starts-with(@id, "file-")]', $file );
					$name = ( $ids && $ids->length && $ids->item( 0 ) instanceof \DOMElement )
						? $ids->item( 0 )->getAttribute( 'id' )
						: 'file-' . $index;
				}
				if ( ! in_array( $name, $unsupported, true ) ) {
					continue;
				}
				$marker                              = 'gistful-notice-' . $token . '-' . count( $notices );
				$notices[ '<!--' . $marker . '-->' ] = self::unsupported_notice( $id, $name );
				$file->parentNode->replaceChild( $dom->createComment( $marker ), $file ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}

			return array( self::serialize( $dom ), $notices );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Apply range, highlight, classes, line-number removal and renumbering to one code table.
	 *
	 * @param \DOMXPath            $xpath XPath.
	 * @param \DOMNode             $table Table.
	 * @param array<string, mixed> $attr  Attributes.
	 */
	private static function process_table( \DOMXPath $xpath, \DOMNode $table, array $attr ): void {
		$min       = (int) ( $attr['lines']['min'] ?? 0 );
		$max       = (int) ( $attr['lines']['max'] ?? 0 );
		$start_at  = (int) ( $attr['lines_start'] ?? 0 );
		$numbers   = ! empty( $attr['show_line_numbers'] );
		$highlight = array_flip( array_map( 'intval', (array) ( $attr['highlight'] ?? array() ) ) );
		$color     = (string) ( $attr['highlight_color'] ?? '' );
		$color     = 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : '';

		$rows   = $xpath->query( './/tr[td[' . self::has_class( 'blob-code' ) . ']]', $table );
		$remove = array();
		$kept   = array();
		foreach ( iterator_to_array( $rows ) as $key => $row ) {
			if ( ! $row instanceof \DOMElement ) {
				continue;
			}
			$line_no = $key + 1;
			if ( ( $min && $line_no < $min ) || ( $max && $line_no > $max ) ) {
				$remove[] = $row;
				continue;
			}
			$kept[] = $row;

			$classes = array( 'line' );
			if ( isset( $highlight[ $line_no ] ) ) {
				$classes[] = 'line-highlight';
				if ( '' !== $color ) {
					foreach ( $row->getElementsByTagName( 'td' ) as $cell ) {
						$cell->setAttribute( 'style', 'background-color: ' . $color . ' !important' );
					}
				}
			} elseif ( array() !== $highlight ) {
				$classes[] = 'line-no-highlight';
			}

			/**
			 * Filter the classes applied to a line of the gist.
			 *
			 * @param string[] $classes Class names.
			 * @param int      $line_no Original 1-based line number.
			 */
			$classes  = apply_filters( 'gistful_line_classes', $classes, $line_no );
			$classes  = is_array( $classes ) ? array_filter( array_map( 'strval', $classes ) ) : array();
			$existing = $row->getAttribute( 'class' );
			$row->setAttribute( 'class', trim( $existing . ' ' . implode( ' ', $classes ) ) );
		}

		foreach ( $remove as $row ) {
			$row->parentNode->removeChild( $row ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		if ( ! $numbers ) {
			foreach ( $kept as $row ) {
				foreach ( iterator_to_array( $row->getElementsByTagName( 'td' ) ) as $cell ) {
					if ( self::node_has_class( $cell, 'blob-num' ) ) {
						$row->removeChild( $cell );
					}
				}
			}
			return;
		}

		if ( ( $min && $max ) || $start_at > 0 ) {
			$first = $start_at > 0 ? $start_at : $min;
			foreach ( $kept as $i => $row ) {
				$cell = $row->getElementsByTagName( 'td' )->item( 0 );
				if ( $cell instanceof \DOMElement && self::node_has_class( $cell, 'blob-num' ) ) {
					$cell->setAttribute( 'data-line-number', (string) ( $first + $i ) );
				}
			}
		}
	}

	/**
	 * Make each code area keyboard reachable and labelled.
	 *
	 * @param \DOMXPath $xpath XPath.
	 */
	private static function add_a11y( \DOMXPath $xpath ): void {
		foreach ( $xpath->query( '//*[' . self::has_class( 'gist-data' ) . ']' ) as $area ) {
			if ( ! $area instanceof \DOMElement ) {
				continue;
			}
			$file  = $xpath->query( 'ancestor::*[' . self::has_class( 'gist-file' ) . '][1]', $area )->item( 0 );
			$name  = $file ? Sanitizer::file_name( $xpath, $file ) : '';
			$label = '' !== $name ? $name : __( 'Gist code', 'gistful' );
			$area->setAttribute( 'tabindex', '0' );
			$area->setAttribute( 'role', 'region' );
			$area->setAttribute( 'aria-label', $label );
		}
	}

	/**
	 * Parse a fragment as UTF-8 without implied html/body.
	 *
	 * @param string $html Fragment.
	 * @return \DOMDocument|null
	 */
	private static function load( string $html ): ?\DOMDocument {
		$dom = new \DOMDocument();
		return $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ? $dom : null;
	}

	/**
	 * Serialize the fragment, dropping the xml declaration.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return string
	 */
	private static function serialize( \DOMDocument $dom ): string {
		$out = '';
		foreach ( $dom->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_PI_NODE !== $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$out .= $dom->saveHTML( $child );
			}
		}
		return $out;
	}

	/**
	 * XPath predicate matching a class token.
	 *
	 * @param string $token Class token (trusted literal).
	 * @return string
	 */
	private static function has_class( string $token ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
	}

	/**
	 * Whether an element carries a class token.
	 *
	 * @param \DOMElement $el    Element.
	 * @param string      $token Class name.
	 * @return bool
	 */
	private static function node_has_class( \DOMElement $el, string $token ): bool {
		return in_array( $token, preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ), true );
	}
}
