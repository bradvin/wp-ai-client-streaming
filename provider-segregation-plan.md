# Provider Segregation Plan

Date: 2026-05-23

## Goal

Move provider-specific streaming behavior out of the core transport and adapter
layer. The core package should own only the generic streaming runtime:

- explicit streaming opt-in
- HTTP transport execution
- raw chunk and SSE event emission
- generic request and response extension points
- stable provider module contracts
- strict boundaries between core transport code and provider behavior

Provider-specific concepts such as OpenAI response formats, Anthropic message
events, Google `streamGenerateContent`, request-body quirks, and final response
normalization should live under `includes/ai-client/providers/`.

This is allowed to be a breaking refactor. Do not preserve provider-specific
internal APIs just for compatibility. Keep behavior that users need, but move the
implementation to cleaner boundaries.

## Breaking-Change Policy

This plan does not require backward compatibility for internal provider APIs.
Use that freedom to remove awkward compatibility shims.

Allowed breaking changes:

- Remove `WP_AI_Client_Streaming_Provider_Request_Override_Interface`.
- Remove `WP_AI_Client_Streaming_Provider_Request_Override_Registry`.
- Move provider normalizer files out of `adapters/`.
- Rename provider implementation classes if that makes ownership clearer.
- Remove broad provider detection from core transport and context classes.
- Replace old provider override mechanisms with the new generic request filters.

Behavior to preserve:

- Streaming helper usage should still trigger streaming requests.
- Built-in OpenAI, Anthropic, and Google support should still work.
- Chunk, SSE event, complete, error, and continue hooks should still fire.
- Final buffered responses should still normalize into the shapes expected by
  upstream provider parsers when `capture_body` is enabled.

## Current Coupling To Remove

Core currently knows about providers in these places:

- `includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php`
  constructs OpenAI, Anthropic, and Google normalizers directly.
- `includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php`
  detects provider response formats from URL paths and JSON body keys:
  `/chat/completions`, `/responses`, `/messages`, `generateContent`,
  `messages`, and `contents`.
- `includes/ai-client/providers/class-wp-ai-client-streaming-provider-request-override-registry.php`
  constructs the Google Generate Content request override directly.
- `includes/ai-client/adapters/class-wp-ai-client-streaming-context.php`
  uses provider-shaped request matching and payload mutation assumptions:
  `messages`, `input`, `contents`, and `stream`.
- `load.php` and
  `includes/class-wp-ai-client-streaming-package-loader.php` list individual
  provider implementation files in the core package manifest.

The target is not to remove built-in provider support. The target is to make all
built-in provider support register from the central provider folder.

## Target Directory Layout

```text
includes/ai-client/
  adapters/
    class-wp-ai-client-sse-event.php
    class-wp-ai-client-sse-parser.php
    class-wp-ai-client-streaming-context.php
    class-wp-ai-client-streaming-discovery-strategy.php
    class-wp-ai-client-streaming-http-client.php
    class-wp-ai-client-streaming-http-service.php
    class-wp-ai-client-streaming-response-normalizer-interface.php
    class-wp-ai-client-streaming-response-normalizer-registry.php
    class-wp-ai-client-streaming-transport-diagnostics.php

  providers/
    load.php
    class-wp-ai-client-streaming-provider-module-interface.php
    class-wp-ai-client-streaming-provider-registry.php

    openai/
      class-wp-ai-client-streaming-openai-provider.php
      class-wp-ai-client-streaming-openai-responses-normalizer.php
      class-wp-ai-client-streaming-openai-chat-completions-normalizer.php

    anthropic/
      class-wp-ai-client-streaming-anthropic-provider.php
      class-wp-ai-client-streaming-anthropic-messages-normalizer.php

    google/
      class-wp-ai-client-streaming-google-provider.php
      class-wp-ai-client-streaming-google-generate-content-normalizer.php
```

`includes/ai-client/providers/load.php` is the only provider-specific file that
the core package manifest should load. That file can require provider registry,
provider contracts, and built-in provider modules.

The core loader should not list `openai`, `anthropic`, or `google` files
directly. Provider names are allowed inside the provider folder.

## Responsibility Boundaries

### Core Transport

Core transport code should:

- detect explicit streaming opt-in from context and control headers
- preserve generic WordPress HTTP behavior in the cURL streaming path
- emit lifecycle hooks:
  - `wp_ai_client_stream_request_start`
  - `wp_ai_client_stream_chunk`
  - `wp_ai_client_stream_sse_event`
  - `wp_ai_client_stream_complete`
  - `wp_ai_client_stream_error`
  - `wp_ai_client_stream_continue`
