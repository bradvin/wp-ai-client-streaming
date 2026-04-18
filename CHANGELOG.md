# Changelog

All notable changes to `bradvin/wp-ai-client-streaming` will be documented in this file.

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
