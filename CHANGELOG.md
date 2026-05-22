# Changelog

All notable changes to `bradvin/wp-ai-client-streaming` will be documented in this file.

## Unreleased

## 1.0.0 - 2026-05-22

- Add `WP_AI_Client_Streaming_Package_Loader`, a global loader that lets multiple bundled package copies register themselves and loads the newest registered version.
- Defer adapter class loading until `plugins_loaded` in WordPress requests so active plugins have a chance to register their bundled package versions before one copy wins.
- Add early proxy helpers for `wp_ai_client_stream_prompt()` and `wp_ai_client_stream()` so wrapper plugins can detect the public API during bootstrap.
- Add loaded package version, path, and registered package metadata to transport diagnostics.
- Route Google `generateContent` streaming requests through `streamGenerateContent?alt=sse` and remove the unsupported OpenAI-style `stream` payload field.
- Bump the documented Composer constraint to `^1.0`.

## 0.1.3 - 2026-05-22

- Move streamed response body normalization behind provider-specific normalizer classes.
- Add an OpenAI-compatible chat completions normalizer for OpenRouter-style streamed chunks.
- Add Anthropic Messages and Google Generate Content normalizers.
- Add the `wp_ai_client_stream_response_normalizers` filter for registering additional normalizers.
- Keep chat-completions requests returning a final `choices` response even when a gateway emits Responses-style stream events.

## 0.1.2 - 2026-05-13

- Guard the Composer file autoloader so the package does not fatal when WordPress AI client dependency interfaces are unavailable.
- Retry streaming adapter loading on WordPress hooks after the AI client dependency stack becomes available.

## 0.1.1 - 2026-04-18

- Repair the default AI registry transporter when the streaming discovery strategy initializes after another plugin has already instantiated `AiClient::defaultRegistry()`.
- Keep streaming available when multiple plugins vendor the package and one of them touches the AI registry before calling `WP_AI_Client_Streaming_Discovery_Strategy::init()`.
- Document the `wp-stream` wrapper plugin repo as the demo implementation for the package.

## 0.1.0 - 2026-04-18

- Initial standalone package release.
- WordPress-style `WP_AI_*` streaming adapter surface.
- Streaming-aware prompt builder helpers.
- HTTPlug discovery integration for the streaming HTTP client.
- Streaming transport diagnostics helper.
- Core review notes and integration guide.
