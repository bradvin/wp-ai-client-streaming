<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Provider_Registry class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Provider_Registry', false ) ) {
	return;
}

/**
 * Registers provider-owned streaming modules once per request.
 *
 * @since 1.0.0
 */
class WP_AI_Client_Streaming_Provider_Registry {

	/**
	 * Whether provider modules have already been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers provider modules.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, string> $providers Provider module class names.
	 * @return void
	 */
	public static function register( array $providers ): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters provider streaming modules before registration.
			 *
			 * Provider module classes must implement
			 * WP_AI_Client_Streaming_Provider_Module_Interface.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int|string, string> $providers Provider module class names.
			 */
			$providers = apply_filters( 'wp_ai_client_stream_provider_modules', $providers );
		}

		if ( ! is_array( $providers ) ) {
			return;
		}

		foreach ( $providers as $provider ) {
			if ( ! is_string( $provider ) || ! is_subclass_of( $provider, 'WP_AI_Client_Streaming_Provider_Module_Interface' ) ) {
				continue;
			}

			$provider::register();
		}
	}
}
