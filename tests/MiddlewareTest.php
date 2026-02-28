<?php

declare(strict_types=1);

namespace PeekApi\Tests;

use PeekApi\Client;
use PeekApi\Middleware\PSR15;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Stub client that records tracked events.
 */
class StubClient extends Client
{
    /** @var array<int, array<string, mixed>> */
    public array $events = [];
    /** @var (callable(array<string, string>): ?string)|null */
    private $customIdentifyConsumer = null;
    private bool $stubCollectQueryString = false;

    public function __construct()
    {
        // Don't call parent — we don't need real HTTP
    }

    public function track(array $event): void
    {
        $this->events[] = $event;
    }

    public function setIdentifyConsumer(?callable $cb): void
    {
        $this->customIdentifyConsumer = $cb;
    }

    public function getIdentifyConsumer(): ?callable
    {
        return $this->customIdentifyConsumer;
    }

    public function setCollectQueryString(bool $value): void
    {
        $this->stubCollectQueryString = $value;
    }

    public function getCollectQueryString(): bool
    {
        return $this->stubCollectQueryString;
    }

    public function shutdown(): void {}
}

/**
 * Test handler that returns a fixed response.
 */
class TestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = 'OK',
        private readonly array $headers = [],
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response($this->status);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $factory = new Psr17Factory();
        $stream = $factory->createStream($this->body);
        return $response->withBody($stream);
    }
}

/**
 * Test handler that throws an exception.
 */
class RaisingHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('boom');
    }
}

final class MiddlewareTest extends TestCase
{
    public function testOkResponseTracked(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler(200, 'Hello');
        $request = new ServerRequest('GET', '/api/users');

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $stub->events);

        $event = $stub->events[0];
        $this->assertSame('GET', $event['method']);
        $this->assertSame('/api/users', $event['path']);
        $this->assertSame(200, $event['status_code']);
        $this->assertIsFloat($event['response_time_ms']);
        $this->assertGreaterThanOrEqual(0, $event['response_time_ms']);
    }

    public function test500ResponseTracked(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler(500, 'Error');
        $request = new ServerRequest('POST', '/api/orders');

        $response = $middleware->process($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $stub->events);
        $this->assertSame(500, $stub->events[0]['status_code']);
    }

    public function testConsumerIdFromApiKey(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = (new ServerRequest('GET', '/api/users'))
            ->withHeader('x-api-key', 'ak_live_abc');

        $middleware->process($request, $handler);

        $this->assertSame('ak_live_abc', $stub->events[0]['consumer_id']);
    }

    public function testConsumerIdFromAuthorization(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = (new ServerRequest('GET', '/api/users'))
            ->withHeader('Authorization', 'Bearer token123');

        $middleware->process($request, $handler);

        $consumer = $stub->events[0]['consumer_id'];
        $this->assertStringStartsWith('hash_', $consumer);
        $this->assertSame(17, strlen($consumer));
    }

    public function testCustomIdentifyConsumer(): void
    {
        $stub = new StubClient();
        $stub->setIdentifyConsumer(fn(array $headers) => $headers['x-tenant-id'] ?? null);
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = (new ServerRequest('GET', '/api/users'))
            ->withHeader('x-tenant-id', 'tenant-42')
            ->withHeader('x-api-key', 'ignored');

        $middleware->process($request, $handler);

        $this->assertSame('tenant-42', $stub->events[0]['consumer_id']);
    }

    public function testNoClientPassthrough(): void
    {
        $middleware = new PSR15(null);
        $handler = new TestHandler(200, 'OK');
        $request = new ServerRequest('GET', '/test');

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareNeverRaisesOnTrackingError(): void
    {
        // Create a mock client that throws on track
        $badClient = new class extends StubClient {
            public function track(array $event): void
            {
                throw new RuntimeException('tracking failed');
            }
        };

        $middleware = new PSR15($badClient);
        $handler = new TestHandler();
        $request = new ServerRequest('GET', '/test');

        $response = $middleware->process($request, $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAppExceptionStillTracked(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new RaisingHandler();
        $request = new ServerRequest('GET', '/fail');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $middleware->process($request, $handler);
        } finally {
            $this->assertCount(1, $stub->events);
            $this->assertSame(500, $stub->events[0]['status_code']);
            $this->assertSame('/fail', $stub->events[0]['path']);
        }
    }

    public function testResponseSizeFromContentLength(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler(200, 'Hello', ['Content-Length' => '5']);
        $request = new ServerRequest('GET', '/test');

        $middleware->process($request, $handler);

        $this->assertSame(5, $stub->events[0]['response_size']);
    }

    public function testPostMethodCaptured(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = new ServerRequest('POST', '/api/create');

        $middleware->process($request, $handler);

        $this->assertSame('POST', $stub->events[0]['method']);
    }

    public function testRequestSizeCaptured(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = (new ServerRequest('POST', '/api/data'))
            ->withHeader('Content-Length', '1024');

        $middleware->process($request, $handler);

        $this->assertSame(1024, $stub->events[0]['request_size']);
    }

    public function testCollectQueryStringDisabledByDefault(): void
    {
        $stub = new StubClient();
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = new ServerRequest('GET', '/search?z=3&a=1');

        $middleware->process($request, $handler);

        $this->assertSame('/search', $stub->events[0]['path']);
    }

    public function testCollectQueryStringEnabled(): void
    {
        $stub = new StubClient();
        $stub->setCollectQueryString(true);
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = new ServerRequest('GET', '/search?z=3&a=1');

        $middleware->process($request, $handler);

        $this->assertSame('/search?a=1&z=3', $stub->events[0]['path']);
    }

    public function testCollectQueryStringSortsParams(): void
    {
        $stub = new StubClient();
        $stub->setCollectQueryString(true);
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = new ServerRequest('GET', '/users?role=admin&name=alice');

        $middleware->process($request, $handler);

        $this->assertSame('/users?name=alice&role=admin', $stub->events[0]['path']);
    }

    public function testCollectQueryStringNoQs(): void
    {
        $stub = new StubClient();
        $stub->setCollectQueryString(true);
        $middleware = new PSR15($stub);
        $handler = new TestHandler();
        $request = new ServerRequest('GET', '/users');

        $middleware->process($request, $handler);

        $this->assertSame('/users', $stub->events[0]['path']);
    }
}
