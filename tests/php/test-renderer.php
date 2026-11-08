<?php
/**
 * Renderer tests.
 *
 * @package Gistful
 */

use Gistful\Attributes;
use Gistful\Gist_Payload;
use Gistful\Renderer;
use Gistful\Sanitizer;

/**
 * DOM processing, wrapper, a11y and fallbacks.
 */
class Test_Renderer extends Gistful_Test_Case {

	private function div( string $name ): string {
		return (string) json_decode( (string) file_get_contents( GISTFUL_DIR . 'tests/fixtures/' . $name ) )->div;
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( GISTFUL_DIR . 'tests/fixtures/' . $name );
	}

	private function payload( string $name ): Gist_Payload {
		return new Gist_Payload( $this->div( $name ), array(), 'json' );
	}

	/**
	 * Canonical form for like-for-like comparison with GistPress output.
	 *
	 * Compared: element structure, sorted class tokens, text, data-line-number.
	 * Ignored: whitespace-only text nodes, attribute order, every other
	 * attribute (id, style, ...), the html/body wrapper GistPress's second
	 * loadHTML adds, and what Gistful intentionally adds that GistPress does not:
	 * the `line-no-highlight` class and tabindex/role/aria-label on `.gist-data`
	 * (those attributes are not compared at all).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function normalize_dom( string $html ): string {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		$walk = function ( DOMNode $node ) use ( &$walk ): string {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				return '' === trim( $node->nodeValue ) ? '' : 'T(' . $node->nodeValue . ')'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			if ( ! $node instanceof DOMElement ) {
				return '';
			}
			$classes = array_diff( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ), array( 'line-no-highlight' ) );
			sort( $classes );
			$out = '<' . $node->tagName . ' class="' . implode( ' ', $classes ) . '"'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $node->hasAttribute( 'data-line-number' ) ) {
				$out .= ' n=' . $node->getAttribute( 'data-line-number' );
			}
			$out .= '>';
			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$out .= $walk( $child );
			}
			return $out . '</' . $node->tagName . '>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		$out = '';
		foreach ( $body->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out .= $walk( $child );
		}
		return $out;
	}

	public function test_structure_matches_gistpress() {
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'lines' => '2-4', 'highlight' => '3' ) ) );
		$this->assertSame( $this->normalize_dom( $this->fixture( 'gistpress-expected-single-lines-2-4-hl-3.html' ) ), $this->normalize_dom( $out ) );
		$this->assertStringContainsString( 'data-line-number="2"', $out );
		$this->assertStringNotContainsString( 'data-line-number="5"', $out );
	}

	public function test_no_html_body_wrapper_after_lines_start() {
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'lines' => '2-4', 'lines_start' => '10' ) ) );
		$this->assertStringNotContainsString( '<body', $out );
		$this->assertStringNotContainsString( '<html', $out );
		$this->assertStringContainsString( 'data-line-number="10"', $out );
		$this->assertStringContainsString( 'data-line-number="12"', $out );
	}

	public function test_line_no_highlight_class_when_highlighting() {
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'highlight' => '1' ) ) );
		$this->assertStringContainsString( 'line-highlight', $out );
		$this->assertStringContainsString( 'line-no-highlight', $out );
	}

	public function test_highlight_color_applied_after_sanitize() {
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'highlight' => '1', 'highlight_color' => '#123456' ) ) );
		$this->assertStringContainsString( 'background-color: #123456', $out );
	}

	public function test_no_range_means_all_lines_and_no_error() {
		$clean = Sanitizer::clean( $this->div( 'json-single.json' ) );
		$out   = Renderer::process( $clean, Attributes::normalize( array( 'id' => 'a' ) ) );
		$this->assertSame( substr_count( $clean, '<tr' ), substr_count( $out, '<tr' ) );
	}

	public function test_line_classes_filter() {
		add_filter(
			'gistful_line_classes',
			static function ( array $classes, int $n ) {
				$classes[] = 'n-' . $n;
				return $classes;
			},
			10,
			2
		);
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'lines' => '2-3' ) ) );
		$this->assertStringContainsString( 'n-2', $out );
		$this->assertStringContainsString( 'n-3', $out );
		$this->assertStringNotContainsString( 'n-1"', $out );
	}

	public function test_utf8_preserved() {
		$html = '<div class="gist"><div class="gist-file"><div class="gist-data"><table><tr><td class="blob-num js-line-number" data-line-number="1"></td><td class="blob-code">// perché 🚀 日本</td></tr></table></div></div></div>';
		$this->assertStringContainsString( '// perché 🚀 日本', Renderer::process( $html, Attributes::normalize( array( 'id' => 'a' ) ) ) );
	}

	public function test_hide_line_numbers_and_meta() {
		$out = Renderer::process( Sanitizer::clean( $this->div( 'json-single.json' ) ), Attributes::normalize( array( 'id' => 'a', 'show_line_numbers' => 'false', 'show_meta' => 'no' ) ) );
		$this->assertStringNotContainsString( 'blob-num', $out );
		$this->assertStringNotContainsString( 'gist-meta', $out );
		$this->assertStringContainsString( 'blob-code', $out );
	}

	public function test_plain_fallback_escapes() {
		$out = Renderer::render_plain( array( 'x.html' => '<script>a</script>' ), Attributes::normalize( array( 'id' => 'a' ) ) );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'blob-code', $out );
	}

	public function test_plain_fallback_honours_range_and_highlight() {
		$out = Renderer::render_plain( array( 'x.txt' => "a\nb\nc\nd" ), Attributes::normalize( array( 'id' => 'a', 'lines' => '2-3', 'highlight' => '3' ) ) );
		$this->assertSame( 2, substr_count( $out, '<tr' ) );
		$this->assertStringContainsString( 'line-highlight', $out );
		$this->assertStringContainsString( 'data-line-number="2"', $out );
	}

	public function test_wrapper_scheme_and_a11y() {
		update_option( 'gistful_settings', array( 'scheme' => 'dark' ) );
		$out = Renderer::render( $this->payload( 'json-single.json' ), Attributes::normalize( array( 'id' => 'a' ) ) );
		$this->assertStringStartsWith( '<div class="gistful" data-scheme="dark">', $out );
		$this->assertStringContainsString( 'tabindex="0"', $out );
		$this->assertStringContainsString( 'role="region"', $out );
		$this->assertStringContainsString( 'aria-label="swaksWrapper.sh"', $out );
	}

	public function test_attribute_scheme_overrides_setting() {
		update_option( 'gistful_settings', array( 'scheme' => 'dark' ) );
		$out = Renderer::render( $this->payload( 'json-single.json' ), Attributes::normalize( array( 'id' => 'a', 'scheme' => 'light' ) ) );
		$this->assertStringStartsWith( '<div class="gistful" data-scheme="light">', $out );
	}

	public function test_unsupported_file_becomes_notice() {
		$out = Renderer::render( $this->payload( 'json-ipynb.json' ), Attributes::normalize( array( 'id' => 'abc123' ) ) );
		$this->assertStringContainsString( 'View this file on GitHub', $out );
		$this->assertStringContainsString( 'gistful-unsupported__name">listesChaineesCorrection.ipynb<', $out );
		$this->assertStringContainsString( 'href="https://gist.github.com/abc123"', $out );
		$this->assertStringNotContainsString( 'render-viewer', $out );
		$this->assertStringNotContainsString( 'gistful-notice-', $out );
	}

	public function test_empty_payload_and_truncated() {
		$this->assertSame( '', Renderer::render( new Gist_Payload(), Attributes::normalize( array( 'id' => 'a' ) ) ) );
		$out = Renderer::render( new Gist_Payload( '', array( 'x' ), 'api', array( 'x' => 'y' ), true ), Attributes::normalize( array( 'id' => 'abc' ) ) );
		$this->assertStringContainsString( 'https://gist.github.com/abc', $out );
		$this->assertStringNotContainsString( 'gistful"', $out );
	}

	public function test_api_payload_renders_plain() {
		$out = Renderer::render( new Gist_Payload( '', array( 'x.sh' ), 'api', array( 'x.sh' => 'if a<b && c' ) ), Attributes::normalize( array( 'id' => 'abc' ) ) );
		$this->assertStringStartsWith( '<div class="gistful"', $out );
		$this->assertStringContainsString( 'if a&lt;b &amp;&amp; c', $out );
		$this->assertStringContainsString( 'aria-label="x.sh"', $out );
	}

	public function test_plain_does_not_double_decode_and_survives_invalid_utf8() {
		$out = Renderer::render_plain( array( 'x.txt' => "x &amp; y &lt;b&gt;\n\xff\xfe" ), Attributes::normalize( array( 'id' => 'a' ) ) );
		$this->assertStringContainsString( 'x &amp;amp; y &amp;lt;b&amp;gt;', $out );
		$this->assertStringContainsString( "\u{FFFD}", $out );
	}

	public function test_plain_trailing_newline_has_no_extra_row() {
		$out = Renderer::render_plain( array( 'x.txt' => "a\nb\n" ), Attributes::normalize( array( 'id' => 'a' ) ) );
		$this->assertSame( 2, substr_count( $out, '<tr' ) );
	}

	public function test_comment_in_remote_html_does_not_hijack_notice() {
		$div = str_replace( '<div class="gist-file"', '<!--gistful-notice-0--><div class="gist-file"', $this->div( 'json-ipynb.json' ) );
		$out = Renderer::render( new Gist_Payload( $div, array(), 'json' ), Attributes::normalize( array( 'id' => 'abc123' ) ) );
		$this->assertSame( 1, substr_count( $out, 'gistful-unsupported"' ) );
		$this->assertSame( 1, substr_count( $out, 'View this file on GitHub' ) );
	}
}