- apply generic filters that let provider modules analyze and mutate requests
- normalize captured response bodies by delegating to filters and registered
  normalizers

Core transport code should not:

- check provider hostnames
- check provider endpoint names such as `/responses`, `/messages`,
  `/chat/completions`, or `generateContent`
- inspect provider payload keys such as `messages`, `input`, or `contents`
- inject provider payload keys such as `stream`
- construct provider normalizers
- construct provider request override objects
- contain provider-specific response-format keys as defaults

### Provider Modules

Provider modules should:

- register their hooks from `includes/ai-client/providers/`
- identify requests they understand
- decide whether a request should be stream-enabled
- mutate request URLs, headers, or bodies for provider streaming APIs
- declare the expected final response format
- register response normalizers
- own provider event names and response reconstruction logic

Provider modules should not:

- replace the core HTTP execution path
- reach into cURL setup directly
- depend on core transport knowing provider names
- hard-fail unknown provider variants unless continuing would be unsafe

## Core Hook Points

These hooks are the primary integration surface between core and providers.

### `wp_ai_client_stream_context_matches_request`

Runs while a streaming context is deciding whether to attach to a PSR-7 request.
This removes provider payload-key checks from the core context class.

```php
$matched = apply_filters(
	'wp_ai_client_stream_context_matches_request',
	null,
	$request,
	$headers,
	$body,
	$context
);
```

Arguments:

- `bool|null $matched`: `null` means the provider did not decide.
- `object $request`: PSR-7 request.
- `array $headers`: Request headers.
- `string|null $body`: Request body.
- `array $context`: Active streaming context.

Rules:

- The first provider that can confidently match should return `true`.
- Providers that know a request is not theirs should return `null`, not `false`.
- `false` should be reserved for an explicit veto.
- If all providers return `null`, core can use a conservative generic fallback:
  stream-enabled context plus a mutating HTTP method.

### `wp_ai_client_stream_request_analysis`

Runs after the transport builds the initial analysis array and before request
mutation.

```php
$analysis = apply_filters(
	'wp_ai_client_stream_request_analysis',
	$analysis,
	$request
);
```

Expected `$analysis` shape:

```php
array(
	'headers'  => array(),
	'body'     => null,
	'contract' => array(
		'enabled'      => true,
		'mode'         => 'sse',
		'capture_body' => true,
		'request_id'   => '...',
	),
	'provider'  => null,
	'operation' => null,
	'meta'      => array(),
)
```

Providers use this to add metadata such as `provider`, `operation`, endpoint
family, or parsed JSON payload. Core should validate that `headers`, `body`, and
`contract` remain present after filters run.

### `wp_ai_client_stream_prepare_request`

Runs before WordPress HTTP args are prepared. This is the replacement for the
current provider request override system.

```php
$prepared = apply_filters(
	'wp_ai_client_stream_prepare_request',
	array(
		'url'      => $url,
		'analysis' => $analysis,
	),
	$request
);
```

Providers use this to:

- convert non-streaming endpoints to streaming endpoints
- add or remove provider-specific JSON body fields
- add provider-specific `Accept` or feature headers
- update `$analysis['body']` after mutation

Validation:

- Ignore returned values without a string `url`.
- Ignore returned values without an array `analysis`.
- Re-run unsafe URL validation after this filter.

### `wp_ai_client_stream_response_contract`

Runs after request preparation and before execution.

```php
$contract = apply_filters(
	'wp_ai_client_stream_response_contract',
	$contract,
	$url,
	$analysis
);
```

Providers use this to declare the expected final response shape:

```php
$contract['provider']                 = 'openai';
$contract['operation']                = 'responses';
$contract['expected_response_format'] = 'openai-responses';
$contract['request_url']              = $url;
$contract['request_path']             = (string) parse_url( $url, PHP_URL_PATH );
```

Core may add generic fields such as `request_url` and `request_path`, but it
must not decide provider response formats.

### `wp_ai_client_stream_normalize_response_body`

Runs before the normalizer registry fallback. It lets a provider normalize with
a callback instead of a normalizer class.

```php
$normalized = apply_filters(
	'wp_ai_client_stream_normalize_response_body',
	null,
	$body,
	$contract,
	$response
);
```

