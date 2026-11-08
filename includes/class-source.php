<?php
/**
 * Gist HTTP source: gist.github.com .json endpoint with api.github.com fallback.
 *
 * Request handling is based on the approach of GistPress 3.0.3 by Brady Vercher
 * and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches gists over HTTP. No caching here.
 */
final class Source {

	const MAX_BYTES = 2097152;

	/**
	 * Fetch a gist, trying the primary endpoint first and the API second.
	 *
	 * @param string $id   Gist ID.
	 * @param string $file Optional file name to restrict to.
	 * @return Gist_Payload|\WP_Error
	 */
	public static function fetch( string $id, string $file = '' ) {
		$outcomes = array();

		$url     = 'https://gist.github.com/' . rawurlencode( $id ) . '.json';
		$url    .= '' !== $file ? '?file=' . rawurlencode( $file ) : '';
		$primary = self::request( $url, array() );
		$data    = self::decode( $primary );

		if ( is_array( $data ) && isset( $data['div'], $data['files'] ) && is_string( $data['div'] ) && '' !== $data['div'] && is_array( $data['files'] ) ) {
			return new Gist_Payload( $data['div'], self::names( $data['files'] ), 'json' );
		}
		$outcomes[] = self::classify( $primary, $data );

		$api     = self::request( 'https://api.github.com/gists/' . rawurlencode( $id ), self::api_headers() );
		$data    = self::decode( $api );
		$payload = self::api_payload( $data, $file );

		if ( $payload ) {
			return $payload;
		}
		$outcome = self::classify( $api, $data );
		if ( 'ok' === $outcome ) {
			// 200 and valid JSON, but the requested file is not in the gist.
			$outcome = 'file_missing';
		}
		$outcomes[] = $outcome;

		if ( in_array( 'not_found', $outcomes, true ) || in_array( 'file_missing', $outcomes, true ) ) {
			return new \WP_Error( 'gistful_not_found', __( 'Gist not found.', 'gistful' ) );
		}
		if ( in_array( 'bad_body', $outcomes, true ) ) {
			return new \WP_Error( 'gistful_bad_body', __( 'GitHub returned an unexpected response.', 'gistful' ) );
		}

		return new \WP_Error( 'gistful_http', __( 'Could not reach GitHub.', 'gistful' ) );
	}

	/**
	 * File names of a gist; empty array on failure.
	 *
	 * @param string $id Gist ID.
	 * @return string[]
	 */
	public static function fetch_file_names( string $id ): array {
		$data = self::decode( self::request( 'https://gist.github.com/' . rawurlencode( $id ) . '.json', array() ) );
		if ( is_array( $data ) && isset( $data['files'] ) && is_array( $data['files'] ) && array() !== $data['files'] ) {
			return self::names( $data['files'] );
		}

		$data = self::decode( self::request( 'https://api.github.com/gists/' . rawurlencode( $id ), self::api_headers() ) );
		if ( is_array( $data ) && isset( $data['files'] ) && is_array( $data['files'] ) ) {
			return array_map( 'strval', array_keys( $data['files'] ) );
		}

		return array();
	}

	/**
	 * Token from the GISTFUL_GITHUB_TOKEN constant, if usable.
	 *
	 * @return string
	 */
	private static function token(): string {
		if ( defined( 'GISTFUL_GITHUB_TOKEN' ) && is_string( GISTFUL_GITHUB_TOKEN ) && '' !== GISTFUL_GITHUB_TOKEN ) {
			return GISTFUL_GITHUB_TOKEN;
		}

		return '';
	}

	/**
	 * Headers for api.github.com.
	 *
	 * @return array<string,string>
	 */
	private static function api_headers(): array {
		$headers = array( 'Accept' => 'application/vnd.github+json' );
		$token   = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Perform a safe GET.
	 *
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Extra headers.
	 * @return array|\WP_Error
	 */
	private static function request( string $url, array $headers ) {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Gistful/' . GISTFUL_VERSION . '; ' . home_url(),
				'headers'             => $headers,
			)
		);
	}

	/**
	 * Decode a 200 response body into an array, or null.
	 *
	 * @param array|\WP_Error $response Response.
	 * @return array|null
	 */
	private static function decode( $response ): ?array {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return null;
		}
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Classify a failed attempt.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param array|null      $data     Decoded body.
	 * @return string 'http', 'not_found', 'bad_body' or 'ok' (valid JSON, unusable only for lack of the requested file).
	 */
	private static function classify( $response, ?array $data ): string {
		if ( is_wp_error( $response ) ) {
			return 'http';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return 'not_found';
		}
		if ( 200 !== $code ) {
			return 'http';
		}

		return null === $data || ! isset( $data['files'] ) ? 'bad_body' : 'ok';
	}

	/**
	 * Build a payload from an API response, or null if unusable.
	 *
	 * @param array|null $data Decoded body.
	 * @param string     $file Optional file filter.
	 * @return Gist_Payload|null
	 */
	private static function api_payload( ?array $data, string $file ): ?Gist_Payload {
		if ( null === $data || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return null;
		}

		$raw       = array();
		$truncated = false;
		foreach ( $data['files'] as $name => $info ) {
			if ( ! is_array( $info ) || ( '' !== $file && (string) $name !== $file ) ) {
				continue;
			}
			$raw[ (string) $name ] = isset( $info['content'] ) && is_string( $info['content'] ) ? $info['content'] : '';
			$truncated             = $truncated || ! empty( $info['truncated'] );
		}

		if ( array() === $raw ) {
			return null;
		}

		return new Gist_Payload( '', array_map( 'strval', array_keys( $raw ) ), 'api', $raw, $truncated );
	}

	/**
	 * Normalise a primary `files` list to file-name strings.
	 *
	 * @param array $files Decoded `files` value.
	 * @return string[]
	 */
	private static function names( array $files ): array {
		return array_values( array_filter( $files, 'is_string' ) );
	}
}
