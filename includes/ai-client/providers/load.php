<?php
/**
 * Loads built-in WordPress AI streaming provider modules.
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

require_once __DIR__ . '/class-wp-ai-client-streaming-provider-module-interface.php';
require_once __DIR__ . '/class-wp-ai-client-streaming-provider-registry.php';
require_once __DIR__ . '/openai/class-wp-ai-client-streaming-openai-provider.php';
require_once __DIR__ . '/anthropic/class-wp-ai-client-streaming-anthropic-provider.php';
require_once __DIR__ . '/google/class-wp-ai-client-streaming-google-provider.php';

WP_AI_Client_Streaming_Provider_Registry::register(
	array(
		'google'    => 'WP_AI_Client_Streaming_Google_Provider',
		'anthropic' => 'WP_AI_Client_Streaming_Anthropic_Provider',
		'openai'    => 'WP_AI_Client_Streaming_OpenAI_Provider',
	)
);
