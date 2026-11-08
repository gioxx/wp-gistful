<?php
/**
 * Settings storage.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `gistful_settings` option.
 */
final class Settings_Store {

	/**
	 * Option name.
	 */
	public const OPTION = 'gistful_settings';

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'scheme'            => 'auto',
			'load_css'          => true,
			'show_line_numbers' => true,
			'show_meta'         => true,
			'cache_hours'       => 12,
		);
	}

	/**
	 * All settings, merged with defaults and cast to their types.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = wp_parse_args( $stored, self::defaults() );

		return array(
			'scheme'            => (string) $merged['scheme'],
			'load_css'          => (bool) $merged['load_css'],
			'show_line_numbers' => (bool) $merged['show_line_numbers'],
			'show_meta'         => (bool) $merged['show_meta'],
			'cache_hours'       => (int) $merged['cache_hours'],
		);
	}

	/**
	 * A single setting, or null for an unknown key.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Merge values into the stored settings. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $values Values to store.
	 * @return void
	 */
	public static function update( array $values ): void {
		$values = array_intersect_key( $values, self::defaults() );

		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}
}
