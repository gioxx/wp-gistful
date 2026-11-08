<?php
/**
 * `composer test` entry point: runs PHPUnit, forwarding extra args.
 *
 * Usage: composer test -- --filter test_name
 *
 * @package Gistful
 */

$root = dirname( __DIR__ );
$args = array_slice( $argv, 1 );
$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/vendor/bin/phpunit' ) . ' -c ' . escapeshellarg( $root . '/phpunit.xml.dist' );
foreach ( $args as $arg ) {
	$cmd .= ' ' . escapeshellarg( $arg );
}
passthru( $cmd, $code );
exit( $code );
