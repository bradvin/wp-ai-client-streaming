<?php
/**
 * Lightweight package test runner.
 *
 * @package WordPress
 * @subpackage AI
 */

namespace WordPress\AiClient\Providers\Http\Exception {
	class NetworkException extends \Exception {}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'WPINC', 'wp-includes' );

	$GLOBALS['wp_stream_test_hooks'] = array();
	$GLOBALS['wp_stream_test_uuid']  = 0;

	function wp_stream_test_filter_id( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$target = is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0];
			return $target . '::' . (string) $callback[1];
		}

		if ( $callback instanceof \Closure ) {
			return spl_object_hash( $callback );
		}

		if ( is_object( $callback ) ) {
			return spl_object_hash( $callback );
		}

		return md5( serialize( $callback ) );
	}

	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wp_stream_test_hooks'][ $hook ][ $priority ][ wp_stream_test_filter_id( $callback ) ] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);

		return true;
	}

	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['wp_stream_test_hooks'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['wp_stream_test_hooks'][ $hook ] );

		foreach ( $GLOBALS['wp_stream_test_hooks'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback_args = array_merge( array( $value ), $args );
				$value         = call_user_func_array(
					$entry['callback'],
					array_slice( $callback_args, 0, $entry['accepted_args'] )
				);
			}
		}

		return $value;
	}

	function do_action( string $hook, ...$args ): void {
		if ( empty( $GLOBALS['wp_stream_test_hooks'][ $hook ] ) ) {
			return;
		}

		ksort( $GLOBALS['wp_stream_test_hooks'][ $hook ] );

		foreach ( $GLOBALS['wp_stream_test_hooks'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				call_user_func_array(
					$entry['callback'],
					array_slice( $args, 0, $entry['accepted_args'] )
				);
			}
		}
	}

	function do_action_ref_array( string $hook, array $args ): void {
		do_action( $hook, ...$args );
	}

	function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
		if ( empty( $GLOBALS['wp_stream_test_hooks'][ $hook ][ $priority ] ) ) {
			return false;
		}

		$id = wp_stream_test_filter_id( $callback );

		if ( isset( $GLOBALS['wp_stream_test_hooks'][ $hook ][ $priority ][ $id ] ) ) {
			unset( $GLOBALS['wp_stream_test_hooks'][ $hook ][ $priority ][ $id ] );
			return true;
		}

		return false;
	}

	function remove_action( string $hook, $callback, int $priority = 10 ): bool {
		return remove_filter( $hook, $callback, $priority );
	}

	function wp_parse_args( array $args, array $defaults = array() ): array {
		return array_merge( $defaults, $args );
	}

	function wp_generate_uuid4(): string {
		$GLOBALS['wp_stream_test_uuid']++;
		return '00000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['wp_stream_test_uuid'], 12, '0', STR_PAD_LEFT );
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function __( string $text ): string {
		return $text;
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function wp_http_validate_url( string $url ) {
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? $url : false;
	}

	function wp_kses_bad_protocol( string $url, array $allowed_protocols ): string {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, $allowed_protocols, true ) ? $url : '';
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}

	function wp_remote_retrieve_response_message( array $response ): string {
		return isset( $response['response']['message'] ) ? (string) $response['response']['message'] : '';
	}

	function wp_remote_retrieve_headers( array $response ) {
		return isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
	}

	function wp_remote_retrieve_body( array $response ): string {
		return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
	}

	function get_bloginfo( string $show ): string {
		return 'test';
	}

	function add_query_arg( string $key, string $value, string $url ): string {
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$query[ $key ]  = $value;
		$parts['query'] = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		return wp_stream_test_build_url( $parts );
	}

	class WP_Error {
		/**
		 * @var string
		 */
		private string $code;

		/**
		 * @var string
		 */
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_HTTP_Requests_Response {}

	class WP_Http {
		/**
		 * @param array<string, string>|string $headers Request headers.
		 * @return array{headers:array<string,string>}
		 */
		public static function processHeaders( $headers, string $url ): array {
			if ( is_array( $headers ) ) {
				return array( 'headers' => $headers );
			}

			$processed = array();

			foreach ( explode( "\n", (string) $headers ) as $line ) {
				if ( false === strpos( $line, ':' ) ) {
					continue;
				}

				list( $name, $value ) = explode( ':', $line, 2 );
				$processed[ trim( $name ) ] = trim( $value );
			}

			return array( 'headers' => $processed );
		}

		/**
		 * @param array<string, mixed> $args Request args.
		 */
		public static function buildCookieHeader( array &$args ): void {}
	}

	final class WP_Stream_Test_Response {
		/**
		 * @var int
		 */
		public int $status;

		/**
		 * @var string
		 */
		public string $reason;

		/**
		 * @var array<string, mixed>
		 */
		public array $headers = array();

		/**
		 * @var string
		 */
		public string $body = '';

		public function __construct( int $status, string $reason ) {
			$this->status = $status;
			$this->reason = $reason;
		}

		public function withHeader( string $name, $value ): self {
			$this->headers[ $name ] = $value;
			return $this;
		}

		public function withBody( string $body ): self {
			$this->body = $body;
			return $this;
		}
	}

	final class WP_Stream_Test_Response_Factory {
		public function createResponse( int $status, string $reason = '' ): WP_Stream_Test_Response {
			return new WP_Stream_Test_Response( $status, $reason );
		}
	}

	final class WP_Stream_Test_Stream_Factory {
		public function createStream( string $body ): string {
			return $body;
		}
	}

	function wp_stream_test_build_url( array $parts ): string {
		$url = '';

		if ( isset( $parts['scheme'] ) ) {
			$url .= $parts['scheme'] . '://';
		}

		if ( isset( $parts['host'] ) ) {
			$url .= $parts['host'];
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

	final class WP_Stream_Test_Body {
		/**
		 * @var string
		 */
		private string $body;

		public function __construct( string $body ) {
			$this->body = $body;
		}

		public function getSize(): int {
			return strlen( $this->body );
		}

		public function isSeekable(): bool {
			return true;
		}

		public function rewind(): void {}

		public function __toString(): string {
			return $this->body;
		}
	}

	final class WP_Stream_Test_Request {
		/**
		 * @var string
		 */
		private string $url;

		/**
		 * @var string
		 */
		private string $method;

		/**
		 * @var array<string, array<int, string>>
		 */
		private array $headers;

		/**
		 * @var WP_Stream_Test_Body
		 */
		private WP_Stream_Test_Body $body;

		/**
		 * @param array<string, string> $headers Request headers.
		 */
		public function __construct( string $url, string $method = 'POST', array $headers = array(), string $body = '' ) {
			$this->url     = $url;
			$this->method  = $method;
			$this->headers = array();

			foreach ( $headers as $name => $value ) {
				$this->headers[ $name ] = array( $value );
			}

			$this->body = new WP_Stream_Test_Body( $body );
		}

		public function getUri(): string {
			return $this->url;
		}

		public function getMethod(): string {
			return $this->method;
		}

		/**
		 * @return array<string, array<int, string>>
		 */
		public function getHeaders(): array {
			return $this->headers;
		}

		public function getBody(): WP_Stream_Test_Body {
			return $this->body;
		}

		public function getProtocolVersion(): string {
			return '1.1';
		}
	}

	function wp_stream_test_hook_count( string $hook ): int {
		if ( empty( $GLOBALS['wp_stream_test_hooks'][ $hook ] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $GLOBALS['wp_stream_test_hooks'][ $hook ] as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	/**
	 * @return array<int, string>
	 */
	function wp_stream_test_hook_callbacks( string $hook ): array {
		if ( empty( $GLOBALS['wp_stream_test_hooks'][ $hook ] ) ) {
			return array();
		}

		$callbacks = array();
		$hooks     = $GLOBALS['wp_stream_test_hooks'][ $hook ];
		ksort( $hooks );

		foreach ( $hooks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callbacks[] = wp_stream_test_filter_id( $entry['callback'] );
			}
		}

		return $callbacks;
	}

	function wp_stream_test_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function wp_stream_test_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	function wp_stream_test_decode_json( string $json ): array {
		$decoded = json_decode( $json, true );

		wp_stream_test_assert( is_array( $decoded ), 'Expected JSON object.' );

		return $decoded;
	}

	/**
	 * @param array<int, array{event?:string,data:mixed}> $events SSE events.
	 */
	function wp_stream_test_sse( array $events ): string {
		$body = '';

		foreach ( $events as $event ) {
			if ( isset( $event['event'] ) ) {
				$body .= 'event: ' . $event['event'] . "\n";
			}

			$data  = is_string( $event['data'] ) ? $event['data'] : wp_json_encode( $event['data'] );
			$body .= 'data: ' . $data . "\n\n";
		}

		return $body;
	}

	/**
	 * @param array<string, mixed>  $payload Request payload.
	 * @param array<string, string> $headers Request headers.
	 * @return array<string, mixed>
	 */
	function wp_stream_test_provider_flow( string $url, array $payload, array $headers = array() ): array {
		$headers = array_merge(
			array(
				'Content-Type'             => 'application/json',
				'Content-Length'           => '99',
				'X-WP-AI-Client-Stream'    => '1',
				'X-WP-AI-Client-Stream-Id' => 'ignored',
			),
			$headers
		);
		$body    = wp_json_encode( $payload );
		$request = new WP_Stream_Test_Request( $url, 'POST', $headers, $body );
		$service = new WP_AI_Client_Streaming_HTTP_Service(
			new WP_Stream_Test_Response_Factory(),
			new WP_Stream_Test_Stream_Factory(),
			new \stdClass(),
			true
		);
		$analysis = wp_stream_test_call_private( $service, 'inspectRequest', array( $request ) );
		$prepared = wp_stream_test_call_private( $service, 'prepareStreamingRequest', array( $url, $analysis, $request ) );
		$contract = wp_stream_test_call_private( $service, 'prepareResponseNormalizationContract', array( $prepared['analysis']['contract'], $prepared['url'], $prepared['analysis'] ) );

		return array(
			'request'  => $request,
			'service'  => $service,
			'analysis' => $analysis,
			'prepared' => $prepared,
			'contract' => $contract,
		);
	}

	function wp_stream_test_call_private( object $object, string $method, array $args ) {
		$reflection = new \ReflectionObject( $object );
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );

		return $method_ref->invokeArgs( $object, $args );
	}

	function wp_stream_test_run( string $name, callable $test ): void {
		try {
			$test();
			echo '.';
		} catch ( \Throwable $throwable ) {
			fwrite( STDERR, "\nFAIL: {$name}\n{$throwable->getMessage()}\n" );
			exit( 1 );
		}
	}

	$root = dirname( __DIR__ );

	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-sse-event.php';
	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-sse-parser.php';
	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-interface.php';
	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php';
	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-context.php';
	require_once $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php';
	require_once $root . '/includes/class-wp-ai-client-streaming-package-loader.php';
	require_once $root . '/includes/ai-client/providers/load.php';

	wp_stream_test_run(
		'provider modules register idempotently',
		static function (): void {
			$hooks = array(
				'wp_ai_client_stream_context_matches_request',
				'wp_ai_client_stream_request_analysis',
				'wp_ai_client_stream_prepare_request',
				'wp_ai_client_stream_response_contract',
				'wp_ai_client_stream_response_normalizers',
			);

			$expected_callbacks = array(
				'wp_ai_client_stream_context_matches_request' => array(
					'WP_AI_Client_Streaming_Google_Provider::match_request',
					'WP_AI_Client_Streaming_Anthropic_Provider::match_request',
					'WP_AI_Client_Streaming_OpenAI_Provider::match_request',
				),
				'wp_ai_client_stream_request_analysis'        => array(
					'WP_AI_Client_Streaming_Google_Provider::filter_request_analysis',
					'WP_AI_Client_Streaming_Anthropic_Provider::filter_request_analysis',
					'WP_AI_Client_Streaming_OpenAI_Provider::filter_request_analysis',
				),
				'wp_ai_client_stream_prepare_request'         => array(
					'WP_AI_Client_Streaming_Google_Provider::prepare_request',
					'WP_AI_Client_Streaming_Anthropic_Provider::prepare_request',
					'WP_AI_Client_Streaming_OpenAI_Provider::prepare_request',
				),
				'wp_ai_client_stream_response_contract'       => array(
					'WP_AI_Client_Streaming_Google_Provider::filter_response_contract',
					'WP_AI_Client_Streaming_Anthropic_Provider::filter_response_contract',
					'WP_AI_Client_Streaming_OpenAI_Provider::filter_response_contract',
				),
				'wp_ai_client_stream_response_normalizers'    => array(
					'WP_AI_Client_Streaming_Google_Provider::register_normalizers',
					'WP_AI_Client_Streaming_Anthropic_Provider::register_normalizers',
					'WP_AI_Client_Streaming_OpenAI_Provider::register_normalizers',
				),
			);

			foreach ( $hooks as $hook ) {
				wp_stream_test_same( 3, wp_stream_test_hook_count( $hook ), "{$hook} should have one callback per built-in provider." );
				wp_stream_test_same( $expected_callbacks[ $hook ], wp_stream_test_hook_callbacks( $hook ), "{$hook} should preserve provider ownership and order." );
			}

			$counts = array();

			foreach ( $hooks as $hook ) {
				$counts[ $hook ] = wp_stream_test_hook_count( $hook );
			}

			WP_AI_Client_Streaming_Provider_Registry::register(
				array(
					'openai'    => 'WP_AI_Client_Streaming_OpenAI_Provider',
					'anthropic' => 'WP_AI_Client_Streaming_Anthropic_Provider',
					'google'    => 'WP_AI_Client_Streaming_Google_Provider',
				)
			);
			WP_AI_Client_Streaming_OpenAI_Provider::register();
			WP_AI_Client_Streaming_Anthropic_Provider::register();
			WP_AI_Client_Streaming_Google_Provider::register();

			foreach ( $hooks as $hook ) {
				wp_stream_test_same( $counts[ $hook ], wp_stream_test_hook_count( $hook ), "{$hook} should not duplicate callbacks." );
			}
		}
	);

	wp_stream_test_run(
		'provider request analysis preparation and contracts',
		static function (): void {
			$openai = wp_stream_test_provider_flow(
				'https://api.openai.com/v1/chat/completions',
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
				)
			);
			$openai_body = wp_stream_test_decode_json( $openai['prepared']['analysis']['body'] );

			wp_stream_test_same( 'openai', $openai['analysis']['provider'], 'OpenAI provider should be detected.' );
			wp_stream_test_same( 'chat-completions', $openai['analysis']['operation'], 'OpenAI operation should be detected.' );
			wp_stream_test_same( true, $openai_body['stream'], 'OpenAI should inject stream=true.' );
			wp_stream_test_assert( ! array_key_exists( 'Content-Length', $openai['prepared']['analysis']['headers'] ), 'OpenAI should remove Content-Length after mutation.' );
			wp_stream_test_same( 'openai-chat-completions', $openai['contract']['expected_response_format'], 'OpenAI chat contract should be set.' );

			$anthropic = wp_stream_test_provider_flow(
				'https://api.anthropic.com/v1/messages',
				array(
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
					'max_tokens' => 64,
				)
			);
			$anthropic_body = wp_stream_test_decode_json( $anthropic['prepared']['analysis']['body'] );

			wp_stream_test_same( 'anthropic', $anthropic['analysis']['provider'], 'Anthropic path should win before OpenAI payload fallback.' );
			wp_stream_test_same( 'messages', $anthropic['analysis']['operation'], 'Anthropic operation should be detected.' );
			wp_stream_test_same( true, $anthropic_body['stream'], 'Anthropic should inject stream=true.' );
			wp_stream_test_same( 'anthropic-messages', $anthropic['contract']['expected_response_format'], 'Anthropic contract should be set.' );

			$google = wp_stream_test_provider_flow(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini:generatecontent?key=abc&alt=json',
				array(
					'contents' => array(
						array(
							'parts' => array(
								array( 'text' => 'Hello' ),
							),
						),
					),
					'stream'   => true,
				)
			);
			$google_body = wp_stream_test_decode_json( $google['prepared']['analysis']['body'] );

			wp_stream_test_same( 'google', $google['analysis']['provider'], 'Google provider should be detected from host/path.' );
			wp_stream_test_same( 'generate-content', $google['analysis']['operation'], 'Google operation should be detected.' );
			wp_stream_test_assert( false !== strpos( $google['prepared']['url'], ':streamGenerateContent' ), 'Google URL should be rewritten case-insensitively.' );
			wp_stream_test_assert( false !== strpos( $google['prepared']['url'], 'key=abc' ), 'Google URL should preserve existing query args.' );
			wp_stream_test_assert( false !== strpos( $google['prepared']['url'], 'alt=sse' ), 'Google URL should force alt=sse.' );
			wp_stream_test_assert( ! array_key_exists( 'stream', $google_body ), 'Google should remove top-level stream from JSON body.' );
			wp_stream_test_same( 'google-generate-content', $google['contract']['expected_response_format'], 'Google contract should be set.' );

			$unknown = wp_stream_test_provider_flow(
				'https://example.com/v1/generate',
				array(
					'contents' => array(
						array(
							'parts' => array(
								array( 'text' => 'Hello' ),
							),
						),
					),
					'stream'   => true,
				)
			);
			$unknown_body = wp_stream_test_decode_json( $unknown['prepared']['analysis']['body'] );

			wp_stream_test_same( null, $unknown['analysis']['provider'], 'Unknown contents payload should not be claimed as Google.' );
			wp_stream_test_same( 'https://example.com/v1/generate', $unknown['prepared']['url'], 'Unknown provider URL should not be mutated.' );
			wp_stream_test_same( true, $unknown_body['stream'], 'Unknown provider body should not be mutated.' );
			wp_stream_test_assert( ! isset( $unknown['contract']['expected_response_format'] ), 'Unknown provider should not receive a response format.' );
		}
	);

	wp_stream_test_run(
		'streaming context stays provider-neutral',
		static function (): void {
			$body    = wp_json_encode(
				array(
					'contents' => array(
						array(
							'parts' => array(
								array( 'text' => 'Hello' ),
							),
						),
					),
				)
			);
			$request = new WP_Stream_Test_Request(
				'https://example.com/v1/generate',
				'POST',
				array( 'Content-Type' => 'application/json' ),
				$body
			);

			$result = WP_AI_Client_Streaming_Context::with_streaming(
				static function () use ( $request, $body ) {
					return WP_AI_Client_Streaming_Context::maybe_apply_request_context(
						$request,
						array( 'Content-Type' => 'application/json' ),
						$body,
						array(
							'enabled'      => false,
							'mode'         => null,
							'capture_body' => true,
							'request_id'   => 'fallback',
						)
					);
				}
			);

			wp_stream_test_same( true, $result['contract']['enabled'], 'Mutating fallback requests should still stream inside a context.' );
			wp_stream_test_same( $body, $result['body'], 'Core context should not inject provider stream fields.' );
			wp_stream_test_same( '1', $result['headers']['X-WP-AI-Client-Stream'], 'Context should add control header.' );
		}
	);

	wp_stream_test_run(
		'core filters validate invalid return shapes',
		static function (): void {
			$service = new WP_AI_Client_Streaming_HTTP_Service( new \stdClass(), new \stdClass(), new \stdClass(), true );
			$request = new WP_Stream_Test_Request( 'https://example.com/v1/generate' );
			$analysis = array(
				'headers'   => array(),
				'body'      => null,
				'contract'  => array(
					'enabled'      => true,
					'mode'         => 'sse',
					'capture_body' => true,
					'request_id'   => 'filter-test',
				),
				'provider'  => null,
				'operation' => null,
				'meta'      => array(),
			);
			$bad_prepare = static function () {
				return array(
					'url'      => array( 'not a string' ),
					'analysis' => 'not an array',
				);
			};

			add_filter( 'wp_ai_client_stream_prepare_request', $bad_prepare, 100, 2 );

			$prepared = wp_stream_test_call_private(
				$service,
				'prepareStreamingRequest',
				array( 'https://example.com/v1/generate', $analysis, $request )
			);

			remove_filter( 'wp_ai_client_stream_prepare_request', $bad_prepare, 100 );

			wp_stream_test_same( 'https://example.com/v1/generate', $prepared['url'], 'Invalid prepared URL should be ignored.' );
			wp_stream_test_same( $analysis['contract']['request_id'], $prepared['analysis']['contract']['request_id'], 'Invalid prepared analysis should be ignored.' );

			$bad_contract = static function () {
				return array(
					'mode'       => 'bad',
					'request_id' => 123,
				);
			};

			add_filter( 'wp_ai_client_stream_response_contract', $bad_contract, 100, 3 );

			$contract = wp_stream_test_call_private(
				$service,
				'prepareResponseNormalizationContract',
				array( $analysis['contract'], 'https://example.com/v1/generate', $analysis )
			);

			remove_filter( 'wp_ai_client_stream_response_contract', $bad_contract, 100 );

			wp_stream_test_same( 'sse', $contract['mode'], 'Invalid contract mode should fall back.' );
			wp_stream_test_same( 'filter-test', $contract['request_id'], 'Invalid contract request ID should fall back.' );
			wp_stream_test_same( 'https://example.com/v1/generate', $contract['request_url'], 'Contract should preserve request URL.' );
		}
	);

	wp_stream_test_run(
		'http service applies provider hooks on send path',
		static function (): void {
			$body    = wp_json_encode(
				array(
					'contents' => array(
						array(
							'parts' => array(
								array( 'text' => 'Hello' ),
							),
						),
					),
					'stream'   => true,
				)
			);
			$request = new WP_Stream_Test_Request(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini:generateContent?key=abc&alt=json',
				'POST',
				array(
					'Content-Type'          => 'application/json',
					'Content-Length'        => '99',
					'X-WP-AI-Client-Stream' => '1',
				),
				$body
			);
			$service = new WP_AI_Client_Streaming_HTTP_Service(
				new WP_Stream_Test_Response_Factory(),
				new WP_Stream_Test_Stream_Factory(),
				new \stdClass(),
				true
			);
			$analysis = wp_stream_test_call_private( $service, 'inspectRequest', array( $request ) );
			$captured = array();
			$pre      = static function ( $pre, array $args, string $url ) use ( &$captured ) {
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'headers'  => array( 'content-type' => 'text/event-stream' ),
					'body'     => wp_stream_test_sse(
						array(
							array(
								'data' => array(
									'candidates' => array(
										array(
											'content' => array(
												'parts' => array(
													array( 'text' => 'Hi' ),
												),
											),
										),
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			};

			add_filter( 'pre_http_request', $pre, 10, 3 );

			try {
				$response = wp_stream_test_call_private( $service, 'sendStreamingRequest', array( $request, null, $analysis ) );
			} finally {
				remove_filter( 'pre_http_request', $pre, 10 );
			}

			$sent_body = wp_stream_test_decode_json( $captured['args']['body'] );
			$response_body = wp_stream_test_decode_json( $response->body );

			wp_stream_test_assert( false !== strpos( $captured['url'], ':streamGenerateContent' ), 'sendStreamingRequest should use provider-mutated URL.' );
			wp_stream_test_assert( false !== strpos( $captured['url'], 'alt=sse' ), 'sendStreamingRequest should force Google alt=sse.' );
			wp_stream_test_assert( ! array_key_exists( 'stream', $sent_body ), 'sendStreamingRequest should use provider-mutated body.' );
			wp_stream_test_assert( ! array_key_exists( 'Content-Length', $captured['args']['headers'] ), 'sendStreamingRequest should use provider-mutated headers.' );
			wp_stream_test_same( 'Hi', $response_body['candidates'][0]['content']['parts'][0]['text'], 'sendStreamingRequest should normalize captured provider response.' );
		}
	);

	wp_stream_test_run(
		'unsafe URL validation runs after request preparation',
		static function (): void {
			$request = new WP_Stream_Test_Request(
				'https://example.com/v1/chat/completions',
				'POST',
				array(
					'Content-Type'          => 'application/json',
					'X-WP-AI-Client-Stream' => '1',
				),
				wp_json_encode(
					array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => 'Hello',
							),
						),
					)
				)
			);
			$service = new WP_AI_Client_Streaming_HTTP_Service(
				new WP_Stream_Test_Response_Factory(),
				new WP_Stream_Test_Stream_Factory(),
				new \stdClass(),
				true
			);
			$analysis = wp_stream_test_call_private( $service, 'inspectRequest', array( $request ) );
			$mutator  = static function ( array $prepared ) {
				$prepared['url'] = 'ftp://example.com/not-allowed';
				return $prepared;
			};
			$pre_seen = false;
			$pre      = static function () use ( &$pre_seen ) {
				$pre_seen = true;
				return false;
			};
			$thrown   = false;

			add_filter( 'wp_ai_client_stream_prepare_request', $mutator, 200, 2 );
			add_filter( 'pre_http_request', $pre, 10, 3 );

			try {
				wp_stream_test_call_private( $service, 'sendStreamingRequest', array( $request, null, $analysis ) );
			} catch ( \WordPress\AiClient\Providers\Http\Exception\NetworkException $exception ) {
				$thrown = true;
			} finally {
				remove_filter( 'wp_ai_client_stream_prepare_request', $mutator, 200 );
				remove_filter( 'pre_http_request', $pre, 10 );
			}

			wp_stream_test_assert( $thrown, 'Unsafe URL introduced by request preparation should be rejected before execution.' );
			wp_stream_test_same( false, $pre_seen, 'Unsafe URL introduced by request preparation should be rejected before pre_http_request.' );
		}
	);

	wp_stream_test_run(
		'response normalizer fixtures',
		static function (): void {
			$openai_responses = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'response.completed',
							'data'  => array(
								'type'     => 'response.completed',
								'response' => array(
									'id'          => 'resp_1',
									'status'      => 'completed',
									'output_text' => 'Hello',
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-responses',
				)
			);
			$openai_responses_json = wp_stream_test_decode_json( $openai_responses );
			wp_stream_test_same( 'resp_1', $openai_responses_json['id'], 'OpenAI Responses normalizer should return terminal response.' );

			$openai_failed = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'response.failed',
							'data'  => array(
								'type'     => 'response.failed',
								'response' => array(
									'id'     => 'resp_failed',
									'status' => 'failed',
									'error'  => array( 'message' => 'Nope' ),
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-responses',
				)
			);
			$openai_failed_json = wp_stream_test_decode_json( $openai_failed );
			wp_stream_test_same( 'failed', $openai_failed_json['status'], 'OpenAI Responses normalizer should preserve failed terminal response.' );

			$openai_latest = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'response.output_text.delta',
							'data'  => array(
								'type'     => 'response.output_text.delta',
								'response' => array(
									'id'          => 'resp_latest',
									'status'      => 'in_progress',
									'output_text' => 'partial',
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-responses',
				)
			);
			$openai_latest_json = wp_stream_test_decode_json( $openai_latest );
			wp_stream_test_same( 'resp_latest', $openai_latest_json['id'], 'OpenAI Responses normalizer should fall back to latest response snapshot.' );

			$openai_chat = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'data' => array(
								'id'      => 'chat_1',
								'object'  => 'chat.completion.chunk',
								'choices' => array(
									array(
										'index' => 0,
										'delta' => array(
											'role'    => 'assistant',
											'content' => 'Hel',
										),
									),
								),
							),
						),
						array(
							'data' => array(
								'choices' => array(
									array(
										'index'         => 0,
										'delta'         => array( 'content' => 'lo' ),
										'finish_reason' => 'stop',
									),
								),
							),
						),
						array( 'data' => '[DONE]' ),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-chat-completions',
				)
			);
			$openai_chat_json = wp_stream_test_decode_json( $openai_chat );
			wp_stream_test_same( 'Hello', $openai_chat_json['choices'][0]['message']['content'], 'OpenAI chat normalizer should merge deltas.' );

			$openai_chat_tools = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'data' => array(
								'choices' => array(
									array(
										'index' => 1,
										'delta' => array(
											'role'          => 'assistant',
											'function_call' => array(
												'name'      => 'get_',
												'arguments' => '{"a"',
											),
											'tool_calls'    => array(
												array(
													'index'    => 0,
													'id'       => 'call_',
													'type'     => 'function',
													'function' => array(
														'name'      => 'tool',
														'arguments' => '{"x"',
													),
												),
											),
										),
									),
								),
							),
						),
						array(
							'data' => array(
								'choices' => array(
									array(
										'index'         => 1,
										'delta'         => array(
											'function_call' => array(
												'name'      => 'weather',
												'arguments' => ':1}',
											),
											'tool_calls'    => array(
												array(
													'index'    => 0,
													'id'       => '1',
													'function' => array( 'arguments' => ':2}' ),
												),
											),
										),
										'finish_reason' => 'tool_calls',
									),
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-chat-completions',
				)
			);
			$openai_tools_json = wp_stream_test_decode_json( $openai_chat_tools );
			wp_stream_test_same( 1, $openai_tools_json['choices'][0]['index'], 'OpenAI chat normalizer should keep choice indexes sorted.' );
			wp_stream_test_same( 'get_weather', $openai_tools_json['choices'][0]['message']['function_call']['name'], 'OpenAI chat normalizer should merge function call names.' );
			wp_stream_test_same( '{"a":1}', $openai_tools_json['choices'][0]['message']['function_call']['arguments'], 'OpenAI chat normalizer should merge function call arguments.' );
			wp_stream_test_same( 'call_1', $openai_tools_json['choices'][0]['message']['tool_calls'][0]['id'], 'OpenAI chat normalizer should merge tool call IDs.' );
			wp_stream_test_same( '{"x":2}', $openai_tools_json['choices'][0]['message']['tool_calls'][0]['function']['arguments'], 'OpenAI chat normalizer should merge tool call arguments.' );

			$openai_gateway = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'response.output_text.delta',
							'data'  => array(
								'type'  => 'response.output_text.delta',
								'delta' => 'Gateway ',
							),
						),
						array(
							'event' => 'response.completed',
							'data'  => array(
								'type'     => 'response.completed',
								'response' => array(
									'id'          => 'resp_gateway',
									'status'      => 'completed',
									'output_text' => 'Gateway response',
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-chat-completions',
					'request_id'               => 'fallback-id',
				)
			);
			$openai_gateway_json = wp_stream_test_decode_json( $openai_gateway );
			wp_stream_test_same( 'Gateway ', $openai_gateway_json['choices'][0]['message']['content'], 'OpenAI chat normalizer should convert Responses-style gateway text deltas.' );

			$openai_gateway_terminal = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'response.completed',
							'data'  => array(
								'type'     => 'response.completed',
								'response' => array(
									'id'          => 'resp_gateway_terminal',
									'status'      => 'completed',
									'output_text' => 'Gateway response',
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'openai-chat-completions',
				)
			);
			$openai_gateway_terminal_json = wp_stream_test_decode_json( $openai_gateway_terminal );
			wp_stream_test_same( 'Gateway response', $openai_gateway_terminal_json['choices'][0]['message']['content'], 'OpenAI chat normalizer should extract terminal Responses output text when deltas are absent.' );

			$anthropic = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'message_start',
							'data'  => array(
								'type'    => 'message_start',
								'message' => array(
									'id'      => 'msg_1',
									'type'    => 'message',
									'role'    => 'assistant',
									'content' => array(),
								),
							),
						),
						array(
							'event' => 'content_block_start',
							'data'  => array(
								'type'          => 'content_block_start',
								'index'         => 0,
								'content_block' => array(
									'type' => 'text',
									'text' => '',
								),
							),
						),
						array(
							'event' => 'content_block_delta',
							'data'  => array(
								'type'  => 'content_block_delta',
								'index' => 0,
								'delta' => array(
									'type' => 'text_delta',
									'text' => 'Hello',
								),
							),
						),
						array(
							'event' => 'message_delta',
							'data'  => array(
								'type'  => 'message_delta',
								'delta' => array( 'stop_reason' => 'end_turn' ),
								'usage' => array( 'output_tokens' => 3 ),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'anthropic-messages',
				)
			);
			$anthropic_json = wp_stream_test_decode_json( $anthropic );
			wp_stream_test_same( 'Hello', $anthropic_json['content'][0]['text'], 'Anthropic normalizer should merge text deltas.' );
			wp_stream_test_same( 3, $anthropic_json['usage']['output_tokens'], 'Anthropic normalizer should merge usage.' );

			$anthropic_blocks = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'content_block_start',
							'data'  => array(
								'type'          => 'content_block_start',
								'index'         => 0,
								'content_block' => array(
									'type' => 'thinking',
								),
							),
						),
						array(
							'event' => 'content_block_delta',
							'data'  => array(
								'type'  => 'content_block_delta',
								'index' => 0,
								'delta' => array(
									'thinking' => 'plan',
								),
							),
						),
						array(
							'event' => 'content_block_start',
							'data'  => array(
								'type'          => 'content_block_start',
								'index'         => 1,
								'content_block' => array(
									'type'  => 'tool_use',
									'id'    => 'tool_1',
									'name'  => 'lookup',
									'input' => array(),
								),
							),
						),
						array(
							'event' => 'content_block_delta',
							'data'  => array(
								'type'  => 'content_block_delta',
								'index' => 1,
								'delta' => array(
									'type'         => 'input_json_delta',
									'partial_json' => '{"city":"Lon',
								),
							),
						),
						array(
							'event' => 'content_block_delta',
							'data'  => array(
								'type'  => 'content_block_delta',
								'index' => 1,
								'delta' => array(
									'type'         => 'input_json_delta',
									'partial_json' => 'don"}',
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'anthropic-messages',
				)
			);
			$anthropic_blocks_json = wp_stream_test_decode_json( $anthropic_blocks );
			wp_stream_test_same( 'plan', $anthropic_blocks_json['content'][0]['thinking'], 'Anthropic normalizer should merge thinking deltas.' );
			wp_stream_test_same( 'London', $anthropic_blocks_json['content'][1]['input']['city'], 'Anthropic normalizer should decode tool input JSON deltas.' );

			$anthropic_empty = ( new WP_AI_Client_Streaming_Anthropic_Messages_Normalizer() )->normalize(
				wp_stream_test_sse(
					array(
						array(
							'event' => 'message_start',
							'data'  => array(
								'type'    => 'message_start',
								'message' => array(
									'id'      => 'empty',
									'content' => array(),
								),
							),
						),
					)
				),
				array( 'mode' => 'sse' )
			);
			wp_stream_test_same( null, $anthropic_empty, 'Anthropic normalizer should return null when no content blocks are present.' );

			$google = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'data' => array(
								'modelVersion' => 'gemini-test',
								'candidates'   => array(
									array(
										'index'   => 0,
										'content' => array(
											'parts' => array(
												array( 'text' => 'Hel' ),
											),
										),
									),
								),
							),
						),
						array(
							'data' => array(
								'usageMetadata' => array( 'totalTokenCount' => 4 ),
								'candidates'    => array(
									array(
										'index'   => 0,
										'content' => array(
											'parts' => array(
												array( 'text' => 'lo' ),
											),
										),
									),
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'google-generate-content',
				)
			);
			$google_json = wp_stream_test_decode_json( $google );
			wp_stream_test_same( 'Hello', $google_json['candidates'][0]['content']['parts'][0]['text'], 'Google normalizer should merge text chunks.' );
			wp_stream_test_same( 'STOP', $google_json['candidates'][0]['finishReason'], 'Google normalizer should default finishReason.' );
			wp_stream_test_same( 'gemini-test', $google_json['modelVersion'], 'Google normalizer should preserve metadata.' );

			$google_multi = WP_AI_Client_Streaming_Response_Normalizer_Registry::normalize(
				wp_stream_test_sse(
					array(
						array(
							'data' => array(
								'promptFeedback' => array( 'blockReason' => 'none' ),
								'candidates'     => array(
									array(
										'index'   => 1,
										'content' => array(
											'parts' => array(
												array( 'text' => 'B' ),
											),
										),
									),
									array(
										'index'   => 0,
										'content' => array(
											'parts' => array(
												array( 'functionCall' => array( 'name' => 'lookup' ) ),
											),
										),
									),
								),
							),
						),
					)
				),
				array(
					'mode'                     => 'sse',
					'expected_response_format' => 'google-generate-content',
				)
			);
			$google_multi_json = wp_stream_test_decode_json( $google_multi );
			wp_stream_test_same( 'lookup', $google_multi_json['candidates'][0]['content']['parts'][0]['functionCall']['name'], 'Google normalizer should sort candidates by index.' );
			wp_stream_test_same( 'B', $google_multi_json['candidates'][1]['content']['parts'][0]['text'], 'Google normalizer should preserve later candidate indexes.' );
			wp_stream_test_same( 'none', $google_multi_json['promptFeedback']['blockReason'], 'Google normalizer should preserve prompt feedback.' );
		}
	);

	wp_stream_test_run(
		'google request mutation edge cases',
		static function (): void {
			$existing_alt = wp_stream_test_provider_flow(
				'https://generativelanguage.googleapis.com/v1/models/gemini:streamGenerateContent?alt=json&key=abc',
				array(
					'contents' => array(),
				)
			);

			wp_stream_test_same( 1, substr_count( $existing_alt['prepared']['url'], ':streamGenerateContent' ), 'Already-streaming Google URLs should not double-rewrite.' );
			wp_stream_test_assert( false !== strpos( $existing_alt['prepared']['url'], 'alt=sse' ), 'Existing alt query arg should be replaced with sse.' );
			wp_stream_test_assert( false !== strpos( $existing_alt['prepared']['url'], 'key=abc' ), 'Existing query args should be preserved when replacing alt.' );

			$non_json = wp_stream_test_provider_flow(
				'https://generativelanguage.googleapis.com/v1/models/gemini:generateContent',
				array(),
				array(
					'Content-Type' => 'text/plain',
				)
			);

			wp_stream_test_same( '[]', $non_json['prepared']['analysis']['body'], 'Non-JSON Google bodies should not be modified.' );
		}
	);

	wp_stream_test_run(
		'loader behavior and source boundaries',
		static function () use ( $root ): void {
			$reflection = new \ReflectionClass( 'WP_AI_Client_Streaming_Package_Loader' );

			foreach (
				array(
					'packages'           => array(),
					'registration_index' => 0,
					'loaded'             => false,
					'loaded_version'     => null,
					'loaded_path'        => null,
					'hooks_registered'   => false,
				) as $property_name => $value
			) {
				$property = $reflection->getProperty( $property_name );
				$property->setAccessible( true );
				$property->setValue( null, $value );
			}

			$default_files_method = $reflection->getMethod( 'get_default_package_files' );
			$default_files_method->setAccessible( true );
			$default_files = $default_files_method->invoke( null );

			wp_stream_test_assert( in_array( 'includes/ai-client/providers/load.php', $default_files, true ), 'Default loader files should include provider bootstrap.' );

			foreach ( $default_files as $file ) {
				foreach ( array( '/openai/', '/anthropic/', '/google/', 'request-override' ) as $needle ) {
					wp_stream_test_assert( false === strpos( $file, $needle ), "Default loader file list should not contain {$needle}." );
				}
			}

			WP_AI_Client_Streaming_Package_Loader::register( '1.0.0', $root, array() );

			$registered = WP_AI_Client_Streaming_Package_Loader::get_registered_packages();
			wp_stream_test_same( $default_files, $registered[0]['files'], 'Registering without files should use the default package manifest.' );

			$tmp_root = sys_get_temp_dir() . '/wp-stream-loader-' . uniqid();
			$path_a   = $tmp_root . '/a';
			$path_b   = $tmp_root . '/b';
			mkdir( $path_a, 0777, true );
			mkdir( $path_b, 0777, true );
			file_put_contents( $path_a . '/dummy.php', "<?php\n" );
			file_put_contents( $path_b . '/dummy.php', "<?php\n" );

			foreach (
				array(
					'packages'           => array(),
					'registration_index' => 0,
				) as $property_name => $value
			) {
				$property = $reflection->getProperty( $property_name );
				$property->setAccessible( true );
				$property->setValue( null, $value );
			}

			WP_AI_Client_Streaming_Package_Loader::register( '1.0.0', $path_a, array( 'dummy.php' ) );
			WP_AI_Client_Streaming_Package_Loader::register( '1.2.0', $path_b, array( 'dummy.php' ) );

			$latest = WP_AI_Client_Streaming_Package_Loader::get_latest_package();
			wp_stream_test_same( realpath( $path_b ), $latest['path'], 'Package loader should choose the newest semantic version.' );

			WP_AI_Client_Streaming_Package_Loader::register( '1.2.0', $path_a, array( 'dummy.php' ) );

			$latest = WP_AI_Client_Streaming_Package_Loader::get_latest_package();
			wp_stream_test_same( realpath( $path_a ), $latest['path'], 'Package loader should use later registration as same-version tiebreaker.' );

			unlink( $path_a . '/dummy.php' );
			unlink( $path_b . '/dummy.php' );
			rmdir( $path_a );
			rmdir( $path_b );
			rmdir( $tmp_root );

			$load_php       = file_get_contents( $root . '/load.php' );
			$package_loader = file_get_contents( $root . '/includes/class-wp-ai-client-streaming-package-loader.php' );
			$http_service   = file_get_contents( $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php' );
			$context        = file_get_contents( $root . '/includes/ai-client/adapters/class-wp-ai-client-streaming-context.php' );

			foreach ( array( $load_php, $package_loader ) as $manifest ) {
				wp_stream_test_assert( false !== strpos( $manifest, 'includes/ai-client/providers/load.php' ), 'Root manifest should load provider bootstrap only.' );
				foreach ( array( '/openai/', '/anthropic/', '/google/', 'request-override' ) as $needle ) {
					wp_stream_test_assert( false === strpos( $manifest, $needle ), "Root manifest should not contain {$needle}." );
				}
			}

			foreach ( array( 'openai', 'anthropic', 'google', '/chat/completions', '/responses', '/messages', 'generateContent', 'streamGenerateContent', 'generativelanguage' ) as $needle ) {
				wp_stream_test_assert( false === stripos( $http_service, $needle ), "Core HTTP service should not contain provider detail {$needle}." );
			}

			foreach ( array( 'messages', 'input', 'contents', 'inject_stream_parameter' ) as $needle ) {
				wp_stream_test_assert( false === strpos( $context, $needle ), "Core context matcher should not contain provider payload key {$needle}." );
			}
		}
	);

	echo "\nAll tests passed.\n";
}
