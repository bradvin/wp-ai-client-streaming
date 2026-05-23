<?php
/**
 * Loads the WordPress AI streaming adapter package.
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

$wp_ai_client_streaming_package_version = '1.0.0';
$wp_ai_client_streaming_package_files   = array(
	'includes/ai-client/adapters/class-wp-ai-client-sse-event.php',
	'includes/ai-client/adapters/class-wp-ai-client-sse-parser.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-interface.php',
	'includes/ai-client/providers/load.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-context.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-http-client.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php',
	'includes/ai-client/class-wp-ai-client-streaming-prompt-builder.php',
	'includes/ai-client.php',
);

if ( ! class_exists( 'WP_AI_Client_Streaming_Package_Loader', false ) ) {
	require_once __DIR__ . '/includes/class-wp-ai-client-streaming-package-loader.php';
}

WP_AI_Client_Streaming_Package_Loader::register(
	$wp_ai_client_streaming_package_version,
	__DIR__,
	$wp_ai_client_streaming_package_files
);

if ( ! function_exists( 'wp_ai_client_streaming_dependencies_available' ) ) {
	/**
	 * Returns whether the WordPress AI client dependencies required for streaming are loaded.
	 *
	 * @since 0.2.2
	 *
	 * @return bool
	 */
	function wp_ai_client_streaming_dependencies_available(): bool {
		return WP_AI_Client_Streaming_Package_Loader::dependencies_available();
	}
}

if ( ! function_exists( 'wp_ai_client_streaming_load' ) ) {
	/**
	 * Loads the newest registered streaming adapter package when available.
	 *
	 * @since 0.2.2
	 *
	 * @return bool
	 */
	function wp_ai_client_streaming_load(): bool {
		return WP_AI_Client_Streaming_Package_Loader::load();
	}
}

if ( ! function_exists( 'wp_ai_client_stream_prompt' ) ) {
	/**
	 * Starts a streaming-aware prompt builder.
	 *
	 * This proxy exists before the selected package version is loaded so wrapper
	 * plugins can safely detect the helper during bootstrap.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                                                       $prompt      Prompt input.
	 * @param array<string, mixed>                                        $stream_args Streaming arguments.
	 * @param \WordPress\AiClient\Providers\ProviderRegistry|null         $registry    Optional provider registry.
	 * @return WP_AI_Client_Streaming_Prompt_Builder
	 */
	function wp_ai_client_stream_prompt( $prompt = null, array $stream_args = array(), ?\WordPress\AiClient\Providers\ProviderRegistry $registry = null ): WP_AI_Client_Streaming_Prompt_Builder {
		if ( ! wp_ai_client_streaming_load() || ! class_exists( 'WP_AI_Client_Streaming_Prompt_Builder' ) ) {
			throw new RuntimeException(
				'The wp-ai-client-streaming package is not available yet. Call wp_ai_client_stream_prompt() after plugins_loaded once the WordPress AI client dependencies are loaded.'
			);
		}

		return new WP_AI_Client_Streaming_Prompt_Builder(
			new WP_AI_Client_Prompt_Builder(
				$registry ?? \WordPress\AiClient\AiClient::defaultRegistry(),
				$prompt
			),
			$stream_args
		);
	}
}

if ( ! function_exists( 'wp_ai_client_stream' ) ) {
	/**
	 * Wraps an existing prompt builder with streaming behavior.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder     Prompt builder.
	 * @param array<string, mixed>        $stream_args Streaming arguments.
	 * @return WP_AI_Client_Streaming_Prompt_Builder
	 */
	function wp_ai_client_stream( WP_AI_Client_Prompt_Builder $builder, array $stream_args = array() ): WP_AI_Client_Streaming_Prompt_Builder {
		if ( ! wp_ai_client_streaming_load() || ! class_exists( 'WP_AI_Client_Streaming_Prompt_Builder' ) ) {
			throw new RuntimeException(
				'The wp-ai-client-streaming package is not available yet. Call wp_ai_client_stream() after plugins_loaded once the WordPress AI client dependencies are loaded.'
			);
		}

		return WP_AI_Client_Streaming_Prompt_Builder::from_prompt_builder( $builder, $stream_args );
	}
}

WP_AI_Client_Streaming_Package_Loader::schedule();
WP_AI_Client_Streaming_Package_Loader::load();
