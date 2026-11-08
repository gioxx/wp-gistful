<?php
/**
 * Attributes tests.
 *
 * @package Gistful
 */

use Gistful\Attributes;

/**
 * Attribute normalization.
 */
class Test_Attributes extends Gistful_Test_Case {

	public function test_id_strips_invalid_and_limits_length() {
		$this->assertSame( 'abc123', Attributes::normalize( array( 'id' => 'abc-123' ) )['id'] );
		$this->assertSame( '', Attributes::normalize( array( 'id' => str_repeat( 'a', 41 ) ) )['id'] );
	}

	public function test_file_rejects_slashes() {
		$this->assertSame( '', Attributes::normalize( array( 'id' => 'a', 'file' => '../x.php' ) )['file'] );
		$this->assertSame( 'foo.bar.php', Attributes::normalize( array( 'id' => 'a', 'file' => 'foo.bar.php' ) )['file'] );
	}

	public function test_bool_semantics_match_gistpress() {
		foreach ( array( 'false', '0', 'no', 'n', 'NO', '' ) as $v ) {
			$this->assertFalse( Attributes::to_bool( $v ) );
		}
		foreach ( array( 'true', '1', 'yes' ) as $v ) {
			$this->assertTrue( Attributes::to_bool( $v ) );
		}
	}

	public function test_highlight_ranges_expand_and_clamp() {
		$this->assertSame( array( 1, 3, 4, 5 ), Attributes::normalize( array( 'id' => 'a', 'highlight' => '1, 3-5' ) )['highlight'] );
		$this->assertSame( array( 3, 4, 5 ), Attributes::normalize( array( 'id' => 'a', 'highlight' => '5-3' ) )['highlight'] );
		$this->assertCount( 5000, Attributes::normalize( array( 'id' => 'a', 'highlight' => '1-99999999' ) )['highlight'] );
		$this->assertSame( array( 2 ), Attributes::normalize( array( 'id' => 'a', 'highlight' => 'x,2,-1' ) )['highlight'] );
	}

	public function test_lines_open_ended() {
		$this->assertSame( array( 'min' => 5, 'max' => 0 ), Attributes::normalize( array( 'id' => 'a', 'lines' => '5-' ) )['lines'] );
		$this->assertSame( array( 'min' => 1, 'max' => 3 ), Attributes::normalize( array( 'id' => 'a', 'lines' => '-3' ) )['lines'] );
		$this->assertSame( array( 'min' => 5, 'max' => 0 ), Attributes::normalize( array( 'id' => 'a', 'lines' => ' 5 - ' ) )['lines'] );
		$this->assertSame( array( 'min' => 0, 'max' => 0 ), Attributes::normalize( array( 'id' => 'a', 'lines' => '-' ) )['lines'] );
		$this->assertSame( array( 'min' => 2, 'max' => 9 ), Attributes::normalize( array( 'id' => 'a', 'lines' => ' 9 - 2 ' ) )['lines'] );
	}

	public function test_lines_parse() {
		$this->assertSame( array( 'min' => 2, 'max' => 9 ), Attributes::normalize( array( 'id' => 'a', 'lines' => '2-9' ) )['lines'] );
		$this->assertSame( array( 'min' => 4, 'max' => 4 ), Attributes::normalize( array( 'id' => 'a', 'lines' => '4' ) )['lines'] );
		$this->assertSame( array( 'min' => 0, 'max' => 0 ), Attributes::normalize( array( 'id' => 'a' ) )['lines'] );
	}

	public function test_highlight_color_defaults_to_empty() {
		$this->assertSame( '', Attributes::normalize( array( 'id' => 'a' ) )['highlight_color'] );
	}

	public function test_highlight_color_rejects_css_injection() {
		$this->assertSame( '', Attributes::normalize( array( 'id' => 'a', 'highlight_color' => 'red;background:url(x)' ) )['highlight_color'] );
		$this->assertSame( '#abcdef', Attributes::normalize( array( 'id' => 'a', 'highlight_color' => '#abcdef' ) )['highlight_color'] );
	}

	public function test_defaults_follow_settings_and_scheme_validates() {
		update_option( 'gistful_settings', array( 'show_meta' => false ) );
		$this->assertFalse( Attributes::normalize( array( 'id' => 'a' ) )['show_meta'] );
		$this->assertSame( '', Attributes::normalize( array( 'id' => 'a', 'scheme' => 'purple' ) )['scheme'] );
		$this->assertSame( 'dark', Attributes::normalize( array( 'id' => 'a', 'scheme' => 'dark' ) )['scheme'] );
	}

	public function test_output_keys_and_lines_start() {
		$out = Attributes::normalize( array( 'id' => 'a', 'lines_start' => '-7' ) );
		$this->assertSame(
			array( 'id', 'file', 'lines', 'lines_start', 'highlight', 'highlight_color', 'show_line_numbers', 'show_meta', 'embed_stylesheet', 'copy', 'scheme', 'oembed' ),
			array_keys( $out )
		);
		$this->assertSame( 7, $out['lines_start'] );
		$this->assertFalse( $out['oembed'] );
	}
}
