<?php

declare(strict_types=1);

namespace PeekApi\Tests;

use PeekApi\Consumer;
use PHPUnit\Framework\TestCase;

final class ConsumerTest extends TestCase
{
    // --- hashConsumerId ---

    public function testFormatPrefixAndLength(): void
    {
        $result = Consumer::hashConsumerId('Bearer token123');
        $this->assertStringStartsWith('hash_', $result);
        // hash_ (5 chars) + 12 hex chars = 17
        $this->assertSame(17, strlen($result));
    }

    public function testDeterministic(): void
    {
        $a = Consumer::hashConsumerId('same-value');
        $b = Consumer::hashConsumerId('same-value');
        $this->assertSame($a, $b);
    }

    public function testDifferentInputsProduceDifferentHashes(): void
    {
        $a = Consumer::hashConsumerId('value-a');
        $b = Consumer::hashConsumerId('value-b');
        $this->assertNotSame($a, $b);
    }

    public function testHexOutput(): void
    {
        $result = Consumer::hashConsumerId('test');
        $hexPart = substr($result, 5); // after "hash_"
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hexPart);
    }

    // --- defaultIdentifyConsumer ---

    public function testApiKeyReturnedAsIs(): void
    {
        $headers = ['x-api-key' => 'ak_live_abc123'];
        $this->assertSame('ak_live_abc123', Consumer::defaultIdentifyConsumer($headers));
    }

    public function testApiKeyPriorityOverAuthorization(): void
    {
        $headers = [
            'x-api-key' => 'ak_live_abc123',
            'authorization' => 'Bearer token',
        ];
        $this->assertSame('ak_live_abc123', Consumer::defaultIdentifyConsumer($headers));
    }

    public function testAuthorizationHashed(): void
    {
        $headers = ['authorization' => 'Bearer secret-token'];
        $result = Consumer::defaultIdentifyConsumer($headers);
        $this->assertStringStartsWith('hash_', $result);
        $this->assertSame(17, strlen($result));
    }

    public function testNoHeadersReturnsNull(): void
    {
        $this->assertNull(Consumer::defaultIdentifyConsumer([]));
    }

    public function testEmptyApiKeyFallsThrough(): void
    {
        $headers = ['x-api-key' => '', 'authorization' => 'Bearer x'];
        $result = Consumer::defaultIdentifyConsumer($headers);
        $this->assertStringStartsWith('hash_', $result);
    }
}
