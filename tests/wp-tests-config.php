<?php
/**
 * WordPress test-suite configuration (SQLite, project-local).
 *
 * @package Gistful
 */

$gistful_root = dirname( __DIR__ );
$gistful_dev  = $gistful_root . '/.dev/test';

define( 'ABSPATH', $gistful_root . '/vendor/johnpbloch/wordpress-core/' );

// Own wp-content so the SQLite db.php drop-in lives outside vendor.
define( 'WP_CONTENT_DIR', $gistful_dev . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_DEFAULT_THEME', 'default' );

// SQLite integration: database file location.
define( 'DB_DIR', $gistful_dev . '/db/' );
define( 'DB_FILE', 'gistful-tests.sqlite' );

// Unused by SQLite but required by the WP test bootstrap.
define( 'DB_NAME', 'gistful_tests' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Gistful Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
