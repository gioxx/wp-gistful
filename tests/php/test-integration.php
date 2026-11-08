<?php
/**
 * Hook wiring tests: shortcode, oEmbed, prefetch, legacy filters.
 *
 * @package Gistful
 */

use Gistful\Backup;
use Gistful\Cache;
use Gistful\Embed;
use Gistful\Prefetch;

/**
 * Integration with WordPress hooks.
 */
class Test_Integration extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock();
		wp_dequeue_style( 'gistful' );
		wp_deregister_style( 'gistful' );
	}

	private function mock_ok() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
	}

	public function test_classic_post_url_on_own_line_embeds() {
		$this->mock_ok();
		$post = self::factory()->post->create( array( 'post_content' => "Intro\n\nhttps://gist.github.com/gioxx/abc123\n\nOutro" ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertStringContainsString( 'class="gistful"', apply_filters( 'the_content', get_post( $post )->post_content ) );
	}

	public function test_shortcode_overrides_previous_owner() {
		add_shortcode( 'gist', fn() => 'OLD' );
		do_action( 'init' );
		$this->mock_ok();
		$this->assertStringContainsString( 'gistful', do_shortcode( '[gist id="abc123"]' ) );
	}

	public function test_shortcode_with_enclosed_url() {
		\Gistful\Plugin::register_embeds();
		$this->mock_ok();
		$out = do_shortcode( '[gist]https://gist.github.com/gioxx/abc123[/gist]' );
		$this->assertStringContainsString( 'class="gistful', $out );
		$this->assertSame( Embed::render( array( 'id' => 'abc123' ) ), $out );
	}

	public function test_shortcode_with_enclosed_url_resolves_anchor() {
		\Gistful\Plugin::register_embeds();
		$this->mock_ok();
		do_shortcode( '[gist] https://gist.github.com/gioxx/abc123#file_swaksWrapper.sh [/gist]' );
		$this->assertStringContainsString( '?file=swaksWrapper.sh', end( $this->requests )['url'] );
	}

	public function test_shortcode_id_wins_over_enclosed_content_and_junk_is_ignored() {
		\Gistful\Plugin::register_embeds();
		$this->mock_ok();
		$this->assertSame( Embed::render( array( 'id' => 'abc123' ) ), do_shortcode( '[gist id="abc123"]https://gist.github.com/u/def456[/gist]' ) );
		$this->assertSame( '', do_shortcode( '[gist]https://example.com/abc123[/gist]' ) );
	}

	public function test_prefetch_finds_enclosed_shortcode_url() {
		$ids = array_map(
			static function ( $a ) {
				return $a['id'];
			},
			Prefetch::find_gists( '[gist]https://gist.github.com/u/ccc333[/gist]' )
		);
		$this->assertSame( array( 'ccc333' ), $ids );
	}

	public function test_rest_oembed_proxy_renders_gist() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->mock_ok();
		$req = new WP_REST_Request( 'GET', '/oembed/1.0/proxy' );
		$req->set_param( 'url', 'https://gist.github.com/gioxx/abc123' );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'gistful', $res->get_data()->html );
	}

	public function test_save_prefetches_and_stores_backup() {
		$this->mock_ok();
		$post = self::factory()->post->create(
			array(
				'post_content' => 'https://gist.github.com/gioxx/abc123',
				'post_status'  => 'publish',
			)
		);
		$this->assertNotEmpty( get_post_meta( $post, Backup::meta_key( 'abc123', '' ), true ) );
		$this->assertNotEmpty( get_post_meta( $post, Cache::META_KEYS, true ) );
	}

	public function test_prefetch_finds_shortcodes_and_blocks() {
		$this->mock_ok();
		$content = "[gist id=\"aaa111\"]\n\n[[gist id=\"escaped1\"]]\n\n<!-- wp:gistful/gist {\"url\":\"https://gist.github.com/u/bbb222\",\"linesStart\":3} /-->";
		$ids     = array_map(
			static function ( $a ) {
				return $a['id'];
			},
			Prefetch::find_gists( $content )
		);
		$this->assertSame( array( 'aaa111', 'bbb222' ), $ids );
	}

	public function test_prefetch_ignores_inline_urls_and_deduplicates() {
		$content = "See https://gist.github.com/u/abc123 inline\n\n<p>https://gist.github.com/u/abc123</p>\n\nhttps://gist.github.com/u/abc123";
		$this->assertCount( 1, Prefetch::find_gists( $content ) );
	}

	public function test_draft_save_does_not_fetch() {
		$this->mock_ok();
		self::factory()->post->create(
			array(
				'post_content' => 'https://gist.github.com/gioxx/abc123',
				'post_status'  => 'draft',
			)
		);
		$this->assertCount( 0, $this->requests );
	}

	public function test_save_flushes_post_cache() {
		$this->mock_ok();
		$post = self::factory()->post->create( array( 'post_content' => 'nothing' ) );
		set_transient( 'gistful_h_x', 'cached', 60 );
		update_post_meta( $post, Cache::META_KEYS, array( 'gistful_h_x' ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'changed',
			)
		);
		$this->assertFalse( get_transient( 'gistful_h_x' ) );
	}

	public function test_prefetch_stops_when_budget_exhausted() {
		$this->mock_ok();
		add_filter( 'gistful_prefetch_budget', '__return_zero' );
		$post = self::factory()->post->create(
			array(
				'post_content' => '[gist id="aaa111"] [gist id="bbb222"]',
				'post_status'  => 'publish',
			)
		);
		$this->assertCount( 0, $this->requests );
		$this->assertEmpty( get_post_meta( $post, Backup::meta_key( 'aaa111', '' ), true ) );
	}

	public function test_shortcode_outside_loop_does_not_track_keys() {
		$this->mock_ok();
		$post = self::factory()->post->create( array( 'post_content' => 'plain' ) );
		$GLOBALS['post'] = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		do_shortcode( '[gist id="abc123"]' );
		$this->assertEmpty( get_post_meta( $post, Cache::META_KEYS, true ) );
	}

	public function test_shortcode_in_main_loop_tracks_keys() {
		$this->mock_ok();
		$post = self::factory()->post->create( array( 'post_content' => 'plain' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( have_posts() );
		the_post();
		do_shortcode( '[gist id="abc123"]' );
		$this->assertNotEmpty( get_post_meta( $post, Cache::META_KEYS, true ) );
	}

	public function test_legacy_filter_still_applies() {
		$this->setExpectedDeprecated( 'gistpress_html' );
		add_filter(
			'gistpress_html',
			static function ( $h ) {
				return $h . '<!--legacy-->';
			}
		);
		$this->mock_ok();
		$this->assertStringContainsString( '<!--legacy-->', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	public function test_legacy_stylesheet_default_filter() {
		$this->setExpectedDeprecated( 'gistpress_stylesheet_default' );
		add_filter( 'gistpress_stylesheet_default', '__return_false' );
		$attr = Gistful\Attributes::normalize( array( 'id' => 'abc123' ) );
		$this->assertFalse( $attr['embed_stylesheet'] );
	}

	public function test_legacy_line_classes_and_feed_and_color() {
		$this->setExpectedDeprecated( 'gistpress_line_classes' );
		$this->setExpectedDeprecated( 'gistpress_feed_html' );
		$this->setExpectedDeprecated( 'gistpress_highlight_color' );
		$seen = array();
		add_filter(
			'gistpress_line_classes',
			static function ( $c ) use ( &$seen ) {
				$seen[] = func_num_args();
				return $c;
			}
		);
		add_filter(
			'gistpress_feed_html',
			static function ( $h ) {
				return $h . '<!--feed-->';
			}
		);
		add_filter( 'gistpress_highlight_color', fn() => '#123456' );
		$this->mock_ok();
		$this->assertSame( '#123456', Gistful\Attributes::normalize( array( 'id' => 'abc123' ) )['highlight_color'] );
		Embed::render( array( 'id' => 'abc123' ) );
		$this->assertSame( 1, $seen[0] ?? 1 );
		$this->go_to( get_feed_link() );
		$this->assertStringContainsString( '<!--feed-->', Embed::render( array( 'id' => 'abc123' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_importing_does_not_fetch() {
		$this->mock_ok();
		define( 'WP_IMPORTING', true );
		self::factory()->post->create(
			array(
				'post_content' => 'https://gist.github.com/gioxx/abc123',
				'post_status'  => 'publish',
			)
		);
		$this->assertCount( 0, $this->requests );
	}

	public function test_non_viewable_type_flushes_but_does_not_fetch() {
		register_post_type( 'gistful_priv', array( 'public' => false ) );
		$this->mock_ok();
		$post = self::factory()->post->create(
			array(
				'post_type'    => 'gistful_priv',
				'post_content' => 'nothing',
				'post_status'  => 'publish',
			)
		);
		set_transient( 'gistful_h_y', 'cached', 60 );
		update_post_meta( $post, Cache::META_KEYS, array( 'gistful_h_y' ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'https://gist.github.com/gioxx/abc123',
			)
		);
		$this->assertFalse( get_transient( 'gistful_h_y' ) );
		$this->assertCount( 0, $this->requests );
		unregister_post_type( 'gistful_priv' );
	}

	public function test_fresh_raw_cache_is_reused_for_backup_without_http() {
		$this->mock_ok();
		$args = array(
			'post_content' => '[gist id="abc123"]',
			'post_status'  => 'publish',
		);
		self::factory()->post->create( $args );
		$calls = count( $this->requests );
		$this->assertGreaterThan( 0, $calls );
		$second = self::factory()->post->create( $args );
		$this->assertCount( $calls, $this->requests );
		$this->assertNotEmpty( get_post_meta( $second, Backup::meta_key( 'abc123', '' ), true ) );
	}

	public function test_dash_anchor_resolution_is_inside_budget() {
		$this->mock_ok();
		add_filter( 'gistful_prefetch_budget', '__return_zero' );
		self::factory()->post->create(
			array(
				'post_content' => 'https://gist.github.com/gioxx/abc123#file-a-sh',
				'post_status'  => 'publish',
			)
		);
		$this->assertCount( 0, $this->requests );
	}

	public function test_prefetch_detects_paragraph_with_attributes() {
		$this->assertCount( 1, Prefetch::find_gists( '<p class="x">https://gist.github.com/u/abc123</p>' ) );
	}
}
