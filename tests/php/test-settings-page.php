<?php
/**
 * Settings page, CSS and cache-invalidation tests.
 *
 * @package Gistful
 */

use Gistful\Cache;
use Gistful\Admin\Settings_Page;
use Gistful\Settings_Store;

/**
 * Sanitizing, clear-cache handler, status box and stylesheet contract.
 */
class Test_Settings_Page extends Gistful_Test_Case {

	private function css(): string {
		return (string) file_get_contents( GISTFUL_DIR . 'assets/css/gistful.css' );
	}

	public function test_sanitize_settings() {
		$clean = Settings_Page::sanitize(
			array(
				'scheme'      => 'neon',
				'load_css'    => '1',
				'cache_hours' => '-5',
				'show_meta'   => '',
			)
		);
		$this->assertSame( 'auto', $clean['scheme'] );
		$this->assertTrue( $clean['load_css'] );
		$this->assertSame( 1, $clean['cache_hours'] );
		$this->assertFalse( $clean['show_meta'] );
		$this->assertFalse( $clean['show_line_numbers'] );
		$this->assertSame( 168, Settings_Page::sanitize( array( 'cache_hours' => '9999' ) )['cache_hours'] );
		$this->assertSame( 12, Settings_Page::sanitize( array( 'cache_hours' => 'abc' ) )['cache_hours'] );
		$this->assertSame( 'dark', Settings_Page::sanitize( array( 'scheme' => 'dark' ) )['scheme'] );
		$this->assertSame( array( 'scheme', 'load_css', 'show_line_numbers', 'show_meta', 'cache_hours' ), array_keys( Settings_Page::sanitize( 'junk' ) ) );
	}

