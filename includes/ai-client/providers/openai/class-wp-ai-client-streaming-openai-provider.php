<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_OpenAI_Provider class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_OpenAI_Provider', false ) ) {
	return;
}

/**
 * Registers OpenAI streaming behavior.
 *
 * @since 1.0.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_OpenAI_Provider implements WP_AI_Client_Streaming_Provider_Module_Interface {

	/**
	 * Whether provider hooks have already been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers OpenAI provider hooks.
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

		require_once __DIR__ . '/class-wp-ai-client-streaming-openai-responses-normalizer.php';
		require_once __DIR__ . '/class-wp-ai-client-streaming-openai-chat-completions-normalizer.php';

		add_filter( 'wp_ai_client_stream_context_matches_request', array( __CLASS__, 'match_request' ), 10, 5 );
		add_filter( 'wp_ai_client_stream_request_analysis', array( __CLASS__, 'filter_request_analysis' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_prepare_request', array( __CLASS__, 'prepare_request' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_response_contract', array( __CLASS__, 'filter_response_contract' ), 10, 3 );
		add_filter( 'wp_ai_client_stream_response_normalizers', array( __CLASS__, 'register_normalizers' ), 10, 2 );
	}

	/**
	 * Matches OpenAI and OpenAI-compatible streaming requests.
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
	 * Adds OpenAI provider metadata to request analysis.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $analysis Request analysis.
	 * @param object               $request  PSR-7 request.
	 * @return array<string, mixed>
	 */
	public static function filter_request_analysis( array $analysis, $request ): array {
		if ( ! empty( $analysis['provider'] ) && 'openai' !== $analysis['provider'] ) {
			return $analysis;
		}

		$headers   = isset( $analysis['headers'] ) && is_array( $analysis['headers'] ) ? $analysis['headers'] : array();
		$body      = isset( $analysis['body'] ) && is_string( $analysis['body'] ) ? $analysis['body'] : null;
		$operation = self::detect_operation( (string) $request->getUri(), $headers, $body );

		if ( null === $operation ) {
			return $analysis;
		}

		$analysis['provider']  = 'openai';
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
	 * Prepares OpenAI requests for provider-side streaming.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $prepared Prepared request details.
	 * @param object               $request  PSR-7 request.
	 * @return array<string, mixed>
	 */
	public static function prepare_request( array $prepared, $request ): array {
		if ( empty( $prepared['analysis'] ) || ! is_array( $prepared['analysis'] ) ) {
			return $prepared;
		}

		$analysis = $prepared['analysis'];

		if ( 'openai' !== ( $analysis['provider'] ?? null ) ) {
			return $prepared;
		}

		if ( ! in_array( $analysis['operation'] ?? null, array( 'chat-completions', 'responses' ), true ) ) {
			return $prepared;
		}

		$headers = isset( $analysis['headers'] ) && is_array( $analysis['headers'] ) ? $analysis['headers'] : array();
		$body    = isset( $analysis['body'] ) && is_string( $analysis['body'] ) ? $analysis['body'] : null;
		$payload = self::decode_json_body( $headers, $body );

		if ( ! is_array( $payload ) || ! empty( $payload['stream'] ) ) {
			return $prepared;
		}

		$payload['stream'] = true;
		$encoded          = wp_json_encode( $payload );

		if ( false === $encoded ) {
			return $prepared;
		}

		$analysis['body']    = $encoded;
		$analysis['headers'] = self::remove_header( self::remove_header( $headers, 'Content-Length' ), 'Transfer-Encoding' );
		$prepared['analysis'] = $analysis;

		return $prepared;
	}

	/**
	 * Adds OpenAI response normalization details to the streaming contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @param string               $url      Prepared request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array<string, mixed>
	 */
	public static function filter_response_contract( array $contract, string $url, array $analysis ): array {
		if ( 'openai' !== ( $analysis['provider'] ?? null ) ) {
			return $contract;
		}

		$operation = $analysis['operation'] ?? null;

		if ( 'chat-completions' === $operation ) {
			$contract['expected_response_format'] = 'openai-chat-completions';
		} elseif ( 'responses' === $operation ) {
			$contract['expected_response_format'] = 'openai-responses';
		}

		$contract['provider']  = 'openai';
		$contract['operation'] = is_string( $operation ) ? $operation : null;

		return $contract;
	}

	/**
	 * Registers OpenAI response normalizers.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $normalizers Registered normalizers.
	 * @param array<string, mixed>     $contract    Streaming contract.
	 * @return array<int|string, mixed>
	 */
	public static function register_normalizers( array $normalizers, array $contract ): array {
		$normalizers['openai-responses']         = new WP_AI_Client_Streaming_OpenAI_Responses_Normalizer();
		$normalizers['openai-chat-completions'] = new WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer();

		return $normalizers;
	}

	/**
	 * Detects the OpenAI operation for a request.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url     Request URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @return string|null
	 */
	private static function detect_operation( string $url, array $headers, ?string $body ): ?string {
		$path = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );

		if ( false !== strpos( $path, '/chat/completions' ) ) {
			return 'chat-completions';
		}

		if ( preg_match( '#/responses/?$#', $path ) ) {
			return 'responses';
		}

		$payload = self::decode_json_body( $headers, $body );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
			return 'chat-completions';
		}

		if ( array_key_exists( 'input', $payload ) ) {
			return 'responses';
		}

		return null;
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
