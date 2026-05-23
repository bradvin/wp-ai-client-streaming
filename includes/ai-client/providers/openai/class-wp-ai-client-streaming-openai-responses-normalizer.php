<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_OpenAI_Responses_Normalizer class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_OpenAI_Responses_Normalizer', false ) ) {
	return;
}

/**
 * Normalizes OpenAI Responses API SSE streams into the final response object.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_OpenAI_Responses_Normalizer implements WP_AI_Client_Streaming_Response_Normalizer_Interface {

	/**
	 * Normalizes a captured OpenAI Responses API SSE response body.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string|null Normalized JSON response body, or null when unsupported.
	 */
	public function normalize( string $body, array $contract ): ?string {
		$parser            = new WP_AI_Client_SSE_Parser();
		$terminal_response = null;
		$latest_response   = null;
		$normalized_body   = substr( $body, -2 ) === "\n\n" ? $body : $body . "\n\n";
		$events            = $parser->push( $normalized_body );

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) || empty( $data['response'] ) || ! is_array( $data['response'] ) ) {
				continue;
			}

			$type            = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : $event->get_event();
			$latest_response = $data['response'];

			if ( in_array( $type, array( 'response.completed', 'response.failed', 'response.incomplete' ), true ) ) {
				$terminal_response = $data['response'];
			}
		}

		$normalized = is_array( $terminal_response ) ? $terminal_response : $latest_response;

		if ( ! is_array( $normalized ) ) {
			return null;
		}

		$json = wp_json_encode( $normalized );

		return false !== $json && '' !== $json ? $json : null;
	}
}
