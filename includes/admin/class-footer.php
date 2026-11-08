<?php
/**
 * Footer shared by the Gistful admin pages.
 *
 * @package Gistful
 */

namespace Gistful\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Author and project links at the bottom of the admin pages.
 */
final class Footer {

	/**
	 * Project page (short link).
	 */
	const SITE_URL = 'https://go.gioxx.org/gistful';

	/**
	 * Source code repository.
	 */
	const GITHUB_URL = 'https://github.com/gioxx/wp-gistful';

	/**
	 * Print the footer.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="gistful-footer" style="padding-top: 35px;">
			<hr>
			<span class="dashicons dashicons-superhero" aria-hidden="true"></span> Gioxx, <?php echo esc_html( gmdate( 'Y' ) ); ?> &#x2022;
			<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
			<a href="<?php echo esc_url( self::SITE_URL ); ?>">Gioxx.org</a> &#x2022;
			<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			<a href="<?php echo esc_url( self::GITHUB_URL ); ?>">GitHub</a>
		</div>
		<?php
	}
}
