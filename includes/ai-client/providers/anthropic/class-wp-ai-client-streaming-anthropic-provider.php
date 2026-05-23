<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Anthropic_Provider class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Anthropic_Provider', false ) ) {
	return;
}

/**
 * Registers Anthropic streaming behavior.
 *
 * @since 1.0.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Anthropic_Provider implements WP_AI_Client_Streaming_Provider_Module_Interface {

	/**
	 * Whether provider hooks have already been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers Anthropic provider hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once __DIR__ . '/class-wp-ai-client-streaming-anthropic-messages-normalizer.php';

		add_filter( 'wp_ai_client_stream_response_normalizers', array( __CLASS__, 'register_normalizers' ), 10, 2 );
	}

	/**
	 * Registers Anthropic response normalizers.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $normalizers Registered normalizers.
	 * @param array<string, mixed>     $contract    Streaming contract.
	 * @return array<int|string, mixed>
	 */
	public static function register_normalizers( array $normalizers, array $contract ): array {
		$normalizers['anthropic-messages'] = new WP_AI_Client_Streaming_Anthropic_Messages_Normalizer();

		return $normalizers;
	}
}
