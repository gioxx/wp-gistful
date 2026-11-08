<?php
/**
 * WP-CLI commands.
 *
 * @package Gistful
 */

namespace Gistful\Cli;

use Gistful\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Gistful from the command line.
 */
class Command {

	/**
	 * Clear the Gistful cache.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Clear only the entries tracked for this post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gistful cache clear
	 *     wp gistful cache clear --post=42
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['post'] ) ) {
			Cache::flush_all();
			$this->success( 'Gistful cache cleared.' );
			return;
		}

		$raw = (string) $assoc_args['post'];
		$id  = ctype_digit( $raw ) ? (int) $raw : 0;
		if ( $id < 1 || ! get_post( $id ) ) {
			$this->fail( 'Invalid post: ' . $raw . ' is not an existing post ID.' );
			return;
		}

		Cache::flush_post( $id );
		$this->success( "Gistful cache cleared for post $id." );
	}

	/**
	 * Report success.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function success( string $message ): void {
		\WP_CLI::success( $message );
	}

	/**
	 * Report a fatal error (WP-CLI exits).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function fail( string $message ): void {
		\WP_CLI::error( $message );
	}
}
