<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Response_Normalizer_Registry class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Response_Normalizer_Registry', false ) ) {
	return;
}

/**
 * Coordinates provider-specific streamed response body normalizers.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Response_Normalizer_Registry {

	/**
	 * Normalizes captured SSE output back into a final JSON response body.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string
	 */
	public static function normalize( string $body, array $contract ): string {
		if ( '' === $body || 'sse' !== ( $contract['mode'] ?? null ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $body;
		}

		foreach ( self::get_normalizers( $contract ) as $normalizer ) {
			if ( ! $normalizer instanceof WP_AI_Client_Streaming_Response_Normalizer_Interface ) {
				continue;
			}

			$normalized = $normalizer->normalize( $body, $contract );

			if ( is_string( $normalized ) && '' !== $normalized ) {
				return $normalized;
			}
		}

		return $body;
	}

	/**
	 * Returns the registered streamed response normalizers.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return array<int, mixed>
	 */
	private static function get_normalizers( array $contract ): array {
		$normalizers = array();

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters streamed response normalizers.
			 *
			 * Normalizers should implement WP_AI_Client_Streaming_Response_Normalizer_Interface.
			 *
			 * @since 0.2.0
			 *
			 * @param array<int|string, mixed> $normalizers Registered normalizers.
			 * @param array<string, mixed>     $contract    Streaming contract.
			 */
			$normalizers = apply_filters( 'wp_ai_client_stream_response_normalizers', $normalizers, $contract );
		}

		if ( ! is_array( $normalizers ) ) {
			return array();
		}

		$expected_format = self::get_expected_response_format( $contract );

		if ( isset( $normalizers[ $expected_format ] ) ) {
			$normalizers = array( $expected_format => $normalizers[ $expected_format ] ) + $normalizers;
		}

		return array_values( $normalizers );
	}

	/**
	 * Resolves the expected final response format from an explicit contract or request path.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string
	 */
	private static function get_expected_response_format( array $contract ): string {
		if ( isset( $contract['expected_response_format'] ) && is_string( $contract['expected_response_format'] ) && '' !== $contract['expected_response_format'] ) {
			return $contract['expected_response_format'];
		}

		$request_path = self::get_request_path( $contract );

		if ( false !== strpos( $request_path, '/chat/completions' ) ) {
			return 'openai-chat-completions';
		}

		if ( preg_match( '#/responses/?$#', $request_path ) ) {
			return 'openai-responses';
		}

		return '';
	}

	/**
	 * Returns a normalized request path from the streaming contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string
	 */
	private static function get_request_path( array $contract ): string {
		if ( isset( $contract['request_path'] ) && is_string( $contract['request_path'] ) ) {
			return strtolower( $contract['request_path'] );
		}

		if ( isset( $contract['request_url'] ) && is_string( $contract['request_url'] ) ) {
			return strtolower( (string) parse_url( $contract['request_url'], PHP_URL_PATH ) );
		}

		return '';
	}
}
