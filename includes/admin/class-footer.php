<?php
/**
 * Credits shared by the Gistful admin pages.
 *
 * @package Gistful
 */

namespace Gistful\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Author, source and trademark notice at the bottom of the admin pages.
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
	 * Print the credits.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="gistful-credits" style="max-width:600px;margin-top:32px;padding-top:16px;border-top:1px solid #dcdcde">
			<p class="description" style="margin:0 0 6px;color:#646970">
				<?php
				printf(
					/* translators: 1: link to the author's site, 2: link to the plugin repository */
					esc_html__( 'Gistful for GitHub by %1$s, source on %2$s.', 'gistful' ),
					'<a href="' . esc_url( self::AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Gioxx</a>',
					'<a href="' . esc_url( self::GITHUB_URL ) . '" target="_blank" rel="noopener noreferrer">GitHub</a>'
				);
				?>
			</p>
			<p class="description gistful-trademark-notice" style="margin:0;color:#646970;font-size:11px;line-height:1.5">
				<?php esc_html_e( 'All trademarks mentioned are the property of their respective owners. Third-party trademarks, product names, trade names, corporate names and companies mentioned may be trademarks of their respective owners or registered trademarks of other companies and have been used for explanatory purposes only and for the benefit of the owner, without any intent to infringe existing copyright.', 'gistful' ); ?>
			</p>
		</div>
		<?php
	}
}
