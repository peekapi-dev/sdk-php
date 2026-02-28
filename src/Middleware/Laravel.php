<?php

declare(strict_types=1);

namespace PeekApi\Middleware;

use PeekApi\Client;
use PeekApi\Consumer;
use PeekApi\SSRF;
use Closure;
use Throwable;

/**
 * Laravel middleware for tracking API usage.
 *
 * Register in app/Http/Kernel.php or via route middleware.
 * Auto-configures from PEEKAPI_API_KEY / PEEKAPI_ENDPOINT env vars.
 */
final class Laravel
{
    private ?Client $client;

    public function __construct(?Client $client = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        // Auto-configure from env vars
        $apiKey = $_ENV['PEEKAPI_API_KEY'] ?? getenv('PEEKAPI_API_KEY') ?: '';
        $endpoint = $_ENV['PEEKAPI_ENDPOINT'] ?? getenv('PEEKAPI_ENDPOINT') ?: '';

        if ($apiKey !== '' && $endpoint !== '') {
            $this->client = new Client([
                'api_key' => $apiKey,
                'endpoint' => $endpoint,
            ]);
        } else {
            $this->client = null;
        }
    }

    /**
     * Handle an incoming request.
     *
     * @param mixed $request Laravel Request object
     * @param Closure $next
     * @return mixed
     */
    public function handle(mixed $request, Closure $next): mixed
    {
        if ($this->client === null) {
            return $next($request);
        }

        $start = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            try {
                $path = '/' . ltrim($request->path(), '/');
                if ($this->client->getCollectQueryString()) {
                    $qs = $request->getQueryString() ?? '';
                    if ($qs !== '') {
                        $parts = explode('&', $qs);
                        sort($parts);
                        $path .= '?' . implode('&', $parts);
                    }
                }
                $this->client->track([
                    'method' => $request->method(),
                    'path' => $path,
                    'status_code' => 500,
                    'response_time_ms' => round($elapsedMs, 2),
                    'request_size' => (int)$request->header('Content-Length', '0'),
                    'response_size' => 0,
                    'consumer_id' => $this->identifyConsumer($request),
                ]);
            } catch (Throwable) {
                // Never crash
            }

            throw $e;
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        try {
            $statusCode = method_exists($response, 'getStatusCode')
                ? $response->getStatusCode()
                : (method_exists($response, 'status') ? $response->status() : 200);

            $responseSize = 0;
            if (method_exists($response, 'headers') && $response->headers->has('Content-Length')) {
                $responseSize = (int)$response->headers->get('Content-Length');
            } elseif (method_exists($response, 'getContent')) {
                $responseSize = strlen($response->getContent());
            }

            $path = '/' . ltrim($request->path(), '/');
            if ($this->client->getCollectQueryString()) {
                $qs = $request->getQueryString() ?? '';
                if ($qs !== '') {
                    $parts = explode('&', $qs);
                    sort($parts);
                    $path .= '?' . implode('&', $parts);
                }
            }
            $this->client->track([
                'method' => $request->method(),
                'path' => $path,
                'status_code' => $statusCode,
                'response_time_ms' => round($elapsedMs, 2),
                'request_size' => (int)$request->header('Content-Length', '0'),
                'response_size' => $responseSize,
                'consumer_id' => $this->identifyConsumer($request),
            ]);
        } catch (Throwable) {
            // Never crash the app
        }

        return $response;
    }

    private function identifyConsumer(mixed $request): ?string
    {
        $headers = [];
        if (method_exists($request, 'header')) {
            // Build a full headers dict for the callback
            if (method_exists($request, 'headers') && method_exists($request->headers, 'all')) {
                foreach ($request->headers->all() as $name => $values) {
                    $headers[strtolower($name)] = $values[0] ?? '';
                }
            } else {
                $apiKey = $request->header('x-api-key', '');
                if ($apiKey !== '') {
                    $headers['x-api-key'] = $apiKey;
                }
                $auth = $request->header('authorization', '');
                if ($auth !== '') {
                    $headers['authorization'] = $auth;
                }
            }
        }
        $cb = $this->client->getIdentifyConsumer();
        if ($cb !== null) {
            return $cb($headers);
        }
        return Consumer::defaultIdentifyConsumer($headers);
    }
}