Rules:

- `null` means "not handled".
- The first returned string wins.
- If a provider needs to intentionally return an empty body, document and test
  that provider behavior explicitly.

### `wp_ai_client_stream_response_normalizers`

Keep this as the normalizer registry hook, not as a compatibility shim. It is a
reasonable public extension point.

```php
$normalizers = apply_filters(
	'wp_ai_client_stream_response_normalizers',
	$normalizers,
	$contract
);
```

Changes:

- Core default array is empty.
- Built-in providers populate the array from provider modules.
- `WP_AI_Client_Streaming_Response_Normalizer_Interface` should be public
  package API, not marked `@internal`.

### Hooks To Remove

Remove `wp_ai_client_stream_provider_request_overrides`. The new
`wp_ai_client_stream_prepare_request` hook is clearer and does not require a
provider override interface.

## Provider Bootstrap

### Provider Folder Load File

Add `includes/ai-client/providers/load.php`.

Responsibilities:

- require provider module interface and registry
- require built-in provider bootstrap files
- call the provider registry once

Example:

```php
require_once __DIR__ . '/class-wp-ai-client-streaming-provider-module-interface.php';
require_once __DIR__ . '/class-wp-ai-client-streaming-provider-registry.php';
require_once __DIR__ . '/openai/class-wp-ai-client-streaming-openai-provider.php';
require_once __DIR__ . '/anthropic/class-wp-ai-client-streaming-anthropic-provider.php';
require_once __DIR__ . '/google/class-wp-ai-client-streaming-google-provider.php';

WP_AI_Client_Streaming_Provider_Registry::register(
	array(
		'openai'    => 'WP_AI_Client_Streaming_OpenAI_Provider',
		'anthropic' => 'WP_AI_Client_Streaming_Anthropic_Provider',
		'google'    => 'WP_AI_Client_Streaming_Google_Provider',
	)
);
```

This keeps provider names in the provider folder. The root package loader only
knows that provider modules exist.

### `WP_AI_Client_Streaming_Provider_Module_Interface`

```php
interface WP_AI_Client_Streaming_Provider_Module_Interface {
	public static function register(): void;
}
```

Keep it small. The provider module owns its own file requirements and hook
callbacks.

### `WP_AI_Client_Streaming_Provider_Registry`

Responsibilities:

- register provider modules once
- validate provider module classes
- expose a filter for replacing the provider module list

Suggested API:

```php
class WP_AI_Client_Streaming_Provider_Registry {
	private static bool $registered = false;

	public static function register( array $providers ): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		$providers = apply_filters(
			'wp_ai_client_stream_provider_modules',
			$providers
		);

		foreach ( $providers as $provider ) {
			if ( is_string( $provider ) && is_subclass_of( $provider, 'WP_AI_Client_Streaming_Provider_Module_Interface' ) ) {
				$provider::register();
			}
		}
	}
}
```

## Loader Changes

### Current Problem

`load.php` and `WP_AI_Client_Streaming_Package_Loader::get_default_package_files()`
currently list provider implementation files directly:

- OpenAI normalizers under `adapters/`
- Anthropic normalizer under `adapters/`
- Google normalizer under `adapters/`
- Google request override under `providers/google/`

### Target Manifest

The root package manifest should list core files and one provider load file:

```php
$wp_ai_client_streaming_package_files = array(
	'includes/ai-client/adapters/class-wp-ai-client-sse-event.php',
	'includes/ai-client/adapters/class-wp-ai-client-sse-parser.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-interface.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-context.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-http-client.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php',
	'includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php',
	'includes/ai-client/providers/load.php',
	'includes/ai-client/class-wp-ai-client-streaming-prompt-builder.php',
	'includes/ai-client.php',
);
```

Provider bootstrap classes should require their leaf files. Example:

```php
// providers/google/class-wp-ai-client-streaming-google-provider.php
require_once __DIR__ . '/class-wp-ai-client-streaming-google-generate-content-normalizer.php';
```

There should be no direct `openai`, `anthropic`, or `google` file paths in the
root package file manifest.

## Core Implementation Steps

### Step 1: Delete The Provider Request Override System

Remove:

- `includes/ai-client/providers/class-wp-ai-client-streaming-provider-request-override-interface.php`
- `includes/ai-client/providers/class-wp-ai-client-streaming-provider-request-override-registry.php`
- `wp_ai_client_stream_provider_request_overrides`

