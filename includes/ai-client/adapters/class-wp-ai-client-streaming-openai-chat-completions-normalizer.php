<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer', false ) ) {
	return;
}

/**
 * Normalizes OpenAI-compatible chat completion SSE streams into the final response object.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer implements WP_AI_Client_Streaming_Response_Normalizer_Interface {

	/**
	 * Normalizes a captured OpenAI-compatible chat completion SSE response body.
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
		$response        = array();
		$choices         = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) || empty( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
				continue;
			}

			$this->copy_response_metadata( $response, $data );

			foreach ( $data['choices'] as $fallback_index => $choice_delta ) {
				if ( ! is_array( $choice_delta ) ) {
					continue;
				}

				$index = isset( $choice_delta['index'] ) && is_numeric( $choice_delta['index'] )
					? (int) $choice_delta['index']
					: (int) $fallback_index;

				if ( ! isset( $choices[ $index ] ) ) {
					$choices[ $index ] = $this->create_empty_choice( $index );
				}

				$choices[ $index ] = $this->merge_choice_delta( $choices[ $index ], $choice_delta );
			}
		}

		if ( empty( $choices ) ) {
			return null;
		}

		if ( empty( $response['object'] ) ) {
			$response['object'] = 'chat.completion';
		} elseif ( is_string( $response['object'] ) ) {
			$response['object'] = str_replace( '.chunk', '', $response['object'] );
		}

		ksort( $choices );

		$response['choices'] = array_values(
			array_map(
				array( $this, 'finalize_choice' ),
				$choices
			)
		);

		$json = wp_json_encode( $response );

		return false !== $json && '' !== $json ? $json : null;
	}

	/**
	 * Copies stable top-level response metadata from a stream chunk.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $response Response accumulator.
	 * @param array<string, mixed> $data     Chunk data.
	 * @return void
	 */
	private function copy_response_metadata( array &$response, array $data ): void {
		foreach ( array( 'id', 'object', 'created', 'model', 'system_fingerprint', 'service_tier', 'usage' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$response[ $key ] = $data[ $key ];
			}
		}
	}

	/**
	 * Creates an empty choice accumulator.
	 *
	 * @since 0.2.0
	 *
	 * @param int $index Choice index.
	 * @return array<string, mixed>
	 */
	private function create_empty_choice( int $index ): array {
		return array(
			'index'         => $index,
			'role'          => 'assistant',
			'content'       => '',
			'finish_reason' => null,
			'logprobs'      => null,
			'function_call' => array(),
			'tool_calls'    => array(),
		);
	}

	/**
	 * Merges a streaming choice delta into an accumulated choice.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $choice       Choice accumulator.
	 * @param array<string, mixed> $choice_delta Choice chunk.
	 * @return array<string, mixed>
	 */
	private function merge_choice_delta( array $choice, array $choice_delta ): array {
		if ( array_key_exists( 'finish_reason', $choice_delta ) ) {
			$choice['finish_reason'] = $choice_delta['finish_reason'];
		}

		if ( array_key_exists( 'logprobs', $choice_delta ) ) {
			$choice['logprobs'] = $choice_delta['logprobs'];
		}

		if ( ! empty( $choice_delta['message'] ) && is_array( $choice_delta['message'] ) ) {
			$choice = $this->merge_message_delta( $choice, $choice_delta['message'] );
		}

		if ( ! empty( $choice_delta['delta'] ) && is_array( $choice_delta['delta'] ) ) {
			$choice = $this->merge_message_delta( $choice, $choice_delta['delta'] );
		}

		return $choice;
	}

	/**
	 * Merges a message or delta object into an accumulated choice.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $choice Choice accumulator.
	 * @param array<string, mixed> $delta  Message or delta object.
	 * @return array<string, mixed>
	 */
	private function merge_message_delta( array $choice, array $delta ): array {
		if ( isset( $delta['role'] ) && is_string( $delta['role'] ) && '' !== $delta['role'] ) {
			$choice['role'] = $delta['role'];
		}

		if ( isset( $delta['content'] ) && is_string( $delta['content'] ) ) {
			$choice['content'] .= $delta['content'];
		}

		if ( ! empty( $delta['function_call'] ) && is_array( $delta['function_call'] ) ) {
			$choice['function_call'] = $this->merge_function_call_delta( $choice['function_call'], $delta['function_call'] );
		}

		if ( ! empty( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
			$choice['tool_calls'] = $this->merge_tool_call_deltas( $choice['tool_calls'], $delta['tool_calls'] );
		}

		return $choice;
	}

	/**
	 * Merges streamed function-call fields.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $current Current function-call accumulator.
	 * @param array<string, mixed> $delta   Function-call delta.
	 * @return array<string, mixed>
	 */
	private function merge_function_call_delta( array $current, array $delta ): array {
		if ( isset( $delta['name'] ) && is_string( $delta['name'] ) ) {
			$current['name'] = ( $current['name'] ?? '' ) . $delta['name'];
		}

		if ( isset( $delta['arguments'] ) && is_string( $delta['arguments'] ) ) {
			$current['arguments'] = ( $current['arguments'] ?? '' ) . $delta['arguments'];
		}

		return $current;
	}

	/**
	 * Merges streamed tool-call fields.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $current Current tool-call accumulators.
	 * @param array<int, array<string, mixed>> $deltas  Tool-call deltas.
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_tool_call_deltas( array $current, array $deltas ): array {
		foreach ( $deltas as $fallback_index => $delta ) {
			if ( ! is_array( $delta ) ) {
				continue;
			}

			$index = isset( $delta['index'] ) && is_numeric( $delta['index'] )
				? (int) $delta['index']
				: (int) $fallback_index;

			if ( ! isset( $current[ $index ] ) ) {
				$current[ $index ] = array(
					'index'    => $index,
					'function' => array(),
				);
			}

			foreach ( array( 'id', 'type' ) as $key ) {
				if ( isset( $delta[ $key ] ) && is_string( $delta[ $key ] ) ) {
					$current[ $index ][ $key ] = ( $current[ $index ][ $key ] ?? '' ) . $delta[ $key ];
				}
			}

			if ( ! empty( $delta['function'] ) && is_array( $delta['function'] ) ) {
				$current[ $index ]['function'] = $this->merge_function_call_delta( $current[ $index ]['function'], $delta['function'] );
			}
		}

		ksort( $current );

		return $current;
	}

	/**
	 * Converts an accumulated choice into the non-streaming response shape.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $choice Choice accumulator.
	 * @return array<string, mixed>
	 */
	private function finalize_choice( array $choice ): array {
		$message = array(
			'role'    => $choice['role'],
			'content' => $choice['content'],
		);

		if ( ! empty( $choice['function_call'] ) ) {
			$message['function_call'] = $choice['function_call'];
		}

		if ( ! empty( $choice['tool_calls'] ) ) {
			$message['tool_calls'] = array_values( $choice['tool_calls'] );
		}

		return array(
			'index'         => $choice['index'],
			'message'       => $message,
			'finish_reason' => $choice['finish_reason'],
			'logprobs'      => $choice['logprobs'],
		);
	}
}
