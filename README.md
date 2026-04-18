# WP AI Client Streaming

`bradvin/wp-ai-client-streaming` is a WordPress 7 streaming adapter package designed to read like a small extension of core’s AI client layer.

It exposes core-style `WP_AI_*` classes and helper functions, while leaving initialization explicit so callers can bootstrap it early in their own plugin load order.

If you are reviewing the package for upstream WordPress use, start with:

- `docs/core-review-notes.md`
- `docs/integration-guide.md`

## Install

```bash
composer require bradvin/wp-ai-client-streaming:^0.1
```

## Demo Plugin

If you want a working wrapper plugin and demo UI for this package, see:

- `https://github.com/bradvin/wp-stream`

## Bootstrap

Initialize the discovery strategy during your plugin bootstrap, before you start registering or using AI providers:

```php
WP_AI_Client_Streaming_Discovery_Strategy::init();
```

## Usage

Use the streaming-aware prompt helper directly:

```php
$result = wp_ai_client_stream_prompt(
	$prompt_messages,
	array(
		'streaming_enabled' => true,
	)
)
	->using_model_config( $model_config )
	->generate_result();
```

Or wrap an existing core prompt builder:

```php
$builder = wp_ai_client_prompt( $prompt_messages )->using_model_config( $model_config );
$result  = wp_ai_client_stream( $builder, array( 'streaming_enabled' => true ) )->generate_result();
```

For transport diagnostics against the default registry, call:

```php
$diagnostics = WP_AI_Client_Streaming_Transport_Diagnostics::get_default_registry_diagnostics();
```

## License

`bradvin/wp-ai-client-streaming` is licensed under `GPL-2.0-or-later`.

## Publishing

The latest tagged release for this package is `v0.1.1`.

For the publish checklist, see `docs/publishing.md`.
