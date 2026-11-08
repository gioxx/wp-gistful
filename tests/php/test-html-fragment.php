<?php
/**
 * HTML fragments must round-trip the same way on every libxml version.
 *
 * @package Gistful
 */

use Gistful\Html_Fragment;

/**
 * Html_Fragment load/serialize.
 */
class Test_Html_Fragment extends Gistful_Test_Case {

	/**
	 * Parse and serialize once.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private function roundtrip( string $html ): string {
		$dom = Html_Fragment::load( $html );
		$this->assertNotNull( $dom );

		return Html_Fragment::serialize( $dom );
	}

	public function test_keeps_every_top_level_node() {
		$out = $this->roundtrip( '<a href="https://x">KEEP</a> mid <b>bold</b>tail' );
		$this->assertStringContainsString( 'KEEP', $out );
		$this->assertStringContainsString( ' mid ', $out );
		$this->assertStringContainsString( '<b>bold</b>', $out );
		$this->assertStringContainsString( 'tail', $out );
	}

	public function test_leaves_no_encoding_hint_and_is_idempotent() {
		$html = '<div class="gist"><p>caf&eacute; ❤ “quoted”</p></div>';
		$once = $this->roundtrip( $html );
		$this->assertStringNotContainsString( '?xml', $once );
		$this->assertStringNotContainsString( 'gistful-fragment', $once );
		$this->assertStringContainsString( '❤', $once );
		$this->assertStringContainsString( '“quoted”', $once );
		$this->assertSame( $once, $this->roundtrip( $once ) );
	}

	public function test_stray_end_tags_do_not_drop_content() {
		$out = $this->roundtrip( '<div>one</div></div>two</script>three' );
		foreach ( array( 'one', 'two', 'three' ) as $text ) {
			$this->assertStringContainsString( $text, $out );
		}
	}
}