Move any still-needed behavior into provider modules. The Google URL/body
mutation becomes a Google provider callback on
`wp_ai_client_stream_prepare_request`.

### Step 2: Make The Response Normalizer Registry Provider-Neutral

Update
`includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php`.

Target behavior:

- start with an empty normalizer array
- apply `wp_ai_client_stream_response_normalizers`
- if `expected_response_format` matches an array key, move that normalizer to
  the front
- validate `WP_AI_Client_Streaming_Response_Normalizer_Interface`
- try normalizers in order

Remove direct construction of:

- `WP_AI_Client_Streaming_OpenAI_Responses_Normalizer`
- `WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer`
- `WP_AI_Client_Streaming_Anthropic_Messages_Normalizer`
- `WP_AI_Client_Streaming_Google_Generate_Content_Normalizer`

### Step 3: Add Generic Transport Filters

Update
`includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php`.

In `inspectRequest()`:

- keep generic control-header handling
- keep context application
- apply `wp_ai_client_stream_request_analysis` before returning analysis

In `sendStreamingRequest()`:

- remove `WP_AI_Client_Streaming_Provider_Request_Override_Registry::prepare()`
- apply `wp_ai_client_stream_prepare_request`
- validate the returned URL and analysis
- prepare the response contract after request preparation

In `prepareResponseNormalizationContract()`:

- add `request_url` and `request_path`
- apply `wp_ai_client_stream_response_contract`
- delete `detectExpectedResponseFormat()`

In `createPsrResponse()`:

- apply `wp_ai_client_stream_normalize_response_body`
- if no filter returns a string, call the normalizer registry

### Step 4: Remove Provider Assumptions From Streaming Context

Update `includes/ai-client/adapters/class-wp-ai-client-streaming-context.php`.

Remove provider-specific default matching from `default_request_matcher()`:

- remove `messages`
- remove `input`
- remove `contents`
- remove `stream`

Add `wp_ai_client_stream_context_matches_request` and let providers match their
own payloads.

Suggested fallback:

- match only mutating HTTP methods while a streaming context is active
- avoid inspecting provider payload keys
- let users pass `request_matcher` for precise call-site control

Remove provider-specific payload mutation from `prepare_streaming_body()`:

- do not inject `stream => true` in core
- keep `payload_mutator` only if it is still considered part of the public
  streaming helper API
- otherwise remove `payload_mutator` and make provider modules handle request
  mutation through `wp_ai_client_stream_prepare_request`

Preferred breaking cleanup:

- remove `inject_stream_parameter`
- keep `payload_mutator` only as a generic one-off call-site escape hatch
- provider modules own all reusable provider mutation

### Step 5: Move Provider Files

Move provider files into provider folders. Renaming classes is allowed if useful,
but do not combine that with logic changes unless tests are in place.

Required moves:

```text
includes/ai-client/adapters/class-wp-ai-client-streaming-openai-responses-normalizer.php
  -> includes/ai-client/providers/openai/class-wp-ai-client-streaming-openai-responses-normalizer.php

includes/ai-client/adapters/class-wp-ai-client-streaming-openai-chat-completions-normalizer.php
  -> includes/ai-client/providers/openai/class-wp-ai-client-streaming-openai-chat-completions-normalizer.php

includes/ai-client/adapters/class-wp-ai-client-streaming-anthropic-messages-normalizer.php
  -> includes/ai-client/providers/anthropic/class-wp-ai-client-streaming-anthropic-messages-normalizer.php

includes/ai-client/adapters/class-wp-ai-client-streaming-google-generate-content-normalizer.php
  -> includes/ai-client/providers/google/class-wp-ai-client-streaming-google-generate-content-normalizer.php
```

The current Google request override can be folded into
`WP_AI_Client_Streaming_Google_Provider`. If keeping a helper class improves
readability, keep it under `providers/google/` and do not expose it as a core
interface.

### Step 6: Add Provider Bootstrap Classes

Each provider gets one bootstrap class with a static `register()` method. That
method requires provider leaf files and attaches hooks.

Provider classes should be idempotent:

```php
private static bool $registered = false;

public static function register(): void {
	if ( self::$registered ) {
		return;
	}

	self::$registered = true;

	add_filter( '...', array( __CLASS__, '...' ), 10, 3 );
}
```

## OpenAI Provider

### Current Behavior

OpenAI support currently lives in two normalizers:

- `WP_AI_Client_Streaming_OpenAI_Responses_Normalizer`
- `WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer`

