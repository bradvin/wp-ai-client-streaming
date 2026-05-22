# WP AI Client Streaming

`bradvin/wp-ai-client-streaming` is a WordPress 7 streaming adapter package designed to read like a small extension of core’s AI client layer.

It exposes core-style `WP_AI_*` classes and helper functions. When multiple plugins bundle the package, its global loader keeps a single active copy and loads the newest registered package version.

If you are reviewing the package for upstream WordPress use, start with:

- `docs/core-review-notes.md`
- `docs/integration-guide.md`

## Install

```bash
composer require bradvin/wp-ai-client-streaming:^1.0
```

## Demo Plugin

If you want a working wrapper plugin and demo UI for this package, see:

- `https://github.com/bradvin/wp-stream`

## Bootstrap

Initialize the discovery strategy after plugins have loaded:

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

The package registers itself through `WP_AI_Client_Streaming_Package_Loader` as Composer autoloads each bundled copy. Actual adapter classes are loaded later from the newest registered version, so direct class access should happen on or after `plugins_loaded`.

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

The latest tagged release for this package is `v1.0.0`.

For the publish checklist, see `docs/publishing.md`.
