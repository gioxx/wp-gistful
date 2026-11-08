<?php
/**
 * Bootstrap tests.
 *
 * @package Gistful
 */

/**
 * Plugin bootstrap and settings defaults.
 */
class Test_Bootstrap extends Gistful_Test_Case {

	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'GISTFUL_VERSION' ) );
		$this->assertTrue( class_exists( \Gistful\Plugin::class ) );
	}

	public function test_settings_defaults() {
		delete_option( 'gistful_settings' );
		$this->assertSame( 'auto', \Gistful\Settings_Store::get( 'scheme' ) );
		$this->assertSame( 12, \Gistful\Settings_Store::get( 'cache_hours' ) );
	}

	public function test_boot_is_idempotent() {
		\Gistful\Plugin::boot();
		\Gistful\Plugin::boot();
		$this->assertSame( 10, has_action( 'plugins_loaded', array( \Gistful\Plugin::class, 'boot' ) ) );
	}

	public function test_update_merges_and_ignores_unknown_keys() {
		delete_option( 'gistful_settings' );
		\Gistful\Settings_Store::update(
			array(
				'cache_hours' => '24',
				'bogus'       => 'x',
			)
		);
		$all = \Gistful\Settings_Store::all();
		$this->assertSame( 24, $all['cache_hours'] );
		$this->assertSame( 'auto', $all['scheme'] );
		$this->assertArrayNotHasKey( 'bogus', $all );
		$this->assertNull( \Gistful\Settings_Store::get( 'bogus' ) );
	}

	public function test_autoloader_maps_class_names() {
		$base = GISTFUL_DIR . 'includes/';
		$this->assertSame( $base . 'class-foo-bar.php', \Gistful\Autoloader::path_for( 'Gistful\\Foo_Bar' ) );
		$this->assertSame( $base . 'admin/class-foo.php', \Gistful\Autoloader::path_for( 'Gistful\\Admin\\Foo' ) );
		$this->assertSame( '', \Gistful\Autoloader::path_for( 'Other\\Foo' ) );
	}

	public function test_settings_option_is_autoloaded() {
		delete_option( 'gistful_settings' );
		\Gistful\Settings_Store::update( array( 'cache_hours' => 6 ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayHasKey( 'gistful_settings', wp_load_alloptions( true ) );
	}

	public function test_plugin_file_detection() {
		$this->assertTrue( Gistful_Test_Case::is_plugin_file( GISTFUL_FILE ) );
		$this->assertTrue( Gistful_Test_Case::is_plugin_file( GISTFUL_DIR . 'includes/class-plugin.php' ) );
		$this->assertFalse( Gistful_Test_Case::is_plugin_file( ABSPATH . 'wp-includes/functions.php' ) );
		$this->assertFalse( Gistful_Test_Case::is_plugin_file( GISTFUL_DIR . 'tests/php/test-bootstrap.php' ) );
	}

	public function test_plugin_deprecation_fails_but_core_does_not() {
		$plugin_file = GISTFUL_DIR . 'includes/class-plugin.php';
		foreach ( array( E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_WARNING ) as $level ) {
			$this->assertTrue( Gistful_Test_Case::should_fail( $level, $plugin_file ) );
		}
		$this->assertFalse( Gistful_Test_Case::should_fail( E_USER_DEPRECATED, ABSPATH . 'wp-includes/functions.php' ) );

		try {
			Gistful_Test_Case::handle_error( E_USER_DEPRECATED, 'boom', $plugin_file, 1 );
			$this->fail( 'Plugin deprecation did not throw.' );
		} catch ( ErrorException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertFalse( Gistful_Test_Case::handle_error( E_USER_DEPRECATED, 'core', ABSPATH . 'wp-includes/functions.php', 1 ) );
	}
}
