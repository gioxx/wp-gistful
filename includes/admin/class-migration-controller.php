<?php
/**
 * REST routes of the GistPress migration (gistful/v1/migration/*).
 *
 * @package Gistful
 */

namespace Gistful\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Thin REST layer over Migrator, with resumable progress state.
 */
final class Migration_Controller {

	const NAMESPACE_ = 'gistful/v1';
	const STATE      = 'gistful_migration_state';

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$batch = array(
			'offset' => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 0,
			),
			'limit'  => array(
				'type'              => 'integer',
				'default'           => 50,
				'sanitize_callback' => static fn( $v ): int => max( 1, min( 200, absint( $v ) ) ),
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
				'maximum'           => 200,
			),
		);
		$id    = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
		$clean = $batch + array(
			'force' => array(
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
		$conv  = $id + array(
			'hash' => array(
				'type'              => 'string',
				'required'          => true,
				'pattern'           => '^[a-f0-9]{32}$',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		$routes = array(
			'/migration/scan'                => array( 'GET', 'scan', array() ),
			'/migration/import'              => array( 'POST', 'import', $batch ),
			'/migration/cleanup'             => array( 'POST', 'cleanup', $batch ),
			'/migration/preview/(?P<id>\d+)' => array( 'GET', 'preview', $id ),
			'/migration/convert/(?P<id>\d+)' => array( 'POST', 'convert', $conv ),
		);
		foreach ( $routes as $route => list( $method, $callback, $args ) ) {
			register_rest_route(
				self::NAMESPACE_,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Permission check shared by every route.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Save progress without autoloading it.
	 *
	 * @param string $step Step name.
	 * @param array  $data Step data.
	 * @return void
	 */
	private static function save_state( string $step, array $data ): void {
		$state          = self::state();
		$state[ $step ] = $data + array( 'updated' => time() );
		update_option( self::STATE, $state, false );
	}

	/**
	 * Stored progress.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * GET scan (read-only).
	 *
	 * @return \WP_REST_Response
	 */
	public static function scan(): \WP_REST_Response {
		$scan                = Migrator::scan();
		$scan['state']       = self::state();
		$scan['convertible'] = Migrator::convertible_ids();

		return new \WP_REST_Response( $scan );
	}

	/**
	 * POST import: one batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Migrator::import_backups( (int) $request['offset'], (int) $request['limit'] );
		self::save_state( 'import', array( 'next' => $result['next'] ) );

		return new \WP_REST_Response( $result );
	}

	/**
	 * POST cleanup: one batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cleanup( \WP_REST_Request $request ) {
		$result = Migrator::cleanup( (int) $request['offset'], (int) $request['limit'], rest_sanitize_boolean( $request['force'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::save_state( 'cleanup', array( 'next' => $result['next'] ) );

		return new \WP_REST_Response( $result );
	}

	/**
	 * Post lookup plus per-post capability.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Error|null
	 */
	private static function check_post( int $post_id ): ?\WP_Error {
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'gistful_not_found', __( 'Post not found.', 'gistful' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'gistful_forbidden', __( 'You cannot edit this post.', 'gistful' ), array( 'status' => 403 ) );
		}

		return null;
	}

	/**
	 * GET preview of one conversion (read-only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$error   = self::check_post( $post_id );
		if ( $error ) {
			return $error;
		}

		return new \WP_REST_Response( Migrator::preview_conversion( $post_id ) );
	}

	/**
	 * POST convert one post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function convert( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$error   = self::check_post( $post_id );
		if ( $error ) {
			return $error;
		}

		$result = Migrator::convert( $post_id, (string) $request['hash'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'converted' => $result ) );
	}
}