The HTTP service currently detects OpenAI formats by:

- `/chat/completions` path -> `openai-chat-completions`
- `/responses` path -> `openai-responses`
- JSON body with `messages` -> `openai-chat-completions`

The context currently injects `stream => true`. After this refactor, OpenAI
should own that request-body mutation.

### Target Files

```text
includes/ai-client/providers/openai/
  class-wp-ai-client-streaming-openai-provider.php
  class-wp-ai-client-streaming-openai-responses-normalizer.php
  class-wp-ai-client-streaming-openai-chat-completions-normalizer.php
```

### Registration

`WP_AI_Client_Streaming_OpenAI_Provider::register()` should attach:

- `wp_ai_client_stream_context_matches_request`
- `wp_ai_client_stream_request_analysis`
- `wp_ai_client_stream_prepare_request`
- `wp_ai_client_stream_response_contract`
- `wp_ai_client_stream_response_normalizers`

### Request Matching

OpenAI should match:

- paths containing `/chat/completions`
- paths ending in `/responses`
- JSON payloads with `messages`
- JSON payloads with `input`

The payload fallback intentionally supports OpenAI-compatible gateways that use
non-OpenAI hostnames.

### Request Analysis

OpenAI should set:

```php
$analysis['provider']  = 'openai';
$analysis['operation'] = 'chat-completions';
```

or:

```php
$analysis['provider']  = 'openai';
$analysis['operation'] = 'responses';
```

It may also store decoded JSON in `$analysis['meta']['json_body']` to avoid
decoding the same body in later callbacks.

### Request Preparation

OpenAI should add `stream => true` to JSON request bodies for operations that
use that switch.

Rules:

- Only mutate JSON bodies.
- Do not mutate if the request is not identified as OpenAI or
  OpenAI-compatible.
- Remove `Content-Length` after mutation so the transport recalculates it.
- Preserve existing caller-provided `stream` if it is already truthy.

### Response Contract

OpenAI should set:

- `expected_response_format => openai-chat-completions` for chat completions
- `expected_response_format => openai-responses` for Responses API

Example:

```php
public static function filter_response_contract( array $contract, string $url, array $analysis ): array {
	if ( 'openai' !== ( $analysis['provider'] ?? null ) ) {
		return $contract;
	}

	if ( 'chat-completions' === ( $analysis['operation'] ?? null ) ) {
		$contract['expected_response_format'] = 'openai-chat-completions';
	}

	if ( 'responses' === ( $analysis['operation'] ?? null ) ) {
		$contract['expected_response_format'] = 'openai-responses';
	}

	$contract['provider']  = 'openai';
	$contract['operation'] = $analysis['operation'] ?? null;

	return $contract;
}
```

### Normalizers

Register:

```php
$normalizers['openai-responses']         = new WP_AI_Client_Streaming_OpenAI_Responses_Normalizer();
$normalizers['openai-chat-completions'] = new WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer();
```

`WP_AI_Client_Streaming_OpenAI_Responses_Normalizer` remains responsible for:

- parsing SSE events
- tracking latest `response`
- preferring terminal response events:
  - `response.completed`
  - `response.failed`
  - `response.incomplete`
- returning the final response object JSON

`WP_AI_Client_Streaming_OpenAI_Chat_Completions_Normalizer` remains responsible
for:

- merging streamed `choices`
- merging `delta` and `message`
- accumulating content, reasoning content, function calls, and tool calls
- converting Responses-style stream events into chat-completion response shape
  when the expected format is chat completions
- preserving OpenAI-compatible gateway behavior

### OpenAI Tests

Add fixtures for:

- Responses API terminal `response.completed`
- Responses API `response.failed`
- Responses API stream without terminal event but with latest response snapshot
- Chat Completions chunk stream with multiple choices
- Chat Completions function call deltas
- Chat Completions tool call deltas
- OpenAI-compatible gateway that emits Responses-style events while the expected
  final shape is chat completions

Assert:

- OpenAI provider registers idempotently
- OpenAI request preparation injects `stream => true`
- `/responses` contract becomes `openai-responses`
- `/chat/completions` contract becomes `openai-chat-completions`
- body with `messages` can map to chat completions in the provider module
- no OpenAI endpoint strings remain in the core HTTP service

## Anthropic Provider

### Current Behavior

Anthropic support currently lives in:

- `WP_AI_Client_Streaming_Anthropic_Messages_Normalizer`

