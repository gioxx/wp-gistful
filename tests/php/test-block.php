<?php
/**
 * Block tests.
 *
 * @package Gistful
 */

use Gistful\Block;

/**
 * The gistful/gist dynamic block.
 */
class Test_Block extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock();
	}

	private function mock_ok() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
	}

	public function test_block_render_matches_shortcode() {
		$this->mock_ok();
		$block = Block::render(
			array(
				'url'   => 'https://gist.github.com/gioxx/abc123',
				'lines' => '2-4',
			)
		);
		$this->assertNotSame( '', $block );
		$this->assertStringContainsString( do_shortcode( '[gist id="abc123" lines="2-4"]' ), $block );
	}

	public function test_bare_id_is_accepted() {
		$this->mock_ok();
		$block = Block::render( array( 'url' => 'abc123' ) );
		$this->assertSame( Block::render( array( 'url' => 'https://gist.github.com/abc123' ) ), $block );
	}

	public function test_unset_flags_are_omitted_and_set_flags_mapped() {
		$this->mock_ok();
		$with = Block::render(
			array(
				'url'      => 'abc123',
				'showMeta' => false,
			)
		);
		$this->assertSame( do_shortcode( '[gist id="abc123" show_meta="false"]' ), $this->inner( $with ) );
	}

	public function test_empty_url_renders_nothing() {
		$this->assertSame( '', Block::render( array() ) );
		$this->assertSame( '', Block::render( array( 'url' => 'https://example.com/x' ) ) );
	}

	public function test_block_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'gistful/gist' ) );
	}

	public function test_rest_block_renderer_returns_gist() {
		$this->mock_ok();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/gistful/gist' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', array( 'url' => 'https://gist.github.com/gioxx/abc123' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'gistful', $response->get_data()['rendered'] );
	}

	/**
	 * Strip the block wrapper div.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	private function inner( string $html ): string {
		return (string) preg_replace( '#^<div[^>]*>(.*)</div>$#s', '$1', $html );
	}
}
