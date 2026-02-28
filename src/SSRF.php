<?php

declare(strict_types=1);

namespace PeekApi;

use InvalidArgumentException;

final class SSRF
{
    /** Private/reserved IPv4 CIDR ranges. */
    private const array PRIVATE_NETWORKS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',   // CGNAT
        '127.0.0.0/8',     // Loopback
        '169.254.0.0/16',  // Link-local
        '0.0.0.0/8',       // "This" network
    ];

    /** Private/reserved IPv6 CIDR ranges. */
    private const array PRIVATE_NETWORKS_V6 = [
        '::1/128',         // Loopback
        'fe80::/10',       // Link-local
        'fc00::/7',        // ULA
    ];

    /**
     * Check if a hostname/IP is a private or reserved address.
     */
    public static function isPrivateIp(string $host): bool
    {
        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }

        $len = strlen($packed);

        // IPv6
        if ($len === 16) {
            // Check IPv4-mapped IPv6 (::ffff:x.x.x.x)
            $prefix = substr($packed, 0, 12);
            $v4mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if ($prefix === $v4mapped) {
                $v4 = inet_ntop(substr($packed, 12));
                if ($v4 !== false) {
                    return self::isPrivateIpV4($v4);
                }
            }

            foreach (self::PRIVATE_NETWORKS_V6 as $cidr) {
                if (self::ipInCidr($host, $cidr)) {
                    return true;
                }
            }
            return false;
        }

        // IPv4
        return self::isPrivateIpV4($host);
    }

    private static function isPrivateIpV4(string $ip): bool
    {
        foreach (self::PRIVATE_NETWORKS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $len = strlen($ipBin);
        $mask = str_repeat("\xff", intdiv($bits, 8));
        $remainder = $bits % 8;
        if ($remainder > 0) {
            $mask .= chr(0xff << (8 - $remainder) & 0xff);
        }
        $mask = str_pad($mask, $len, "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * Validate and normalize the ingestion endpoint URL.
     *
     * @throws InvalidArgumentException
     */
    public static function validateEndpoint(string $endpoint): string
    {
        if ($endpoint === '') {
            throw new InvalidArgumentException('endpoint is required');
        }

        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Invalid endpoint URL: {$endpoint}");
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Invalid endpoint URL: {$endpoint}");
        }

        $hostname = strtolower($parts['host']);
        $isLocalhost = in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https' && !$isLocalhost) {
            throw new InvalidArgumentException(
                "HTTPS required for non-localhost endpoint: {$endpoint}",
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Endpoint URL must not contain credentials');
        }

        if (!$isLocalhost && self::isPrivateIp($hostname)) {
            throw new InvalidArgumentException(
                "Endpoint resolves to private/reserved IP: {$hostname}",
            );
        }

        return $endpoint;
    }
}
