<?php
/**
 * Settings page (Settings > Gistful).
 *
 * @package Gistful
 */

namespace Gistful\Admin;

use Gistful\Cache;
use Gistful\Conflicts;
use Gistful\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API page, clear-cache action and status box.
 */
final class Settings_Page {

	const SLUG   = 'gistful';
	const ACTION = 'gistful_clear_cache';

	/**
	 * Hook everything. Cache invalidation hooks are registered on every request,
	 * so settings changed by WP-CLI or REST also flush the cache.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'update_option_' . Settings_Store::OPTION, array( self::class, 'flush_cache' ) );
		add_action( 'add_option_' . Settings_Store::OPTION, array( self::class, 'flush_cache' ) );
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_clear_cache' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Drop cached HTML: the scheme and display defaults are baked into it.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		Cache::flush_all();
	}

	/**
	 * Add Settings > Gistful.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'Gistful for GitHub', 'gistful' ),
			__( 'Gistful', 'gistful' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Register the option.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'gistful',
			Settings_Store::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Settings_Store::defaults(),
			)
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Settings_Store::defaults();
		$scheme   = isset( $input['scheme'] ) ? (string) $input['scheme'] : '';
		$hours    = $input['cache_hours'] ?? $defaults['cache_hours'];

		return array(
			'scheme'            => in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ? $scheme : $defaults['scheme'],
			'load_css'          => ! empty( $input['load_css'] ),
			'show_line_numbers' => ! empty( $input['show_line_numbers'] ),
			'show_meta'         => ! empty( $input['show_meta'] ),
			'copy_button'       => ! empty( $input['copy_button'] ),
			'cache_hours'       => is_numeric( $hours ) ? max( 1, min( 168, (int) $hours ) ) : $defaults['cache_hours'],
		);
	}

	/**
	 * Handle the clear-cache form post.
	 *
	 * @return void
	 */
	public static function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gistful' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		Cache::flush_all();

		$url = add_query_arg( 'gistful_notice', 'cache_cleared', admin_url( 'options-general.php?page=' . self::SLUG ) );
		// wp_safe_redirect() returns false when a wp_redirect filter cancels it (tests).
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Admin notice after clearing the cache.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$notice = isset( $_GET['gistful_notice'] ) ? sanitize_key( wp_unslash( $_GET['gistful_notice'] ) ) : '';
		if ( 'cache_cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gistful cache cleared.', 'gistful' ) . '</p></div>';
		}
	}

	/**
	 * Status rows.
	 *
	 * @return array<string, array{label: string, value: string}>
	 */
	public static function status_rows(): array {
		$rows = array(
			'token'     => array(
				'label' => __( 'GitHub token configured', 'gistful' ),
				'value' => self::yes_no( defined( 'GISTFUL_GITHUB_TOKEN' ) && '' !== (string) GISTFUL_GITHUB_TOKEN ),
			),
			'legacy'    => array(
				'label' => __( 'Legacy GistPress data found', 'gistful' ),
				'value' => self::yes_no( self::has_legacy_data() ),
			),
			'conflicts' => Conflicts::status(),
		);

		return $rows;
	}

	/**
	 * Cheap check for GistPress data.
	 *
	 * @return bool
	 */
	private static function has_legacy_data(): bool {
		global $wpdb;

		if ( false !== get_option( 'gistpress_stylesheet' ) ) {
			return true;
		}
		$like = $wpdb->esc_like( '_gist_raw_' ) . '%';
		$hit  = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return null !== $hit;
	}

	/**
	 * Localized Yes/No.
	 *
	 * @param bool $flag Value.
	 * @return string
	 */
	private static function yes_no( bool $flag ): string {
		return $flag ? __( 'Yes', 'gistful' ) : __( 'No', 'gistful' );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Settings_Store::all();
		$name = Settings_Store::OPTION;
		$tick = static function ( string $key ) use ( $s, $name ): void {
			printf(
				'<input type="hidden" name="%1$s[%2$s]" value="0"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
				esc_attr( $name ),
				esc_attr( $key ),
				checked( $s[ $key ], true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		};
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Gistful for GitHub', 'gistful' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gistful' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gistful-scheme"><?php echo esc_html__( 'Color scheme', 'gistful' ); ?></label></th>
						<td>
							<select id="gistful-scheme" name="<?php echo esc_attr( $name ); ?>[scheme]">
								<option value="auto" <?php selected( $s['scheme'], 'auto' ); ?>><?php echo esc_html__( 'Automatic', 'gistful' ); ?></option>
								<option value="light" <?php selected( $s['scheme'], 'light' ); ?>><?php echo esc_html__( 'Always light', 'gistful' ); ?></option>
								<option value="dark" <?php selected( $s['scheme'], 'dark' ); ?>><?php echo esc_html__( 'Always dark', 'gistful' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Load the plugin CSS', 'gistful' ); ?></th>
						<td><label><?php $tick( 'load_css' ); ?> <?php echo esc_html__( 'Load the Gistful stylesheet on pages that contain a gist', 'gistful' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Line numbers', 'gistful' ); ?></th>
						<td><label><?php $tick( 'show_line_numbers' ); ?> <?php echo esc_html__( 'Show line numbers by default', 'gistful' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Meta bar', 'gistful' ); ?></th>
						<td><label><?php $tick( 'show_meta' ); ?> <?php echo esc_html__( 'Show the file name and "view raw" bar by default', 'gistful' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Copy button', 'gistful' ); ?></th>
						<td>
							<label><?php $tick( 'copy_button' ); ?> <?php echo esc_html__( 'Show a "Copy" button on each file, and "Copy all" on gists with more than one file', 'gistful' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Adds a small local script to pages that show a gist.', 'gistful' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gistful-cache-hours"><?php echo esc_html__( 'Cache duration (hours)', 'gistful' ); ?></label></th>
						<td><input id="gistful-cache-hours" type="number" min="1" max="168" step="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[cache_hours]" value="<?php echo esc_attr( (string) $s['cache_hours'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Cache', 'gistful' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Clear cache', 'gistful' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Status', 'gistful' ); ?></h2>
			<table class="widefat striped gistful-status">
				<tbody>
				<?php foreach ( self::status_rows() as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( $row['value'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Footer::render(); ?>
		</div>
		<?php
	}
}
