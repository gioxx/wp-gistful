<?php
/**
 * Base test case: plugin-originated notices fail the test.
 *
 * PHPUnit is configured not to convert deprecations to exceptions (WordPress core
 * is noisy on newer PHP), so this handler restores strictness for plugin code only.
 *
 * @package Gistful
 */

/**
 * Base class for Gistful tests.
 */
abstract class Gistful_Test_Case extends WP_UnitTestCase {

	/**
	 * Error levels that fail a test when raised from plugin code.
	 */
	const STRICT_LEVELS = E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING;

	/**
	 * Whether a file belongs to the plugin (main file or includes/).
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	public static function is_plugin_file( string $file ): bool {
		$file = wp_normalize_path( $file );
		$dir  = wp_normalize_path( GISTFUL_DIR );

		return wp_normalize_path( GISTFUL_FILE ) === $file || 0 === strpos( $file, $dir . 'includes/' );
	}

	/**
	 * Whether an error must fail the test.
	 *
	 * @param int    $level Error level.
	 * @param string $file  Originating file.
	 * @return bool
	 */
	public static function should_fail( int $level, string $file ): bool {
		return ( $level & self::STRICT_LEVELS ) && self::is_plugin_file( $file );
	}

	/**
	 * Error handler: throw for plugin code, defer to the previous handler otherwise.
	 *
	 * @param int    $level   Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return bool
	 * @throws ErrorException For plugin-originated errors.
	 */
	public static function handle_error( int $level, string $message, string $file = '', int $line = 0 ): bool {
		if ( self::should_fail( $level, $file ) ) {
			throw new ErrorException( $message, 0, $level, $file, $line ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		return false; // Fall through to the standard handler.
	}

	/**
	 * Install the handler.
	 */
	public function set_up() {
		parent::set_up();
		set_error_handler( array( self::class, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
	}

	/**
	 * Restore the previous handler.
	 */
	public function tear_down() {
		restore_error_handler();
		parent::tear_down();
	}
}
