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

		add_action( 'init', array( Embed::class, 'register_style' ) );
		// Priority 20: the 'gistful' style handle (priority 10) must exist first.
		add_action( 'init', array( Block::class, 'register' ), 20 );
		Conflicts::register();
		// Priority 20: after GistPress (default priority), so Gistful takes over.
		add_action( 'init', array( self::class, 'register_embeds' ), 20 );
		add_action( 'save_post', array( Prefetch::class, 'on_save' ), 10, 2 );
		Placeholders::register();
		Legacy_Hooks::register();
		Admin\Settings_Page::register();
		Admin\Migration_Controller::register();
		Admin\Migration_Page::register();
	}

	/**
	 * Register the [gist] shortcode and the gist oEmbed handler.
	 *
	 * @return void
	 */
	public static function register_embeds(): void {
		add_shortcode( 'gist', array( Embed::class, 'shortcode' ) );
		wp_embed_register_handler( 'gist', Gist_Url::PATTERN, array( Embed::class, 'oembed_handler' ) );
	}
}
