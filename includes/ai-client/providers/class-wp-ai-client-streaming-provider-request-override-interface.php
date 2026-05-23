<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Provider_Request_Override_Interface interface
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( interface_exists( 'WP_AI_Client_Streaming_Provider_Request_Override_Interface', false ) ) {
	return;
}

/**
 * Contract for provider-specific streaming request adjustments.
 *
 * @since 1.0.0
 */
interface WP_AI_Client_Streaming_Provider_Request_Override_Interface {

	/**
	 * Returns whether this override should handle the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return bool
	 */
	public function applies( string $url, array $analysis ): bool;

	/**
	 * Prepares the request for provider-side streaming.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array{url:string,analysis:array<string,mixed>}
	 */
	public function prepare( string $url, array $analysis ): array;
}
