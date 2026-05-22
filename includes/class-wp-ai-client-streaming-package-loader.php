<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Package_Loader class
 *
 * @package WordPress
 * @subpackage AI
 * @since 1.0.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Package_Loader', false ) ) {
	return;
}

/**
 * Coordinates multiple bundled copies of the streaming package.
 *
 * The package exposes global WordPress-style classes, so only one copy can be
 * active in a request. This loader lets each bundled copy register itself and
 * defers class loading until active plugins have finished loading, so the newest
 * registered package version wins.
 *
 * @since 1.0.0
 */
class WP_AI_Client_Streaming_Package_Loader {

	/**
	 * Registered package candidates.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>
	 */
	private static array $packages = array();

	/**
	 * Registration counter used as a stable same-version tiebreaker.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private static int $registration_index = 0;

	/**
	 * Whether package classes have been loaded.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Loaded package version.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private static ?string $loaded_version = null;

	/**
	 * Loaded package path.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private static ?string $loaded_path = null;

	/**
	 * Whether WordPress retry hooks have been registered.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Registers a bundled package copy as a load candidate.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $version Package version.
	 * @param string            $path    Absolute package root path.
	 * @param array<int,string> $files   Package files to require when selected.
	 * @return void
	 */
	public static function register( string $version, string $path, array $files = array() ): void {
		$path      = rtrim( $path, '/\\' );
		$real_path = realpath( $path );

		if ( false !== $real_path && is_dir( $real_path ) ) {
			$path = $real_path;
		}

		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}

		self::$registration_index++;

		self::$packages[ $path ] = array(
			'version'            => $version,
			'normalized_version' => self::normalize_version( $version ),
			'path'               => $path,
			'files'              => self::normalize_files( $files ),
			'index'              => self::$registration_index,
		);

		if ( function_exists( 'do_action' ) ) {
			do_action( 'wp_ai_client_streaming_package_registered', self::$packages[ $path ] );
		}
	}

	/**
	 * Loads the newest registered package once dependencies are available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when package classes are loaded.
	 */
	public static function load(): bool {
		if ( self::$loaded ) {
			return true;
		}

		if ( self::should_defer_for_plugin_discovery() ) {
			self::schedule();
			return false;
		}

		if ( ! self::dependencies_available() ) {
			self::schedule();
			return false;
		}

		$package = self::get_latest_package();

		if ( null === $package ) {
			return false;
		}

		foreach ( $package['files'] as $file ) {
			$file_path = $package['path'] . '/' . $file;

			if ( ! is_readable( $file_path ) ) {
				return false;
			}

			require_once $file_path;
		}

		self::$loaded         = true;
		self::$loaded_version = $package['version'];
		self::$loaded_path    = $package['path'];

		if ( function_exists( 'do_action' ) ) {
			do_action( 'wp_ai_client_streaming_loaded', $package );
		}

		return true;
	}

	/**
	 * Returns whether WordPress AI client dependencies are available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function dependencies_available(): bool {
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

	/**
	 * Registers WordPress hooks that retry loading when safe.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( self::$hooks_registered || ! function_exists( 'add_action' ) ) {
			return;
		}

		self::$hooks_registered = true;

		add_action( 'plugins_loaded', array( __CLASS__, 'load' ), PHP_INT_MAX );
		add_action( 'init', array( __CLASS__, 'load' ), 0 );
	}

	/**
	 * Returns all registered package candidates.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_registered_packages(): array {
		return array_values( self::$packages );
	}

	/**
	 * Returns the newest registered package candidate.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_package(): ?array {
		if ( empty( self::$packages ) ) {
			return null;
		}

		$packages = array_values( self::$packages );

		usort(
			$packages,
			static function ( array $a, array $b ): int {
				$version_compare = version_compare( $b['normalized_version'], $a['normalized_version'] );

				if ( 0 !== $version_compare ) {
					return $version_compare;
				}

				return $b['index'] <=> $a['index'];
			}
		);

		return $packages[0];
	}

	/**
	 * Returns the loaded package version.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public static function get_loaded_version(): ?string {
		return self::$loaded_version;
	}

	/**
	 * Returns the loaded package path.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public static function get_loaded_path(): ?string {
		return self::$loaded_path;
	}

	/**
	 * Returns whether package class loading is deferred until all plugins register.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function should_defer_for_plugin_discovery(): bool {
		return function_exists( 'add_action' )
			&& function_exists( 'did_action' )
			&& ! did_action( 'plugins_loaded' );
	}

	/**
	 * Normalizes a package version for comparison.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Package version.
	 * @return string
	 */
	private static function normalize_version( string $version ): string {
		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
		}

		if ( preg_match( '/^(\d+)\.(\d+)/', $version, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.0';
		}

		if ( preg_match( '/^(\d+)/', $version, $matches ) ) {
			return $matches[1] . '.0.0';
		}

		return '0.0.0';
	}

	/**
	 * Normalizes package file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,string> $files Package files.
	 * @return array<int,string>
	 */
	private static function normalize_files( array $files ): array {
		if ( empty( $files ) ) {
			$files = self::get_default_package_files();
		}

		$normalized = array();

		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				continue;
			}

			$normalized[] = ltrim( $file, '/\\' );
		}

		return $normalized;
	}

	/**
	 * Returns the default package file list.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,string>
	 */
	private static function get_default_package_files(): array {
		return array(
			'includes/ai-client/adapters/class-wp-ai-client-sse-event.php',
			'includes/ai-client/adapters/class-wp-ai-client-sse-parser.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-interface.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-openai-responses-normalizer.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-openai-chat-completions-normalizer.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-anthropic-messages-normalizer.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-google-generate-content-normalizer.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-context.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-http-client.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php',
			'includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php',
			'includes/ai-client/class-wp-ai-client-streaming-prompt-builder.php',
			'includes/ai-client.php',
		);
	}
}
