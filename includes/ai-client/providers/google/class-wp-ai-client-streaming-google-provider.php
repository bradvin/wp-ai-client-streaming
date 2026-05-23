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
	 * Request-scoped Google function call thought signatures.
	 *
	 * @since 0.2.2
	 * @var array<string, list<array{identity:string,signature:string}>>
	 */
	private static array $thought_signature_cache = array();

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

		if ( ! is_array( $payload ) ) {
			$prepared['analysis'] = $analysis;
			return $prepared;
		}

		if ( ! isset( $analysis['meta'] ) || ! is_array( $analysis['meta'] ) ) {
			$analysis['meta'] = array();
		}

		$cache_key = self::get_contents_thought_signature_cache_key( $payload['contents'] ?? null );
		if ( null !== $cache_key ) {
			$analysis['meta']['google_thought_signature_cache_key'] = $cache_key;
		}

		$mutated_payload = self::add_cached_thought_signatures_to_payload( $payload );
		$body_changed    = $mutated_payload !== $payload;
		$payload         = $mutated_payload;

		if ( array_key_exists( 'stream', $payload ) ) {
			unset( $payload['stream'] );
			$body_changed = true;
		}

		if ( ! $body_changed ) {
			$prepared['analysis'] = $analysis;
			return $prepared;
		}

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

		if (
			isset( $analysis['meta']['google_thought_signature_cache_key'] ) &&
			is_string( $analysis['meta']['google_thought_signature_cache_key'] ) &&
			'' !== $analysis['meta']['google_thought_signature_cache_key']
		) {
			$contract['google_thought_signature_cache_key'] = $analysis['meta']['google_thought_signature_cache_key'];
		}

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
	 * Captures Google function call thought signatures for later request repair.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $response Normalized Generate Content response.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return void
	 */
	public static function capture_thought_signatures_from_response( array $response, array $contract ): void {
		if ( 'google-generate-content' !== ( $contract['expected_response_format'] ?? null ) ) {
			return;
		}

		$cache_key = isset( $contract['google_thought_signature_cache_key'] ) && is_string( $contract['google_thought_signature_cache_key'] )
			? $contract['google_thought_signature_cache_key']
			: '';

		if ( '' === $cache_key ) {
			return;
		}

		$candidate = $response['candidates'][0] ?? null;
		$content   = is_array( $candidate ) && isset( $candidate['content'] ) && is_array( $candidate['content'] )
			? $candidate['content']
			: null;

		$entries = is_array( $content ) ? self::get_function_call_signature_entries_from_content( $content ) : array();

		if ( empty( $entries ) ) {
			unset( self::$thought_signature_cache[ $cache_key ] );
			return;
		}

		self::$thought_signature_cache[ $cache_key ] = $entries;

		if ( count( self::$thought_signature_cache ) > 20 ) {
			array_shift( self::$thought_signature_cache );
		}
	}

	/**
	 * Adds cached thought signatures to matching Google model function call parts.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $payload Google Generate Content request payload.
	 * @return array<string, mixed> Payload with missing thought signatures restored.
	 */
	private static function add_cached_thought_signatures_to_payload( array $payload ): array {
		if ( empty( self::$thought_signature_cache ) || empty( $payload['contents'] ) || ! is_array( $payload['contents'] ) || ! self::is_list_array( $payload['contents'] ) ) {
			return $payload;
		}

		$contents = $payload['contents'];

		foreach ( $contents as $content_index => $content ) {
			if ( ! is_array( $content ) || ! self::is_model_function_call_content( $content ) ) {
				continue;
			}

			$cache_key = self::get_contents_thought_signature_cache_key(
				array_slice( $contents, 0, (int) $content_index )
			);

			if ( null === $cache_key || empty( self::$thought_signature_cache[ $cache_key ] ) ) {
				continue;
			}

			$contents[ $content_index ] = self::add_signatures_to_function_call_content(
				$content,
				self::$thought_signature_cache[ $cache_key ]
			);
		}

		$payload['contents'] = $contents;

		return $payload;
	}

	/**
	 * Adds cached signatures to one model content object.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed>                              $content Model content object.
	 * @param list<array{identity:string,signature:string}>     $entries Cached signature entries.
	 * @return array<string, mixed> Content with missing thought signatures restored.
	 */
	private static function add_signatures_to_function_call_content( array $content, array $entries ): array {
		if ( empty( $content['parts'] ) || ! is_array( $content['parts'] ) || ! self::is_list_array( $content['parts'] ) ) {
			return $content;
		}

		$calls = array();

		foreach ( $content['parts'] as $part_index => $part ) {
			if ( ! is_array( $part ) || empty( $part['functionCall'] ) || ! is_array( $part['functionCall'] ) ) {
				continue;
			}

			$identity = self::get_function_call_identity( $part['functionCall'] );
			if ( null === $identity ) {
				continue;
			}

			$calls[] = array(
				'part_index'    => (int) $part_index,
				'identity'      => $identity,
				'has_signature' => self::part_has_thought_signature( $part ),
			);
		}

		if ( empty( $calls ) ) {
			return $content;
		}

		$matches = self::match_thought_signature_entries_to_calls( $calls, $entries );

		foreach ( $matches as $call_index => $entry ) {
			if ( ! isset( $calls[ $call_index ] ) || ! empty( $calls[ $call_index ]['has_signature'] ) ) {
				continue;
			}

			$content['parts'][ $calls[ $call_index ]['part_index'] ]['thoughtSignature'] = $entry['signature'];
		}

		return $content;
	}

	/**
	 * Matches cached signatures to outgoing function calls without guessing.
	 *
	 * @since 0.2.2
	 *
	 * @param list<array{part_index:int,identity:string,has_signature:bool}> $calls   Outgoing function call parts.
	 * @param list<array{identity:string,signature:string}>                  $entries Cached signature entries.
	 * @return array<int,array{identity:string,signature:string}> Matched entries keyed by call index.
	 */
	private static function match_thought_signature_entries_to_calls( array $calls, array $entries ): array {
		$matches = array();

		if ( count( $calls ) === count( $entries ) ) {
			$order_matches = true;

			foreach ( $calls as $index => $call ) {
				if ( ! isset( $entries[ $index ]['identity'] ) || $entries[ $index ]['identity'] !== $call['identity'] ) {
					$order_matches = false;
					break;
				}
			}

			if ( $order_matches ) {
				foreach ( $entries as $index => $entry ) {
					$matches[ $index ] = $entry;
				}

				return $matches;
			}
		}

		$entries_by_identity = array();
		$call_counts         = array();

		foreach ( $entries as $entry ) {
			$entries_by_identity[ $entry['identity'] ][] = $entry;
		}

		foreach ( $calls as $call ) {
			$call_counts[ $call['identity'] ] = ( $call_counts[ $call['identity'] ] ?? 0 ) + 1;
		}

		foreach ( $calls as $index => $call ) {
			if (
				1 !== ( $call_counts[ $call['identity'] ] ?? 0 ) ||
				1 !== count( $entries_by_identity[ $call['identity'] ] ?? array() )
			) {
				continue;
			}

			$matches[ $index ] = $entries_by_identity[ $call['identity'] ][0];
		}

		return $matches;
	}

	/**
	 * Returns whether a content object is a model message with function calls.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $content Content object.
	 * @return bool Whether the content contains model function calls.
	 */
	private static function is_model_function_call_content( array $content ): bool {
		if ( isset( $content['role'] ) && 'model' !== $content['role'] ) {
			return false;
		}

		if ( empty( $content['parts'] ) || ! is_array( $content['parts'] ) ) {
			return false;
		}

		foreach ( $content['parts'] as $part ) {
			if ( is_array( $part ) && isset( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extracts thought signature entries from a Google model content object.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $content Content object.
	 * @return list<array{identity:string,signature:string}> Thought signature entries.
	 */
	private static function get_function_call_signature_entries_from_content( array $content ): array {
		if ( empty( $content['parts'] ) || ! is_array( $content['parts'] ) || ! self::is_list_array( $content['parts'] ) ) {
			return array();
		}

		$entries = array();

		foreach ( $content['parts'] as $part ) {
			if ( ! is_array( $part ) || empty( $part['functionCall'] ) || ! is_array( $part['functionCall'] ) ) {
				continue;
			}

			$signature = self::get_part_thought_signature( $part );
			$identity  = self::get_function_call_identity( $part['functionCall'] );

			if ( null === $signature || '' === $signature || null === $identity ) {
				continue;
			}

			$entries[] = array(
				'identity'  => $identity,
				'signature' => $signature,
			);
		}

		return $entries;
	}

	/**
	 * Returns the thought signature from a Google part.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $part Google content part.
	 * @return string|null Thought signature, if present.
	 */
	private static function get_part_thought_signature( array $part ): ?string {
		if ( isset( $part['thoughtSignature'] ) && is_string( $part['thoughtSignature'] ) ) {
			return $part['thoughtSignature'];
		}

		if ( isset( $part['thought_signature'] ) && is_string( $part['thought_signature'] ) ) {
			return $part['thought_signature'];
		}

		return null;
	}

	/**
	 * Returns whether a Google part already has a thought signature.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $part Google content part.
	 * @return bool Whether a signature exists.
	 */
	private static function part_has_thought_signature( array $part ): bool {
		$signature = self::get_part_thought_signature( $part );

		return null !== $signature && '' !== $signature;
	}

	/**
	 * Builds a stable identity for a function call payload.
	 *
	 * @since 0.2.2
	 *
	 * @param array<string, mixed> $function_call Function call payload.
	 * @return string|null Stable function call identity.
	 */
	private static function get_function_call_identity( array $function_call ): ?string {
		$encoded = wp_json_encode( self::normalize_value_for_signature_cache( $function_call ) );

		return false !== $encoded && '' !== $encoded ? hash( 'sha256', $encoded ) : null;
	}

	/**
	 * Builds a stable cache key for a Google contents prefix.
	 *
	 * @since 0.2.2
	 *
	 * @param mixed $contents Google contents array.
	 * @return string|null Stable cache key.
	 */
	private static function get_contents_thought_signature_cache_key( $contents ): ?string {
		if ( ! is_array( $contents ) || ! self::is_list_array( $contents ) ) {
			return null;
		}

		$encoded = wp_json_encode( self::normalize_value_for_signature_cache( $contents ) );

		return false !== $encoded && '' !== $encoded ? hash( 'sha256', $encoded ) : null;
	}

	/**
	 * Normalizes request values so thought signatures do not affect cache keys.
	 *
	 * @since 0.2.2
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function normalize_value_for_signature_cache( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();

		foreach ( $value as $key => $item ) {
			if ( in_array( $key, array( 'thoughtSignature', 'thought_signature' ), true ) ) {
				continue;
			}

			$normalized[ $key ] = self::normalize_value_for_signature_cache( $item );
		}

		if ( ! self::is_list_array( $normalized ) ) {
			ksort( $normalized );
		}

		return $normalized;
	}

	/**
	 * Returns whether the array uses contiguous integer indexes.
	 *
	 * @since 0.2.2
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool Whether the array is a list.
	 */
	private static function is_list_array( array $value ): bool {
		$index = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}

			$index++;
		}

		return true;
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
