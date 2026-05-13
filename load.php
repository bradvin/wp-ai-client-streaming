<?php
/**
 * Loads the WordPress AI streaming adapter package.
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( ! function_exists( 'wp_ai_client_streaming_dependencies_available' ) ) {
	/**
	 * Returns whether the WordPress AI client dependencies required for streaming are loaded.
	 *
	 * @since 0.2.2
	 *
	 * @return bool
	 */
	function wp_ai_client_streaming_dependencies_available(): bool {
		$classes = array(
			'WP_AI_Client_HTTP_Client',
			'WP_AI_Client_Prompt_Builder',
			'WordPress\\AiClient\\Providers\\Http\\Abstracts\\AbstractClientDiscoveryStrategy',
			'WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions',
			'WordPress\\AiClient\\Providers\\ProviderRegistry',
			'WordPress\\AiClientDependencies\\Nyholm\\Psr7\\Factory\\Psr17Factory',
		);

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				return false;
			}
		}

		$interfaces = array(
			'WordPress\\AiClient\\Providers\\Http\\Contracts\\ClientWithOptionsInterface',
			'WordPress\\AiClientDependencies\\Psr\\Http\\Client\\ClientInterface',
			'WordPress\\AiClientDependencies\\Psr\\Http\\Message\\RequestInterface',
			'WordPress\\AiClientDependencies\\Psr\\Http\\Message\\ResponseFactoryInterface',
			'WordPress\\AiClientDependencies\\Psr\\Http\\Message\\ResponseInterface',
			'WordPress\\AiClientDependencies\\Psr\\Http\\Message\\StreamFactoryInterface',
		);

		foreach ( $interfaces as $interface ) {
			if ( ! interface_exists( $interface ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'wp_ai_client_streaming_load' ) ) {
	/**
	 * Loads the streaming adapter when the WordPress AI client dependency stack is available.
	 *
	 * @since 0.2.2
	 *
	 * @return bool
	 */
	function wp_ai_client_streaming_load(): bool {
		static $loaded = false;

		if ( $loaded ) {
			return true;
		}

		if ( ! wp_ai_client_streaming_dependencies_available() ) {
			return false;
		}

		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-sse-event.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-sse-parser.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-context.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-client.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php';
		require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php';
		require_once __DIR__ . '/includes/ai-client/class-wp-ai-client-streaming-prompt-builder.php';
		require_once __DIR__ . '/includes/ai-client.php';

		$loaded = true;

		return true;
	}
}

if ( ! wp_ai_client_streaming_load() && function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'wp_ai_client_streaming_load', PHP_INT_MAX );
	add_action( 'init', 'wp_ai_client_streaming_load', 0 );
}
