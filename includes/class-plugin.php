<?php
/**
 * Plugin orchestrator.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
final class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot the plugin. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
	}
}
