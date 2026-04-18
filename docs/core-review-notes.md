# Core Review Notes

Date: 2026-04-18

## Goal

Add transport-side streaming support to the WordPress AI client without patching core, while keeping the package shaped so it could be copied into `wp-includes/ai-client/` with minimal structural change.

## Current Shape

The package mirrors WordPress 7 AI conventions instead of exposing a plugin-branded runtime:

- global `WP_AI_*` classes and functions
- include-friendly `load.php` bootstrap
- explicit initialization through `WP_AI_Client_Streaming_Discovery_Strategy::init()`
- a streaming-aware wrapper around `WP_AI_Client_Prompt_Builder`
- a transport diagnostics helper for inspecting the active registry

## Findings That Drove The Design

1. `wp_remote_request()` and the bundled `Requests` stack still assume a complete response body. They can stream to a file, but not to an in-memory callback that higher-level AI code can consume incrementally.
2. The AI client already exposes a clean HTTP adapter override point through HTTPlug discovery. That makes a plugin-side discovery strategy the smallest viable integration surface.
3. The core AI HTTP adapter is intentionally thin. Replacing only the HTTP client layer is enough to add streaming behavior without changing the higher-level provider and prompt-builder APIs.
4. The WordPress 7 RC2 AI stack still does not expose a first-class public streaming result contract. This package therefore solves transport-side streaming first and keeps the public developer API small.

## Architecture

### Discovery override

- `WP_AI_Client_Streaming_Discovery_Strategy::init()` prepends a streaming-aware PSR-18 client through the same HTTPlug discovery path core already uses.
- Initialization is explicit and idempotent. The package does not self-bootstrap on `plugins_loaded`.

### Prompt-builder surface

- `wp_ai_client_stream_prompt()` creates a streaming-aware prompt builder from scratch.
- `wp_ai_client_stream()` wraps an existing `WP_AI_Client_Prompt_Builder`.
- Streaming behavior is scoped to the generating call and removed immediately afterward.

### Transport layer

- Non-streaming requests delegate to the stock WordPress AI HTTP adapter unchanged.
- Streaming requests use a custom cURL execution path that preserves key WordPress HTTP semantics:
  - `http_request_args`
  - `pre_http_request`
  - `http_api_curl`
  - `http_api_debug`
  - `http_response`
  - proxy handling
  - SSL verification handling
  - redirect handling
- The transport emits WordPress-style streaming hooks instead of a package-specific hook namespace.

## Why This Shape

This is the smallest working change set that still feels like WordPress:

- no core patch
- no Requests patch
- no separate service container or framework runtime
- ordinary non-streaming requests keep the stock path
- the package can be dropped into core mostly by moving files and adjusting load order

## Known Limits

1. This is still transport-side streaming first. The underlying AI client stack still expects a final response object at the end of the request lifecycle.
2. The streaming path depends on cURL. If cURL is unavailable, requests fall back to the stock client and lose incremental streaming behavior.
3. Redirect handling is intentionally narrower than the full `Requests` stack because the package only owns the streaming path, not WordPress HTTP as a whole.
4. OpenAI-style SSE responses still need response normalization before provider parsing so the final result looks like the non-streaming JSON shape upstream expects.

## What Core Contributors Should Evaluate

- Whether transport-side streaming is the right first merge target before a broader `WP_Http` or `Requests` streaming abstraction exists.
- Whether the hook names and prompt-builder wrapper shape fit the public API direction for the WordPress AI client.
- Whether the cURL streaming path preserves enough WordPress HTTP behavior for core use, or whether more of `WP_Http` needs to become callback-aware first.
- Whether the diagnostics helper should remain package-only tooling or inform a core debugging surface.
