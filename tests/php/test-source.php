<?php
/**
 * Source (HTTP) tests.
 *
 * @package Gistful
 */

use Gistful\Source;

/**
 * Primary endpoint plus API fallback.
 */
class Test_Source extends Gistful_Test_Case {

	/**
	 * Recorded requests: list of array{url: string, args: array}.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Mocked responses by host.
	 *
	 * @var array
	 */
	private $routes = array();

	public function set_up() {
		parent::set_up();
		$this->requests = array();
		$this->routes   = array();
		add_filter( 'pre_http_request', array( $this, 'route' ), 10, 3 );
	}

	/**
	 * Route requests by host and record them.
	 *
	 * @param mixed  $pre  Preempt.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function route( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return $this->routes[ $host ] ?? new WP_Error( 'unmocked', 'No mock for ' . $host );
	}

	/**
	 * Mock a host's response ($body may be a WP_Error).
	 *
	 * @param string          $host Host.
	 * @param int|WP_Error    $code Status code or error.
	 * @param string          $body Body.
	 */
	private function mock( string $host, $code, string $body = '' ) {
		$this->routes[ $host ] = is_wp_error( $code ) ? $code : array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( GISTFUL_DIR . 'tests/fixtures/' . $name );
	}

	private function calls_to( string $host ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $host ) {
					return wp_parse_url( $r['url'], PHP_URL_HOST ) === $host;
				}
			)
		);
	}

	public function test_primary_request_is_safe() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
		$p = Source::fetch( 'abc123', 'foo.php' );
		$this->assertCount( 1, $this->requests );
		$args = $this->requests[0]['args'];
		$this->assertSame( 'https://gist.github.com/abc123.json?file=foo.php', $this->requests[0]['url'] );
		$this->assertNotFalse( $args['sslverify'] );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 2097152, $args['limit_response_size'] );
		$this->assertStringStartsWith( 'Gistful/', $args['user-agent'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 'json', $p->source );
		$this->assertSame( array( 'swaksWrapper.sh' ), $p->files );
		$this->assertStringContainsString( 'gist-file', $p->html );
		$this->assertFalse( $p->truncated );
		$this->assertFalse( $p->is_empty() );
	}

	public function test_multi_file_names() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-multi.json' ) );
		$p = Source::fetch( 'abc123' );
		$this->assertSame( array( 'proxmox_shutdown.sh', 'proxmox_start.sh' ), $p->files );
	}

	public function test_unexpected_200_body_falls_back_to_api() {
		$this->mock( 'gist.github.com', 200, '<html>Oops</html>' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$p = Source::fetch( 'abc123' );
		$this->assertSame( 'api', $p->source );
		$this->assertNotEmpty( $p->raw_files );
		$this->assertSame( array( 'swaksWrapper.sh' ), $p->files );
		$this->assertSame( '', $p->html );
		$this->assertFalse( $p->truncated );
	}

	public function provider_bad_primary_bodies() {
		return array(
			'json scalar'  => array( '42' ),
			'json string'  => array( '"hi"' ),
			'json null'    => array( 'null' ),
			'empty'        => array( '' ),
			'no div'       => array( '{"files":["a"]}' ),
			'empty div'    => array( '{"div":"","files":["a"]}' ),
			'files string' => array( '{"div":"<div></div>","files":"a"}' ),
			'list'         => array( '["a"]' ),
		);
	}

	/**
	 * @dataProvider provider_bad_primary_bodies
	 */
	public function test_bad_primary_body_falls_back_without_warnings( $body ) {
		$this->mock( 'gist.github.com', 200, $body );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$this->assertSame( 'api', Source::fetch( 'abc123' )->source );
	}

	public function test_transport_error_and_server_error_fall_back() {
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$this->mock( 'gist.github.com', new WP_Error( 'http_request_failed', 'boom' ) );
		$this->assertSame( 'api', Source::fetch( 'abc123' )->source );
		$this->mock( 'gist.github.com', 500, '' );
		$this->assertSame( 'api', Source::fetch( 'abc123' )->source );
	}

	public function test_api_request_headers() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		Source::fetch( 'abc123' );
		$api = $this->calls_to( 'api.github.com' )[0];
		$this->assertSame( 'https://api.github.com/gists/abc123', $api['url'] );
		$this->assertSame( 'application/vnd.github+json', $api['args']['headers']['Accept'] );
		$this->assertArrayNotHasKey( 'Authorization', $api['args']['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->calls_to( 'gist.github.com' )[0]['args']['headers'] );
	}

	public function test_api_keeps_only_requested_file() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$p = Source::fetch( 'abc123', 'swaksWrapper.sh' );
		$this->assertSame( array( 'swaksWrapper.sh' ), array_keys( $p->raw_files ) );
		$this->assertSame( 'gistful_not_found', Source::fetch( 'abc123', 'nope.txt' )->get_error_code() );
	}

	public function test_404_everywhere_returns_not_found() {
		$this->mock( 'gist.github.com', 404, '' );
		$this->mock( 'api.github.com', 404, '' );
		$this->assertSame( 'gistful_not_found', Source::fetch( 'abc123' )->get_error_code() );
	}

	public function test_transport_failures_return_http_error() {
		$this->mock( 'gist.github.com', new WP_Error( 'http_request_failed', 'boom' ) );
		$this->mock( 'api.github.com', 503, '' );
		$this->assertSame( 'gistful_http', Source::fetch( 'abc123' )->get_error_code() );
	}

	public function test_200_unusable_everywhere_returns_bad_body() {
		$this->mock( 'gist.github.com', 200, '<html>Oops</html>' );
		$this->mock( 'api.github.com', 200, 'not json' );
		$this->assertSame( 'gistful_bad_body', Source::fetch( 'abc123' )->get_error_code() );
		$this->mock( 'api.github.com', 200, '"scalar"' );
		$this->assertSame( 'gistful_bad_body', Source::fetch( 'abc123' )->get_error_code() );
	}

	public function test_truncated_api_file_flags_payload() {
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-truncated.json' ) );
		$this->assertTrue( Source::fetch( 'abc123' )->truncated );
	}

	public function test_stylesheet_field_ignored() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-single.json' ) );
		Source::fetch( 'abc123' );
		$this->assertFalse( get_option( 'gistpress_stylesheet', false ) );
		$this->assertFalse( get_option( 'gistful_stylesheet', false ) );
	}

	public function test_fetch_file_names_primary_then_api() {
		$this->mock( 'gist.github.com', 200, $this->fixture( 'json-multi.json' ) );
		$this->assertSame( array( 'proxmox_shutdown.sh', 'proxmox_start.sh' ), Source::fetch_file_names( 'abc123' ) );
		$this->assertCount( 0, $this->calls_to( 'api.github.com' ) );

		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		$this->assertSame( array( 'swaksWrapper.sh' ), Source::fetch_file_names( 'abc123' ) );
	}

	public function test_fetch_file_names_failure_is_empty() {
		$this->mock( 'gist.github.com', 404, '' );
		$this->mock( 'api.github.com', 200, 'garbage' );
		$this->assertSame( array(), Source::fetch_file_names( 'abc123' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_token_header_only_with_constant_and_only_to_api() {
		define( 'GISTFUL_GITHUB_TOKEN', 't0k' );
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		Source::fetch( 'abc123' );
		$this->assertSame( 'Bearer t0k', $this->calls_to( 'api.github.com' )[0]['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->calls_to( 'gist.github.com' )[0]['args']['headers'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_token_constant_is_ignored() {
		define( 'GISTFUL_GITHUB_TOKEN', '' );
		$this->mock( 'gist.github.com', 500, '' );
		$this->mock( 'api.github.com', 200, $this->fixture( 'api-gist.json' ) );
		Source::fetch( 'abc123' );
		$this->assertArrayNotHasKey( 'Authorization', $this->calls_to( 'api.github.com' )[0]['args']['headers'] );
	}
}
