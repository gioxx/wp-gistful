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
	const GALLERY_URL = 'https://wordpress.gxware.org/';

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
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
	}

	/**
	 * Links under the plugin description on the Plugins screen: the GitHub
	 * repository (unless the row already links to it) and the author's gallery.
	 *
	 * @param mixed  $links Row meta links.
	 * @param string $file  Plugin basename of the row.
	 * @return mixed
	 */
	public static function row_meta( mixed $links, string $file ): mixed {
		if ( ! is_array( $links ) || plugin_basename( GISTFUL_FILE ) !== $file ) {
			return $links;
		}
		if ( false === strpos( implode( ' ', $links ), self::GITHUB_URL ) ) {
			$links[] = '<a href="' . esc_url( self::GITHUB_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on GitHub', 'gistful' ) . '</a>';
		}
		$links[] = '<a href="' . esc_url( self::GALLERY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'More plugins by Gioxx', 'gistful' ) . '</a>';

		return $links;
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
	 * Print the plugin card and the gallery invitation side by side, then the
	 * trademark notice.
	 *
	 * @return void
	 */
	public static function render(): void {
		$images = GISTFUL_URL . 'assets/images/';
		?>
		<div class="gistful-footer">
			<div class="gistful-footer__cards">
				<div class="gistful-footer__card gistful-footer__card--plugin">
					<span class="gistful-footer__badge"><img src="<?php echo esc_url( $images . 'gistful-icon.svg' ); ?>" width="20" height="20" alt=""></span>
					<div class="gistful-footer__body">
						<p class="gistful-footer__eyebrow">
							<?php
							/* translators: %s: plugin version number */
							printf( esc_html__( 'Version %s', 'gistful' ), esc_html( GISTFUL_VERSION ) );
							?>
						</p>
						<h2 class="gistful-footer__title">Gistful for GitHub</h2>
						<p class="gistful-footer__text"><?php esc_html_e( 'GitHub Gists in your posts: cached, sanitized, with dark mode and a block.', 'gistful' ); ?></p>
						<p class="gistful-footer__text">
							<?php
							printf(
								/* translators: 1: link to the author's site, 2: link to the plugin repository */
								esc_html__( 'Made by %1$s. Source code on %2$s.', 'gistful' ),
								'<a href="' . esc_url( self::AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Gioxx</a>',
								'<a href="' . esc_url( self::GITHUB_URL ) . '" target="_blank" rel="noopener noreferrer">GitHub</a>'
							);
							?>
						</p>
						<a class="button gistful-footer__action" href="<?php echo esc_url( self::GITHUB_URL ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View on GitHub', 'gistful' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'gistful' ); ?></span>
						</a>
					</div>
				</div>
				<div class="gistful-footer__card gistful-footer__card--gallery">
					<img class="gistful-footer__logo" src="<?php echo esc_url( $images . 'gioxx-plugins.svg' ); ?>" width="40" height="40" alt="">
					<div class="gistful-footer__body">
						<p class="gistful-footer__eyebrow">gioxx/WordPress</p>
						<h2 class="gistful-footer__title"><?php esc_html_e( 'Discover more plugins by Gioxx', 'gistful' ); ?></h2>
						<p class="gistful-footer__text"><?php esc_html_e( 'A curated collection of open-source plugins for security, analytics, performance and integrations.', 'gistful' ); ?></p>
						<a class="button gistful-footer__action" href="<?php echo esc_url( self::GALLERY_URL ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Browse the plugins', 'gistful' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'gistful' ); ?></span>
						</a>
					</div>
				</div>
			</div>
			<p class="description gistful-trademark-notice">
				<?php esc_html_e( 'All trademarks mentioned are the property of their respective owners. Third-party trademarks, product names, trade names, corporate names and companies mentioned may be trademarks of their respective owners or registered trademarks of other companies and have been used for explanatory purposes only and for the benefit of the owner, without any intent to infringe existing copyright.', 'gistful' ); ?>
			</p>
		</div>
		<?php
	}
}
