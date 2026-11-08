<?php
/**
 * Copy button: attribute, setting, markup flag and script loading.
 *
 * @package Gistful
 */

use Gistful\Attributes;
use Gistful\Embed;
use Gistful\Settings_Store;

/**
 * The copy button is opt-out and only loads its script with a rendered gist.
 */
class Test_Copy_Button extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock();
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
		wp_dequeue_script( 'gistful-copy' );
	}

	public function tear_down() {
		delete_option( Settings_Store::OPTION );
		parent::tear_down();
	}

	public function test_attribute_defaults_to_the_setting_and_can_be_turned_off() {
		$this->assertTrue( Attributes::normalize( array( 'id' => 'a' ) )['copy'] );
		$this->assertFalse( Attributes::normalize( array( 'id' => 'a', 'copy' => 'no' ) )['copy'] );
		update_option( Settings_Store::OPTION, array_merge( Settings_Store::defaults(), array( 'copy_button' => false ) ) );
		$this->assertFalse( Attributes::normalize( array( 'id' => 'a' ) )['copy'] );
		$this->assertTrue( Attributes::normalize( array( 'id' => 'a', 'copy' => 'yes' ) )['copy'] );
	}

	public function test_rendered_gist_is_flagged_and_loads_the_script() {
		$html = Embed::render( array( 'id' => 'abc123' ) );
		$this->assertStringContainsString( 'data-copy="1"', $html );
		$this->assertTrue( wp_script_is( 'gistful-copy', 'enqueued' ) );
		$this->assertStringContainsString( 'gistfulCopy', (string) wp_scripts()->get_inline_script_data( 'gistful-copy', 'before' ) );
	}

	public function test_turned_off_means_no_flag_and_no_script() {
		$html = Embed::render( array( 'id' => 'abc123', 'copy' => 'false' ) );
		$this->assertStringNotContainsString( 'data-copy', $html );
		$this->assertFalse( wp_script_is( 'gistful-copy', 'enqueued' ) );
	}

	public function test_link_fallback_loads_no_script() {
		$this->mock( 'gist.github.com', 404, '' );
		$this->mock( 'api.github.com', 404, '' );
		Embed::render( array( 'id' => 'missing1' ) );
		$this->assertFalse( wp_script_is( 'gistful-copy', 'enqueued' ) );
	}

	public function test_setting_is_sanitized() {
		$this->assertTrue( Gistful\Admin\Settings_Page::sanitize( array( 'copy_button' => '1' ) )['copy_button'] );
		$this->assertFalse( Gistful\Admin\Settings_Page::sanitize( array() )['copy_button'] );
		$this->assertTrue( Settings_Store::defaults()['copy_button'] );
	}

	public function test_block_attribute_turns_the_button_off() {
		$html = Gistful\Block::render( array( 'url' => 'https://gist.github.com/abc123', 'copy' => false ) );
		$this->assertStringContainsString( 'class="gistful"', $html );
		$this->assertStringNotContainsString( 'data-copy', $html );
	}

	public function test_flag_and_script_only_when_there_is_something_to_copy() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-markdown.json' ) );
		$html = Embed::render( array( 'id' => 'md1' ) );
		$this->assertStringContainsString( 'class="gistful"', $html );
		$this->assertStringNotContainsString( 'data-copy', $html, 'Markdown is shown rendered: nothing to copy.' );
		$this->assertFalse( wp_script_is( 'gistful-copy', 'enqueued' ) );

		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-csv.json' ) );
		$this->assertStringContainsString( 'data-copy="1"', Embed::render( array( 'id' => 'csv1' ) ) );
		$this->assertTrue( wp_script_is( 'gistful-copy', 'enqueued' ) );
	}
}
