<?php

declare(strict_types=1);

namespace PeekApi\Middleware;

use PeekApi\Client;
use PeekApi\Consumer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 middleware for tracking API usage.
 *
 * Works with any PSR-15 compatible framework (Slim, Mezzio, etc.).
 */
final class PSR15 implements MiddlewareInterface
{
    public function __construct(private readonly ?Client $client = null) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->client === null) {
            return $handler->handle($request);
        }

        $start = hrtime(true);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            try {
                $path = $request->getUri()->getPath();
                if ($this->client->getCollectQueryString()) {
                    $qs = $request->getUri()->getQuery();
                    if ($qs !== '') {
                        $parts = explode('&', $qs);
                        sort($parts);
                        $path .= '?' . implode('&', $parts);
                    }
                }
                $this->client->track([
                    'method' => $request->getMethod(),
                    'path' => $path,
                    'status_code' => 500,
                    'response_time_ms' => round($elapsedMs, 2),
                    'request_size' => $this->requestSize($request),
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
            $responseSize = 0;
            $contentLength = $response->getHeaderLine('Content-Length');
            if ($contentLength !== '') {
                $responseSize = (int) $contentLength;
            } else {
                $responseSize = $response->getBody()->getSize() ?? 0;
            }

            $path = $request->getUri()->getPath();
            if ($this->client->getCollectQueryString()) {
                $qs = $request->getUri()->getQuery();
                if ($qs !== '') {
                    $parts = explode('&', $qs);
                    sort($parts);
                    $path .= '?' . implode('&', $parts);
                }
            }
            $this->client->track([
                'method' => $request->getMethod(),
                'path' => $path,
                'status_code' => $response->getStatusCode(),
                'response_time_ms' => round($elapsedMs, 2),
                'request_size' => $this->requestSize($request),
                'response_size' => $responseSize,
                'consumer_id' => $this->identifyConsumer($request),
            ]);
        } catch (Throwable) {
            // Never crash the app
        }

        return $response;
    }

    private function identifyConsumer(ServerRequestInterface $request): ?string
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        $cb = $this->client->getIdentifyConsumer();
        if ($cb !== null) {
            return $cb($headers);
        }
        return Consumer::defaultIdentifyConsumer($headers);
    }

    private function requestSize(ServerRequestInterface $request): int
    {
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '') {
            return (int) $contentLength;
        }
        return $request->getBody()->getSize() ?? 0;
    }
}
