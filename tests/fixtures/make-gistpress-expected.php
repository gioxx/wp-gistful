<?php
/**
 * Generator for gistpress-expected-single-lines-2-4-hl-3.html.
 *
 * Runs GistPress 3.0.3's process_gist_html() (GPL-2.0-or-later, by Brady Vercher
 * and Gary Jones) on Sanitizer::clean() of json-single.json's div, with
 * lines="2-4" highlight="3". Dev-only: tests/ is excluded from the package.
 *
 * Usage: php tests/fixtures/make-gistpress-expected.php /path/to/gistpress/includes/class-gistpress.php
 *
 * @package Gistful
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$root     = dirname( __DIR__, 2 );
$gp_class = $argv[1] ?? '';
if ( ! is_file( $gp_class ) ) {
	fwrite( STDERR, "Pass the path to GistPress 3.0.3 includes/class-gistpress.php\n" );
	exit( 1 );
}

define( 'ABSPATH', $root . '/vendor/johnpbloch/wordpress-core/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'GISTFUL_DIR', $root . '/' );

// Minimal WordPress: hooks, formatting, kses.
$GLOBALS['wp_filter']  = array();
$GLOBALS['wp_actions'] = array();
$GLOBALS['wp_current_filter'] = array();
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/l10n.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $gistful_file ) {
	require ABSPATH . WPINC . '/html-api/' . $gistful_file . '.php';
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub (core defines it in load.php, which we do not load).
	 *
	 * @param mixed $n Value.
	 * @return int
	 */
	function absint( $n ) {
		return abs( (int) $n );
	}
}

require $root . '/includes/class-sanitizer.php';
require $gp_class;

$json  = json_decode( (string) file_get_contents( __DIR__ . '/json-single.json' ) );
$clean = Gistful\Sanitizer::clean( $json->div );

$args = array(
	'lines'             => array(
		'min' => 2,
		'max' => 4,
	),
	'lines_start'       => 0,
	'highlight'         => array( 3 ),
	'highlight_color'   => '#ffc',
	'show_line_numbers' => true,
	'show_meta'         => true,
);

$out = ( new GistPress() )->process_gist_html( $clean, $args );
file_put_contents( __DIR__ . '/gistpress-expected-single-lines-2-4-hl-3.html', $out );
echo 'Wrote ' . strlen( $out ) . " bytes\n";
