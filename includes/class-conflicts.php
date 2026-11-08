<?php
/**
 * Detection of other [gist] handlers and GistPress coexistence.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Finds plugins that also handle gists, warns about them and can deactivate GistPress.
 */
final class Conflicts {

	const ACTION        = 'gistful_deactivate_gistpress';
	const GISTPRESS_ID  = 'gistpress/gistpress.php';
	const NOTICE_SCREEN = array( 'dashboard', 'plugins', 'settings_page_gistful' );

	/**
	 * Result captured on init priority 19, before Gistful registers its own handlers.
	 *
	 * @var array<int, array{slug: string, name: string, plugin_file: ?string}>|null
	 */
	private static ?array $found = null;

	/**
	 * Hook the notice and the deactivation action.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Priority 19: after plugins' default init, before our own registration (20).
		add_action( 'init', array( self::class, 'capture' ), 19 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_deactivate' ) );
	}

	/**
	 * Store the current detection for the rest of the request.
	 *
	 * @return void
	 */
	public static function capture(): void {
		self::$found = self::detect();
	}

	/**
	 * Captured result (detects now if capture() never ran).
	 *
	 * @return array<int, array{slug: string, name: string, plugin_file: ?string}>
	 */
	public static function found(): array {
		return self::$found ?? self::detect();
	}

	/**
	 * Detect other handlers of the gist shortcode / embeds right now.
	 *
	 * @return array<int, array{slug: string, name: string, plugin_file: ?string}>
	 */
	public static function detect(): array {
		$found = array();

		if ( class_exists( 'GistPress' ) ) {
			$found[] = array(
				'slug'        => 'gistpress',
				'name'        => 'GistPress',
				'plugin_file' => self::GISTPRESS_ID,
			);
		}
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'shortcodes' ) ) {
			$found[] = array(
				'slug'        => 'jetpack',
				'name'        => 'Jetpack (Shortcodes)',
				'plugin_file' => null,
			);
		}
		if ( ! $found && shortcode_exists( 'gist' ) ) {
			$found[] = array(
				'slug'        => 'unknown',
				'name'        => __( 'another plugin or theme', 'gistful' ),
				'plugin_file' => null,
			);
		}

		return $found;
	}

	/**
	 * Row for the settings status box.
	 *
	 * @return array{label: string, value: string}
	 */
	public static function status(): array {
		$names = array_map(
			static fn( array $c ): string => 'unknown' === $c['slug'] ? $c['name'] : sprintf( /* translators: %s: plugin name */ __( '%s (active)', 'gistful' ), $c['name'] ),
			self::found()
		);

		return array(
			'label' => __( 'Other gist handlers', 'gistful' ),
			'value' => $names ? implode( ', ', $names ) : __( 'None', 'gistful' ),
		);
	}

	/**
	 * Whether the notice should show on the current admin screen.
	 *
	 * @return bool
	 */
	private static function on_notice_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, self::NOTICE_SCREEN, true );
	}

	/**
	 * Admin notices: conflict warning and deactivation result.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$flag = isset( $_GET['gistful_notice'] ) ? sanitize_key( wp_unslash( $_GET['gistful_notice'] ) ) : '';
		if ( 'gistpress_deactivated' === $flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'GistPress has been deactivated. Gistful now handles your gists.', 'gistful' ) . '</p></div>';
		}

		if ( ! self::on_notice_screen() ) {
			return;
		}
		foreach ( self::found() as $conflict ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: name of the other plugin */
					__( 'Gistful detected %s, which also handles gists. Gistful has taken over gist embeds on this site, so they are rendered by Gistful.', 'gistful' ),
					$conflict['name']
				)
			);
			echo '</p>';
			if ( 'gistpress' === $conflict['slug'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
				wp_nonce_field( self::ACTION );
				echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Deactivate GistPress', 'gistful' ) . '</button></p>';
				echo '</form>';
			}
			echo '</div>';
		}
	}

	/**
	 * Handle the "Deactivate GistPress" form post.
	 *
	 * @return void
	 */
	public static function handle_deactivate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gistful' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( self::GISTPRESS_ID );

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( 'gistful_notice', $back ) : admin_url( 'plugins.php' );
		// wp_safe_redirect() returns false when a wp_redirect filter cancels it (tests).
		if ( wp_safe_redirect( add_query_arg( 'gistful_notice', 'gistpress_deactivated', $back ) ) ) {
			exit;
		}
	}
}
