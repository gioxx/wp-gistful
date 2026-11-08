<?php
/**
 * Bottom of the Gistful admin pages: other plugins by the author, credits.
 *
 * @package Gistful
 */

namespace Gistful\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin gallery invitation, author, source and trademark notice.
 */
final class Footer {

	/**
	 * Author's site.
	 */
	const AUTHOR_URL = 'https://gioxx.org';

	/**
	 * Source code repository.
	 */
	const GITHUB_URL = 'https://github.com/gioxx/wp-gistful';

	/**
	 * Gallery of the author's WordPress plugins.
	 */
	const GALLERY_URL = 'https://wordpress.gioxx.org/';

	/**
	 * Style handle of the admin pages.
	 */
	const STYLE_HANDLE = 'gistful-admin';

	/**
	 * Hook the stylesheet loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Load admin.css on the Gistful pages only.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'settings_page_' . Settings_Page::SLUG, 'tools_page_' . Migration_Page::SLUG ), true ) ) {
			return;
		}
		wp_enqueue_style( self::STYLE_HANDLE, GISTFUL_URL . 'assets/css/admin.css', array(), GISTFUL_VERSION );
	}

	/**
	 * Print the gallery invitation and the credits.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="gistful-more-plugins">
			<img class="gistful-more-plugins__logo" src="<?php echo esc_url( GISTFUL_URL . 'assets/images/gioxx-wordpress.svg' ); ?>" width="48" height="48" alt="">
			<div class="gistful-more-plugins__body">
				<p class="gistful-more-plugins__eyebrow">gioxx/WordPress</p>
				<h2 class="gistful-more-plugins__title"><?php esc_html_e( 'Discover more plugins by Gioxx', 'gistful' ); ?></h2>
				<p class="gistful-more-plugins__text"><?php esc_html_e( 'A curated collection of open-source plugins for security, analytics, performance and integrations.', 'gistful' ); ?></p>
			</div>
			<a class="button gistful-more-plugins__action" href="<?php echo esc_url( self::GALLERY_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Browse the plugins', 'gistful' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'gistful' ); ?></span>
			</a>
		</div>
		<div class="gistful-credits">
			<p class="description">
				<?php
				printf(
					/* translators: 1: link to the author's site, 2: link to the plugin repository */
					esc_html__( 'Gistful for GitHub by %1$s, source on %2$s.', 'gistful' ),
					'<a href="' . esc_url( self::AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Gioxx</a>',
					'<a href="' . esc_url( self::GITHUB_URL ) . '" target="_blank" rel="noopener noreferrer">GitHub</a>'
				);
				?>
			</p>
			<p class="description gistful-trademark-notice">
				<?php esc_html_e( 'All trademarks mentioned are the property of their respective owners. Third-party trademarks, product names, trade names, corporate names and companies mentioned may be trademarks of their respective owners or registered trademarks of other companies and have been used for explanatory purposes only and for the benefit of the owner, without any intent to infringe existing copyright.', 'gistful' ); ?>
			</p>
		</div>
		<?php
	}
}
