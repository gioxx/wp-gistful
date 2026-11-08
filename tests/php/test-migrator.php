<?php
/**
 * Migration tests.
 *
 * @package Gistful
 */

use Gistful\Admin\Migrator;
use Gistful\Backup;
use Gistful\Sanitizer;

/**
 * GistPress migration: scan, import, cleanup, convert, REST.
 */
class Test_Migrator extends Gistful_Test_Case {

	use Gistful_Http_Mock;

	public function set_up() {
		parent::set_up();
		$this->set_up_http_mock(); // Unmocked hosts fail: no test touches the network.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Raw legacy gist HTML, with a script that sanitizing must drop.
	 */
	private function legacy_html(): string {
		$json = json_decode( $this->fixture( 'json-single.json' ), true );

		return $json['div'] . '<script>alert(1)</script>';
	}

	/**
	 * 3 posts, 4 legacy metas (one per gist), 2 transients, the stylesheet option.
	 *
	 * @return int[] Post IDs.
	 */
	private function seed_gistpress_data(): array {
		$p1 = self::factory()->post->create( array( 'post_content' => "https://gist.github.com/gioxx/abc111\n\n[gist id=\"abc222\" file=\"a.php\"]" ) );
		$p2 = self::factory()->post->create( array( 'post_content' => '[gist id="abc333"]' ) );
		$p3 = self::factory()->post->create( array( 'post_content' => '[gist id="abc444"]' ) );
		add_post_meta( $p1, Migrator::legacy_key( 'abc111', '' ), wp_slash( $this->legacy_html() ) );
		add_post_meta( $p1, Migrator::legacy_key( 'abc222', 'a.php' ), wp_slash( $this->legacy_html() ) );
		add_post_meta( $p2, Migrator::legacy_key( 'abc333', '' ), wp_slash( $this->legacy_html() ) );
		add_post_meta( $p3, Migrator::legacy_key( 'abc444', '' ), wp_slash( $this->legacy_html() ) );
		set_transient( 'gist_html_deadbeef', '<div>x</div>', 600 );
		set_transient( '_gist_raw_deadbeef', '<div>x</div>', 600 );
		update_option( 'gistpress_stylesheet', 'https://example.com/gist.css' );

		return array( $p1, $p2, $p3 );
	}

	private function db_snapshot(): array {
		global $wpdb;

		return array(
			$wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ),
			$wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ),
			$wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ),
		);
	}

	private function count_meta_like( string $prefix ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	private function any_backup_html(): string {
		global $wpdb;
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( '_gistful_backup_' ) . '%' ) );
		$data  = maybe_unserialize( $value );

		return (string) $data['html'];
	}

	public function test_scan_is_read_only_and_counts() {
		$this->seed_gistpress_data();
		$before = $this->db_snapshot();
		$s      = Migrator::scan();
		$this->assertSame( 4, $s['legacy_meta'] );
		$this->assertSame( 3, $s['posts_with_gists'] );
		$this->assertSame( 2, $s['legacy_transients'] );
		$this->assertTrue( $s['legacy_option'] );
		$this->assertSame( 0, $s['block_posts_convertible'] );
		$this->assertSame( $before, $this->db_snapshot() );
	}

	public function test_import_creates_sanitized_backups_in_batches() {
		$this->seed_gistpress_data();
		$r = Migrator::import_backups( 0, 2 );
		$this->assertSame( 2, $r['next'] );
		$r = Migrator::import_backups( 2, 2 );
		$this->assertNull( $r['next'] );
		$this->assertSame( 4, $this->count_meta_like( '_gistful_backup_' ) );
		$this->assertSame( 4, $this->count_meta_like( '_gist_raw_' ), 'legacy rows are kept until cleanup' );
		$this->assertStringNotContainsString( '<script', $this->any_backup_html() );
	}

	public function test_imported_backup_is_readable_and_resanitizing_is_idempotent() {
		list( $p1 ) = $this->seed_gistpress_data();
		Migrator::import_backups( 0, 50 );
		$payload = Backup::unpack( get_post_meta( $p1, Backup::meta_key( 'abc222', 'a.php' ), true ), 'backup' );
		$this->assertNotNull( $payload );
		$this->assertSame( 'backup', $payload->source );
		$this->assertSame( Sanitizer::clean( $payload->html ), $payload->html );
	}

	public function test_import_skips_unmatched_rows_and_keeps_existing_backups() {
		list( $p1, $p2 ) = $this->seed_gistpress_data();
		add_post_meta( $p2, '_gist_raw_' . md5( 'nothing' ), wp_slash( $this->legacy_html() ) );
		$existing = array(
			'html'      => '<div>mine</div>',
			'files'     => array(),
			'raw_files' => array(),
			'truncated' => false,
			'hash'      => 'h',
		);
		update_post_meta( $p1, Backup::meta_key( 'abc111', '' ), $existing );
		$r = Migrator::import_backups( 0, 50 );
		$this->assertSame( 5, $r['processed'] );
		$this->assertSame( 2, $r['skipped'], 'one unmatched row, one existing backup' );
		$this->assertSame( 3, $r['imported'] );
		$this->assertSame( $existing, get_post_meta( $p1, Backup::meta_key( 'abc111', '' ), true ) );
	}

	public function test_cleanup_refuses_while_gistpress_active() {
		update_option( 'active_plugins', array( 'gistpress/gistpress.php' ) );
		$this->seed_gistpress_data();
		$this->assertWPError( Migrator::cleanup( 0 ) );
		$this->assertSame( 4, $this->count_meta_like( '_gist_raw_' ) );
	}

	public function test_cleanup_deletes_legacy_data_and_escapes_like_wildcards() {
		update_option( '_transient_gistXhtml_keep', 1 ); // "_" is not a wildcard.
		update_option( 'gistXhtml_keep', 1 );
		$posts = $this->seed_gistpress_data();
		update_post_meta( $posts[0], 'XgistXrawX', 'keep' );
		update_post_meta( $posts[0], 'xgist_raw_keep', 'keep' );
		$r = Migrator::cleanup( 0, 500, true );
		$this->assertNull( $r['next'] );
		$this->assertSame( 1, (int) get_option( 'gistXhtml_keep' ) );
		$this->assertSame( 1, (int) get_option( '_transient_gistXhtml_keep' ) );
		$this->assertSame( 'keep', get_post_meta( $posts[0], 'xgist_raw_keep', true ) );
		$this->assertSame( 0, $this->count_meta_like( '_gist_raw_' ) );
		$this->assertFalse( get_option( 'gistpress_stylesheet', false ) );
		$this->assertFalse( get_transient( 'gist_html_deadbeef' ) );
		$this->assertSame( 0, Migrator::scan()['legacy_transients'] );
	}

	public function test_cleanup_batches() {
		$this->seed_gistpress_data();
		$r = Migrator::cleanup( 0, 2, true );
		$this->assertNotNull( $r['next'] );
		$this->assertSame( 2, $this->count_meta_like( '_gist_raw_' ) );
		$r = Migrator::cleanup( (int) $r['next'], 2, true );
		$this->assertNull( $r['next'] );
		$this->assertSame( 0, $this->count_meta_like( '_gist_raw_' ) );
	}

	public function test_convert_skips_classic_posts() {
		$post = self::factory()->post->create( array( 'post_content' => 'https://gist.github.com/gioxx/abc123' ) );
		$this->assertFalse( Migrator::convert( $post ) );
		$this->assertSame( 'https://gist.github.com/gioxx/abc123', get_post( $post )->post_content );
	}

	public function test_convert_block_post_creates_revision() {
		$post = self::factory()->post->create( array( 'post_content' => '<!-- wp:shortcode -->[gist id="abc123" lines="2-4"]<!-- /wp:shortcode -->' ) );
		$prev = Migrator::preview_conversion( $post );
		$this->assertStringNotContainsString( 'wp:shortcode', $prev['after'] );
		$this->assertStringContainsString( 'wp:shortcode', get_post( $post )->post_content, 'preview saves nothing' );

		$this->assertTrue( Migrator::convert( $post ) );
		$this->assertStringContainsString( '<!-- wp:gistful/gist {"url":"https://gist.github.com/abc123","lines":"2-4"} /-->', get_post( $post )->post_content );
		$revisions = wp_get_post_revisions( $post );
		$this->assertNotEmpty( $revisions );
		$old = array_filter(
			$revisions,
			static fn( $r ) => false !== strpos( $r->post_content, 'wp:shortcode' )
		);
		$this->assertCount( 1, $old, 'the original content is kept in a revision' );
		$this->assertFalse( Migrator::convert( $post ), 'second run changes nothing' );
	}

	public function test_convert_embed_and_leaves_other_blocks_alone() {
		$content = "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:embed {\"url\":\"https://gist.github.com/gioxx/abc123#file-a-php\",\"providerNameSlug\":\"embed-handler\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://gist.github.com/gioxx/abc123#file-a-php\n</div></figure>\n<!-- /wp:embed -->\n\n"
			. "<!-- wp:embed {\"url\":\"https://youtu.be/x\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtu.be/x\n</div></figure>\n<!-- /wp:embed -->\n\n"
			. "<!-- wp:shortcode -->\n[gist id=\"a1\"] and more text\n<!-- /wp:shortcode -->\n\n"
			. "<!-- wp:shortcode -->\n[gallery]\n<!-- /wp:shortcode -->";
		$post = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->assertTrue( Migrator::convert( $post ) );
		$out = get_post( $post )->post_content;
		$this->assertStringContainsString( '<!-- wp:gistful/gist {"url":"https://gist.github.com/gioxx/abc123#file-a-php"} /-->', $out );
		$this->assertStringContainsString( '<p>Hi</p>', $out );
		$this->assertStringContainsString( 'https://youtu.be/x', $out );
		$this->assertStringContainsString( '[gist id="a1"] and more text', $out );
		$this->assertStringContainsString( '[gallery]', $out );
		$this->assertSame( 1, substr_count( $out, 'wp:gistful/gist' ) );
	}

	public function test_convert_makes_no_real_http_calls() {
		$post = self::factory()->post->create( array( 'post_content' => '<!-- wp:shortcode -->[gist id="abc123"]<!-- /wp:shortcode -->' ) );
		Migrator::convert( $post );
		foreach ( $this->requests as $r ) {
			$this->assertSame( 'unmocked', ( $this->route( null, array(), $r['url'] ) )->get_error_code(), 'every request went through the mock' );
		}
	}

	public function test_rest_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( 'GET /gistful/v1/migration/scan', 'POST /gistful/v1/migration/import', 'POST /gistful/v1/migration/cleanup', 'GET /gistful/v1/migration/preview/1', 'POST /gistful/v1/migration/convert/1' ) as $route ) {
			list( $method, $path ) = explode( ' ', $route );
			$req = new WP_REST_Request( $method, $path );
			$req->set_param( 'hash', md5( 'x' ) );
			$this->assertSame( 403, rest_do_request( $req )->get_status(), $route );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/gistful/v1/migration/scan' ) )->get_status() );
	}

	public function test_rest_flow_and_progress_state() {
		$this->seed_gistpress_data();
		$res = rest_do_request( new WP_REST_Request( 'GET', '/gistful/v1/migration/scan' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 4, $res->get_data()['legacy_meta'] );

		$req = new WP_REST_Request( 'POST', '/gistful/v1/migration/import' );
		$req->set_param( 'limit', 2 );
		$res = rest_do_request( $req );
		$this->assertSame( 2, $res->get_data()['next'] );
		$this->assertSame( 2, get_option( 'gistful_migration_state' )['import']['next'] );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'gistful_migration_state' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/gistful/v1/migration/scan' ) );
		$this->assertSame( 2, $res->get_data()['state']['import']['next'] );
	}

	public function test_rest_validates_limit() {
		$req = new WP_REST_Request( 'POST', '/gistful/v1/migration/import' );
		$req->set_param( 'limit', 500 );
		$this->assertSame( 400, rest_do_request( $req )->get_status() );
		$req = new WP_REST_Request( 'POST', '/gistful/v1/migration/import' );
		$req->set_param( 'offset', -1 );
		$this->assertSame( 400, rest_do_request( $req )->get_status() );
	}

	public function test_rest_cleanup_conflict_and_convert() {
		update_option( 'active_plugins', array( 'gistpress/gistpress.php' ) );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/gistful/v1/migration/cleanup' ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'gistful_gistpress_active', $res->as_error()->get_error_code() );

		$post = self::factory()->post->create( array( 'post_content' => '<!-- wp:shortcode -->[gist id="abc123"]<!-- /wp:shortcode -->' ) );
		$res  = rest_do_request( new WP_REST_Request( 'GET', "/gistful/v1/migration/preview/$post" ) );
		$this->assertStringContainsString( 'wp:gistful/gist', $res->get_data()['after'] );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/gistful/v1/migration/preview/999999' ) )->get_status() );
		$req = new WP_REST_Request( 'POST', "/gistful/v1/migration/convert/$post" );
		$this->assertSame( 400, rest_do_request( $req )->get_status(), 'hash is required' );
		$req->set_param( 'hash', $res->get_data()['hash'] );
		$res = rest_do_request( $req );
		$this->assertTrue( $res->get_data()['converted'] );
		$this->assertNotContains( $post, Migrator::convertible_ids(), 'converted post is no longer convertible' );
	}

	public function test_convert_requires_a_revision_of_the_current_content() {
		$content = '<!-- wp:shortcode -->[gist id="abc123"]<!-- /wp:shortcode -->';
		$post    = self::factory()->post->create( array( 'post_content' => $content ) );
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$res = Migrator::convert( $post );
		$this->assertWPError( $res );
		$this->assertSame( 'gistful_no_revision', $res->get_error_code() );
		$this->assertSame( $content, get_post( $post )->post_content );
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		$this->assertTrue( Migrator::convert( $post ) );
	}

	public function test_convert_rejects_changed_content_hash() {
		$post = self::factory()->post->create( array( 'post_content' => '<!-- wp:shortcode -->[gist id="abc123"]<!-- /wp:shortcode -->' ) );
		$hash = Migrator::preview_conversion( $post )['hash'];
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<!-- wp:shortcode -->[gist id="zzz999"]<!-- /wp:shortcode -->',
			)
		);
		$res = Migrator::convert( $post, $hash );
		$this->assertWPError( $res );
		$this->assertSame( 'gistful_content_changed', $res->get_error_code() );
		$this->assertStringContainsString( 'wp:shortcode', get_post( $post )->post_content );
	}

	public function test_backslashes_survive_import_and_backup_get() {
		$p1  = self::factory()->post->create( array( 'post_content' => '[gist id="abc111"]' ) );
		$div = '<div class="gist"><div class="gist-file"><table class="highlight"><tr><td class="blob-code blob-code-inner js-file-line">printf("a\\nb"); C:\\path</td></tr></table></div></div>';
		$key = Migrator::legacy_key( 'abc111', '' );
		update_post_meta( $p1, $key, addslashes( $div ) ); // As GistPress did.
		$this->assertSame( $div, get_post_meta( $p1, $key, true ) );
		$this->assertSame( $div, Backup::get( $p1, 'abc111', '' )->html, 'legacy fallback is byte-for-byte' );
		Migrator::import_backups( 0, 50 );
		$html = Backup::get( $p1, 'abc111', '' )->html;
		$this->assertStringContainsString( 'printf("a\\nb"); C:\\path', str_replace( '&quot;', '"', $html ) );
		$this->assertSame( 'backup', Backup::get( $p1, 'abc111', '' )->source );
	}

	public function test_embed_with_caption_or_unknown_attrs_is_not_converted_and_class_align_carry_over() {
		$embed = static fn( string $attrs, string $caption = '' ) => "<!-- wp:embed $attrs -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://gist.github.com/gioxx/abc123\n</div>$caption</figure>\n<!-- /wp:embed -->";
		$url   = '"url":"https://gist.github.com/gioxx/abc123"';
		$cap   = '<figcaption class="wp-element-caption">My gist</figcaption>';

		$captioned = $embed( "{" . $url . "}", $cap );
		$this->assertSame( $captioned, Migrator::transform( $captioned ) );
		$odd = $embed( '{' . $url . ',"allowResponsive":false}' );
		$this->assertSame( $odd, Migrator::transform( $odd ) );

		$ok = Migrator::transform( $embed( '{' . $url . ',"className":"my-class","align":"wide","providerNameSlug":"embed-handler"}', '<figcaption class="wp-element-caption"></figcaption>' ) );
		$this->assertStringContainsString( '<!-- wp:gistful/gist {"url":"https://gist.github.com/gioxx/abc123","className":"my-class","align":"wide"} /-->', $ok );
	}

	public function test_convertible_list_reaches_posts_beyond_the_first_chunk() {
		add_filter( 'gistful_migration_convert_limit', static fn() => 2 );
		$skip = '<!-- wp:shortcode -->[gist id="a1"] trailing text<!-- /wp:shortcode -->';
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create( array( 'post_content' => $skip ) );
		}
		$late = self::factory()->post->create( array( 'post_content' => '<!-- wp:shortcode -->[gist id="abc123"]<!-- /wp:shortcode -->' ) );
		$this->assertSame( array( $late ), Migrator::convertible_ids() );
	}

	public function test_void_embed_block_maps_legacy_key() {
		$p1 = self::factory()->post->create( array( 'post_content' => '<!-- wp:embed {"url":"https://gist.github.com/gioxx/abc777"} /-->' ) );
		add_post_meta( $p1, Migrator::legacy_key( 'abc777', '' ), wp_slash( $this->legacy_html() ) );
		$r = Migrator::import_backups( 0, 50 );
		$this->assertSame( 1, $r['imported'] );
		$this->assertSame( 0, $r['skipped'] );
	}

	public function test_cleanup_requires_finished_import_unless_forced() {
		$this->seed_gistpress_data();
		$res = Migrator::cleanup( 0, 500 );
		$this->assertWPError( $res );
		$this->assertSame( 'gistful_import_pending', $res->get_error_code() );
		$this->assertSame( 4, $this->count_meta_like( '_gist_raw_' ) );

		$req = new WP_REST_Request( 'POST', '/gistful/v1/migration/cleanup' );
		$this->assertSame( 409, rest_do_request( $req )->get_status() );

		rest_do_request( new WP_REST_Request( 'POST', '/gistful/v1/migration/import' ) ); // Whole import in one batch.
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'POST', '/gistful/v1/migration/cleanup' ) )->get_status() );
		$this->assertSame( 0, $this->count_meta_like( '_gist_raw_' ) );
	}

	public function test_cleanup_force_flag_over_rest() {
		$this->seed_gistpress_data();
		$req = new WP_REST_Request( 'POST', '/gistful/v1/migration/cleanup' );
		$req->set_param( 'force', true );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
	}

	public function test_cleanup_stall_is_an_error_not_a_loop() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_content' => '[gist id="abc111"]' ) );
		add_post_meta( $post, Migrator::legacy_key( 'abc111', '' ), 'x' );
		add_filter(
			'query',
			static function ( $sql ) use ( $wpdb ) {
				return 0 === strpos( $sql, "DELETE FROM `{$wpdb->postmeta}`" ) ? $sql . ' AND 1=0' : $sql;
			}
		);
		$res = Migrator::cleanup( 0, 50, true );
		$this->assertWPError( $res );
		$this->assertSame( 'gistful_cleanup_stalled', $res->get_error_code() );
	}
}