The HTTP service currently detects Anthropic by:

- URL path containing `/messages` -> `anthropic-messages`

The normalizer reconstructs a non-streaming Anthropic Messages response from
SSE events.

### Target Files

```text
includes/ai-client/providers/anthropic/
  class-wp-ai-client-streaming-anthropic-provider.php
  class-wp-ai-client-streaming-anthropic-messages-normalizer.php
```

### Registration

`WP_AI_Client_Streaming_Anthropic_Provider::register()` should attach:

- `wp_ai_client_stream_context_matches_request`
- `wp_ai_client_stream_request_analysis`
- `wp_ai_client_stream_prepare_request`
- `wp_ai_client_stream_response_contract`
- `wp_ai_client_stream_response_normalizers`

### Request Matching

Anthropic should match:

- paths containing `/messages`
- Anthropic hostnames when available
- Anthropic-specific headers when available
- JSON payloads that fit the Anthropic Messages shape, if needed

Prefer path plus host/header checks when possible. If broad `/messages` support
is needed for compatible gateways, keep that broadness inside the Anthropic
provider module.

### Request Analysis

Anthropic should set:

```php
$analysis['provider']  = 'anthropic';
$analysis['operation'] = 'messages';
```

### Request Preparation

Anthropic Messages streaming uses request-body stream opt-in. The Anthropic
provider should add or preserve:

```php
$payload['stream'] = true;
```

Rules:

- Only mutate JSON bodies.
- Only mutate Anthropic Messages requests.
- Remove `Content-Length` after mutation.
- Preserve existing truthy `stream`.

### Response Contract

Anthropic should set:

```php
$contract['provider']                 = 'anthropic';
$contract['operation']                = 'messages';
$contract['expected_response_format'] = 'anthropic-messages';
```

### Normalizer

Register:

```php
$normalizers['anthropic-messages'] = new WP_AI_Client_Streaming_Anthropic_Messages_Normalizer();
```

`WP_AI_Client_Streaming_Anthropic_Messages_Normalizer` remains responsible for:

- parsing Anthropic SSE events
- applying `message_start`
- applying `content_block_start`
- merging `content_block_delta`
- applying `message_delta`
- merging usage data
- reconstructing `content`
- defaulting `stop_reason` to `end_turn` when needed
- decoding accumulated `input_json_delta` partial JSON into tool input

### Anthropic Tests

Add fixtures for:

- standard text response with `message_start`, text deltas, and `message_delta`
- multiple content blocks
- thinking block deltas
- tool input JSON deltas via `input_json_delta`
- usage data merged from `message_delta`
- stream with no content blocks returns `null` from the normalizer

Assert:

- Anthropic provider registers idempotently
- Anthropic request preparation injects `stream => true`
- `/messages` contract becomes `anthropic-messages`
- no Anthropic endpoint strings remain in the core HTTP service

## Google Provider

### Current Behavior

Google support currently lives in:

- `WP_AI_Client_Streaming_Google_Generate_Content_Normalizer`
- `WP_AI_Client_Streaming_Google_Generate_Content_Request_Override`

The request override:

- applies only to host `generativelanguage.googleapis.com`
- applies only when path ends in `:generateContent`
- removes top-level JSON field `stream`
- converts `:generateContent` to `:streamGenerateContent`
- appends `alt=sse`

The HTTP service currently detects Google by:

- URL path containing `generatecontent`
- JSON body with `contents`

This is the clearest example of the issue: core injects an OpenAI-style
`stream` field, then Google-specific provider code removes it and changes the
URL.

### Target Files

```text
includes/ai-client/providers/google/
  class-wp-ai-client-streaming-google-provider.php
  class-wp-ai-client-streaming-google-generate-content-normalizer.php
```

Do not keep a public Google request override interface. If a helper class is
useful, it should be private to the Google provider folder.

### Registration

`WP_AI_Client_Streaming_Google_Provider::register()` should attach:

- `wp_ai_client_stream_context_matches_request`
- `wp_ai_client_stream_request_analysis`
- `wp_ai_client_stream_prepare_request`
- `wp_ai_client_stream_response_contract`
- `wp_ai_client_stream_response_normalizers`

### Request Matching

Google should match:

- host `generativelanguage.googleapis.com`
- paths ending in `:generateContent`
- paths ending in `:streamGenerateContent`
- JSON payloads with `contents` only when host/path evidence is not available
  and compatible-gateway support is intentionally desired

