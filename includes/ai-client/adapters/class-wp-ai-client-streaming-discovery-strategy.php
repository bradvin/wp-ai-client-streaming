<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Discovery_Strategy class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\Providers\Http\Abstracts\AbstractClientDiscoveryStrategy;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory;
use WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface;

if ( class_exists( 'WP_AI_Client_Streaming_Discovery_Strategy', false ) ) {
	return;
}

/**
 * Discovery strategy that prepends the streaming-aware WordPress HTTP adapter.
 *
 * @since 0.2.0
 * @internal Intended only to register the streaming adapter with HTTPlug discovery.
 * @access private
 */
class WP_AI_Client_Streaming_Discovery_Strategy extends AbstractClientDiscoveryStrategy {

	/**
	 * Whether the discovery strategy has already been registered.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Registers the discovery strategy once per request.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::$initialized ) {
			self::$initialized = true;

			parent::init();
		}

		self::sync_default_registry_transporter();
	}

	/**
	 * Creates the streaming-aware PSR-18 client.
	 *
	 * @since 0.2.0
	 *
	 * @param Psr17Factory $psr17_factory PSR-17 factory.
	 * @return ClientInterface
	 */
	protected static function createClient( Psr17Factory $psr17_factory ): ClientInterface {
		return new WP_AI_Client_Streaming_HTTP_Client( $psr17_factory, $psr17_factory );
	}

	/**
	 * Synchronizes an already-instantiated default registry to the streaming transporter.
	 *
	 * This covers the case where another plugin touches AiClient::defaultRegistry()
	 * before the streaming discovery strategy is initialized.
	 *
	 * @since 0.2.1
	 *
	 * @return void
	 */
	private static function sync_default_registry_transporter(): void {
		$registry = self::get_initialized_default_registry();

		if ( ! $registry instanceof ProviderRegistry || self::registry_uses_streaming_client( $registry ) ) {
			return;
		}

		try {
			$registry->setHttpTransporter( HttpTransporterFactory::createTransporter() );
		} catch ( \Throwable $throwable ) {
			// Leave the existing transporter in place if the streaming transporter cannot be created.
		}
	}

	/**
	 * Returns the default registry when it has already been initialized.
	 *
	 * @since 0.2.1
	 *
	 * @return ProviderRegistry|null
	 */
	private static function get_initialized_default_registry(): ?ProviderRegistry {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return null;
		}

		try {
			$reflection = new \ReflectionClass( '\WordPress\AiClient\AiClient' );

			if ( ! $reflection->hasProperty( 'defaultRegistry' ) ) {
				return null;
			}

			$property = $reflection->getProperty( 'defaultRegistry' );
			$property->setAccessible( true );
			$registry = $property->getValue();

			return $registry instanceof ProviderRegistry ? $registry : null;
		} catch ( \Throwable $throwable ) {
			return null;
		}
	}

	/**
	 * Returns whether the registry already uses the streaming client.
	 *
	 * @since 0.2.1
	 *
	 * @param ProviderRegistry $registry Provider registry instance.
	 * @return bool
	 */
	private static function registry_uses_streaming_client( ProviderRegistry $registry ): bool {
		if ( ! method_exists( $registry, 'getHttpTransporter' ) ) {
			return false;
		}

		try {
			$transporter = $registry->getHttpTransporter();
			$client      = self::read_object_property( $transporter, 'client' );

			return is_object( $client ) && $client instanceof \WP_AI_Client_Streaming_HTTP_Client;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Reads an object property via reflection.
	 *
	 * @since 0.2.1
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 * @return mixed|null
	 */
	private static function read_object_property( $object, string $name ) {
		if ( ! is_object( $object ) ) {
			return null;
		}

		$reflection = new \ReflectionObject( $object );

		while ( $reflection ) {
			if ( $reflection->hasProperty( $name ) ) {
				$property = $reflection->getProperty( $name );
				$property->setAccessible( true );

				return $property->getValue( $object );
			}

			$reflection = $reflection->getParentClass();
		}

		return null;
	}
}
