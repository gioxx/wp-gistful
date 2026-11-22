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
		Admin\Footer::register();
		Admin\Migration_Controller::register();
		Admin\Migration_Page::register();
	}

	/**
	 * Register the [gistful] shortcode, the [gist] compatibility alias and the
	 * gist URL embed handler.
	 *
	 * [gist] is the shortcode used by GistPress content. It is registered only
	 * when no other plugin or theme owns it, unless the site administrator opts
	 * in to the takeover in Settings > Gistful.
	 *
	 * @return void
	 */
	public static function register_embeds(): void {
		$callback  = array( Embed::class, 'shortcode' );
		$take_over = (bool) Settings_Store::get( 'take_over_gist' );

		add_shortcode( 'gistful', $callback );
		if ( $take_over || ! shortcode_exists( 'gist' ) || self::owns_gist_shortcode() ) {
			add_shortcode( 'gist', $callback );
		}

		// Priority 10 leaves gist URLs to handlers registered earlier; 9 only on opt-in takeover.
		wp_embed_register_handler( 'gistful', Gist_Url::PATTERN, array( Embed::class, 'oembed_handler' ), $take_over ? 9 : 10 );
	}

	/**
	 * Whether [gist] is currently handled by Gistful.
	 *
	 * @return bool
	 */
	public static function owns_gist_shortcode(): bool {
		global $shortcode_tags;

		return isset( $shortcode_tags['gist'] ) && array( Embed::class, 'shortcode' ) === $shortcode_tags['gist'];
	}
}
