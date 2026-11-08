<?php
/**
 * Value object for a fetched gist.
 *
 * @package Gistful
 */

namespace Gistful;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of Source::fetch().
 */
final class Gist_Payload {

	/**
	 * Constructor.
	 *
	 * @param string   $html      Raw gist `div` markup from the primary endpoint, or ''.
	 * @param string[] $files     File names in the gist.
	 * @param string   $source    'json', 'api' or 'backup'.
	 * @param array    $raw_files Map of file name => content (API only).
	 * @param bool     $truncated Whether the API truncated any file content.
	 */
	public function __construct(
		public readonly string $html = '',
		public readonly array $files = array(),
		public readonly string $source = 'json',
		public readonly array $raw_files = array(),
		public readonly bool $truncated = false
	) {}

	/**
	 * Whether the payload carries nothing renderable.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->html && array() === $this->raw_files;
	}

	/**
	 * Whether the code comes without syntax highlighting: an API fallback
	 * payload, or gist markup that GitHub occasionally serves unhighlighted.
	 * Such a payload is cached briefly and never replaces a highlighted backup.
	 *
	 * @return bool
	 */
	public function is_degraded(): bool {
		return '' === $this->html || self::lacks_highlighting( $this->html );
	}

	/**
	 * Whether gist markup has code cells of a programming language but not a
	 * single syntax token. Plain text and the files GitHub renders (CSV,
	 * Markdown, notebooks) never carry tokens, so they never count.
	 *
	 * @param string $html Gist markup.
	 * @return bool
	 */
	public static function lacks_highlighting( string $html ): bool {
		// Even a one-line snippet of a programming language gets tokens.
		if ( 1 === preg_match( '/class="[^"]*\bpl-[a-z0-9]/', $html ) ) {
			return false;
		}
		if ( '' === self::cells_text( $html ) ) {
			return false; // No code at all: nothing to highlight.
		}

		return 1 === preg_match( '/\btype-(?!(?:text|txt|plain|plaintext|csv|tsv|markdown|md|jupyter-notebook)\b)[a-z0-9]/', $html );
	}

	/**
	 * Fingerprint of the code itself, whatever its form (highlighted markup,
	 * unhighlighted markup or the API's raw files): the text with all
	 * whitespace removed. Two copies with the same signature show the same code.
	 *
	 * @return string
	 */
	public function code_signature(): string {
		$text = '' !== $this->html ? self::cells_text( $this->html ) : implode( '', $this->raw_files );

		return md5( (string) preg_replace( '/\s+/u', '', $text ) );
	}

	/**
	 * Text of the code cells of gist markup, tags stripped and entities decoded.
	 *
	 * @param string $html Gist markup.
	 * @return string
	 */
	private static function cells_text( string $html ): string {
		if ( ! preg_match_all( '#<td[^>]*class="[^"]*\bblob-code\b[^"]*"[^>]*>(.*?)</td>#s', $html, $m ) ) {
			return '';
		}

		return trim( html_entity_decode( wp_strip_all_tags( implode( "\n", $m[1] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
