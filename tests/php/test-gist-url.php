<?php
/**
 * Gist URL parsing tests.
 *
 * @package Gistful
 */

use Gistful\Gist_Url;

/**
 * Gist URL parsing and file resolution.
 */
class Test_Gist_Url extends Gistful_Test_Case {

	public function provider_urls() {
		return array(
			array( 'https://gist.github.com/gioxx/0123abcd', '0123abcd', '' ),
			array( 'https://gist.github.com/0123abcd', '0123abcd', '' ),
			array( 'https://www.gist.github.com/gioxx/0123abcd/', '0123abcd', '' ),
			array( 'https://gist.github.com/gioxx/0123abcd?ts=4', '0123abcd', '' ),
			array( 'https://gist.github.com/gioxx/0123abcd#file-foo-bar-php', '0123abcd', 'foo-bar-php' ),
			array( 'https://gist.github.com/gioxx/0123abcd#file_foo.php', '0123abcd', 'foo.php' ),
			array( 'https://gist.github.com/1234567', '1234567', '' ),
		);
	}

	/**
	 * @dataProvider provider_urls
	 */
	public function test_parse( $url, $id, $anchor ) {
		$p = Gist_Url::parse( $url );
		$this->assertSame( $id, $p['id'] );
		$this->assertSame( $anchor, $p['anchor'] );
	}

	public function test_rejects_other_hosts() {
		$this->assertNull( Gist_Url::parse( 'https://gist.github.com.evil.tld/x/abc' ) );
		$this->assertNull( Gist_Url::parse( 'https://github.com/gioxx/repo' ) );
	}

	public function test_resolve_file() {
		$files = array( 'README.md', 'foo.bar.php', 'foo-bar.php' );
		$this->assertSame( 'foo.bar.php', Gist_Url::resolve_file( 'foo-bar-php', '-', $files ) );
		$this->assertSame( 'whatever.php', Gist_Url::resolve_file( 'whatever.php', '_', $files ) );
		$this->assertSame( '', Gist_Url::resolve_file( 'nope', '-', $files ) );
	}
}
