<?php

declare(strict_types=1);

namespace PeekApi;

use InvalidArgumentException;
use Throwable;

class Client
{
    // --- Constants ---
    public const string DEFAULT_ENDPOINT = 'https://ingest.peekapi.dev/v1/events';
    public const int DEFAULT_FLUSH_INTERVAL = 15;        // seconds
    public const int DEFAULT_BATCH_SIZE = 250;
    public const int DEFAULT_MAX_BUFFER_SIZE = 10_000;
    public const int DEFAULT_MAX_STORAGE_BYTES = 5_242_880;  // 5 MB
    public const int DEFAULT_MAX_EVENT_BYTES = 65_536;       // 64 KB
    public const int MAX_PATH_LENGTH = 2_048;
    public const int MAX_METHOD_LENGTH = 16;
    public const int MAX_CONSUMER_ID_LENGTH = 256;
    public const int MAX_CONSECUTIVE_FAILURES = 5;
    public const float BASE_BACKOFF_S = 1.0;
    public const int SEND_TIMEOUT_S = 5;
    public const int DISK_RECOVERY_INTERVAL_S = 60;
    public const array RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    private readonly string $apiKey;
    private readonly string $endpoint;
    private readonly float $flushInterval;
    private readonly int $batchSize;
    private readonly int $maxBufferSize;
    private readonly int $maxStorageBytes;
    private readonly int $maxEventBytes;
    private readonly string $storagePath;
    private readonly bool $debug;
    /** NOTE: increases DB usage — each unique path+query creates a separate endpoint row. */
    private readonly bool $collectQueryString;
    /** @var (callable(Throwable): void)|null */
    private $onError;
    /** @var (callable(array<string, string>): ?string)|null */
    private $identifyConsumer;

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];
    private int $consecutiveFailures = 0;
    private float $backoffUntil = 0.0;
    private bool $isShutdown = false;
    private ?string $recoveryPath = null;
    private float $lastDiskRecovery;

    /**
     * @param array{
     *   api_key: string,
     *   endpoint?: string,
     *   flush_interval?: int|float,
     *   batch_size?: int,
     *   max_buffer_size?: int,
     *   max_storage_bytes?: int,
     *   max_event_bytes?: int,
     *   storage_path?: string,
     *   debug?: bool,
     *   on_error?: callable(Throwable): void,
     * } $options
     */
    public function __construct(array $options)
    {
        $apiKey = $options['api_key'] ?? '';
        if ($apiKey === '') {
            throw new InvalidArgumentException('api_key is required');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw new InvalidArgumentException('api_key contains invalid control characters');
        }
        $this->apiKey = $apiKey;

        $rawEndpoint = ($options['endpoint'] ?? '') ?: self::DEFAULT_ENDPOINT;
        $this->endpoint = SSRF::validateEndpoint($rawEndpoint);

        $this->flushInterval = (float) ($options['flush_interval'] ?? self::DEFAULT_FLUSH_INTERVAL);
        $this->batchSize = (int) ($options['batch_size'] ?? self::DEFAULT_BATCH_SIZE);
        $this->maxBufferSize = (int) ($options['max_buffer_size'] ?? self::DEFAULT_MAX_BUFFER_SIZE);
        $this->maxStorageBytes = (int) ($options['max_storage_bytes'] ?? self::DEFAULT_MAX_STORAGE_BYTES);
        $this->maxEventBytes = (int) ($options['max_event_bytes'] ?? self::DEFAULT_MAX_EVENT_BYTES);
        $this->debug = (bool) ($options['debug'] ?? false);
        $this->collectQueryString = (bool) ($options['collect_query_string'] ?? false);
        $this->onError = $options['on_error'] ?? null;
        $this->identifyConsumer = $options['identify_consumer'] ?? null;

        // Storage path
        if (isset($options['storage_path'])) {
            $this->storagePath = $options['storage_path'];
        } else {
            $h = substr(hash('sha256', $this->endpoint), 0, 12);
            $this->storagePath = sys_get_temp_dir() . "/peekapi-events-{$h}.jsonl";
        }

        $this->lastDiskRecovery = microtime(true);

        // Load persisted events from disk
        $this->loadFromDisk();

        // Register shutdown function for graceful cleanup
        register_shutdown_function([$this, 'shutdown']);
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getCollectQueryString(): bool
    {
        return $this->collectQueryString;
    }

    /** @return (callable(array<string, string>): ?string)|null */
    public function getIdentifyConsumer(): ?callable
    {
        return $this->identifyConsumer;
    }

    /**
     * Buffer an analytics event. Never throws.
     *
     * @param array<string, mixed> $event
     */
    public function track(array $event): void
    {
        try {
            $this->trackInner($event);
        } catch (Throwable $e) {
            $this->debugLog("[peekapi] track() error: {$e->getMessage()}\n");
        }
    }

    /**
     * Flush buffered events synchronously.
     */
    public function flush(): void
    {
        // Periodically recover persisted events from disk
        $now = microtime(true);
        if ($now - $this->lastDiskRecovery >= self::DISK_RECOVERY_INTERVAL_S) {
            $this->lastDiskRecovery = $now;
            $this->loadFromDisk();
        }

        $batch = $this->drainBatch();
        if ($batch === []) {
            return;
        }
        $this->doFlush($batch);
    }

    /**
     * Graceful shutdown: final flush, persist remainder.
     */
    public function shutdown(): void
    {
        if ($this->isShutdown) {
            return;
        }
        $this->isShutdown = true;

        // Final flush
        $this->flush();

        // Persist remainder
        if ($this->buffer !== []) {
            $this->persistToDisk($this->buffer);
            $this->buffer = [];
        }
    }

    /** @return int Current buffer count (for testing). */
    public function bufferCount(): int
    {
        return count($this->buffer);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function trackInner(array $event): void
    {
        if ($this->isShutdown) {
            return;
        }

        // Sanitize
        $event['method'] = strtoupper(substr((string) ($event['method'] ?? ''), 0, self::MAX_METHOD_LENGTH));
        $event['path'] = substr((string) ($event['path'] ?? ''), 0, self::MAX_PATH_LENGTH);
        if (isset($event['consumer_id'])) {
            $event['consumer_id'] = substr((string) $event['consumer_id'], 0, self::MAX_CONSUMER_ID_LENGTH);
        }

        // Timestamp
        $event['timestamp'] ??= gmdate('Y-m-d\TH:i:s.v\Z');

        // Per-event size limit
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        if (strlen($raw) > $this->maxEventBytes) {
            unset($event['metadata']);
            $raw = json_encode($event, JSON_THROW_ON_ERROR);
            if (strlen($raw) > $this->maxEventBytes) {
                $this->debugLog("[peekapi] event too large, dropping (" . strlen($raw) . " bytes)\n");
                return;
            }
        }

        if (count($this->buffer) >= $this->maxBufferSize) {
            // Buffer full — flush immediately
            $this->flush();
            return;
        }

        $this->buffer[] = $event;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function drainBatch(): array
    {
        if ($this->buffer === []) {
            return [];
        }

        $now = microtime(true);
        if ($now < $this->backoffUntil) {
            return [];
        }

        $batch = array_splice($this->buffer, 0, $this->batchSize);
        return $batch;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function doFlush(array $batch): void
    {
        try {
            $this->sendEvents($batch);

            // Success
            $this->consecutiveFailures = 0;
            $this->backoffUntil = 0.0;
            $this->cleanupRecoveryFile();

            $this->debugLog("[peekapi] flushed " . count($batch) . " events\n");
        } catch (NonRetryableException $e) {
            $this->persistToDisk($batch);
            $this->callOnError($e);
            $this->debugLog("[peekapi] non-retryable error, persisted to disk: {$e->getMessage()}\n");
        } catch (Throwable $e) {
            $this->consecutiveFailures++;
            $failures = $this->consecutiveFailures;

            if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                $this->consecutiveFailures = 0;
                $this->persistToDisk($batch);
            } else {
                // Re-insert at front
                $space = $this->maxBufferSize - count($this->buffer);
                $reinsert = array_slice($batch, 0, $space);
                if ($reinsert !== []) {
                    array_unshift($this->buffer, ...$reinsert);
                }
                // Exponential backoff with jitter
                $delay = self::BASE_BACKOFF_S * (2 ** ($failures - 1)) * (mt_rand(50, 100) / 100.0);
                $this->backoffUntil = microtime(true) + $delay;
            }

            $this->callOnError($e);
            $this->debugLog("[peekapi] flush failed (attempt {$failures}): {$e->getMessage()}\n");
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function sendEvents(array $events): void
    {
        $body = json_encode($events, JSON_THROW_ON_ERROR);

        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new RetryableException('Failed to initialize curl');
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "x-api-key: {$this->apiKey}",
                    'x-peekapi-sdk: php/' . Version::VERSION,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::SEND_TIMEOUT_S,
                CURLOPT_TIMEOUT => self::SEND_TIMEOUT_S,
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                $error = curl_error($ch);
                throw new RetryableException("Network error: {$error}");
            }

            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($status >= 200 && $status < 300) {
                return;
            }

            $responseBody = is_string($response) ? substr($response, 0, 1024) : '';

            if (in_array($status, self::RETRYABLE_STATUS_CODES, true)) {
                throw new RetryableException("HTTP {$status}: {$responseBody}");
            }

            throw new NonRetryableException("HTTP {$status}: {$responseBody}");
        } finally {
            curl_close($ch);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function persistToDisk(array $events): void
    {
        if ($events === []) {
            return;
        }

        try {
            $path = $this->storagePath;
            $size = file_exists($path) ? filesize($path) : 0;

            if ($size !== false && $size >= $this->maxStorageBytes) {
                $this->debugLog("[peekapi] storage file full, dropping " . count($events) . " events\n");
                return;
            }

            $line = json_encode($events, JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            chmod($path, 0600);
        } catch (Throwable $e) {
            $this->debugLog("[peekapi] disk persist failed: {$e->getMessage()}\n");
        }
    }

    private function loadFromDisk(): void
    {
        $recovery = $this->storagePath . '.recovering';

        foreach ([$recovery, $this->storagePath] as $path) {
            if (!is_file($path)) {
                continue;
            }

            try {
                $content = file_get_contents($path);
                if ($content === false) {
                    continue;
                }

                $events = [];
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $parsed = json_decode($line, true);
                    if (is_array($parsed)) {
                        // Check if it's a list of events or a single event
                        if (array_is_list($parsed)) {
                            array_push($events, ...$parsed);
                        } else {
                            $events[] = $parsed;
                        }
                    }

                    if (count($events) >= $this->maxBufferSize) {
                        break;
                    }
                }

                if ($events !== []) {
                    $this->buffer = array_merge(
                        $this->buffer,
                        array_slice($events, 0, $this->maxBufferSize),
                    );
                    $this->debugLog("[peekapi] loaded " . count($events) . " events from disk\n");
                }

                // Rename to .recovering so we don't double-load
                if ($path === $this->storagePath) {
                    $rpath = $this->storagePath . '.recovering';
                    if (!@rename($path, $rpath)) {
                        @unlink($path);
                    }
                    $this->recoveryPath = $rpath;
                } else {
                    $this->recoveryPath = $path;
                }

                break; // loaded from one file, done
            } catch (Throwable $e) {
                $this->debugLog("[peekapi] disk load failed from {$path}: {$e->getMessage()}\n");
            }
        }
    }

    private function cleanupRecoveryFile(): void
    {
        if ($this->recoveryPath === null) {
            return;
        }
        @unlink($this->recoveryPath);
        $this->recoveryPath = null;
    }

    private function callOnError(Throwable $e): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($e);
        } catch (Throwable) {
            // ignore
        }
    }

    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }
        if (defined('STDERR')) {
            fwrite(STDERR, $message);
        } else {
            error_log(rtrim($message, "\n"));
        }
    }
}

class RetryableException extends \RuntimeException {}
class NonRetryableException extends \RuntimeException {}
