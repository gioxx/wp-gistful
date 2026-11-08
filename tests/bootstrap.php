<?php
/**
 * PHPUnit bootstrap: WP test suite on SQLite, loading the plugin from the repo.
 *
 * @package Gistful
 */

$gistful_root = dirname( __DIR__ );

// Prepare .dev/test (db.php drop-in etc.) before WordPress loads.
require_once $gistful_root . '/bin/lib/sqlite-env.php';
gistful_prepare_sqlite_env( $gistful_root . '/.dev/test' );

putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$_tests_dir = $gistful_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $gistful_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $gistful_root ) {
		require $gistful_root . '/gistful.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-gistful-test-case.php';
require_once __DIR__ . '/trait-http-mock.php';
