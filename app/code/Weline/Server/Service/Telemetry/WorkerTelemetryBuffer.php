<?php

declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

/**
 * Process-local request counters for Worker -> Master telemetry.
 *
 * Ordinary requests are reduced to one aggregate sample per host/minute and
 * sent periodically. Server errors and latency timeouts bypass the buffer so
 * the control plane can react immediately without imposing one IPC write per
 * healthy request.
 */
final class WorkerTelemetryBuffer
{
    private const BUCKET_SECONDS = 60;

    /** @var array<string, array<string, int|string>> */
    private array $buckets = [];

    private int $pendingRequests = 0;

    private float $lastFlushAt = 0.0;

    public function __construct(
        private readonly string $instanceName,
        private readonly int $maxPendingRequests = 64,
        private readonly float $flushIntervalSeconds = 0.25,
        private readonly int $immediateLatencyMs = 1000,
    ) {
        if ($this->maxPendingRequests < 1 || $this->maxPendingRequests > 4096) {
            throw new \InvalidArgumentException('Telemetry batch size must be between 1 and 4096.');
        }
        if ($this->flushIntervalSeconds <= 0.0 || $this->flushIntervalSeconds > 60.0) {
            throw new \InvalidArgumentException('Telemetry flush interval must be between 0 and 60 seconds.');
        }
    }

    /**
     * @return array{immediate:?array<string,int|string>,batch:list<array<string,int|string>>}
     */
    public function record(
        string $host,
        int $status,
        int $latencyMs,
        int $bytesOut,
        int $timestamp = 0,
    ): array {
        $timestamp = $timestamp > 0 ? $timestamp : \time();
        $host = $this->normalizeHost($host);
        $status = ($status >= 100 && $status <= 599) ? $status : 500;
        $latencyMs = \max(0, \min(600_000, $latencyMs));
        $bytesOut = \max(0, \min(512 * 1024 * 1024, $bytesOut));

        if ($status >= 500 || $status === 408 || $latencyMs >= $this->immediateLatencyMs) {
            return [
                'immediate' => [
                    'instance' => $this->normalizedInstanceName(),
                    'host' => $host,
                    'status' => $status,
                    'latency_ms' => $latencyMs,
                    'bytes_out' => $bytesOut,
                    'ts' => $timestamp,
                ],
                'batch' => $this->flushIfDue(),
            ];
        }

        $now = \microtime(true);
        if ($this->pendingRequests === 0) {
            $this->lastFlushAt = $now;
        }
        $bucketTs = \intdiv($timestamp, self::BUCKET_SECONDS) * self::BUCKET_SECONDS;
        $key = $host . '|' . $bucketTs;
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'instance' => $this->normalizedInstanceName(),
                'host' => $host,
                'bucket_ts' => $bucketTs,
                'request_count' => 0,
                'error_count' => 0,
                'bytes_out' => 0,
                'latency_total_ms' => 0,
                'latency_max_ms' => 0,
            ];
        }

        $bucket = &$this->buckets[$key];
        $bucket['request_count'] = (int)$bucket['request_count'] + 1;
        $bucket['bytes_out'] = (int)$bucket['bytes_out'] + $bytesOut;
        $bucket['latency_total_ms'] = (int)$bucket['latency_total_ms'] + $latencyMs;
        $bucket['latency_max_ms'] = \max((int)$bucket['latency_max_ms'], $latencyMs);
        unset($bucket);
        $this->pendingRequests++;

        return [
            'immediate' => null,
            'batch' => $this->pendingRequests >= $this->maxPendingRequests ? $this->drain() : [],
        ];
    }

    /** @return list<array<string, int|string>> */
    public function flushIfDue(?float $now = null): array
    {
        if ($this->pendingRequests === 0) {
            return [];
        }
        $now ??= \microtime(true);
        if (($now - $this->lastFlushAt) < $this->flushIntervalSeconds) {
            return [];
        }

        return $this->drain($now);
    }

    /** @return list<array<string, int|string>> */
    public function drain(?float $now = null): array
    {
        if ($this->pendingRequests === 0) {
            return [];
        }
        $batch = \array_values($this->buckets);
        $this->buckets = [];
        $this->pendingRequests = 0;
        $this->lastFlushAt = $now ?? \microtime(true);

        return $batch;
    }

    public function pendingRequestCount(): int
    {
        return $this->pendingRequests;
    }

    private function normalizedInstanceName(): string
    {
        $instance = \trim($this->instanceName);
        return $instance !== '' ? \substr($instance, 0, 128) : 'default';
    }

    private function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return 'unknown';
        }
        if ($host[0] === '[') {
            $end = \strpos($host, ']');
            $host = $end === false ? $host : \substr($host, 1, $end - 1);
        } elseif (\substr_count($host, ':') === 1) {
            $host = (string)\explode(':', $host, 2)[0];
        }

        return \substr($host !== '' ? $host : 'unknown', 0, 255);
    }
}
