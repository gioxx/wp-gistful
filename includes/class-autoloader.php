<?php
/**
 * Class autoloader.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `Gistful\Foo_Bar` to `includes/class-foo-bar.php` and
 * `Gistful\Admin\Foo` to `includes/admin/class-foo.php`.
 */
final class Autoloader {

	/**
	 * Root namespace handled by this loader.
	 */
	private const PREFIX = 'Gistful\\';

	/**
	 * Absolute path (with trailing slash) of the includes directory.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Register the loader.
	 *
	 * @param string $base_dir Includes directory, with trailing slash.
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = $base_dir;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Resolve a class name to its file path, or an empty string if it is not ours.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string
	 */
	public static function path_for( string $class_name ): string {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return '';
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$class = array_pop( $parts );
		$file  = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$dir   = '';

		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		return self::$base_dir . $dir . $file;
	}

	/**
	 * Load a class file if it exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		$path = self::path_for( $class_name );

		if ( '' !== $path && is_readable( $path ) ) {
			require_once $path;
		}
	}
}
