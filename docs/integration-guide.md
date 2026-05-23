# Integration Guide

Date: 2026-04-18

## Bootstrap

Composer autoload registers each bundled package copy with `WP_AI_Client_Streaming_Package_Loader`. The loader waits until active plugins have loaded, selects the newest registered package version, and then loads the global `WP_AI_*` classes from that package copy.

Initialize the streaming discovery strategy on or after `plugins_loaded`:

```php
add_action(
	'plugins_loaded',
	static function (): void {
		if ( wp_ai_client_streaming_load() ) {
			WP_AI_Client_Streaming_Discovery_Strategy::init();
		}
	},
	PHP_INT_MAX
);
```

The initialization call is idempotent and only needs to run once per request. Direct references to package classes should also happen on or after `plugins_loaded`; the helper functions are available earlier as proxies, but they load the selected package before doing real work.

## Shared Package Version

Because this package uses global WordPress-style symbols, only one version can be active in a request. If several plugins include compatible package versions, the global loader chooses the highest semantic version and exposes diagnostics through:

```php
$packages = WP_AI_Client_Streaming_Package_Loader::get_registered_packages();
$version  = WP_AI_Client_Streaming_Package_Loader::get_loaded_version();
$path     = WP_AI_Client_Streaming_Package_Loader::get_loaded_path();
```

Plugins should require `bradvin/wp-ai-client-streaming:^1.0` or newer compatible releases so they participate in the shared-loader flow.

## Public Entry Points

### `wp_ai_client_stream_prompt()`

Creates a streaming-aware prompt builder:

```php
$result = wp_ai_client_stream_prompt(
	$prompt_messages,
	array(
		'streaming_enabled' => true,
		'on_event'          => static function ( WP_AI_Client_SSE_Event $event, array $context ) {
			// Handle parsed SSE events.
		},
	)
)
	->using_model_config( $model_config )
	->generate_result();
```

### `wp_ai_client_stream()`

Wraps an existing core prompt builder:

```php
$builder = wp_ai_client_prompt( $prompt_messages )->using_model_config( $model_config );
$result  = wp_ai_client_stream( $builder, array( 'streaming_enabled' => true ) )->generate_result();
```

### `WP_AI_Client_Streaming_Transport_Diagnostics`

Inspects the active registry and transporter:

```php
$diagnostics = WP_AI_Client_Streaming_Transport_Diagnostics::get_default_registry_diagnostics();
```

## Streaming Options

Supported `stream_args` keys:

- `mode`: `sse` or `raw`. Defaults to `sse`.
- `streaming_enabled`: master on/off switch. Defaults to `true`.
- `capture_body`: whether the response body should still be buffered and rebuilt for the final result. Defaults to `true`.
- `request_id`: explicit correlation ID for the matching request.
- `max_requests`: how many matching outbound requests inside the wrapped call should opt into streaming. Defaults to `1`.
- `request_matcher`: callable that decides whether the active streaming context should attach to a specific PSR-7 request.
- `payload_mutator`: callable that can mutate the decoded JSON payload before it is re-encoded and sent. Reusable provider request changes should use provider modules instead.
- `on_chunk`: callback for raw chunks.
- `on_event`: callback for parsed `WP_AI_Client_SSE_Event` objects.
- `on_complete`: callback after the HTTP response finishes.
- `on_error`: callback for transport errors.
- `should_continue`: callback/filter for aborting a stream early.
- `request_options`: a `RequestOptions` instance to apply to the wrapped generation call.
- `request_timeout`: shorthand timeout override in seconds.
- `connect_timeout`: shorthand connection-timeout override in seconds.
- `max_redirects`: shorthand redirect-limit override.

## Hook Reference

The transport emits these hooks:

- `wp_ai_client_stream_request_start`
- `wp_ai_client_stream_chunk`
- `wp_ai_client_stream_sse_event`
- `wp_ai_client_stream_complete`
- `wp_ai_client_stream_error`
- `wp_ai_client_stream_continue`

It also emits `requests-request.progress` while chunks are arriving so existing progress listeners can continue to work.

Provider modules and advanced integrations can use these filters:

- `wp_ai_client_stream_provider_modules`: replace or add provider modules.
- `wp_ai_client_stream_context_matches_request`: decide whether a streaming context should attach to a PSR-7 request.
- `wp_ai_client_stream_request_analysis`: add provider metadata before request preparation.
- `wp_ai_client_stream_prepare_request`: mutate the request URL, headers, or body before the transport builds WordPress HTTP args.
- `wp_ai_client_stream_response_contract`: declare the expected final response shape for captured streaming bodies.
- `wp_ai_client_stream_normalize_response_body`: normalize a captured response body before class-based normalizers run.
- `wp_ai_client_stream_response_normalizers`: add class-based response normalizers.

