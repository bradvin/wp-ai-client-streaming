<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Google_Generate_Content_Normalizer class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Google_Generate_Content_Normalizer', false ) ) {
	return;
}

/**
 * Normalizes Google Generate Content SSE streams into the final response object.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Google_Generate_Content_Normalizer implements WP_AI_Client_Streaming_Response_Normalizer_Interface {

	/**
	 * Normalizes a captured Google Generate Content SSE response body.
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
		$candidates      = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) || empty( $data['candidates'] ) || ! is_array( $data['candidates'] ) ) {
				continue;
			}

			$this->copy_response_metadata( $response, $data );

			foreach ( $data['candidates'] as $fallback_index => $candidate_delta ) {
				if ( ! is_array( $candidate_delta ) ) {
					continue;
				}

				$index = isset( $candidate_delta['index'] ) && is_numeric( $candidate_delta['index'] )
					? (int) $candidate_delta['index']
					: (int) $fallback_index;

				if ( ! isset( $candidates[ $index ] ) ) {
					$candidates[ $index ] = $this->create_empty_candidate();
				}

				$candidates[ $index ] = $this->merge_candidate_delta( $candidates[ $index ], $candidate_delta );
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		ksort( $candidates );

		$response['candidates'] = array_values(
			array_map(
				array( $this, 'finalize_candidate' ),
				$candidates
			)
		);

		if ( class_exists( 'WP_AI_Client_Streaming_Google_Provider', false ) ) {
			WP_AI_Client_Streaming_Google_Provider::capture_thought_signatures_from_response( $response, $contract );
		}

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
		foreach ( array( 'id', 'modelVersion', 'promptFeedback', 'usageMetadata' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$response[ $key ] = $data[ $key ];
			}
		}
	}

	/**
	 * Creates an empty candidate accumulator.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	private function create_empty_candidate(): array {
		return array(
			'content'      => array(
				'role'  => 'model',
				'parts' => array(),
			),
			'finishReason' => null,
		);
	}

	/**
	 * Merges a streaming candidate delta into an accumulated candidate.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $candidate       Candidate accumulator.
	 * @param array<string, mixed> $candidate_delta Candidate chunk.
	 * @return array<string, mixed>
	 */
	private function merge_candidate_delta( array $candidate, array $candidate_delta ): array {
		foreach ( $candidate_delta as $key => $value ) {
			if ( in_array( $key, array( 'index', 'content' ), true ) ) {
				continue;
			}

			$candidate[ $key ] = $value;
		}

		if ( isset( $candidate_delta['content'] ) && is_array( $candidate_delta['content'] ) ) {
			$candidate['content'] = $this->merge_content_delta( $candidate['content'], $candidate_delta['content'] );
		}

		return $candidate;
	}

	/**
	 * Merges a streaming content delta into an accumulated content object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $content Content accumulator.
	 * @param array<string, mixed> $delta   Content chunk.
	 * @return array<string, mixed>
	 */
	private function merge_content_delta( array $content, array $delta ): array {
		if ( isset( $delta['role'] ) && is_string( $delta['role'] ) && '' !== $delta['role'] ) {
			$content['role'] = $delta['role'];
		}

		if ( ! empty( $delta['parts'] ) && is_array( $delta['parts'] ) ) {
			foreach ( $delta['parts'] as $part_index => $part_delta ) {
				if ( ! is_array( $part_delta ) ) {
					continue;
				}

				$index = (int) $part_index;

				if ( ! isset( $content['parts'][ $index ] ) || ! is_array( $content['parts'][ $index ] ) ) {
					$content['parts'][ $index ] = array();
				}

				$content['parts'][ $index ] = $this->merge_part_delta( $content['parts'][ $index ], $part_delta );
			}
		}

		return $content;
	}

	/**
	 * Merges a streaming part delta into an accumulated part.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $part  Part accumulator.
	 * @param array<string, mixed> $delta Part chunk.
	 * @return array<string, mixed>
	 */
	private function merge_part_delta( array $part, array $delta ): array {
		if ( isset( $delta['text'] ) && is_string( $delta['text'] ) ) {
			$part['text'] = ( $part['text'] ?? '' ) . $delta['text'];
		}

		foreach ( array( 'inlineData', 'fileData', 'functionCall' ) as $key ) {
			if ( isset( $delta[ $key ] ) ) {
				$part[ $key ] = $delta[ $key ];
			}
		}

		if ( isset( $delta['thoughtSignature'] ) && is_string( $delta['thoughtSignature'] ) ) {
			$part['thoughtSignature'] = $delta['thoughtSignature'];
		} elseif ( isset( $delta['thought_signature'] ) && is_string( $delta['thought_signature'] ) ) {
			$part['thoughtSignature'] = $delta['thought_signature'];
		}

		if ( isset( $delta['thought'] ) ) {
			$part['thought'] = $delta['thought'];
		}

		return $part;
	}

	/**
	 * Converts an accumulated candidate into the non-streaming response shape.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $candidate Candidate accumulator.
	 * @return array<string, mixed>
	 */
	private function finalize_candidate( array $candidate ): array {
		if ( empty( $candidate['finishReason'] ) ) {
			$candidate['finishReason'] = 'STOP';
		}

		if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
			ksort( $candidate['content']['parts'] );
			$candidate['content']['parts'] = array_values( $candidate['content']['parts'] );
		}

		return $candidate;
	}
}
