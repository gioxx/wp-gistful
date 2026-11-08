<?php
/**
 * Gist URL parsing and file resolution.
 *
 * The anchor and file-name resolution logic is based on GistPress 3.0.3
 * (get_file_name) by Brady Vercher and Gary Jones.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts gist IDs and file anchors from gist.github.com URLs.
 */
final class Gist_Url {

	/**
	 * Regex used with wp_embed_register_handler(). Captures: 1 = gist ID,
	 * 2 = anchor delimiter ('_' legacy, '-' bookmark), 3 = file anchor.
	 */
	const PATTERN = '#https?://(?:www\.)?gist\.github\.com/(?:[\w.-]+/)?([a-f0-9]+|\d+)/?(?:\?[^\#\s]*)?(?:\#file([_-])([^\s]*))?#i';

	/**
	 * Parse a gist URL.
	 *
	 * @param string $url URL to parse.
	 * @return array{id: string, anchor: string, delimiter: string}|null Null if the URL is not a gist URL.
	 */
	public static function parse( string $url ): ?array {
		if ( ! preg_match( '#^https?://(www\.)?gist\.github\.com/#i', $url ) ) {
			return null;
		}

		if ( ! preg_match( self::PATTERN, $url, $matches ) ) {
			return null;
		}

		return array(
			'id'        => $matches[1],
			'anchor'    => $matches[3] ?? '',
			'delimiter' => $matches[2] ?? '',
		);
	}

	/**
	 * Resolve a file anchor to the actual file name in a gist.
	 *
	 * Legacy '_' anchors already hold the real name. For '-' bookmarks, the
	 * dots of the original name were replaced with dashes, so the first file
	 * whose dotted-to-dashed name matches the anchor is returned.
	 *
	 * @param string   $anchor     File anchor from the URL.
	 * @param string   $delimiter  Anchor delimiter ('_', '-' or '').
	 * @param string[] $file_names Names of the files in the gist.
	 * @return string File name, or '' if none matches.
	 */
	public static function resolve_file( string $anchor, string $delimiter, array $file_names ): string {
		if ( '_' === $delimiter ) {
			return $anchor;
		}

		if ( '-' !== $delimiter ) {
			return '';
		}

		foreach ( $file_names as $name ) {
			if ( 0 === strcasecmp( str_replace( '.', '-', $name ), $anchor ) ) {
				return $name;
			}
		}

		return '';
	}
}
