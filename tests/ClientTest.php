<?php

declare(strict_types=1);

namespace PeekApi\Tests;

use PeekApi\Client;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    // --- Constructor validation ---

    public function testMissingApiKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key is required');
        new Client(['api_key' => '', 'endpoint' => 'https://example.com/ingest']);
    }

    public function testControlCharsInApiKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Client(['api_key' => "key\x00bad", 'endpoint' => 'https://example.com/ingest']);
    }

    public function testMissingEndpointUsesDefault(): void
    {
        $client = new Client(['api_key' => 'ak_test', 'endpoint' => '', 'storage_path' => tmpStoragePath()]);
        $this->assertStringContainsString('ingest.peekapi.dev', $client->getEndpoint());
        $client->shutdown();
    }

    public function testHttpEndpointForLocalhostAllowed(): void
    {
        $storagePath = tmpStoragePath();
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:9999/ingest',
            'storage_path' => $storagePath,
        ]);
        $this->assertSame('http://localhost:9999/ingest', $client->getEndpoint());
        @unlink($storagePath);
    }

    public function testHttpsEndpointRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS required');
        new Client(['api_key' => 'ak_test', 'endpoint' => 'http://example.com/ingest']);
    }

    // --- Track + buffer ---

    public function testTrackBuffersEvents(): void
    {
        $storagePath = tmpStoragePath();
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:9999/ingest',
            'storage_path' => $storagePath,
            'batch_size' => 1000,
        ]);

        $client->track([
            'method' => 'GET',
            'path' => '/api/users',
            'status_code' => 200,
            'response_time_ms' => 42.5,
        ]);

        $this->assertSame(1, $client->bufferCount());
        @unlink($storagePath);
    }

    public function testTrackSanitizesMethod(): void
    {
        $storagePath = tmpStoragePath();
        $server = (new \IngestServer())->start();

        try {
            $client = new Client([
                'api_key' => 'ak_test',
                'endpoint' => $server->endpoint(),
                'storage_path' => $storagePath,
                'batch_size' => 1,
            ]);

            $client->track([
                'method' => 'get',
                'path' => '/api/users',
                'status_code' => 200,
            ]);

            // Wait for flush to complete
            usleep(300_000);

            $events = $server->allEvents();
            $this->assertNotEmpty($events);
            $this->assertSame('GET', $events[0]['method']);
        } finally {
            $server->stop();
            @unlink($storagePath);
        }
    }

    public function testTrackNeverThrows(): void
    {
        $storagePath = tmpStoragePath();
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:9999/ingest',
            'storage_path' => $storagePath,
            'batch_size' => 1000,
        ]);

        // Track with missing fields should not throw
        $client->track([]);
        $this->assertSame(1, $client->bufferCount());
        @unlink($storagePath);
    }

    public function testTrackDropsOversizedEvents(): void
    {
        $storagePath = tmpStoragePath();
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:9999/ingest',
            'storage_path' => $storagePath,
            'max_event_bytes' => 100,
            'batch_size' => 1000,
        ]);

        $client->track([
            'method' => 'GET',
            'path' => str_repeat('x', 200),
            'status_code' => 200,
        ]);

        $this->assertSame(0, $client->bufferCount());
        @unlink($storagePath);
    }

    // --- Flush ---

    public function testFlushSendsEventsToServer(): void
    {
        $storagePath = tmpStoragePath();
        $server = (new \IngestServer())->start();

        try {
            $client = new Client([
                'api_key' => 'ak_test',
                'endpoint' => $server->endpoint(),
                'storage_path' => $storagePath,
                'batch_size' => 1000,
            ]);

            $client->track([
                'method' => 'GET',
                'path' => '/api/users',
                'status_code' => 200,
                'response_time_ms' => 42.5,
            ]);
            $client->track([
                'method' => 'POST',
                'path' => '/api/orders',
                'status_code' => 201,
                'response_time_ms' => 100.0,
            ]);

            $client->flush();
            usleep(100_000);

            $events = $server->allEvents();
            $this->assertCount(2, $events);
            $this->assertSame('GET', $events[0]['method']);
            $this->assertSame('/api/users', $events[0]['path']);
            $this->assertSame('POST', $events[1]['method']);
        } finally {
            $server->stop();
            @unlink($storagePath);
        }
    }

    public function testFlushIncludesTimestamp(): void
    {
        $storagePath = tmpStoragePath();
        $server = (new \IngestServer())->start();

        try {
            $client = new Client([
                'api_key' => 'ak_test',
                'endpoint' => $server->endpoint(),
                'storage_path' => $storagePath,
                'batch_size' => 1000,
            ]);

            $client->track([
                'method' => 'GET',
                'path' => '/test',
                'status_code' => 200,
            ]);
            $client->flush();
            usleep(100_000);

            $events = $server->allEvents();
            $this->assertNotEmpty($events);
            $this->assertArrayHasKey('timestamp', $events[0]);
        } finally {
            $server->stop();
            @unlink($storagePath);
        }
    }

    public function testFlushSendsHeaders(): void
    {
        $storagePath = tmpStoragePath();
        $server = (new \IngestServer())->start();

        try {
            $client = new Client([
                'api_key' => 'ak_test_key_123',
                'endpoint' => $server->endpoint(),
                'storage_path' => $storagePath,
                'batch_size' => 1000,
            ]);

            $client->track(['method' => 'GET', 'path' => '/test', 'status_code' => 200]);
            $client->flush();
            usleep(100_000);

            // Verify events arrived (header verification requires server-side support,
            // but we can verify the SDK didn't crash)
            $events = $server->allEvents();
            $this->assertCount(1, $events);
        } finally {
            $server->stop();
            @unlink($storagePath);
        }
    }

    // --- Disk persistence ---

    public function testPersistAndRecover(): void
    {
        $storagePath = tmpStoragePath();

        // Create client with unreachable endpoint — events will buffer
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:1/ingest',
            'storage_path' => $storagePath,
            'batch_size' => 1000,
        ]);

        $client->track(['method' => 'GET', 'path' => '/recover-test', 'status_code' => 200]);

        // Shutdown persists to disk
        $client->shutdown();

        // Verify file exists
        $this->assertTrue(
            file_exists($storagePath) || file_exists($storagePath . '.recovering'),
            'Persistence file should exist',
        );

        // New client should load from disk
        $client2 = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:1/ingest',
            'storage_path' => $storagePath,
            'batch_size' => 1000,
        ]);

        $this->assertGreaterThanOrEqual(1, $client2->bufferCount());

        $client2->shutdown();
        @unlink($storagePath);
        @unlink($storagePath . '.recovering');
    }

    public function testRuntimeDiskRecovery(): void
    {
        $storagePath = tmpStoragePath();

        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:1/ingest',
            'storage_path' => $storagePath,
            'batch_size' => 1000,
        ]);

        // Simulate events persisted to disk mid-process
        $events = [['method' => 'GET', 'path' => '/runtime-recover', 'status_code' => 200]];
        file_put_contents($storagePath, json_encode($events) . "\n");

        // Trigger runtime recovery via flush() (which checks disk recovery interval)
        // Set lastDiskRecovery far in the past so it triggers immediately
        $ref = new \ReflectionProperty($client, 'lastDiskRecovery');
        $ref->setValue($client, 0.0);

        $client->flush();

        $this->assertGreaterThanOrEqual(1, $client->bufferCount());

        $client->shutdown();
        @unlink($storagePath);
        @unlink($storagePath . '.recovering');
    }

    // --- Shutdown idempotent ---

    public function testShutdownIdempotent(): void
    {
        $storagePath = tmpStoragePath();
        $client = new Client([
            'api_key' => 'ak_test',
            'endpoint' => 'http://localhost:9999/ingest',
            'storage_path' => $storagePath,
        ]);

        // Should not throw when called multiple times
        $client->shutdown();
        $client->shutdown();
        $this->assertTrue(true);
        @unlink($storagePath);
    }

    // --- Consumer ID truncation ---

    public function testConsumerIdTruncated(): void
    {
        $storagePath = tmpStoragePath();
        $server = (new \IngestServer())->start();

        try {
            $client = new Client([
                'api_key' => 'ak_test',
                'endpoint' => $server->endpoint(),
                'storage_path' => $storagePath,
                'batch_size' => 1,
            ]);

            $longId = str_repeat('x', 500);
            $client->track([
                'method' => 'GET',
                'path' => '/test',
                'status_code' => 200,
                'consumer_id' => $longId,
            ]);

            usleep(300_000);
            $events = $server->allEvents();
            $this->assertNotEmpty($events);
            $this->assertSame(Client::MAX_CONSUMER_ID_LENGTH, strlen($events[0]['consumer_id']));
        } finally {
            $server->stop();
            @unlink($storagePath);
        }
    }
}
