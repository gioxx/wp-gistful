<?php
/**
 * Tools > Gistful Migration page.
 *
 * @package Gistful
 */

namespace Gistful\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Hosts the (vanilla JS) migration UI.
 */
final class Migration_Page {

	const SLUG = 'gistful-migration';

	/**
	 * Hook the menu and the asset loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Add Tools > Gistful Migration.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_management_page(
			__( 'Gistful Migration', 'gistful' ),
			__( 'Gistful Migration', 'gistful' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Load the script on this page only.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'gistful-migration', GISTFUL_URL . 'assets/js/migration.js', array( 'wp-api-fetch' ), GISTFUL_VERSION, true );
		wp_localize_script(
			'gistful-migration',
			'gistfulMigration',
			array(
				'base' => 'gistful/v1/migration',
				'i18n' => self::strings(),
			)
		);
	}

	/**
	 * UI strings (the text domain needs literals, hence one list here).
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'scanning'       => __( 'Scanning...', 'gistful' ),
			/* translators: 1: posts, 2: backups, 3: transients, 4: convertible posts, 5: yes/no */
			'scanResult'     => __( 'Found: %1$d posts with gists, %2$d legacy backups, %3$d legacy transients, %4$d convertible block posts. Legacy stylesheet option: %5$s.', 'gistful' ),
			'yes'            => __( 'yes', 'gistful' ),
			'no'             => __( 'no', 'gistful' ),
			/* translators: %d: number of legacy backups */
			'importAsk'      => __( 'This will copy %d legacy backups into Gistful backups. Your posts are not modified.', 'gistful' ),
			/* translators: 1: number of backup rows, 2: number of transients */
			'cleanupAsk'     => __( 'This will permanently delete GistPress data: %1$d backup rows, %2$d transients and its stylesheet option. Import the backups first if you want to keep them. Deactivate GistPress before this step.', 'gistful' ),
			'importPending'  => __( 'Import the backups first (step 2). Cleaning up now would delete GistPress last-good copies that were not imported.', 'gistful' ),
			'cleanupAnyway'  => __( 'Clean up without importing', 'gistful' ),
			'confirm'        => __( 'Confirm', 'gistful' ),
			'cancel'         => __( 'Cancel', 'gistful' ),
			'stop'           => __( 'Stop', 'gistful' ),
			'resume'         => __( 'Resume', 'gistful' ),
			'stopped'        => __( 'Stopped. You can resume from where it stopped.', 'gistful' ),
			/* translators: %d: rows processed so far */
			'progress'       => __( 'Processed %d rows...', 'gistful' ),
			'done'           => __( 'Done.', 'gistful' ),
			/* translators: %s: error message */
			'error'          => __( 'Error: %s', 'gistful' ),
			'nothing'        => __( 'Nothing to do.', 'gistful' ),
			'preview'        => __( 'Preview', 'gistful' ),
			'before'         => __( 'Before', 'gistful' ),
			'after'          => __( 'After', 'gistful' ),
			'convertConfirm' => __( 'Confirm conversion (a revision is saved first)', 'gistful' ),
			'converted'      => __( 'Converted.', 'gistful' ),
			'unchanged'      => __( 'Nothing changed.', 'gistful' ),
			/* translators: %d: post ID */
			'postLabel'      => __( 'Post #%d', 'gistful' ),
		);
	}

	/**
	 * Render the page shell.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" id="gistful-migration">
			<h1><?php echo esc_html__( 'Gistful Migration', 'gistful' ); ?></h1>
			<p><?php echo esc_html__( 'Move from GistPress to Gistful. Nothing is changed until you review the numbers or the preview and confirm.', 'gistful' ); ?></p>

			<h2><?php echo esc_html__( '1. Scan', 'gistful' ); ?></h2>
			<p><button type="button" class="button" id="gistful-scan"><?php echo esc_html__( 'Scan the site', 'gistful' ); ?></button></p>
			<p id="gistful-scan-result" aria-live="polite"></p>

			<h2><?php echo esc_html__( '2. Import backups', 'gistful' ); ?></h2>
			<p><?php echo esc_html__( 'Copies GistPress last-good copies into Gistful backups.', 'gistful' ); ?></p>
			<div id="gistful-import" class="gistful-step"></div>

			<h2><?php echo esc_html__( '3. Clean up GistPress data', 'gistful' ); ?></h2>
			<p><?php echo esc_html__( 'Requires GistPress to be deactivated.', 'gistful' ); ?></p>
			<div id="gistful-cleanup" class="gistful-step"></div>

			<h2><?php echo esc_html__( '4. Convert to blocks', 'gistful' ); ?></h2>
			<p><?php echo esc_html__( 'Block-editor posts only. Gist embeds and [gist] shortcode blocks become Gistful blocks, one post at a time, after a preview.', 'gistful' ); ?></p>
			<div id="gistful-convert"></div>
			<?php Footer::render(); ?>
		</div>
		<?php
	}
}
