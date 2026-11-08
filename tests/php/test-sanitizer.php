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
}
