<?php
/**
 * The embed pipeline shared by shortcode, oEmbed and block.
 *
 * The cache plus post-meta fallback flow is based on GistPress 3.0.3 by Brady
 * Vercher and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Normalize, cache, fetch, fall back, render.
 */
final class Embed {

	const STYLE_HANDLE = 'gistful';

	/**
	 * Register the stylesheet handle (idempotent).
	 *
	 * @return void
	 */
	public static function register_style(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, GISTFUL_URL . 'assets/css/gistful.css', array(), GISTFUL_VERSION );
		}
	}

	/**
	 * Render a gist.
	 *
	 * @param array<string, mixed> $raw_attr Raw attributes.
	 * @param int|null             $post_id  Containing post, when certain.
	 * @return string
	 */
	public static function render( array $raw_attr, ?int $post_id = null ): string {
		$attr = Attributes::normalize( $raw_attr );
		$id   = $attr['id'];
		if ( '' === $id ) {
			return '';
		}
		$file = $attr['file'];
		$url  = 'https://gist.github.com/' . $id;

		if ( is_feed() ) {
			$html = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View this code snippet on GitHub.', 'gistful' ) . '</a>';

			return (string) apply_filters( 'gistful_feed_html', $html, $url, $attr );
		}

		$html_key = Cache::html_key( $attr );
		$raw_key  = Cache::raw_key( $id, $file );
		$html     = Cache::get( $html_key );
		$is_link  = false;

		if ( is_string( $html ) && '' !== $html ) {
			self::log( "html cache hit $id" );
		} else {
			$html     = '';
			$ttl      = null;
			$payload  = self::raw_payload( $id, $file, $raw_key, $post_id );
			$fallback = false;

			if ( is_wp_error( $payload ) ) {
				self::log( "fetch failed $id: " . $payload->get_error_code() );
				$fallback = true;
				$payload  = $post_id ? Backup::get( $post_id, $id, $file ) : null;
				self::log( $payload ? "using backup $id" : "using link $id" );
			}

			if ( $payload ) {
				$html = Renderer::render( $payload, $attr );
			}
			if ( '' === $html ) {
				$html    = Renderer::link( $id );
				$is_link = true;
			} elseif ( $fallback ) {
				$ttl = Cache::NEGATIVE_TTL;
			}

			if ( ! $is_link ) {
				Cache::set( $html_key, $html, $ttl );
			}
		}

		if ( $post_id && self::post_is_certain( $post_id ) ) {
			Cache::track( $post_id, $html_key, $raw_key );
		}

		if ( ! $is_link && $attr['embed_stylesheet'] && Settings_Store::get( 'load_css' ) ) {
			self::register_style();
			wp_enqueue_style( self::STYLE_HANDLE );
		}

		return (string) apply_filters( 'gistful_html', $html, $url, $attr, $post_id );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$post_id = get_the_ID();

		return self::render( is_array( $atts ) ? $atts : array(), $post_id ? (int) $post_id : null );
	}

	/**
	 * Handle oEmbed for gist URLs.
	 *
	 * @param array<int, string>   $matches Regex matches (not trusted).
	 * @param array<string, mixed> $attr    Embed attributes.
	 * @param string               $url     Embedded URL.
	 * @param array<string, mixed> $rawattr Raw attributes.
	 * @return string
	 */
	public static function oembed_handler( array $matches, array $attr, string $url, array $rawattr ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $matches, $attr, $rawattr );

		$parsed = Gist_Url::parse( $url );
		if ( null === $parsed ) {
			return '';
		}

		$file = '';
		if ( '' !== $parsed['anchor'] ) {
			$names = '-' === $parsed['delimiter'] ? self::file_names( $parsed['id'] ) : array();
			$file  = Gist_Url::resolve_file( $parsed['anchor'], $parsed['delimiter'], $names );
		}
		$post_id = get_the_ID();

		return self::render(
			array(
				'id'     => $parsed['id'],
				'file'   => $file,
				'oembed' => true,
			),
			$post_id ? (int) $post_id : null
		);
	}

	/**
	 * File names of a gist, cached (7 days, 15 minutes when empty).
	 *
	 * @param string $id Gist ID.
	 * @return string[]
	 */
	public static function file_names( string $id ): array {
		$key    = Cache::names_key( $id );
		$cached = Cache::get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$names = Source::fetch_file_names( $id );
		Cache::set( $key, $names, array() === $names ? 15 * MINUTE_IN_SECONDS : 7 * DAY_IN_SECONDS );

		return $names;
	}

	/**
	 * Payload from the raw cache or the network; stores the frontend backup.
	 *
	 * @param string   $id       Gist ID.
	 * @param string   $file     File name.
	 * @param string   $raw_key  Raw cache key.
	 * @param int|null $post_id  Post ID.
	 * @return Gist_Payload|\WP_Error
	 */
	private static function raw_payload( string $id, string $file, string $raw_key, ?int $post_id ) {
		$cached  = Cache::get( $raw_key );
		$payload = Backup::unpack( $cached, is_array( $cached ) && '' === ( $cached['html'] ?? '' ) ? 'api' : 'json' );
		if ( $payload ) {
			self::log( "raw cache hit $id" );
		} elseif ( Cache::is_negative( $raw_key ) ) {
			return new \WP_Error( 'gistful_negative', 'Recent fetch failed.' );
		} else {
			$payload = Source::fetch( $id, $file );
			if ( is_wp_error( $payload ) ) {
				Cache::set_negative( $raw_key );

				return $payload;
			}
			self::log( "fetched $id via {$payload->source}" );
			Cache::set( $raw_key, Backup::pack( $payload ) );
		}

		if ( $post_id && self::post_is_certain( $post_id ) ) {
			Backup::maybe_store( $post_id, $id, $file, $payload );
		}

		return $payload;
	}

	/**
	 * Whether the post ID is certainly the main content of the current request
	 * (never true in widgets or other secondary contexts).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function post_is_certain( int $post_id ): bool {
		return get_queried_object_id() === $post_id && in_the_loop() && is_main_query();
	}

	/**
	 * Debug log, only with WP_DEBUG and the gistful_debug filter.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'gistful_debug', false ) ) {
			error_log( 'Gistful: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
