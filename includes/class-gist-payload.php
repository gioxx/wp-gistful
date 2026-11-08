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
}
