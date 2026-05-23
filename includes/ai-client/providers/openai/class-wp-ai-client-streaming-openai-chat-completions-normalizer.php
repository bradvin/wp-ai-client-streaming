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

		$normalized = $this->normalize_chat_completion_events( $events );

		if ( is_string( $normalized ) ) {
			return $normalized;
		}

		if ( $this->expects_chat_completions_response( $contract ) ) {
			return $this->normalize_responses_events_as_chat_completion( $events, $contract );
		}

		return null;
	}

	/**
	 * Normalizes OpenAI-compatible chat completion events.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, WP_AI_Client_SSE_Event> $events Parsed SSE events.
	 * @return string|null Normalized JSON response body, or null when unsupported.
	 */
	private function normalize_chat_completion_events( array $events ): ?string {
		$response = array();
		$choices  = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) || ! isset( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
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
	 * Normalizes OpenAI Responses API events into a chat completions response.
	 *
	 * Some OpenAI-compatible gateways can emit Responses-style stream events even
	 * when the caller used a chat-completions model parser. In that case the final
	 * buffered body still needs to expose `choices`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, WP_AI_Client_SSE_Event> $events   Parsed SSE events.
	 * @param array<string, mixed>               $contract Streaming contract.
	 * @return string|null Normalized JSON response body, or null when unsupported.
	 */
	private function normalize_responses_events_as_chat_completion( array $events, array $contract ): ?string {
		$response          = array(
			'object' => 'chat.completion',
		);
		$choice            = $this->create_empty_choice( 0 );
		$latest_response   = null;
		$terminal_response = null;
		$has_response_data = false;
		$has_delta_text    = false;

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) ) {
				continue;
			}

			$type = $this->get_event_type( $event, $data );

			if ( isset( $data['response'] ) && is_array( $data['response'] ) ) {
				$has_response_data = true;
				$latest_response   = $data['response'];
				$this->copy_response_metadata_from_response_object( $response, $data['response'] );

				if ( in_array( $type, array( 'response.completed', 'response.failed', 'response.incomplete' ), true ) ) {
					$terminal_response       = $data['response'];
					$choice['finish_reason'] = $this->get_finish_reason_from_response_object( $data['response'] );
				}
			}

			if ( 'response.output_text.delta' === $type && isset( $data['delta'] ) && is_string( $data['delta'] ) ) {
				$choice['content'] .= $data['delta'];
				$has_delta_text     = true;
				continue;
			}

			if ( 'response.output_text.done' === $type && ! $has_delta_text && isset( $data['text'] ) && is_string( $data['text'] ) ) {
				$choice['content'] = $data['text'];
				continue;
			}

			if ( 'response.reasoning_summary_text.delta' === $type && isset( $data['delta'] ) && is_string( $data['delta'] ) ) {
				$choice['reasoning_content'] .= $data['delta'];
				continue;
			}

			if ( 'response.reasoning_summary_text.done' === $type && '' === $choice['reasoning_content'] ) {
				foreach ( array( 'text', 'summary', 'content' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
						$choice['reasoning_content'] = $data[ $key ];
						break;
					}
				}
			}
		}

		$final_response = is_array( $terminal_response ) ? $terminal_response : $latest_response;

		if ( is_array( $final_response ) ) {
			$this->copy_response_metadata_from_response_object( $response, $final_response );
			$choice['finish_reason'] = $this->get_finish_reason_from_response_object( $final_response );

			if ( '' === $choice['content'] ) {
				$choice['content'] = $this->extract_response_output_text( $final_response );
			}
		}

		if ( empty( $response['id'] ) && ! empty( $contract['request_id'] ) && is_string( $contract['request_id'] ) ) {
			$response['id'] = $contract['request_id'];
		}

		if ( '' === $choice['content'] && '' === $choice['reasoning_content'] && ! $has_response_data ) {
			return null;
		}

		$response['choices'] = array( $this->finalize_choice( $choice ) );

		$json = wp_json_encode( $response );

		return false !== $json && '' !== $json ? $json : null;
	}

	/**
	 * Returns whether the caller expects a chat-completions response object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return bool
	 */
	private function expects_chat_completions_response( array $contract ): bool {
		if ( isset( $contract['expected_response_format'] ) && 'openai-chat-completions' === $contract['expected_response_format'] ) {
			return true;
		}

		return isset( $contract['request_path'] )
			&& is_string( $contract['request_path'] )
			&& false !== strpos( strtolower( $contract['request_path'] ), '/chat/completions' );
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
	 * Copies chat-completion metadata from an OpenAI Responses response object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $response        Chat-completions response accumulator.
	 * @param array<string, mixed> $response_object Responses API response object.
	 * @return void
	 */
	private function copy_response_metadata_from_response_object( array &$response, array $response_object ): void {
		foreach ( array( 'id', 'model' ) as $key ) {
			if ( isset( $response_object[ $key ] ) && is_string( $response_object[ $key ] ) ) {
				$response[ $key ] = $response_object[ $key ];
			}
		}

		if ( isset( $response_object['created'] ) && is_numeric( $response_object['created'] ) ) {
			$response['created'] = (int) $response_object['created'];
		} elseif ( isset( $response_object['created_at'] ) && is_numeric( $response_object['created_at'] ) ) {
			$response['created'] = (int) $response_object['created_at'];
		}

		if ( isset( $response_object['usage'] ) && is_array( $response_object['usage'] ) ) {
			$response['usage'] = $this->normalize_response_usage( $response_object['usage'] );
		}
	}

	/**
	 * Converts Responses API usage keys to OpenAI-compatible chat usage keys.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $usage Usage data.
	 * @return array<string, mixed>
	 */
	private function normalize_response_usage( array $usage ): array {
		$prompt_tokens     = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
		$completion_tokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
		$total_tokens      = $usage['total_tokens'] ?? ( (int) $prompt_tokens + (int) $completion_tokens );

		return array_merge(
			$usage,
			array(
				'prompt_tokens'     => (int) $prompt_tokens,
				'completion_tokens' => (int) $completion_tokens,
				'total_tokens'      => (int) $total_tokens,
			)
		);
	}

	/**
	 * Maps an OpenAI Responses status to a chat-completions finish reason.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $response_object Responses API response object.
	 * @return string
	 */
	private function get_finish_reason_from_response_object( array $response_object ): string {
		$status = isset( $response_object['status'] ) && is_string( $response_object['status'] )
			? strtolower( $response_object['status'] )
			: '';

		if ( 'incomplete' === $status ) {
			return 'length';
		}

		if ( 'failed' === $status ) {
			return 'content_filter';
		}

		return 'stop';
	}

	/**
	 * Extracts final assistant text from an OpenAI Responses response object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $response_object Responses API response object.
	 * @return string
	 */
	private function extract_response_output_text( array $response_object ): string {
		if ( isset( $response_object['output_text'] ) && is_string( $response_object['output_text'] ) ) {
			return $response_object['output_text'];
		}

		if ( empty( $response_object['output'] ) || ! is_array( $response_object['output'] ) ) {
			return '';
		}

		$text = '';

		foreach ( $response_object['output'] as $output_item ) {
			if ( ! is_array( $output_item ) ) {
				continue;
			}

			if ( isset( $output_item['content'] ) && is_string( $output_item['content'] ) ) {
				$text .= $output_item['content'];
				continue;
			}

			if ( empty( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
				continue;
			}

			foreach ( $output_item['content'] as $content_part ) {
				if ( ! is_array( $content_part ) ) {
					continue;
				}

				foreach ( array( 'text', 'content', 'value' ) as $key ) {
					if ( isset( $content_part[ $key ] ) && is_string( $content_part[ $key ] ) ) {
						$text .= $content_part[ $key ];
						break;
					}
				}
			}
		}

		return $text;
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
			'index'             => $index,
			'role'              => 'assistant',
			'content'           => '',
			'reasoning_content' => '',
			'finish_reason'     => null,
			'logprobs'          => null,
			'function_call'     => array(),
			'tool_calls'        => array(),
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

		foreach ( array( 'reasoning_content', 'reasoning', 'reasoning_summary' ) as $key ) {
			if ( isset( $delta[ $key ] ) && is_string( $delta[ $key ] ) ) {
				$choice['reasoning_content'] .= $delta[ $key ];
			}
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

		if ( '' !== $choice['reasoning_content'] ) {
			$message['reasoning_content'] = $choice['reasoning_content'];
		}

		if ( ! empty( $choice['function_call'] ) ) {
			$message['function_call'] = $choice['function_call'];
		}

		if ( ! empty( $choice['tool_calls'] ) ) {
			$message['tool_calls'] = array_values( $choice['tool_calls'] );
		}

		return array(
			'index'         => $choice['index'],
			'message'       => $message,
			'finish_reason' => is_string( $choice['finish_reason'] ) && '' !== $choice['finish_reason']
				? $choice['finish_reason']
				: 'stop',
			'logprobs'      => $choice['logprobs'],
		);
	}
}
