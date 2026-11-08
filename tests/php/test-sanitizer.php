<?php
/**
 * Sanitizer tests.
 *
 * @package Gistful
 */

use Gistful\Sanitizer;

/**
 * Allowlist and unsupported-renderer detection.
 */
class Test_Sanitizer extends Gistful_Test_Case {

	private function div( string $name ): string {
		return (string) json_decode( (string) file_get_contents( GISTFUL_DIR . 'tests/fixtures/' . $name ) )->div;
	}

	public function test_strips_dangerous_markup() {
		$out = Sanitizer::clean( '<div class="gist"><script>x</script><img src="https://a/b.png" onerror="x"><a href="javascript:x">l</a><iframe src="https://x"></iframe><td style="color:red">c</td></div>' );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'onerror', $out );
		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringNotContainsString( '<iframe', $out );
		$this->assertStringNotContainsString( 'style=', $out );
	}

	public function test_drops_script_and_template_content() {
		$out = Sanitizer::clean( '<div><script>alert(1)</script><template><b>{{ hidden }}</b></template>ok</div>' );
		$this->assertStringNotContainsString( 'alert', $out );
		$this->assertStringNotContainsString( 'hidden', $out );
		$this->assertStringContainsString( 'ok', $out );
	}

	public function test_preserves_gist_structure() {
		$out = Sanitizer::clean( $this->div( 'json-single.json' ) );
		foreach ( array( 'gist', 'gist-file', 'gist-data', 'blob-num', 'blob-code', 'js-line-number', 'gist-meta' ) as $class ) {
			$this->assertStringContainsString( $class, $out );
		}
		$this->assertMatchesRegularExpression( '/data-line-number="\d+"/', $out );
		$this->assertMatchesRegularExpression( '/class="pl-[a-z0-9]+"/', $out );
		$this->assertStringContainsString( 'href="https://gist.github.com/', $out );
	}

	public function test_markdown_gist_keeps_rendered_tags() {
		$out = Sanitizer::clean( $this->div( 'json-markdown.json' ) );
		$this->assertMatchesRegularExpression( '/<h[1-6]/', $out );
		$this->assertStringContainsString( '<p', $out );
		$this->assertStringContainsString( 'href="#editpdfkit-client-side-utilities"', $out );
	}

	public function test_csv_table_survives_and_is_supported() {
		$raw = $this->div( 'json-csv.json' );
		$this->assertSame( array(), Sanitizer::unsupported_files( $raw ) );
		$out = Sanitizer::clean( $raw );
		$this->assertStringContainsString( '<table', $out );
		$this->assertStringContainsString( '<thead', $out );
		$this->assertStringContainsString( '<th', $out );
		$this->assertStringContainsString( '<td', $out );
		$this->assertStringNotContainsString( '<input', $out );
		$this->assertStringNotContainsString( 'revealButtonHref', $out );
	}

	public function test_detects_ipynb_viewer() {
		// The fixture's real file name (the plan's 'notebook.ipynb' was a placeholder).
		$this->assertSame( array( 'listesChaineesCorrection.ipynb' ), Sanitizer::unsupported_files( $this->div( 'json-ipynb.json' ) ) );
	}

	public function test_normal_files_are_not_unsupported() {
		$this->assertSame( array(), Sanitizer::unsupported_files( $this->div( 'json-multi.json' ) ) );
		$this->assertSame( array(), Sanitizer::unsupported_files( '' ) );
	}

	public function test_only_https_urls() {
		$out = Sanitizer::clean( '<a href="http://x.y">a</a><img src="http://x.y/i.png">' );
		$this->assertStringNotContainsString( 'http://', $out );
	}

	public function test_fragment_hrefs_survive() {
		$this->assertStringContainsString( 'href="#file-x"', Sanitizer::clean( '<a href="#file-x">x</a>' ) );
	}

	/**
	 * @dataProvider bad_urls
	 */
	public function test_non_https_urls_dropped( string $url ) {
		$out = Sanitizer::clean( '<a href="' . $url . '">A</a><img src="' . $url . '" alt="i">' );
		$this->assertStringNotContainsString( 'href=', $out );
		$this->assertStringNotContainsString( 'src=', $out );
		$this->assertStringContainsString( '>A</a>', $out );
	}

	public function bad_urls(): array {
		return array(
			'protocol-relative' => array( '//evil.com/x' ),
			'root-relative'     => array( '/wp-admin/' ),
			'relative'          => array( 'x.png' ),
			'data'              => array( 'data:text/html;base64,PHNjcmlwdD4=' ),
			'entity js'         => array( '&#106;avascript:alert(1)' ),
			'tab js'            => array( 'java&#x09;script:alert(1)' ),
			'colon js'          => array( 'javascript&colon;alert(1)' ),
			'http'              => array( 'http://x.y/' ),
			'space in scheme'   => array( 'ht tps://evil.com/t.png' ),
			'space before colon' => array( 'https ://evil.com/t.png' ),
			'space after h'     => array( 'h ttps://evil.com/t.png' ),
			'newline entity'    => array( 'ht&#x0A;tps://evil.com/t.png' ),
		);
	}

	/**
	 * @dataProvider bad_fragments
	 */
	public function test_bad_fragment_hrefs_dropped( string $href ) {
		$out = Sanitizer::clean( '<a href="' . $href . '">A</a>' );
		$this->assertStringNotContainsString( 'href=', $out );
		$this->assertStringContainsString( '>A</a>', $out );
	}

	public function bad_fragments(): array {
		return array(
			'colon-slashes' => array( '#x://h' ),
			'colon-slash'   => array( '#x:/h' ),
			'colon'         => array( '#user-content-a:b' ),
		);
	}

	public function test_kses_failure_fails_closed() {
		$out = Gistful_Failing_Sanitizer::clean( '<div id="gist1" class="gist"><a href="https://x.y/">A</a><img src="https://x.y/i.png"><script>bad()</script>t</div>' );
		$this->assertStringNotContainsString( 'href=', $out );
		$this->assertStringNotContainsString( 'src=', $out );
		$this->assertStringNotContainsString( 'id=', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'class="gist"', $out );
	}

	public function test_https_urls_kept() {
		$out = Sanitizer::clean( '<a href="https://x.y/a">A</a><img src="https://x.y/i.png">' );
		$this->assertStringContainsString( 'href="https://x.y/a"', $out );
		$this->assertStringContainsString( 'src="https://x.y/i.png"', $out );
		$out = Sanitizer::clean( '<a href="https://gist.github.com/a/b#file-foo">A</a><a href="#file-foo">B</a>' );
		$this->assertStringContainsString( 'href="https://gist.github.com/a/b#file-foo"', $out );
		$this->assertStringContainsString( 'href="#file-foo"', $out );
	}

	public function test_large_unclosed_svg_keeps_rows() {
		$rows = str_repeat( '<table><tr><td class="blob-code">row</td></tr></table>', 28000 );
		$out  = Sanitizer::clean( '<div class="gist"><svg><path d="x">' . str_repeat( 'a', 1400000 ) . '</div>' . $rows );
		$this->assertStringContainsString( 'row', Sanitizer::clean( '<div>' . $rows . '</div>' ) );
		$this->assertNotSame( '', $out );
		$this->assertStringContainsString( 'row', $out );
		$big = Sanitizer::clean( '<div class="gist">' . $rows . '<template>' . str_repeat( 'T', 1500000 ) . '</template></div>' );
		$this->assertStringContainsString( 'row', $big );
		$this->assertStringNotContainsString( 'TTTT', $big );
	}

	public function test_nested_template_leaves_no_text() {
		$out = Sanitizer::clean( '<div><template><template>inner</template>outer</template>ok</div>' );
		$this->assertStringNotContainsString( 'inner', $out );
		$this->assertStringNotContainsString( 'outer', $out );
		$this->assertStringContainsString( 'ok', $out );
	}

	public function test_attribute_with_script_text_does_not_lose_content() {
		$out = Sanitizer::clean( '<a title="<script>" href="https://x">KEEP</a> mid </script>tail' );
		$this->assertStringContainsString( 'KEEP', $out );
		$this->assertStringContainsString( 'tail', $out );
		$this->assertStringNotContainsString( '<script', $out );
	}

	public function test_flagged_file_without_meta_gets_fallback_name() {
		$with_id = '<div class="gist"><div class="gist-file"><div id="file-foo-ipynb"><iframe src="https://x"></iframe></div></div></div>';
		$this->assertSame( array( 'file-foo-ipynb' ), Sanitizer::unsupported_files( $with_id ) );
		$bare = '<div class="gist"><div class="gist-file"><div class="render-container"></div></div></div>';
		$this->assertSame( array( 'file-1' ), Sanitizer::unsupported_files( $bare ) );
	}

	public function test_untrusted_ids_dropped_fixture_ids_kept() {
		$out = Sanitizer::clean( '<div id="wpadminbar" class="x">a</div><div id="gist123">b</div><div id="file-a-sh">c</div><td id="LC4">d</td><h1 id="user-content-foo">e</h1>' );
		$this->assertStringNotContainsString( 'wpadminbar', $out );
		foreach ( array( 'gist123', 'file-a-sh', 'LC4', 'user-content-foo' ) as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $out );
		}
	}
}

/**
 * Sanitizer whose DOM loading always fails, to exercise the fail-closed path.
 */
class Gistful_Failing_Sanitizer extends Sanitizer {
	protected static function load_dom( string $html ): ?\DOMDocument {
		return null;
	}
}
