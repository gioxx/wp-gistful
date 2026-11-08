<?php
/**
 * Conflict detection and GistPress deactivation tests.
 *
 * @package Gistful
 */

use Gistful\Admin\Settings_Page;
use Gistful\Conflicts;

/**
 * Detection of other gist handlers, notice and deactivation action.
 */
class Test_Conflicts extends Gistful_Test_Case {

	public function set_up() {
		parent::set_up();
		// Our own registration (init 20) makes the shortcode exist; start clean.
		remove_shortcode( 'gist' );
	}

	public function test_no_conflict_when_nothing_owns_gist() {
		if ( class_exists( 'GistPress' ) || class_exists( 'Jetpack' ) ) {
			$this->markTestSkipped( 'Stub class leaked into this process.' );
		}
		$this->assertSame( array(), Conflicts::detect() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detects_gistpress_by_class() {
		require_once __DIR__ . '/stubs/class-gistpress.php';
		add_shortcode( 'gist', '__return_empty_string' );
		$found = Conflicts::detect();
		$this->assertCount( 1, $found );
		$this->assertSame( 'gistpress', $found[0]['slug'] );
		$this->assertSame( 'gistpress/gistpress.php', $found[0]['plugin_file'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detects_jetpack_with_shortcodes_module() {
		eval( 'class Jetpack { public static function is_module_active( $m ) { return "shortcodes" === $m; } }' ); // phpcs:ignore Squiz.PHP.Eval
		add_shortcode( 'gist', '__return_empty_string' );
		$found = Conflicts::detect();
		$this->assertSame( 'jetpack', $found[0]['slug'] );
	}

	public function test_unknown_owner_reported_generically() {
		if ( class_exists( 'GistPress' ) || class_exists( 'Jetpack' ) ) {
			$this->markTestSkipped( 'Stub class leaked into this process.' );
		}
		add_shortcode( 'gist', '__return_empty_string' );
		$found = Conflicts::detect();
		$this->assertSame( 'unknown', $found[0]['slug'] );
		$this->assertNull( $found[0]['plugin_file'] );
	}

	public function test_capture_stores_result_for_request_and_status() {
		add_shortcode( 'gist', '__return_empty_string' );
		Conflicts::capture();
		// Later (like after init 20) the shortcode state no longer matters.
		remove_shortcode( 'gist' );
		$this->assertNotEmpty( Conflicts::found() );
		$this->assertNotSame( '', Conflicts::status()['value'] );
	}

	public function test_status_none() {
		if ( class_exists( 'GistPress' ) || class_exists( 'Jetpack' ) ) {
			$this->markTestSkipped( 'Stub class leaked into this process.' );
		}
		Conflicts::capture();
		$this->assertSame( 'None', Conflicts::status()['value'] );
	}

	public function test_settings_page_has_conflicts_row() {
		$this->assertArrayHasKey( 'conflicts', Settings_Page::status_rows() );
	}

	public function test_deactivate_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( WPDieException::class );
		Conflicts::handle_deactivate();
	}

	public function test_deactivate_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'gistful_deactivate_gistpress' );
		$this->expectException( WPDieException::class );
		Conflicts::handle_deactivate();
	}

	public function test_deactivate_runs_and_redirects() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']    = wp_create_nonce( 'gistful_deactivate_gistpress' );
		$_POST['_wpnonce']       = $_REQUEST['_wpnonce'];
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
		update_option( 'active_plugins', array( 'gistpress/gistpress.php', 'other/other.php' ) );
		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$location ) {
				$location = $url;
				return false;
			}
		);
		Conflicts::handle_deactivate();
		$this->assertSame( array( 'other/other.php' ), array_values( get_option( 'active_plugins' ) ) );
		$this->assertStringContainsString( 'gistful_notice=gistpress_deactivated', $location );
	}

	public function test_notice_only_for_capable_users_and_names_plugin() {
		set_current_screen( 'dashboard' );
		add_shortcode( 'gist', '__return_empty_string' );
		Conflicts::capture();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Conflicts::render_notice();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Conflicts::render_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'Gistful', $html );
	}

	private function capture_redirect( string &$location ): void {
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$location ) {
				$location = $url;
				return false;
			}
		);
	}

	private function prepare_post(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']    = wp_create_nonce( 'gistful_deactivate_gistpress' );
		$_POST['_wpnonce']       = $_REQUEST['_wpnonce'];
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
	}

	public function test_gistpress_in_renamed_folder_is_resolved_and_deactivated() {
		$this->prepare_post();
		update_option( 'active_plugins', array( 'gistpress-master/gistpress.php' ) );
		$this->assertSame( 'gistpress-master/gistpress.php', Conflicts::gistpress_file() );
		$location = '';
		$this->capture_redirect( $location );
		Conflicts::handle_deactivate();
		$this->assertSame( array(), array_values( get_option( 'active_plugins' ) ) );
		$this->assertStringContainsString( 'gistpress_deactivated', $location );
	}

	public function test_failed_deactivation_shows_failure_notice() {
		$this->prepare_post();
		update_option( 'active_plugins', array( 'gistpress/gistpress.php' ) );
		// Force the plugin to remain active.
		add_filter( 'pre_update_option_active_plugins', static fn( $new, $old ) => $old, 10, 2 );
		$location = '';
		$this->capture_redirect( $location );
		Conflicts::handle_deactivate();
		$this->assertStringContainsString( 'gistpress_deactivate_failed', $location );

		$_GET['gistful_notice'] = 'gistpress_deactivate_failed';
		ob_start();
		Conflicts::render_notice();
		$this->assertStringContainsString( 'notice-error', ob_get_clean() );
	}

	public function test_deactivation_mode_decision() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'site', Conflicts::deactivation_mode( 'gistpress/gistpress.php' ) );
		if ( ! is_multisite() ) {
			return; // Network-active branches only run with WP_TESTS_MULTISITE.
		}
		update_site_option( 'active_sitewide_plugins', array( 'gistpress/gistpress.php' => time() ) );
		$this->assertSame( 'denied', Conflicts::deactivation_mode( 'gistpress/gistpress.php' ) );
		grant_super_admin( get_current_user_id() );
		$this->assertSame( 'network', Conflicts::deactivation_mode( 'gistpress/gistpress.php' ) );
	}
}
