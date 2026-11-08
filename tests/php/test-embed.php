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

	public function test_truncated_payload_gives_uncached_link_without_css() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-truncated.json' ) );
		$attr = array( 'id' => 'abc123' );
		$out  = Embed::render( $attr );
		$this->assertStringContainsString( 'gist.github.com/abc123', $out );
		$this->assertStringNotContainsString( 'gist-data', $out );
		$this->assertFalse( Cache::get( Cache::html_key( Gistful\Attributes::normalize( $attr ) ) ), 'Link must not be cached as HTML.' );
		$this->assertFalse( wp_style_is( 'gistful', 'enqueued' ) );
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
		$this->assertSame( array( 'swaksWrapper.sh' ), get_transient( \Gistful\Cache::names_key( 'abc123' ) ) );
		$this->reset_calls();
		Embed::oembed_handler( array(), array(), $url, array() );
		$this->assertSame( 0, $this->http_calls(), 'names and html both cached' );
	}

	public function test_oembed_empty_file_names_cached_short() {
		$this->mock_all( 500 );
		Embed::oembed_handler( array(), array(), 'https://gist.github.com/gioxx/abc123#file-foo-sh', array() );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, (int) get_option( '_transient_timeout_' . \Gistful\Cache::names_key( 'abc123' ) ), 5 );
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

	/**
	 * Expiry timestamp of a transient.
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private function expiry( string $key ): int {
		return (int) get_option( '_transient_timeout_' . $key );
	}

	public function test_api_fallback_is_cached_for_one_hour_only() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$attr = array( 'id' => 'abc123' );
		$this->assertStringContainsString( 'blob-code', Embed::render( $attr ) );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $this->expiry( Cache::html_key( Gistful\Attributes::normalize( $attr ) ) ), 5 );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $this->expiry( Cache::raw_key( 'abc123', '' ) ), 5 );
	}

	public function test_api_fallback_keeps_a_shorter_configured_ttl() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$ttl = static function () {
			return 60;
		};
		add_filter( 'gistful_cache_ttl', $ttl );
		Embed::render( array( 'id' => 'abc123' ) );
		remove_filter( 'gistful_cache_ttl', $ttl );
		$this->assertEqualsWithDelta( time() + 60, $this->expiry( Cache::raw_key( 'abc123', '' ) ), 5 );
	}

	public function test_api_fallback_stores_nothing_when_caching_is_disabled() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		add_filter( 'gistful_cache_ttl', '__return_zero' );
		Embed::render( array( 'id' => 'abc123' ) );
		remove_filter( 'gistful_cache_ttl', '__return_zero' );
		$this->assertFalse( Cache::get( Cache::raw_key( 'abc123', '' ) ) );
	}

	/**
	 * The single-file fixture as GitHub sometimes serves it: same markup, no
	 * syntax highlighting spans.
	 *
	 * @return string JSON body.
	 */
	private function unhighlighted_json(): string {
		$data      = json_decode( $this->fixture( 'json-single.json' ), true );
		$data['div'] = str_replace( 'class="pl-', 'class="xx-', $data['div'] );

		return (string) wp_json_encode( $data );
	}

	public function test_unhighlighted_payload_is_detected() {
		$this->assertFalse( $this->payload( 'json-single.json' )->is_degraded() );
		$plain = new Gist_Payload( str_replace( 'class="pl-', 'class="xx-', $this->div( 'json-single.json' ) ), array(), 'json' );
		$this->assertTrue( $plain->is_degraded() );
		$this->assertTrue( ( new Gist_Payload( '', array( 'a.sh' ), 'api', array( 'a.sh' => 'echo' ) ) )->is_degraded() );
		// Plain text and rendered files (CSV, Markdown) never carry highlighting.
		$text = str_replace( array( 'class="pl-', 'type-shell' ), array( 'class="xx-', 'type-text' ), $this->div( 'json-single.json' ) );
		$this->assertFalse( ( new Gist_Payload( $text, array(), 'json' ) )->is_degraded() );
		$this->assertFalse( $this->payload( 'json-csv.json' )->is_degraded() );
		$this->assertFalse( $this->payload( 'json-markdown.json' )->is_degraded() );
	}

	public function test_unhighlighted_response_is_cached_for_one_hour_only() {
		$this->mock( 'gist.github.com', 200, $this->unhighlighted_json() );
		$attr = array( 'id' => 'abc123' );
		$this->assertStringContainsString( 'blob-code', Embed::render( $attr ) );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_' . Cache::html_key( Gistful\Attributes::normalize( $attr ) ) ), 5 );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_' . Cache::raw_key( 'abc123', '' ) ), 5 );
	}

	public function test_unhighlighted_response_falls_back_to_a_highlighted_backup() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc123', '', $this->payload( 'json-single.json' ) );
		$this->mock( 'gist.github.com', 200, $this->unhighlighted_json() );
		$this->go_to( get_permalink( $post ) );
		have_posts();
		the_post();
		$out = Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertStringContainsString( 'class="pl-', $out, 'The highlighted backup should be shown.' );
		$this->assertStringContainsString( 'class="pl-', Backup::get( $post, 'abc123', '' )->html, 'The backup must not be overwritten.' );
		$attr = Gistful\Attributes::normalize( array( 'id' => 'abc123' ) );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_' . Cache::html_key( $attr ) ), 5, 'Not pinned for the full TTL.' );
	}

	public function test_unhighlighted_response_does_not_replace_a_highlighted_backup() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc123', '', $this->payload( 'json-single.json' ) );
		Backup::maybe_store( $post, 'abc123', '', new Gist_Payload( str_replace( 'class="pl-', 'class="xx-', $this->div( 'json-single.json' ) ), array(), 'json' ) );
		$this->assertStringContainsString( 'class="pl-', Backup::get( $post, 'abc123', '' )->html );
	}

	public function test_short_unhighlighted_snippet_is_detected() {
		$one = '<div class="gist"><div class="blob-wrapper data type-shell"><table><tr><td class="blob-num"></td><td class="blob-code blob-code-inner">echo hi</td></tr></table></div></div>';
		$this->assertTrue( ( new Gist_Payload( $one, array(), 'json' ) )->is_degraded() );
		$empty = str_replace( 'echo hi', '', $one );
		$this->assertFalse( ( new Gist_Payload( $empty, array(), 'json' ) )->is_degraded(), 'No code, nothing to highlight.' );
	}

	public function test_code_signature_matches_across_json_and_api_copies() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$from_api = Gistful\Source::fetch( 'abc123' );
		$this->assertSame( $this->payload( 'json-single.json' )->code_signature(), $from_api->code_signature() );
	}

	public function test_edited_gist_without_highlighting_is_shown_not_the_old_backup() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc123', '', $this->payload( 'json-single.json' ) );
		$data        = json_decode( $this->unhighlighted_json(), true );
		$data['div'] = str_replace( 'SMTP interactive tester wrapper for swaks', 'edited wrapper', $data['div'] );
		$this->mock( 'gist.github.com', 200, (string) wp_json_encode( $data ) );
		$this->go_to( get_permalink( $post ) );
		have_posts();
		the_post();
		$out = Embed::render( array( 'id' => 'abc123' ), $post );
		$this->assertStringContainsString( 'edited wrapper', $out, 'The current code wins over an outdated backup.' );
		$this->assertStringContainsString( 'edited wrapper', Backup::get( $post, 'abc123', '' )->html, 'The backup follows the edit.' );
	}
}
