<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Lightweight HTTP test server that records payloads.
 * Uses PHP's built-in server via proc_open.
 */
class IngestServer
{
    private string $host = '127.0.0.1';
    private int $port;
    /** @var resource|null */
    private $process = null;
    private string $logFile;
    public string $payloadsFile;
    private string $serverScript;
    public int $responseStatus;

    public function __construct(int $status = 200)
    {
        $this->responseStatus = $status;
        $this->port = self::findFreePort();
        $this->logFile = tempnam(sys_get_temp_dir(), 'ingest-log-');
        $this->payloadsFile = tempnam(sys_get_temp_dir(), 'ingest-payloads-');
        $this->serverScript = tempnam(sys_get_temp_dir(), 'ingest-server-') . '.php';
    }

    public function start(): self
    {
        // Embed config directly in the router script
        $payloadsFile = addslashes($this->payloadsFile);
        $status = $this->responseStatus;

        $script = <<<PHP
<?php
\$payloadsFile = '{$payloadsFile}';
\$responseStatus = {$status};

\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$uri = \$_SERVER['REQUEST_URI'] ?? '/';

if (\$method === 'POST' && str_starts_with(\$uri, '/ingest')) {
    \$body = file_get_contents('php://input');
    file_put_contents(\$payloadsFile, \$body . "\\n", FILE_APPEND | LOCK_EX);
    http_response_code(\$responseStatus);
    header('Content-Type: application/json');
    \$events = json_decode(\$body, true);
    echo json_encode(['accepted' => is_array(\$events) ? count(\$events) : 0]);
} else {
    http_response_code(404);
    echo 'Not found';
}
PHP;

        file_put_contents($this->serverScript, $script);
        // Clear payloads file
        file_put_contents($this->payloadsFile, '');

        $cmd = sprintf(
            'php -S %s:%d %s',
            $this->host,
            $this->port,
            escapeshellarg($this->serverScript),
        );

        // Use proc_open to run in background
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'w'],
        ];

        $this->process = proc_open($cmd, $descriptors, $pipes);
        // Give server time to start
        usleep(300_000);

        return $this;
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        @unlink($this->serverScript);
        @unlink($this->logFile);
        @unlink($this->payloadsFile);
    }

    public function endpoint(): string
    {
        return "http://{$this->host}:{$this->port}/ingest";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allEvents(): array
    {
        $content = file_get_contents($this->payloadsFile);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $events = [];
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') continue;
            $parsed = json_decode($line, true);
            if (is_array($parsed)) {
                if (array_is_list($parsed)) {
                    array_push($events, ...$parsed);
                } else {
                    $events[] = $parsed;
                }
            }
        }
        return $events;
    }

    private static function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return $port;
    }
}

function tmpStoragePath(): string
{
    return sys_get_temp_dir() . '/peekapi-test-' . bin2hex(random_bytes(8)) . '.jsonl';
}
