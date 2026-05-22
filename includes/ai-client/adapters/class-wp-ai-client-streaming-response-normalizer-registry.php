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

		foreach ( self::get_normalizers() as $normalizer ) {
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
	 * @return array<int, mixed>
	 */
	private static function get_normalizers(): array {
		$normalizers = array(
			new WP_AI_Client_Streaming_OpenAI_Responses_Normalizer(),
			new WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer(),
			new WP_AI_Client_Streaming_Anthropic_Messages_Normalizer(),
			new WP_AI_Client_Streaming_Google_Generate_Content_Normalizer(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters streamed response normalizers.
			 *
			 * Normalizers should implement WP_AI_Client_Streaming_Response_Normalizer_Interface.
			 *
			 * @since 0.2.0
			 *
			 * @param array<int, mixed> $normalizers Registered normalizers.
			 */
			$normalizers = apply_filters( 'wp_ai_client_stream_response_normalizers', $normalizers );
		}

		return is_array( $normalizers ) ? array_values( $normalizers ) : array();
	}
}
