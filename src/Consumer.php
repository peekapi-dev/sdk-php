<?php

declare(strict_types=1);

namespace PeekApi;

final class Consumer
{
    /**
     * SHA-256 hash truncated to 12 hex chars, prefixed with "hash_".
     */
    public static function hashConsumerId(string $raw): string
    {
        $digest = substr(hash('sha256', $raw), 0, 12);
        return "hash_{$digest}";
    }

    /**
     * Identify consumer from request headers.
     *
     * Priority:
     *   1. x-api-key (stored as-is)
     *   2. Authorization (hashed — contains credentials)
     *
     * Header keys are expected to be lowercase.
     */
    public static function defaultIdentifyConsumer(array $headers): ?string
    {
        $apiKey = $headers['x-api-key'] ?? null;
        if ($apiKey !== null && $apiKey !== '') {
            return $apiKey;
        }

        $auth = $headers['authorization'] ?? null;
        if ($auth !== null && $auth !== '') {
            return self::hashConsumerId($auth);
        }

        return null;
    }
}
