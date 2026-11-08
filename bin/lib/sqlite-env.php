<?php
/**
 * Prepares a project-local wp-content directory for the SQLite drop-in.
 *
 * @package Gistful
 */

/**
 * Create `<dir>/wp-content` with the sqlite-database-integration plugin and its db.php drop-in.
 *
 * @param string $dir Base dir (e.g. .dev/test).
 * @return void
 */
function gistful_prepare_sqlite_env( string $dir ): void {
	$root    = dirname( __DIR__, 2 );
	$plugin  = $root . '/vendor/wordpress/plugins/sqlite-database-integration';
	$content = $dir . '/wp-content';

	if ( ! is_dir( $plugin ) ) {
		fwrite( STDERR, "sqlite-database-integration not found; run `composer install`.\n" );
		exit( 1 );
	}

	foreach ( array( $content . '/plugins', $content . '/themes', $content . '/uploads', $dir . '/db' ) as $path ) {
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}
	}

	// Stub theme so WP_DEFAULT_THEME resolves without bundled themes.
	$theme = $content . '/themes/default';
	if ( ! is_dir( $theme ) ) {
		mkdir( $theme, 0777, true );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Default\n*/\n" );
		file_put_contents( $theme . '/index.php', "<?php\n" );
	}

	$template = file_get_contents( $plugin . '/db.copy' );
	$dropin   = str_replace(
		array( '{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}' ),
		array( $plugin, 'sqlite-database-integration/load.php' ),
		$template
	);
	if ( ! file_exists( $content . '/db.php' ) || file_get_contents( $content . '/db.php' ) !== $dropin ) {
		file_put_contents( $content . '/db.php', $dropin );
	}
}
