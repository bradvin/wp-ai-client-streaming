# TODO

Architecture and verification follow-ups from the PHP architecture review of
`bradvin/wp-ai-client-streaming`.

## Higher Impact

- [ ] Make transport initialization harder to miss.
  - Add a public bootstrap function such as `wp_ai_client_streaming_init()`.
  - Ensure prompt helpers initialize streaming discovery or fail with a clear diagnostic.
  - Relevant files:
    - `load.php`
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php`
    - wrapper integration: `wp-stream/includes/class-plugin.php`

- [ ] Reduce reflection against private AI Client internals.
  - Avoid depending on private properties like `AiClient::$defaultRegistry` and transporter `client`.
  - Prefer a public registration path, explicit registry attachment, or an upstream filter/action.
  - Keep reflection only as a best-effort compatibility fallback with version checks.
  - Relevant files:
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php`
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php`

- [ ] Treat the custom cURL transport as a compatibility-critical subsystem.
  - Isolate the cURL implementation behind a transport interface.
  - Add parity coverage for expected WordPress HTTP behavior.
  - Scrub host-sensitive headers such as `Authorization` and `Cookie` on cross-host redirects.
  - Validate each redirect hop with safe URL rules.
  - Relevant file:
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php`

- [ ] Clarify the result contract for streaming callbacks versus final buffered results.
  - Document and enforce buffered-compatible mode for existing `generate_*` APIs.
  - Define an event-only or iterator mode for true streaming.
  - Make `capture_body=false` fail clearly or return a defined streaming result object when used with final-result APIs.
  - Relevant file:
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php`

- [ ] Formalize extension points currently marked internal.
  - Make filter-exposed interfaces public package contracts.
  - Document `$contract` and `$analysis` array shapes.
  - Consider DTOs or value objects once the shapes stabilize.
  - Relevant files:
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-interface.php`
    - `includes/ai-client/adapters/class-wp-ai-client-streaming-response-normalizer-registry.php`
    - `includes/ai-client/providers/class-wp-ai-client-streaming-provider-request-override-interface.php`
    - `includes/ai-client/providers/class-wp-ai-client-streaming-provider-request-override-registry.php`

- [ ] Plan for multi-version Composer loading limits.
  - Keep global helper functions as a tiny backward-compatible ABI.
  - Consider splitting the immutable shared loader/shim from versioned implementation code.
  - Relevant files:
    - `load.php`
    - `includes/class-wp-ai-client-streaming-package-loader.php`

## Quick Wins

- [ ] Add `wp_ai_client_streaming_is_active()` or enhance diagnostics so consumers can distinguish package-loaded from transport-active.
- [ ] Make the generating method list in `WP_AI_Client_Streaming_Prompt_Builder` extensible or derive it from the core builder where possible.
- [ ] Avoid duplicate helper definitions between `load.php` and `includes/ai-client.php`.
- [ ] Deduplicate the package file manifest currently present in both `load.php` and `includes/class-wp-ai-client-streaming-package-loader.php`.
- [ ] Preserve core-style error mapping in `WP_AI_Client_Streaming_Prompt_Builder::__call()` instead of returning only a generic `wp_ai_client_stream_error`.

## Verification

- [ ] Add package-level test tooling and Composer scripts.
- [ ] Add loader tests for multiple package copies:
  - older copy loaded first
  - newer copy registered later
  - selected implementation path
  - helper behavior
- [ ] Add bootstrap integration tests for:
  - Composer autoload before AI Client availability
  - Composer autoload after AI Client availability
  - default registry already initialized
  - helper use without explicit discovery initialization
- [ ] Add transport parity tests for:
  - `pre_http_request`
  - `http_response`
  - `http_api_debug`
  - `http_api_curl`
  - safe URL rejection
  - redirects
  - proxy and SSL options
  - timeout handling
  - auth header scrubbing
- [ ] Add fixture tests for SSE parsing and provider normalizers:
  - OpenAI Responses
  - OpenAI chat completions
  - Anthropic Messages
  - Google Generate Content
  - chunk boundary splits
  - multiline `data:`
  - `[DONE]`
  - malformed JSON
  - truncated streams
- [ ] Add an end-to-end test with a local fake SSE server proving chunk callbacks fire and final `generate_*` results still parse correctly.