## Response Normalizers

When `capture_body` is enabled, the transport buffers SSE output and then asks registered normalizers to rebuild the non-streaming JSON response shape expected by the upstream provider parser.

Built-in provider modules register normalizers for:

- OpenAI Responses API streams.
- OpenAI-compatible chat completion streams, including OpenRouter-style chunks.
- Anthropic Messages API streams.
- Google Generate Content streams.

Add or replace normalizers with the `wp_ai_client_stream_response_normalizers` filter. A normalizer should implement `WP_AI_Client_Streaming_Response_Normalizer_Interface` and return `null` when it cannot handle the captured body. The filter receives the normalizer list and the streaming contract, including `expected_response_format` when a provider module declares one.

## Provider Modules

Provider-specific request matching, request mutation, response contracts, and normalizer registration live in provider modules under `includes/ai-client/providers/`.

Provider modules implement `WP_AI_Client_Streaming_Provider_Module_Interface` and register hooks from a static `register()` method. Add modules with the `wp_ai_client_stream_provider_modules` filter. Request changes should be implemented with `wp_ai_client_stream_prepare_request`; for example, the built-in Google module converts `generateContent` requests to `streamGenerateContent?alt=sse`.

### Provider Module API

Provider module classes are global WordPress-style classes. A module must implement:

```php
interface WP_AI_Client_Streaming_Provider_Module_Interface {
	public static function register(): void;
}
```

Register extra modules by filtering the class list:

```php
add_filter(
	'wp_ai_client_stream_provider_modules',
	static function ( array $providers ): array {
		$providers['acme'] = 'Acme_AI_Streaming_Provider';
		return $providers;
	}
);
```

The registry calls `register()` once per request. Provider modules should also guard their own `register()` method with a static boolean so direct calls stay idempotent.

Most provider modules register these filters:

- `wp_ai_client_stream_context_matches_request`: return `true` for a confident match, `false` only for an explicit veto, and `null` when undecided.
- `wp_ai_client_stream_request_analysis`: set provider metadata such as `provider`, `operation`, and optional parsed request details under `meta`.
- `wp_ai_client_stream_prepare_request`: return a prepared `url` and `analysis` array after provider URL, header, or body mutation.
- `wp_ai_client_stream_response_contract`: set provider response details such as `provider`, `operation`, and `expected_response_format`.
- `wp_ai_client_stream_response_normalizers`: add normalizer instances keyed by response format.

The transport validates filtered request and contract shapes. Invalid `wp_ai_client_stream_prepare_request` results are ignored, and unsafe URLs are validated after request preparation.

Minimal module skeleton:

```php
class Acme_AI_Streaming_Provider implements WP_AI_Client_Streaming_Provider_Module_Interface {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'wp_ai_client_stream_request_analysis', array( __CLASS__, 'analyze_request' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_prepare_request', array( __CLASS__, 'prepare_request' ), 10, 2 );
		add_filter( 'wp_ai_client_stream_response_contract', array( __CLASS__, 'response_contract' ), 10, 3 );
	}
}
```

## Matching Behavior

Provider modules first decide whether an active streaming context should attach to an outbound request. If no provider decides, the generic fallback attaches to mutating HTTP methods (`POST`, `PUT`, or `PATCH`) while a streaming context is active.

For more precise control, pass a custom `request_matcher`.

## Transport Contract

The package uses internal control headers to communicate between the prompt-builder context and the streaming HTTP adapter:

- `X-WP-AI-Client-Stream`
- `X-WP-AI-Client-Stream-Mode`
- `X-WP-AI-Client-Stream-Request-Id`
- `X-WP-AI-Client-Stream-Capture`

These headers are internal to the adapter. They are interpreted by the transport layer and are not intended as the preferred public integration API.

## Abort Example

Use `should_continue` to stop a stream once enough data has arrived:

```php
$first_text = null;

$result = wp_ai_client_stream_prompt(
	$prompt_messages,
	array(
		'on_event' => static function ( WP_AI_Client_SSE_Event $event ) use ( &$first_text ) {
			$payload = $event->get_json_data();
			$text    = $payload['choices'][0]['delta']['content'] ?? null;

			if ( is_string( $text ) && '' !== $text && null === $first_text ) {
				$first_text = $text;
			}
		},
		'should_continue' => static function ( bool $continue ) use ( &$first_text ) {
			return null === $first_text ? $continue : false;
		},
	)
)
	->using_model_config( $model_config )
	->generate_result();
```
