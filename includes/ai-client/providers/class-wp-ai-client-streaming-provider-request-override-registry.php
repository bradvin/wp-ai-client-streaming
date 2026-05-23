<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Provider_Request_Override_Registry class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Provider_Request_Override_Registry', false ) ) {
	return;
}

/**
 * Applies provider-specific request adjustments before streaming transport.
 *
 * @since 1.0.0
 * @internal Intended only to support WP_AI_Client_Streaming_HTTP_Service.
 * @access private
 */
class WP_AI_Client_Streaming_Provider_Request_Override_Registry {

	/**
	 * Applies matching provider request overrides.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array{url:string,analysis:array<string,mixed>}
	 */
	public static function prepare( string $url, array $analysis ): array {
		if ( empty( $analysis['contract']['enabled'] ) ) {
			return array(
				'url'      => $url,
				'analysis' => $analysis,
			);
		}

		foreach ( self::get_overrides( $analysis ) as $override ) {
			if ( ! $override instanceof WP_AI_Client_Streaming_Provider_Request_Override_Interface ) {
				continue;
			}

			if ( ! $override->applies( $url, $analysis ) ) {
				continue;
			}

			$prepared = $override->prepare( $url, $analysis );

			if ( ! isset( $prepared['url'], $prepared['analysis'] ) || ! is_string( $prepared['url'] ) || ! is_array( $prepared['analysis'] ) ) {
				continue;
			}

			$url      = $prepared['url'];
			$analysis = $prepared['analysis'];
		}

		return array(
			'url'      => $url,
			'analysis' => $analysis,
		);
	}

	/**
	 * Returns registered provider request overrides.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array<int, mixed>
	 */
	private static function get_overrides( array $analysis ): array {
		$overrides = array(
			new WP_AI_Client_Streaming_Google_Generate_Content_Request_Override(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters provider-specific streaming request overrides.
			 *
			 * Overrides should implement WP_AI_Client_Streaming_Provider_Request_Override_Interface.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int, mixed>     $overrides Registered provider request overrides.
			 * @param array<string, mixed>  $analysis  Request analysis.
			 */
			$overrides = apply_filters( 'wp_ai_client_stream_provider_request_overrides', $overrides, $analysis );
		}

		return is_array( $overrides ) ? array_values( $overrides ) : array();
	}
}
