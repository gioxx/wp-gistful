<?php
/**
 * Uninstall and WP-CLI command tests.
 *
 * @package Gistful
 */

use Gistful\Cache;
use Gistful\Cli\Command;

/**
 * uninstall.php removes only Gistful data; the CLI command clears the cache.
 */
class Test_Uninstall extends Gistful_Test_Case {

	/**
	 * Seed Gistful and GistPress data, returning the post ID.
	 *
	 * @return int
	 */
	private function seed(): int {
		update_option( 'gistful_settings', array( 'scheme' => 'dark' ) );
		update_option( 'gistful_migration_state', array( 'x' => 1 ) );
		update_option( 'gistful_cache_gen', 3 );
		set_transient( 'gistful_h_x', 'y' );
		set_transient( 'gistful_r_x_neg', 1 );
		set_transient( 'other_plugin_t', 'keep' );
		$p = self::factory()->post->create();
		update_post_meta( $p, '_gistful_backup_x', 'y' );
		update_post_meta( $p, '_gistful_keys', array( 'gistful_h_x' ) );
		update_post_meta( $p, '_gist_raw_x', 'keep' );
		update_option( 'gistpress_stylesheet', 'keep' );
		update_option( 'blazersix_x', 'keep' );
		set_transient( 'gist_abc', 'keep' );
		return $p;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_removes_only_gistful_data() {
		global $wpdb;
		$p = $this->seed();
		define( 'WP_UNINSTALL_PLUGIN', 'gistful/gistful.php' );
		include GISTFUL_DIR . 'uninstall.php';

		$this->assertFalse( get_option( 'gistful_settings' ) );
		$this->assertFalse( get_option( 'gistful_migration_state' ) );
		$this->assertFalse( get_option( 'gistful_cache_gen' ) );
		$this->assertFalse( get_transient( 'gistful_h_x' ) );
		$this->assertFalse( get_transient( 'gistful_r_x_neg' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%gistful%'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '', get_post_meta( $p, '_gistful_backup_x', true ) );
		$this->assertSame( '', get_post_meta( $p, '_gistful_keys', true ) );
		$this->assertSame( 'keep', get_post_meta( $p, '_gist_raw_x', true ) );
		$this->assertSame( 'keep', get_option( 'gistpress_stylesheet' ) );
		$this->assertSame( 'keep', get_option( 'blazersix_x' ) );
		$this->assertSame( 'keep', get_transient( 'gist_abc' ) );
		$this->assertSame( 'keep', get_transient( 'other_plugin_t' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_loops_over_sites_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs WP_MULTISITE=1.' );
		}
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		update_option( 'gistful_settings', array( 'a' => 1 ) );
		update_option( 'gistpress_stylesheet', 'keep' );
		restore_current_blog();
		update_option( 'gistful_settings', array( 'b' => 1 ) );

		define( 'WP_UNINSTALL_PLUGIN', 'gistful/gistful.php' );
		include GISTFUL_DIR . 'uninstall.php';

		$this->assertFalse( get_option( 'gistful_settings' ) );
		switch_to_blog( $blog );
		$this->assertFalse( get_option( 'gistful_settings' ) );
		$this->assertSame( 'keep', get_option( 'gistpress_stylesheet' ) );
		restore_current_blog();
	}

	/**
	 * Command whose WP_CLI output is recorded instead of printed.
	 *
	 * @return Command
	 */
	private function recording_command(): Command {
		return new class() extends Command {
			/**
			 * Recorded [type, message] pairs.
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $log = array();

			protected function success( string $message ): void {
				$this->log[] = array( 'success', $message );
			}

			protected function fail( string $message ): void {
				$this->log[] = array( 'error', $message );
				throw new RuntimeException( $message );
			}
		};
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_clear_all() {
		set_transient( 'gistful_h_x', 'y' );
		$gen = Cache::generation();
		$cmd = $this->recording_command();
		$cmd->clear( array(), array() );
		$this->assertFalse( get_transient( 'gistful_h_x' ) );
		$this->assertSame( $gen + 1, Cache::generation() );
		$this->assertSame( array( array( 'success', 'Gistful cache cleared.' ) ), $cmd->log );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_clear_post() {
		$p = self::factory()->post->create();
		set_transient( 'gistful_h_p', 'y' );
		Cache::track( $p, 'gistful_h_p' );
		$cmd = $this->recording_command();
		$cmd->clear( array(), array( 'post' => (string) $p ) );
		$this->assertFalse( get_transient( 'gistful_h_p' ) );
		$this->assertSame( array( array( 'success', "Gistful cache cleared for post $p." ) ), $cmd->log );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_rejects_invalid_post() {
		$cmd = $this->recording_command();
		foreach ( array( 'abc', '0', '-3', '999999', '1.5' ) as $bad ) {
			try {
				$cmd->clear( array(), array( 'post' => $bad ) );
				$this->fail( "Accepted $bad" );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Invalid post', $e->getMessage() );
			}
		}
	}
}
