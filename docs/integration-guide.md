# Integration Guide

Date: 2026-04-18

## Bootstrap

Initialize the streaming discovery strategy early in your plugin bootstrap:

```php
WP_AI_Client_Streaming_Discovery_Strategy::init();
```

The initialization call is idempotent and only needs to run once per request.

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
- `inject_stream_parameter`: whether `"stream": true` should be injected into matching JSON payloads. Defaults to `true`.
- `request_id`: explicit correlation ID for the matching request.
- `max_requests`: how many matching outbound requests inside the wrapped call should opt into streaming. Defaults to `1`.
- `request_matcher`: callable that decides whether the active streaming context should attach to a specific PSR-7 request.
- `payload_mutator`: callable that can mutate the decoded JSON payload before it is re-encoded and sent.
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

## Matching Behavior

By default, the streaming context targets text-style JSON generation requests:

- HTTP methods `POST`, `PUT`, or `PATCH`
- requests whose payload contains `messages`, `input`, or `contents`
- requests that already ask for `text/event-stream`
- requests whose payload already contains `stream: true`

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
