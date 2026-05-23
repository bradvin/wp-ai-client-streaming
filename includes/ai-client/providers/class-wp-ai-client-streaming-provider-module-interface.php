<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Provider_Module_Interface interface
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( interface_exists( 'WP_AI_Client_Streaming_Provider_Module_Interface', false ) ) {
	return;
}

/**
 * Contract for provider-owned streaming behavior modules.
 *
 * @since 1.0.0
 */
interface WP_AI_Client_Streaming_Provider_Module_Interface {

	/**
	 * Registers provider hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void;
}
