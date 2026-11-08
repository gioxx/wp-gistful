<?php
/**
 * Gist output must survive the_content (wptexturize, wpautop, smilies, ...)
 * byte-identical on every embed path.
 *
 * @package Gistful
 */

use Gistful\Embed;

/**
 * Regression tests for the_content corrupting gist code.
 */
class Test_Content_Filters extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	const ID  = '89a8520d1117114faaa1268f0c8f8740';
	const URL = 'https://gist.github.com/gioxx/89a8520d1117114faaa1268f0c8f8740';

	/**
	 * Post used as the queried object.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock();
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		wp_dequeue_style( 'gistful' );
		// Other suites may hand [gist] or the oEmbed handler to someone else.
		\Gistful\Plugin::register_embeds();
	}

	/**
	 * Run the_content on a post with this content, as the main query would.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function the_content( string $content ): string {
		$this->post_id = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $this->post_id ) );
		$GLOBALS['post'] = get_post( $this->post_id );
		setup_postdata( $GLOBALS['post'] );

		return (string) apply_filters( 'the_content', get_post( $this->post_id )->post_content );
	}

	/**
	 * Inner HTML of every .gist-data element, in order.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private function code_cells( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = array();
		foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " gist-data ")]' ) as $node ) {
			$inner = '';
			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$inner .= $dom->saveHTML( $child );
			}
			$out[] = $inner;
		}

		return $out;
	}

	/**
	 * Assert the filtered content carries the direct render untouched.
	 *
	 * @param string $content  Filtered content.
	 * @param array  $attr     Attributes for the direct render.
	 * @return void
	 */
	private function assert_untouched( string $content, array $attr ): void {
		$direct = Embed::render( $attr );
		$this->assertNotSame( '', $direct );
		$this->assertStringContainsString( 'gist-data', $direct );

		$cells = $this->code_cells( $content );
		foreach ( $this->code_cells( $direct ) as $cell ) {
			$this->assertContains( $cell, $cells, 'Code cells differ from a direct render.' );
		}
		$this->assertStringContainsString( $direct, $content, 'Gist markup is not byte-identical to a direct render.' );
		// Outside the gist, the regular filters still apply; inside, nothing is added.
		$outside = str_replace( $direct, '', $content );
		foreach ( array( '&#8220;', '&#8221;', '&#8211;', '<br' ) as $needle ) {
			$this->assertSame(
				substr_count( $direct, $needle ),
				substr_count( $content, $needle ) - substr_count( $outside, $needle ),
				"Unexpected $needle inside the gist."
			);
		}
		$joined = implode( '', $this->code_cells( $content ) );
		$this->assertStringNotContainsString( "\u{201C}", $joined, 'Curly quotes inside the code.' );
		$this->assertStringNotContainsString( "\u{2013}", $joined, 'En dash inside the code.' );
		$this->assertStringNotContainsString( 'gistful-ph', $content, 'A placeholder leaked.' );
		$this->assertStringNotContainsString( '<p>' . $direct, $content, 'Gist wrapped in a paragraph.' );
		$this->assertStringNotContainsString( $direct . '</p>', $content, 'Gist wrapped in a paragraph.' );
	}

	public function test_pasted_url_on_own_line() {
		$out = $this->the_content( "Intro \"quoted\" - text\n\n" . self::URL . "\n\nOutro" );
		$this->assert_untouched( $out, array( 'id' => self::ID ) );
		$this->assertStringContainsString( '&#8220;quoted&#8221;', $out, 'Surrounding text should still be texturized.' );
	}

	public function test_embed_shortcode() {
		$out = $this->the_content( "Intro\n\n[embed]" . self::URL . "[/embed]\n\nOutro" );
		$this->assert_untouched( $out, array( 'id' => self::ID ) );
	}

	public function test_core_embed_block() {
		$url     = self::URL;
		$content = "<!-- wp:embed {\"url\":\"$url\",\"type\":\"rich\"} -->\n<figure class=\"wp-block-embed is-type-rich\"><div class=\"wp-block-embed__wrapper\">\n$url\n</div></figure>\n<!-- /wp:embed -->";
		$this->assert_untouched( $this->the_content( $content ), array( 'id' => self::ID ) );
	}

	public function test_gistful_block() {
		$url = self::URL;
		$out = $this->the_content( "<!-- wp:gistful/gist {\"url\":\"$url\"} /-->" );
		$this->assert_untouched( $out, array( 'id' => self::ID ) );
	}

	public function test_gist_shortcode() {
		$out = $this->the_content( "Intro\n\n[gist id=\"" . self::ID . "\"]\n\nOutro" );
		$this->assert_untouched( $out, array( 'id' => self::ID ) );
	}

	public function test_gist_shortcode_survives_late_filters() {
		// convert_smilies (priority 20) runs after do_shortcode (11).
		update_option( 'use_smilies', 1 );
		$code = static function ( $html ) {
			return str_replace( '</table>', '<tr><td class="blob-code">echo :) done</td></tr></table>', $html );
		};
		add_filter( 'gistful_html', $code );
		$out = $this->the_content( "Intro\n\n[gist id=\"" . self::ID . "\"]\n\nOutro" );
		$this->assert_untouched( $out, array( 'id' => self::ID ) );
		$this->assertStringNotContainsString( 'wp-smiley', $out );
		remove_filter( 'gistful_html', $code );
	}

	public function test_classic_post_parity() {
		$content = implode(
			"\n\n",
			array(
				'Pasted:',
				self::URL,
				'Dash anchor:',
				self::URL . '#file-swakswrapper-sh',
				'Underscore anchor:',
				self::URL . '#file_swaksWrapper.sh',
				'Shortcode:',
				'[gist id="' . self::ID . '" lines="2-4" highlight="3"]',
			)
		);
		$out = $this->the_content( $content );

		$this->assert_untouched( $out, array( 'id' => self::ID ) );
		$this->assert_untouched(
			$out,
			array(
				'id'   => self::ID,
				'file' => 'swaksWrapper.sh',
			)
		);
		$this->assert_untouched(
			$out,
			array(
				'id'        => self::ID,
				'lines'     => '2-4',
				'highlight' => '3',
			)
		);
		$this->assertSame( 4, substr_count( $out, 'class="gistful' ) );
	}

	public function test_forged_placeholder_is_not_swapped() {
		// Render a gist inside the_content so a placeholder table exists, then
		// try to reference it from content with a guessed token.
		$out = $this->the_content( "[gist id=\"" . self::ID . "\"]\n\n<div data-gistful-ph=\"0000000000000000-1\"></div>" );
		$this->assertSame( 1, substr_count( $out, 'class="gistful' ) );
	}

	public function test_direct_render_outside_the_content_returns_html() {
		$html = Embed::render( array( 'id' => self::ID ) );
		$this->assertStringContainsString( 'class="gistful', $html );
		$this->assertStringNotContainsString( 'gistful-ph', $html );
	}

	public function test_render_late_in_the_content_returns_html() {
		$captured = '';
		$late     = function ( $content ) use ( &$captured ) {
			$captured = do_shortcode( '[gist id="' . self::ID . '"]' );
			return $content;
		};
		add_filter( 'the_content', $late, 1000 );
		$this->the_content( 'Text' );
		remove_filter( 'the_content', $late, 1000 );
		$this->assertStringContainsString( 'class="gistful', $captured );
		$this->assertStringNotContainsString( 'gistful-ph', $captured );
	}

	public function test_excerpt_has_no_placeholder() {
		$post = self::factory()->post->create(
			array(
				'post_content' => "Intro text\n\n[gist id=\"" . self::ID . '"]',
				'post_excerpt' => '',
			)
		);
		$this->go_to( get_permalink( $post ) );
		$excerpt = get_the_excerpt( $post );
		$this->assertStringNotContainsString( 'gistful-ph', $excerpt );
		$this->assertStringContainsString( 'Intro text', $excerpt );
	}
}
