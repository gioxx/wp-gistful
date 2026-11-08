<?php
/**
 * Plugin Name:       Gistful for GitHub
 * Description:       Embed GitHub Gists in WordPress with a block, a shortcode and oEmbed. Based on GistPress.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Gioxx
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gistful
 *
 * Based on GistPress by Brady Vercher and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

define( 'GISTFUL_VERSION', '0.1.0' );
define( 'GISTFUL_FILE', __FILE__ );
define( 'GISTFUL_DIR', plugin_dir_path( __FILE__ ) );
define( 'GISTFUL_URL', plugin_dir_url( __FILE__ ) );

require_once GISTFUL_DIR . 'includes/class-autoloader.php';

Autoloader::register( GISTFUL_DIR . 'includes/' );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
