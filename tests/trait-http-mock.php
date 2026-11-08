<?php
/**
 * Shared HTTP mocking for tests (pre_http_request).
 *
 * @package Gistful
 */

/**
 * Records requests and serves mocked responses by host.
 */
trait Gistful_Http_Mock {

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

	/**
	 * Reset recorded state and hook the mock. Call from set_up().
	 */
	protected function set_up_http_mock() {
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
		$host  = wp_parse_url( $url, PHP_URL_HOST );
		$route = $this->routes[ $host ] ?? new WP_Error( 'unmocked', 'No mock for ' . $host );
		if ( is_array( $route ) && isset( $route['seq'] ) ) {
			$route = count( $route['seq'] ) > 1 ? array_shift( $this->routes[ $host ]['seq'] ) : $route['seq'][0];
		}

		return $route;
	}

	/**
	 * Mock a host's response.
	 *
	 * @param string       $host    Host.
	 * @param int|WP_Error $code    Status code, or a WP_Error to simulate a transport failure.
	 * @param string       $body    Body.
	 * @param array        $headers Response headers.
	 */
	private function mock( string $host, $code, string $body = '', array $headers = array() ) {
		$this->routes[ $host ] = $this->response( $code, $body, $headers );
	}

	/**
	 * Mock a sequence of responses for a host (the last one repeats).
	 *
	 * @param string $host      Host.
	 * @param array  $responses List of responses built with response().
	 */
	private function mock_seq( string $host, array $responses ) {
		$this->routes[ $host ] = array( 'seq' => $responses );
	}

	/**
	 * Build a response.
	 *
	 * @param int|WP_Error $code    Status code or error.
	 * @param string       $body    Body.
	 * @param array        $headers Headers.
	 * @return array|WP_Error
	 */
	private function response( $code, string $body = '', array $headers = array() ) {
		return is_wp_error( $code ) ? $code : array(
			'headers'  => $headers,
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
}
