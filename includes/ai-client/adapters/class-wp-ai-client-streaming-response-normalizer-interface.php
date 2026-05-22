<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Response_Normalizer_Interface interface
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( interface_exists( 'WP_AI_Client_Streaming_Response_Normalizer_Interface', false ) ) {
	return;
}

/**
 * Normalizes captured provider SSE output into the final JSON shape expected by provider parsers.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
interface WP_AI_Client_Streaming_Response_Normalizer_Interface {

	/**
	 * Normalizes a captured SSE response body when this normalizer can handle it.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string|null Normalized JSON response body, or null when unsupported.
	 */
	public function normalize( string $body, array $contract ): ?string;
}
