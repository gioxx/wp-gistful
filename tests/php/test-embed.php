<?php
/**
 * Embed pipeline tests.
 *
 * @package Gistful
 */

use Gistful\Backup;
use Gistful\Cache;
use Gistful\Embed;
use Gistful\Gist_Payload;
use Gistful\Settings_Store;

/**
 * Cache, backup and fallback behaviour of Embed::render().
 */
class Test_Embed extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock();
		wp_dequeue_style( 'gistful' );
		wp_deregister_style( 'gistful' );
	}

	private function http_calls(): int {
		return count( $this->requests );
	}

	private function reset_calls() {
		$this->requests = array();
	}

	private function mock_ok() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
	}

	private function mock_all( int $code ) {
		$this->mock( 'gist.github.com', $code, '' );
		$this->mock( 'api.github.com', $code, '' );
	}

	private function div( string $name ): string {
		return (string) json_decode( $this->fixture( $name ) )->div;
	}

	private function payload( string $name ): Gist_Payload {
		return new Gist_Payload( $this->div( $name ), array(), 'json' );
	}

	public function test_second_render_hits_cache_no_http() {
		$this->mock_ok();
		Embed::render( array( 'id' => 'abc123' ) );
		$this->reset_calls();
		Embed::render( array( 'id' => 'abc123' ) );
		$this->assertSame( 0, $this->http_calls() );
	}

	public function test_empty_id_returns_empty_string() {
		$this->assertSame( '', Embed::render( array( 'id' => '' ) ) );
		$this->assertSame( 0, $this->http_calls() );
	}

	public function test_same_gist_two_ranges_in_one_post() {
		$this->mock_ok();
		$a = Embed::render(
			array(
				'id'    => 'abc123',
				'lines' => '1-2',
			)
		);
		$b = Embed::render(
			array(
				'id'    => 'abc123',
				'lines' => '3-4',
			)
		);
		$this->assertNotSame( $a, $b );
		$this->assertSame( 1, $this->http_calls() );
	}

	public function test_deleted_gist_uses_backup() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc123', '', $this->payload( 'json-single.json' ) );
		$this->mock_all( 404 );
		$this->assertStringContainsString( 'gist-file', Embed::render( array( 'id' => 'abc123' ), $post ) );
	}

	public function test_backup_result_is_not_pinned_for_the_full_ttl() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc123', '', $this->payload( 'json-single.json' ) );
		$this->mock_all( 503 );
		$attr = \Gistful\Attributes::normalize( array( 'id' => 'abc123' ) );
		Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertEqualsWithDelta( time() + 600, (int) get_option( '_transient_timeout_' . Cache::html_key( $attr ) ), 5 );
	}

	public function test_deleted_gist_without_backup_gives_link_and_negative_cache() {
		$this->mock_all( 404 );
		$this->assertStringContainsString( 'href="https://gist.github.com/abc123"', Embed::render( array( 'id' => 'abc123' ) ) );
		$this->reset_calls();
		$out = Embed::render( array( 'id' => 'abc123' ) );
		$this->assertSame( 0, $this->http_calls() );
		$this->assertStringContainsString( 'https://gist.github.com/abc123', $out );
	}

	public function test_link_fallback_recovers_after_outage() {
		$this->mock_all( 500 );
		Embed::render( array( 'id' => 'abc123' ) );
		Cache::flush_all();
		$this->mock_ok();
		$this->assertStringContainsString( 'gist-file', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	public function test_legacy_gistpress_meta_is_read_and_sanitized() {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_gist_raw_' . md5( 'https://gist.github.com/abc123.json' ), addslashes( $this->div( 'json-single.json' ) . '<script>x</script>' ) );
		$this->mock_all( 500 );
		$out = Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertStringContainsString( 'gist-file', $out );
		$this->assertStringNotContainsString( '<script', $out );
	}

	public function test_no_frontend_write_outside_main_post() {
		$post = self::factory()->post->create();
		$this->mock_ok();
		Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertEmpty( get_post_meta( $post, Backup::meta_key( 'abc123', '' ), true ) );
	}

	public function test_backup_written_for_main_post_in_loop() {
		$post = self::factory()->post->create();
		$this->mock_ok();
		$this->go_to( get_permalink( $post ) );
		have_posts();
		the_post();
		Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertIsArray( get_post_meta( $post, Backup::meta_key( 'abc123', '' ), true ) );
		$this->assertContains( Cache::raw_key( 'abc123', '' ), get_post_meta( $post, '_gistful_keys', true ) );
	}

	public function test_no_post_id_no_backup_read() {
		$this->mock_all( 500 );
		$this->assertStringContainsString( 'href=', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	public function test_feed_returns_link() {
		$this->go_to( get_feed_link() );
		$out = Embed::render( array( 'id' => 'abc123' ) );
		$this->assertStringContainsString( 'View this code snippet on GitHub.', $out );
		$this->assertSame( 0, $this->http_calls() );
	}

	public function test_feed_html_filter() {
		$this->go_to( get_feed_link() );
		add_filter(
			'gistful_feed_html',
			static function () {
				return 'FEED';
			}
		);
		$this->assertSame( 'FEED', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	public function test_bad_200_then_api_then_render() {
		$this->mock( 'gist.github.com', 200, '{"nope":1}' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$this->assertStringContainsString( 'blob-code', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	public function test_css_enqueued_only_when_rendered() {
		$this->assertFalse( wp_style_is( 'gistful', 'enqueued' ) );
		$this->mock_ok();
		Embed::render( array( 'id' => 'abc123' ) );
		$this->assertTrue( wp_style_is( 'gistful', 'enqueued' ) );
		$this->assertSame( GISTFUL_VERSION, wp_styles()->registered['gistful']->ver );
	}

	public function test_css_not_enqueued_when_disabled() {
		$this->mock_ok();
		Embed::render(
			array(
				'id'               => 'abc123',
				'embed_stylesheet' => 'false',
			)
		);
		$this->assertFalse( wp_style_is( 'gistful', 'enqueued' ) );
		Settings_Store::update( array( 'load_css' => false ) );
		Embed::render( array( 'id' => 'def456' ) );
		$this->assertFalse( wp_style_is( 'gistful', 'enqueued' ) );
	}

	public function test_gistful_html_filter_receives_context() {
		$this->mock_ok();
		$seen = null;
		add_filter(
			'gistful_html',
			static function ( $html, $url, $attr, $post_id ) use ( &$seen ) {
				$seen = array( $url, $attr['id'], $post_id );
				return $html;
			},
			10,
			4
		);
		Embed::render( array( 'id' => 'abc123' ), 7 );
		$this->assertSame( array( 'https://gist.github.com/abc123', 'abc123', 7 ), $seen );
	}

	public function test_shortcode_wrapper() {
		$this->mock_ok();
		$this->assertStringContainsString( 'gist-file', Embed::shortcode( array( 'id' => 'abc123' ) ) );
		$this->assertSame( '', Embed::shortcode( '' ) );
	}

	public function test_oembed_rejects_non_gist_url() {
		$this->assertSame( '', Embed::oembed_handler( array(), array(), 'https://evil.example/gist.github.com/abc123', array() ) );
		$this->assertSame( 0, $this->http_calls() );
	}

	public function test_oembed_plain_url() {
		$this->mock_ok();
		$out = Embed::oembed_handler( array(), array(), 'https://gist.github.com/gioxx/abc123', array() );
		$this->assertStringContainsString( 'gist-file', $out );
	}

	public function test_oembed_legacy_underscore_anchor_needs_no_name_fetch() {
		$this->mock_ok();
		Embed::oembed_handler( array(), array(), 'https://gist.github.com/gioxx/abc123#file_swaksWrapper.sh', array() );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '?file=swaksWrapper.sh', $this->requests[0]['url'] );
	}

	public function test_oembed_dash_anchor_resolves_and_caches_names() {
		$this->mock_ok();
		$url = 'https://gist.github.com/gioxx/abc123#file-swaksWrapper-sh';
		Embed::oembed_handler( array(), array(), $url, array() );
		$this->assertStringContainsString( '?file=swaksWrapper.sh', end( $this->requests )['url'] );
		$this->assertSame( array( 'swaksWrapper.sh' ), get_transient( 'gistful_f_' . md5( 'abc123' ) ) );
		$this->reset_calls();
		Embed::oembed_handler( array(), array(), $url, array() );
		$this->assertSame( 0, $this->http_calls(), 'names and html both cached' );
	}

	public function test_oembed_empty_file_names_cached_short() {
		$this->mock_all( 500 );
		Embed::oembed_handler( array(), array(), 'https://gist.github.com/gioxx/abc123#file-foo-sh', array() );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, (int) get_option( '_transient_timeout_gistful_f_' . md5( 'abc123' ) ), 5 );
	}

	public function test_oembed_marks_attr_and_ignores_unresolvable_anchor() {
		$this->mock_ok();
		Embed::oembed_handler( array(), array(), 'https://gist.github.com/gioxx/abc123#file-nomatch-sh', array() );
		$this->assertStringNotContainsString( '?file=', end( $this->requests )['url'] );
	}

	public function test_malformed_raw_cache_is_refetched() {
		$this->mock_ok();
		set_transient( Cache::raw_key( 'abc123', '' ), 'O:8:"stdClass":0:{}', 100 );
		$this->assertStringContainsString( 'gist-file', Embed::render( array( 'id' => 'abc123' ) ) );
		$this->assertSame( 1, $this->http_calls() );
	}
}
