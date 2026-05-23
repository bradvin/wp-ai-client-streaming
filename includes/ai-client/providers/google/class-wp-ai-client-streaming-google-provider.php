<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Google_Provider class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Google_Provider', false ) ) {
	return;
}

/**
 * Registers Google streaming behavior.
 *
 * @since 1.0.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Google_Provider implements WP_AI_Client_Streaming_Provider_Module_Interface {

	/**
	 * Whether provider hooks have already been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers Google provider hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once __DIR__ . '/class-wp-ai-client-streaming-google-generate-content-normalizer.php';

		add_filter( 'wp_ai_client_stream_context_matches_request', array( __CLASS__, 'match_request' ), 10, 5 );
		add_filter( 'wp_ai_client_stream_request_analysis', array( __CLASS__, 'filter_request_analysis' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_prepare_request', array( __CLASS__, 'prepare_request' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_response_contract', array( __CLASS__, 'filter_response_contract' ), 10, 3 );
		add_filter( 'wp_ai_client_stream_response_normalizers', array( __CLASS__, 'register_normalizers' ), 10, 2 );
	}

	/**
	 * Matches Google Generate Content requests.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|null            $matched Existing match decision.
	 * @param object               $request PSR-7 request.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @param array<string,mixed>  $context Active streaming context.
	 * @return bool|null
	 */
	public static function match_request( $matched, $request, array $headers, ?string $body, array $context ) {
		if ( null !== $matched ) {
			return $matched;
		}

		return null !== self::detect_operation( (string) $request->getUri(), $headers, $body ) ? true : null;
	}

	/**
	 * Adds Google provider metadata to request analysis.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $analysis Request analysis.
	 * @param object               $request  PSR-7 request.
	 * @return array<string, mixed>
	 */
	public static function filter_request_analysis( array $analysis, $request ): array {
		if ( ! empty( $analysis['provider'] ) && 'google' !== $analysis['provider'] ) {
			return $analysis;
		}

		$headers   = isset( $analysis['headers'] ) && is_array( $analysis['headers'] ) ? $analysis['headers'] : array();
		$body      = isset( $analysis['body'] ) && is_string( $analysis['body'] ) ? $analysis['body'] : null;
		$operation = self::detect_operation( (string) $request->getUri(), $headers, $body );

		if ( null === $operation ) {
			return $analysis;
		}

		$analysis['provider']  = 'google';
		$analysis['operation'] = $operation;

		$json_body = self::decode_json_body( $headers, $body );
		if ( is_array( $json_body ) ) {
			if ( ! isset( $analysis['meta'] ) || ! is_array( $analysis['meta'] ) ) {
				$analysis['meta'] = array();
			}

			$analysis['meta']['json_body'] = $json_body;
		}

		return $analysis;
	}

	/**
	 * Prepares Google Generate Content requests for SSE streaming.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $prepared Prepared request details.
	 * @param object               $request  PSR-7 request.
	 * @return array<string, mixed>
	 */
	public static function prepare_request( array $prepared, $request ): array {
		if ( empty( $prepared['url'] ) || ! is_string( $prepared['url'] ) || empty( $prepared['analysis'] ) || ! is_array( $prepared['analysis'] ) ) {
			return $prepared;
		}

		$analysis = $prepared['analysis'];

		if ( 'google' !== ( $analysis['provider'] ?? null ) || 'generate-content' !== ( $analysis['operation'] ?? null ) ) {
			return $prepared;
		}

		if ( ! self::is_generate_content_url( $prepared['url'] ) ) {
			return $prepared;
		}

		$prepared['url'] = self::convert_to_streaming_url( $prepared['url'] );

		$headers = isset( $analysis['headers'] ) && is_array( $analysis['headers'] ) ? $analysis['headers'] : array();
		$body    = isset( $analysis['body'] ) && is_string( $analysis['body'] ) ? $analysis['body'] : null;
		$payload = self::decode_json_body( $headers, $body );

		if ( ! is_array( $payload ) || ! array_key_exists( 'stream', $payload ) ) {
			$prepared['analysis'] = $analysis;
			return $prepared;
		}

		unset( $payload['stream'] );

		$encoded = wp_json_encode( $payload );

		if ( false === $encoded ) {
			$prepared['analysis'] = $analysis;
			return $prepared;
		}

		$analysis['body']    = $encoded;
		$analysis['headers'] = self::remove_header( self::remove_header( $headers, 'Content-Length' ), 'Transfer-Encoding' );
		$prepared['analysis'] = $analysis;

		return $prepared;
	}

	/**
	 * Adds Google response normalization details to the streaming contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @param string               $url      Prepared request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array<string, mixed>
	 */
	public static function filter_response_contract( array $contract, string $url, array $analysis ): array {
		if ( 'google' !== ( $analysis['provider'] ?? null ) || 'generate-content' !== ( $analysis['operation'] ?? null ) ) {
			return $contract;
		}

		$contract['provider']                 = 'google';
		$contract['operation']                = 'generate-content';
		$contract['expected_response_format'] = 'google-generate-content';

		return $contract;
	}

	/**
	 * Registers Google response normalizers.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $normalizers Registered normalizers.
	 * @param array<string, mixed>     $contract    Streaming contract.
	 * @return array<int|string, mixed>
	 */
	public static function register_normalizers( array $normalizers, array $contract ): array {
		$normalizers['google-generate-content'] = new WP_AI_Client_Streaming_Google_Generate_Content_Normalizer();

		return $normalizers;
	}

	/**
	 * Detects the Google operation for a request.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url     Request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @return string|null
	 */
	private static function detect_operation( string $url, array $headers, ?string $body ): ?string {
		if ( self::is_generate_content_url( $url ) ) {
			return 'generate-content';
		}

		return null;
	}

	/**
	 * Returns whether the URL targets Google Generate Content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return bool
	 */
	private static function is_generate_content_url( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );

		return 'generativelanguage.googleapis.com' === $host
			&& 1 === preg_match( '/:(stream)?generatecontent$/', $path );
	}

	/**
	 * Converts a Generate Content URL to Google's SSE streaming endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function convert_to_streaming_url( string $url ): string {
		$streaming_url = self::replace_generate_content_path( $url );

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( 'alt', 'sse', $streaming_url );
		}

		$parts = parse_url( $streaming_url );

		if ( ! is_array( $parts ) ) {
			return $streaming_url;
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$query['alt']  = 'sse';
		$parts['query'] = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		return self::build_url( $parts );
	}

	/**
	 * Converts Generate Content path suffixes to the streaming endpoint suffix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function replace_generate_content_path( string $url ): string {
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['path'] ) || ! is_string( $parts['path'] ) ) {
			return $url;
		}

		$parts['path'] = preg_replace( '/:(stream)?generatecontent$/i', ':streamGenerateContent', $parts['path'] );

		return self::build_url( $parts );
	}

	/**
	 * Rebuilds a URL from parsed parts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $parts URL parts.
	 * @return string
	 */
	private static function build_url( array $parts ): string {
		$url = '';

		if ( isset( $parts['scheme'] ) ) {
			$url .= $parts['scheme'] . '://';
		}

		if ( isset( $parts['user'] ) ) {
			$url .= $parts['user'];
			if ( isset( $parts['pass'] ) ) {
				$url .= ':' . $parts['pass'];
			}
			$url .= '@';
		}

		if ( isset( $parts['host'] ) ) {
			$url .= $parts['host'];
		}

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}

		if ( isset( $parts['path'] ) ) {
			$url .= $parts['path'];
		}

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}

	/**
	 * Decodes a JSON request body.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @return array<string,mixed>|null
	 */
	private static function decode_json_body( array $headers, ?string $body ): ?array {
		if ( empty( $body ) || ! self::looks_like_json_request( $headers ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		return JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Returns whether request headers indicate a JSON body.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $headers Request headers.
	 * @return bool
	 */
	private static function looks_like_json_request( array $headers ): bool {
		foreach ( $headers as $name => $value ) {
			if ( 'content-type' !== strtolower( $name ) ) {
				continue;
			}

			return false !== stripos( $value, 'application/json' ) || false !== stripos( $value, '+json' );
		}

		return false;
	}

	/**
	 * Removes a header by name.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $headers Request headers.
	 * @param string               $header  Header name.
	 * @return array<string,string>
	 */
	private static function remove_header( array $headers, string $header ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}
}
