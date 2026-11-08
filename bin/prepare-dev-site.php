<?php
/**
 * Prepares .dev/site: wp-content with SQLite drop-in, plugin symlink, wp-config and router.
 *
 * @package Gistful
 */

require_once __DIR__ . '/lib/sqlite-env.php';

$site = $argv[1];
$port = $argv[2] ?? '8888';
$root = dirname( __DIR__ );

gistful_prepare_sqlite_env( $site );

$plugins = $site . '/wp-content/plugins';
$link    = $plugins . '/gistful';
if ( ! is_link( $link ) ) {
	symlink( $root, $link );
}

$config = <<<PHP
<?php
defined( 'ABSPATH' ) || define( 'ABSPATH', '{$root}/vendor/johnpbloch/wordpress-core/' );
define( 'WP_CONTENT_DIR', '{$site}/wp-content' );
define( 'WP_PLUGIN_DIR', '{$site}/wp-content/plugins' );
define( 'WP_CONTENT_URL', 'http://localhost:{$port}/wp-content' );
define( 'DB_DIR', '{$site}/db/' );
define( 'DB_FILE', 'dev.sqlite' );
define( 'DB_NAME', 'gistful_dev' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'WP_HOME', 'http://localhost:{$port}' );
define( 'WP_SITEURL', 'http://localhost:{$port}' );
\$table_prefix = 'wp_';
require_once ABSPATH . 'wp-settings.php';
PHP;

// wp-config.php one level above ABSPATH is found by WordPress and WP-CLI.
file_put_contents( $root . '/vendor/johnpbloch/wp-config.php', $config );

// Router: serve /wp-content from the site dir, everything else through WordPress.
$router = <<<PHP
<?php
\$path = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( 0 === strpos( \$path, '/wp-content/' ) ) {
	\$file = '{$site}' . \$path;
	if ( is_file( \$file ) && 'php' !== pathinfo( \$file, PATHINFO_EXTENSION ) ) {
		\$types = array( 'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'json' => 'application/json' );
		\$ext   = pathinfo( \$file, PATHINFO_EXTENSION );
		header( 'Content-Type: ' . ( \$types[ \$ext ] ?? mime_content_type( \$file ) ) );
		readfile( \$file );
		return true;
	}
}
\$core = '{$root}/vendor/johnpbloch/wordpress-core';
\$file = \$core . \$path;
if ( is_file( \$file ) && 'php' !== pathinfo( \$file, PATHINFO_EXTENSION ) ) {
	return false;
}
if ( is_dir( \$file ) ) {
	\$file = rtrim( \$file, '/' ) . '/index.php';
}
if ( ! is_file( \$file ) || 'php' !== pathinfo( \$file, PATHINFO_EXTENSION ) ) {
	\$file = \$core . '/index.php';
}
chdir( dirname( \$file ) );
\$_SERVER['SCRIPT_FILENAME'] = \$file;
\$_SERVER['SCRIPT_NAME']     = substr( \$file, strlen( \$core ) );
require \$file;
PHP;
file_put_contents( $site . '/router.php', $router );
