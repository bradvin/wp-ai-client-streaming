<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Google_Generate_Content_Request_Override class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Google_Generate_Content_Request_Override', false ) ) {
	return;
}

/**
 * Adapts Google Generate Content requests to Google's SSE streaming endpoint.
 *
 * @since 1.0.0
 * @internal Intended only to support WP_AI_Client_Streaming_HTTP_Service.
 * @access private
 */
class WP_AI_Client_Streaming_Google_Generate_Content_Request_Override implements WP_AI_Client_Streaming_Provider_Request_Override_Interface {

	/**
	 * Returns whether this override should handle the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return bool
	 */
	public function applies( string $url, array $analysis ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$path = (string) parse_url( $url, PHP_URL_PATH );

		return 'generativelanguage.googleapis.com' === $host
			&& 1 === preg_match( '/:generateContent$/', $path );
	}

	/**
	 * Prepares a Google Generate Content request for SSE streaming.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array{url:string,analysis:array<string,mixed>}
	 */
	public function prepare( string $url, array $analysis ): array {
		$analysis['body'] = $this->remove_json_request_field(
			$analysis['headers'] ?? array(),
			$analysis['body'] ?? null,
			'stream'
		);
		$url              = $this->convert_to_streaming_url( $url );

		return array(
			'url'      => $url,
			'analysis' => $analysis,
		);
	}

	/**
	 * Converts Google's generateContent endpoint URL to its SSE streaming endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private function convert_to_streaming_url( string $url ): string {
		$streaming_url = str_replace( ':generateContent', ':streamGenerateContent', $url );

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( 'alt', 'sse', $streaming_url );
		}

		$separator = false === strpos( $streaming_url, '?' ) ? '?' : '&';

		return $streaming_url . $separator . 'alt=sse';
	}

	/**
	 * Removes a top-level field from a JSON request body.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Request body.
	 * @param string                $field   Field to remove.
	 * @return string|null
	 */
	private function remove_json_request_field( array $headers, ?string $body, string $field ): ?string {
		if ( empty( $body ) || ! $this->looks_like_json_request( $headers ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ! array_key_exists( $field, $decoded ) ) {
			return $body;
		}

		unset( $decoded[ $field ] );

		$encoded = wp_json_encode( $decoded );

		return false === $encoded ? $body : $encoded;
	}

	/**
	 * Returns whether the request looks like a JSON request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @return bool
	 */
	private function looks_like_json_request( array $headers ): bool {
		foreach ( $headers as $name => $value ) {
			if ( 'content-type' !== strtolower( $name ) ) {
				continue;
			}

			return false !== stripos( $value, 'application/json' ) || false !== stripos( $value, '+json' );
		}

		return false;
	}
}
