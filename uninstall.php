<?php
/**
 * Removes all Gistful data on uninstall. GistPress data is never touched.
 *
 * Runs without the plugin bootstrap, so it is standalone.
 *
 * @package Gistful
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete Gistful options, transients and post meta of the current site.
 *
 * @return void
 */
function gistful_uninstall_site() {
	global $wpdb;

	foreach ( array( 'gistful_settings', 'gistful_migration_state', 'gistful_cache_gen' ) as $name ) {
		delete_option( $name );
	}

	// Transient values and their timeout rows ('gistful_' follows the transient prefix).
	foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . 'gistful_' ) . '%' )
		);
	}

	// Per-post backups and key lists: _gistful_backup_*, _gistful_keys.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_gistful_' ) . '%' )
	);

	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $gistful_blog_id ) {
		switch_to_blog( (int) $gistful_blog_id );
		gistful_uninstall_site();
		restore_current_blog();
	}
} else {
	gistful_uninstall_site();
}
