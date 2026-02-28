<?php

declare(strict_types=1);

namespace PeekApi\Tests;

use PeekApi\SSRF;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SSRFTest extends TestCase
{
    // --- isPrivateIp ---

    public function testRfc1918Ten(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('10.0.0.1'));
        $this->assertTrue(SSRF::isPrivateIp('10.255.255.255'));
    }

    public function testRfc1918OneSeventyTwo(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('172.16.0.1'));
        $this->assertTrue(SSRF::isPrivateIp('172.31.255.255'));
    }

    public function testRfc1918OneNinetyTwo(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('192.168.0.1'));
        $this->assertTrue(SSRF::isPrivateIp('192.168.255.255'));
    }

    public function testCgnat(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('100.64.0.1'));
        $this->assertTrue(SSRF::isPrivateIp('100.127.255.255'));
    }

    public function testLoopback(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('127.0.0.1'));
    }

    public function testZero(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('0.0.0.0'));
    }

    public function testLinkLocal(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('169.254.1.1'));
    }

    public function testIpv6Loopback(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('::1'));
    }

    public function testIpv6LinkLocal(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('fe80::1'));
    }

    public function testIpv4MappedIpv6Private(): void
    {
        $this->assertTrue(SSRF::isPrivateIp('::ffff:10.0.0.1'));
        $this->assertTrue(SSRF::isPrivateIp('::ffff:192.168.1.1'));
    }

    public function testPublicIpAllowed(): void
    {
        $this->assertFalse(SSRF::isPrivateIp('8.8.8.8'));
        $this->assertFalse(SSRF::isPrivateIp('1.1.1.1'));
        $this->assertFalse(SSRF::isPrivateIp('203.0.113.1'));
    }

    public function testHostnameReturnsFalse(): void
    {
        $this->assertFalse(SSRF::isPrivateIp('example.com'));
    }

    // --- validateEndpoint ---

    public function testEmptyRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SSRF::validateEndpoint('');
    }

    public function testHttpsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS required');
        SSRF::validateEndpoint('http://example.com/ingest');
    }

    public function testHttpAllowedForLocalhost(): void
    {
        $result = SSRF::validateEndpoint('http://localhost:3000/ingest');
        $this->assertSame('http://localhost:3000/ingest', $result);
    }

    public function testHttpAllowedFor127(): void
    {
        $result = SSRF::validateEndpoint('http://127.0.0.1:3000/ingest');
        $this->assertSame('http://127.0.0.1:3000/ingest', $result);
    }

    public function testBlocksPrivateIps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SSRF::validateEndpoint('https://10.0.0.1/ingest');
    }

    public function testBlocksPrivateIps192(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SSRF::validateEndpoint('https://192.168.1.1/ingest');
    }

    public function testBlocksCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('credentials');
        SSRF::validateEndpoint('https://user:pass@example.com/ingest');
    }

    public function testValidHttps(): void
    {
        $result = SSRF::validateEndpoint('https://example.com/functions/v1/ingest');
        $this->assertSame('https://example.com/functions/v1/ingest', $result);
    }

    public function testMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SSRF::validateEndpoint('not-a-url');
    }
}