### Request Analysis

Google should set:

```php
$analysis['provider']  = 'google';
$analysis['operation'] = 'generate-content';
```

### Request Preparation

Google should own all Generate Content streaming mutation:

- convert `:generateContent` to `:streamGenerateContent`
- append or replace `alt=sse`
- remove top-level JSON field `stream`
- remove `Content-Length` after body mutation
- preserve all other JSON body fields

This should happen in `WP_AI_Client_Streaming_Google_Provider::prepare_request()`
or a provider-local helper.

Important details:

- Existing query arguments must be preserved.
- If `alt` already exists, force it to `sse`.
- Non-JSON bodies should not be modified.
- Non-Google requests should not be modified.
- The provider should support already-streaming URLs without double-rewriting.

### Response Contract

Google should set:

```php
$contract['provider']                 = 'google';
$contract['operation']                = 'generate-content';
$contract['expected_response_format'] = 'google-generate-content';
```

### Normalizer

Register:

```php
$normalizers['google-generate-content'] = new WP_AI_Client_Streaming_Google_Generate_Content_Normalizer();
```

`WP_AI_Client_Streaming_Google_Generate_Content_Normalizer` remains responsible
for:

- parsing Google SSE chunks
- merging streamed `candidates`
- merging candidate `content`
- accumulating `parts`
- preserving `inlineData`, `fileData`, `functionCall`, and `thought`
- copying top-level metadata:
  - `id`
  - `modelVersion`
  - `promptFeedback`
  - `usageMetadata`
- defaulting missing `finishReason` to `STOP`

### Google Tests

Add fixtures for:

- `generateContent` request becomes `streamGenerateContent?alt=sse`
- existing query arguments are preserved when adding `alt=sse`
- existing `alt` query argument is replaced with `sse`
- top-level `stream` is removed from JSON body
- non-JSON bodies are not modified
- non-Google hosts are not modified
- already-streaming Google URLs are not double-rewritten
- Google SSE candidate text chunks merge into final `candidates`
- multiple candidate indexes remain sorted
- `finishReason` defaults to `STOP`
- `usageMetadata`, `promptFeedback`, and `modelVersion` are preserved

Assert:

- Google provider registers idempotently
- Google request preparation owns all URL/body mutation
- `google-generate-content` is set by the Google provider
- no Google host, path, or payload strings remain in core transport files

## Suggested Migration Sequence

1. Add `WP_AI_Client_Streaming_Provider_Module_Interface`.
2. Add `WP_AI_Client_Streaming_Provider_Registry`.
3. Add `includes/ai-client/providers/load.php`.
4. Add the new core hooks:
   - `wp_ai_client_stream_context_matches_request`
   - `wp_ai_client_stream_request_analysis`
   - `wp_ai_client_stream_prepare_request`
   - `wp_ai_client_stream_response_contract`
   - `wp_ai_client_stream_normalize_response_body`
5. Delete the provider request override interface and registry.
6. Make the response normalizer registry default to an empty array.
7. Move OpenAI normalizers into `providers/openai/`.
8. Add `WP_AI_Client_Streaming_OpenAI_Provider`.
9. Move Anthropic normalizer into `providers/anthropic/`.
10. Add `WP_AI_Client_Streaming_Anthropic_Provider`.
11. Move Google normalizer into `providers/google/`.
12. Fold Google request override behavior into `WP_AI_Client_Streaming_Google_Provider`.
13. Remove provider-specific matching and stream injection from
    `WP_AI_Client_Streaming_Context`.
14. Remove `detectExpectedResponseFormat()` from
    `WP_AI_Client_Streaming_HTTP_Service`.
15. Update `load.php` and
    `WP_AI_Client_Streaming_Package_Loader::get_default_package_files()` to load
    `includes/ai-client/providers/load.php`.
16. Update docs for the provider module API.
17. Add provider regression tests.

## Implementation Checklist

Core:

