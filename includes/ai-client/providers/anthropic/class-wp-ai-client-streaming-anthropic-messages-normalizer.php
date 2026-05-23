<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Anthropic_Messages_Normalizer class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Anthropic_Messages_Normalizer', false ) ) {
	return;
}

/**
 * Normalizes Anthropic Messages API SSE streams into the final response object.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Anthropic_Messages_Normalizer implements WP_AI_Client_Streaming_Response_Normalizer_Interface {

	/**
	 * Normalizes a captured Anthropic Messages API SSE response body.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string|null Normalized JSON response body, or null when unsupported.
	 */
	public function normalize( string $body, array $contract ): ?string {
		$parser          = new WP_AI_Client_SSE_Parser();
		$normalized_body = substr( $body, -2 ) === "\n\n" ? $body : $body . "\n\n";
		$events          = $parser->push( $normalized_body );
		$response        = array(
			'type'         => 'message',
			'role'         => 'assistant',
			'content'      => array(),
			'stop_reason'  => null,
			'stop_sequence' => null,
		);
		$blocks          = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) ) {
				continue;
			}

			$type = $this->get_event_type( $event, $data );

			if ( 'message_start' === $type && isset( $data['message'] ) && is_array( $data['message'] ) ) {
				$response = array_merge( $response, $data['message'] );
				if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
					foreach ( $response['content'] as $index => $block ) {
						if ( is_array( $block ) ) {
							$blocks[ (int) $index ] = $block;
						}
					}
				}
				continue;
			}

			if ( 'content_block_start' === $type && isset( $data['content_block'] ) && is_array( $data['content_block'] ) ) {
				$index            = $this->get_block_index( $data );
				$blocks[ $index ] = $data['content_block'];
				continue;
			}

			if ( 'content_block_delta' === $type && isset( $data['delta'] ) && is_array( $data['delta'] ) ) {
				$index = $this->get_block_index( $data );
				if ( ! isset( $blocks[ $index ] ) ) {
					$blocks[ $index ] = array( 'type' => 'text', 'text' => '' );
				}

				$blocks[ $index ] = $this->merge_content_block_delta( $blocks[ $index ], $data['delta'] );
				continue;
			}

			if ( 'message_delta' === $type && isset( $data['delta'] ) && is_array( $data['delta'] ) ) {
				$response = array_merge( $response, $data['delta'] );

				if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
					$response['usage'] = array_merge(
						isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array(),
						$data['usage']
					);
				}
			}
		}

		$content = $this->finalize_content_blocks( $blocks );

		if ( empty( $content ) ) {
			return null;
		}

		$response['content'] = $content;

		if ( empty( $response['stop_reason'] ) ) {
			$response['stop_reason'] = 'end_turn';
		}

		$json = wp_json_encode( $response );

		return false !== $json && '' !== $json ? $json : null;
	}

	/**
	 * Gets the provider event type from a parsed event.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_AI_Client_SSE_Event $event Parsed SSE event.
	 * @param array<string, mixed>   $data  Event payload.
	 * @return string
	 */
	private function get_event_type( WP_AI_Client_SSE_Event $event, array $data ): string {
		if ( isset( $data['type'] ) && is_string( $data['type'] ) ) {
			return $data['type'];
		}

		return $event->get_event();
	}

	/**
	 * Gets the content block index from an event payload.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $data Event payload.
	 * @return int
	 */
	private function get_block_index( array $data ): int {
		return isset( $data['index'] ) && is_numeric( $data['index'] ) ? (int) $data['index'] : 0;
	}

	/**
	 * Merges a content block delta into an accumulated block.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block Content block accumulator.
	 * @param array<string, mixed> $delta Content block delta.
	 * @return array<string, mixed>
	 */
	private function merge_content_block_delta( array $block, array $delta ): array {
		$type = isset( $delta['type'] ) && is_string( $delta['type'] ) ? $delta['type'] : '';

		if ( isset( $delta['text'] ) && is_string( $delta['text'] ) ) {
			$block['type'] = $block['type'] ?? 'text';
			$block['text'] = ( $block['text'] ?? '' ) . $delta['text'];
		}

		if ( isset( $delta['thinking'] ) && is_string( $delta['thinking'] ) ) {
			$block['type']     = $block['type'] ?? 'thinking';
			$block['thinking'] = ( $block['thinking'] ?? '' ) . $delta['thinking'];
		}

		if ( 'input_json_delta' === $type && isset( $delta['partial_json'] ) && is_string( $delta['partial_json'] ) ) {
			$block['_partial_json'] = ( $block['_partial_json'] ?? '' ) . $delta['partial_json'];
		}

		return $block;
	}

	/**
	 * Finalizes accumulated content blocks.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Content block accumulators.
	 * @return array<int, array<string, mixed>>
	 */
	private function finalize_content_blocks( array $blocks ): array {
		ksort( $blocks );

		$content = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['_partial_json'] ) && is_string( $block['_partial_json'] ) ) {
				$decoded = json_decode( $block['_partial_json'], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$block['input'] = $decoded;
				}
				unset( $block['_partial_json'] );
			}

			$content[] = $block;
		}

		return $content;
	}
}