	public function test_clear_cache_requires_cap_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->expectException( WPDieException::class );
		Settings_Page::handle_clear_cache();
	}

	public function test_clear_cache_requires_nonce_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( WPDieException::class );
		Settings_Page::handle_clear_cache();
	}

	public function test_clear_cache_flushes_and_redirects() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gistful_clear_cache' );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
		$_SERVER['HTTP_REFERER'] = admin_url( 'options-general.php?page=gistful' );
		$gen      = Cache::generation();
		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $to ) use ( &$location ) {
				$location = $to;
				return false;
			}
		);
		Settings_Page::handle_clear_cache();
		$this->assertSame( $gen + 1, Cache::generation() );
		$this->assertStringContainsString( 'options-general.php?page=gistful', $location );
		$this->assertStringContainsString( 'gistful_notice=cache_cleared', $location );
	}

	public function test_saving_settings_flushes_cache() {
		$gen = Cache::generation();
		Settings_Store::update( array( 'scheme' => 'dark' ) );
		$this->assertGreaterThan( $gen, Cache::generation() );
		$gen = Cache::generation();
		Settings_Store::update( array( 'scheme' => 'light' ) );
		$this->assertGreaterThan( $gen, Cache::generation() );
		delete_option( Settings_Store::OPTION );
		$gen = Cache::generation();
		add_option( Settings_Store::OPTION, array( 'scheme' => 'dark' ) );
		$this->assertGreaterThan( $gen, Cache::generation() );
	}

	public function test_status_rows_never_print_token() {
		$rows = Settings_Page::status_rows();
		$this->assertSame( 'No', $rows['token']['value'] );
		$this->assertSame( 'No', $rows['legacy']['value'] );
		update_option( 'gistpress_stylesheet', 'x' );
		$this->assertSame( 'Yes', Settings_Page::status_rows()['legacy']['value'] );
	}

	public function test_render_page_requires_cap_and_escapes_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['gistful_notice'] = 'cache_cleared';
		ob_start();
		Settings_Page::render_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-success', $notice );
		$_GET['gistful_notice'] = '<script>';
		ob_start();
		Settings_Page::render_notice();
		$this->assertSame( '', ob_get_clean() );
		ob_start();
		Settings_Page::render_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="gistful_clear_cache"', $html );
		$this->assertStringContainsString( 'name="gistful_settings[cache_hours]"', $html );
		unset( $_GET['gistful_notice'] );
	}

	public function test_css_has_no_external_urls() {
		$this->assertDoesNotMatchRegularExpression( '#url\(\s*["\']?https?://|@import#i', $this->css() );
	}

	public function test_css_defines_dark_scheme_both_ways() {
		$css = $this->css();
		$this->assertStringContainsString( '[data-scheme="dark"]', $css );
		$this->assertStringContainsString( 'prefers-color-scheme: dark', $css );
		$this->assertStringContainsString( '[data-scheme="auto"]', $css );
		$this->assertMatchesRegularExpression( '/\.gist-data\s*\{[^}]*overflow-x:\s*auto/', $css );
	}

	public function test_css_defines_every_fixture_syntax_class_and_variable_for_both_schemes() {
		$css     = $this->css();
		$classes = array();
		foreach ( glob( GISTFUL_DIR . 'tests/fixtures/*' ) as $file ) {
			if ( preg_match_all( '/pl-[a-z0-9]+/', (string) file_get_contents( $file ), $m ) ) {
				$classes = array_merge( $classes, $m[0] );
			}
		}
		$classes = array_unique( $classes );
		$this->assertNotEmpty( $classes );
		$dark_block = (string) substr( $css, (int) strpos( $css, '[data-scheme="dark"]' ) );
		$alias      = array(
			'pl-pds' => 'pl-s',
			'pl-e'   => 'pl-en',
		);
		// Like GitHub, an escape outside a regular expression takes the colour of its string.
		$classes = array_diff( $classes, array( 'pl-cce' ) );
		foreach ( $classes as $class ) {
			$var = $alias[ $class ] ?? $class;
			$this->assertMatchesRegularExpression( '/\.' . $class . '\b[^{]*\{[^}]*var\(--gistful-' . $var . '\)/', $css, "$class styled" );
		}
		foreach ( array_unique( array_map( static fn( $c ) => $alias[ $c ] ?? $c, $classes ) ) as $var ) {
			$this->assertStringContainsString( '--gistful-' . $var . ':', $css, "$var light value" );
			$this->assertGreaterThanOrEqual( 2, substr_count( $dark_block, '--gistful-' . $var . ':' ), "$var dark values (forced and auto)" );
		}
		foreach ( array( 'bg', 'fg', 'border', 'meta-bg', 'meta-fg', 'line-num-fg', 'line-highlight', 'link' ) as $var ) {
			$this->assertGreaterThanOrEqual( 3, substr_count( $css, '--gistful-' . $var . ':' ), "$var light/dark/auto" );
		}
	}

	public function test_admin_pages_render_the_footer() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( array( Settings_Page::class, 'render_page' ), array( \Gistful\Admin\Migration_Page::class, 'render_page' ) ) as $page ) {
			ob_start();
			call_user_func( $page );
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'class="gistful-footer"', $html );
			$this->assertStringContainsString( 'src="' . GISTFUL_URL . 'assets/images/gistful-icon.svg"', $html );
			$this->assertStringContainsString( 'href="https://gioxx.org"', $html );
			$this->assertStringContainsString( 'href="https://github.com/gioxx/wp-gistful"', $html );
			$this->assertStringContainsString( 'gistful-trademark-notice', $html );
			$this->assertStringNotContainsString( 'go.gioxx.org', $html );
			$this->assertStringNotContainsString( 'style=', $html, 'Styles belong in admin.css.' );
			$this->assertStringContainsString( 'href="https://wordpress.gioxx.org/"', $html );
			$this->assertStringContainsString( 'src="' . GISTFUL_URL . 'assets/images/gioxx-plugins.svg"', $html );
		}
	}

	public function test_admin_css_loads_on_gistful_pages_only() {
		\Gistful\Admin\Footer::enqueue( 'index.php' );
		$this->assertFalse( wp_style_is( 'gistful-admin', 'enqueued' ) );
		foreach ( array( 'settings_page_gistful', 'tools_page_gistful-migration' ) as $hook ) {
			wp_dequeue_style( 'gistful-admin' );
			\Gistful\Admin\Footer::enqueue( $hook );
			$this->assertTrue( wp_style_is( 'gistful-admin', 'enqueued' ), $hook );
		}
		$this->assertFileExists( GISTFUL_DIR . 'assets/css/admin.css' );
		$this->assertFileExists( GISTFUL_DIR . 'assets/images/gioxx-plugins.svg' );
	}
}
