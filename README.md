# PeekAPI — PHP SDK

[![Packagist](https://img.shields.io/packagist/v/peekapi/peekapi)](https://packagist.org/packages/peekapi/peekapi)
[![license](https://img.shields.io/packagist/l/peekapi/peekapi)](./LICENSE)

Zero-dependency PHP SDK for [PeekAPI](https://peekapi.dev). PSR-15 middleware for Slim/Mezzio and a dedicated Laravel middleware with auto-configuration from env vars.

## Install

```bash
composer require peekapi/peekapi
```

## Quick Start

### Laravel

Set environment variables and register the middleware:

```bash
# .env
PEEKAPI_API_KEY=ak_live_xxx
PEEKAPI_ENDPOINT=https://...
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\PeekApi\Middleware\Laravel::class);
})
```

```php
// Or app/Http/Kernel.php (Laravel 10)
protected $middleware = [
    \PeekApi\Middleware\Laravel::class,
    // ...
];
```

The Laravel middleware auto-configures from `PEEKAPI_API_KEY` and `PEEKAPI_ENDPOINT` env vars.

### Slim (PSR-15)

```php
use PeekApi\Client;
use PeekApi\Middleware\PSR15;
use Slim\Factory\AppFactory;

$client = new Client([
    'api_key' => 'ak_live_xxx',
]);

$app = AppFactory::create();
$app->add(new PSR15($client));

$app->get('/api/hello', function ($request, $response) {
    $response->getBody()->write(json_encode(['message' => 'hello']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

### Mezzio (PSR-15)

```php
// config/pipeline.php
use PeekApi\Client;
use PeekApi\Middleware\PSR15;

$client = new Client(['api_key' => 'ak_live_xxx']);
$app->pipe(new PSR15($client));
```

### Standalone Client

```php
use PeekApi\Client;

$client = new Client(['api_key' => 'ak_live_xxx']);

$client->track([
    'method' => 'GET',
    'path' => '/api/users',
    'status_code' => 200,
    'response_time_ms' => 42,
]);

// Graceful shutdown (flushes remaining events)
$client->shutdown();
```

## Configuration

| Option | Default | Description |
|---|---|---|
| `api_key` | required | Your PeekAPI key |
| `endpoint` | PeekAPI cloud | Ingestion endpoint URL |
| `flush_interval` | `10` | Seconds between automatic flushes |
| `batch_size` | `100` | Events per HTTP POST (triggers flush) |
| `max_buffer_size` | `10_000` | Max events held in memory |
| `max_storage_bytes` | `5_242_880` | Max disk fallback file size (5MB) |
| `max_event_bytes` | `65_536` | Per-event size limit (64KB) |
| `storage_path` | auto | Custom path for JSONL persistence file |
| `debug` | `false` | Enable debug logging to stderr |
| `on_error` | `null` | Callback `callable(Throwable): void` for flush errors |

## How It Works

1. Middleware intercepts every request/response
2. Captures method, path, status code, response time, request/response sizes, consumer ID
3. Events are buffered in memory and flushed synchronously when `batchSize` is reached
4. On network failure: exponential backoff with jitter, up to 5 retries
5. After max retries: events are persisted to a JSONL file on disk
6. On next startup: persisted events are recovered and re-sent
7. On shutdown: `register_shutdown_function` flushes remaining events or persists to disk

## Consumer Identification

By default, consumers are identified by:

1. `X-API-Key` header — stored as-is
2. `Authorization` header — hashed with SHA-256 (stored as `hash_<hex>`)

Override with the `identify_consumer` option to use any header:

```php
$client = new PeekApi\Client([
    'api_key' => '...',
    'identify_consumer' => fn(array $headers) => $headers['x-tenant-id'] ?? null,
]);
```

The callback receives an associative array of lowercase header names and should return a consumer ID string or `null`.

## Features

- **Zero runtime dependencies** — uses only `ext-curl` and `ext-json`
- **Disk persistence** — undelivered events saved to JSONL, recovered on restart
- **Exponential backoff** — with jitter, max 5 consecutive failures before disk fallback
- **SSRF protection** — private IP blocking, HTTPS enforcement (HTTP only for localhost)
- **Input sanitization** — path (2048), method (16), consumer_id (256) truncation
- **Per-event size limit** — strips metadata first, drops if still too large (default 64KB)
- **Graceful shutdown** — `register_shutdown_function` + `__destruct` fallback
- **PSR-15 compatible** — works with any PSR-15 framework
- **Laravel auto-config** — reads from `PEEKAPI_API_KEY` / `PEEKAPI_ENDPOINT` env vars

## Requirements

- PHP >= 8.2
- `ext-curl`
- `ext-json`

## License

MIT
