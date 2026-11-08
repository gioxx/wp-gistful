<?php
/**
 * Bootstrap tests.
 *
 * @package Gistful
 */

/**
 * Plugin bootstrap and settings defaults.
 */
class Test_Bootstrap extends WP_UnitTestCase {

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
}