- [ ] Add `WP_AI_Client_Streaming_Provider_Module_Interface`.
- [ ] Add `WP_AI_Client_Streaming_Provider_Registry`.
- [ ] Add `includes/ai-client/providers/load.php`.
- [ ] Add `wp_ai_client_stream_provider_modules`.
- [ ] Add `wp_ai_client_stream_context_matches_request`.
- [ ] Add `wp_ai_client_stream_request_analysis`.
- [ ] Add `wp_ai_client_stream_prepare_request`.
- [ ] Add `wp_ai_client_stream_response_contract`.
- [ ] Add `wp_ai_client_stream_normalize_response_body`.
- [ ] Keep `wp_ai_client_stream_response_normalizers` as the normalizer registry hook.
- [ ] Delete `wp_ai_client_stream_provider_request_overrides`.
- [ ] Delete provider request override interface and registry.
- [ ] Make response normalizer registry default to an empty array.
- [ ] Remove provider-specific response-format detection from HTTP service.
- [ ] Remove provider-specific request matching from context.
- [ ] Remove core `stream` request-body injection.
- [ ] Add validation for filtered request and contract shapes.
- [ ] Update loader file manifests.

OpenAI:

- [ ] Move OpenAI normalizers into `providers/openai/`.
- [ ] Add `WP_AI_Client_Streaming_OpenAI_Provider`.
- [ ] Register OpenAI request matching.
- [ ] Register OpenAI request analysis.
- [ ] Register OpenAI request preparation.
- [ ] Register OpenAI response contract detection.
- [ ] Register OpenAI normalizers.
- [ ] Add OpenAI fixture tests.

Anthropic:

- [ ] Move Anthropic normalizer into `providers/anthropic/`.
- [ ] Add `WP_AI_Client_Streaming_Anthropic_Provider`.
- [ ] Register Anthropic request matching.
- [ ] Register Anthropic request analysis.
- [ ] Register Anthropic request preparation.
- [ ] Register Anthropic response contract detection.
- [ ] Register Anthropic normalizer.
- [ ] Add Anthropic fixture tests.

Google:

- [ ] Move Google normalizer into `providers/google/`.
- [ ] Add `WP_AI_Client_Streaming_Google_Provider`.
- [ ] Fold or privatize Google request override logic.
- [ ] Register Google request matching.
- [ ] Register Google request analysis.
- [ ] Register Google request preparation.
- [ ] Register Google response contract detection.
- [ ] Register Google normalizer.
- [ ] Add Google request mutation and normalizer tests.

Docs:

- [ ] Update `docs/integration-guide.md`.
- [ ] Add provider module API documentation.
- [ ] Remove docs for provider request overrides.
- [ ] Remove wording that implies provider-specific normalizers are core
  transport responsibilities.

## Verification Plan

There is no package-level test setup at the time of this plan, so the first
verification task is to add one. The minimum useful coverage should include:

- provider module registration tests
- hook order tests
- request matching tests
- request preparation tests
- response contract tests
- normalizer fixture tests
- loader tests

### Core Verification

Core tests should assert:

- unknown streaming provider requests still stream raw chunks and SSE events
- unknown providers do not receive a made-up `expected_response_format`
- `wp_ai_client_stream_prepare_request` can mutate URL and analysis safely
- invalid filter return values are ignored
- unsafe URL validation runs after URL mutation
- no provider endpoint strings remain in `WP_AI_Client_Streaming_HTTP_Service`
- no provider payload keys remain in
  `WP_AI_Client_Streaming_Context::default_request_matcher()`

### Provider Verification

Provider tests should assert:

- each provider bootstrap is idempotent
- each provider registers only its own callbacks
- each known provider still produces the expected final JSON response shape
- provider implementation files are loaded through `providers/load.php`
- the root loader does not list provider leaf files

### Manual Smoke Test

After implementation:

1. Install the package through the wrapper plugin.
2. Initialize streaming discovery on `plugins_loaded`.
3. Run one streamed prompt with an OpenAI-compatible chat model.
4. Run one streamed prompt with OpenAI Responses if available.
5. Run one streamed prompt with Anthropic Messages if available.
6. Run one streamed prompt with Google Generate Content if available.
7. Confirm chunk callbacks fire before the final result.
8. Confirm final `generate_*` results still parse correctly.
9. Confirm transport diagnostics still reports the streaming client as active.

## Success Criteria

The refactor is complete when:

- no provider names appear in the core HTTP service
- no provider payload keys appear in the core streaming context matcher
- no provider normalizers are constructed by the core normalizer registry
- no provider request override registry exists
- built-in provider support is registered through provider modules
- provider implementation files live under `includes/ai-client/providers/{provider}/`
- the root package loader only loads `includes/ai-client/providers/load.php` for
  provider support
- OpenAI, Anthropic, and Google streaming behavior still works through provider
  modules
- provider-specific behavior is covered by fixtures or integration tests
