<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

class InMemoryMetricsAggregator implements MetricsAggregatorInterface, MetricsQueryInterface
{
    private const BUCKET_SECONDS = 60;
    private const FLUSH_INTERVAL_SECONDS = 30;

    /** @var array<string,array<string,int|string>> */
    private static array $buckets = [];
    /** @var array<int,array{instance:string,host:string,bucket_ts:int,metric_type:string,payload:array}> */
    private static array $retryQueue = [];
    private static int $lastFlushAt = 0;

    public function __construct(
        private readonly MetricsFlushScheduler $flushScheduler
    ) {
    }

    public function record(array $event): void
    {
        $instance = (string)($event['instance'] ?? 'default');
        $hostRaw = (string)($event['host'] ?? '');
        $host = $hostRaw !== '' ? \strtolower($hostRaw) : 'unknown';
        $bucketTs = $this->bucketTs((int)($event['ts'] ?? \time()));
        $status = (int)($event['status'] ?? 200);
        $latency = (int)\max(0, (int)($event['latency_ms'] ?? 0));
        $bytesOut = (int)\max(0, (int)($event['bytes_out'] ?? 0));

        $this->recordBucket($instance, '*', $bucketTs, $status, $latency, $bytesOut);
        $this->recordBucket($instance, $host, $bucketTs, $status, $latency, $bytesOut);

        $this->flushDueBuckets(false);
    }

    public function snapshotGlobal(string $instanceName, int $sinceTs): array
    {
        return $this->aggregateBuckets($instanceName, '*', $sinceTs);
    }

    public function snapshotByHost(string $instanceName, int $sinceTs): array
    {
        $hostGroups = [];
        foreach (self::$buckets as $bucket) {
            if (($bucket['instance'] ?? '') !== $instanceName) {
                continue;
            }
            $host = (string)($bucket['host'] ?? '');
            if ($host === '' || $host === '*') {
                continue;
            }
            $bucketTs = (int)($bucket['bucket_ts'] ?? 0);
            if ($bucketTs < $sinceTs) {
                continue;
            }
            if (!isset($hostGroups[$host])) {
                $hostGroups[$host] = $this->emptyAggregate($instanceName, $host, $sinceTs);
            }
            $this->mergeAggregate($hostGroups[$host], $bucket);
        }

        \uasort($hostGroups, static fn(array $a, array $b): int => ($b['request_count'] ?? 0) <=> ($a['request_count'] ?? 0));
        return \array_values($hostGroups);
    }

    public function snapshotHostDetail(string $instanceName, string $host, int $sinceTs): array
    {
        return $this->aggregateBuckets($instanceName, \strtolower($host), $sinceTs);
    }

    public function query(string $instanceName, int $windowSec = 300, ?string $host = null): array
    {
        $windowSec = \max(60, $windowSec);
        $sinceTs = \time() - $windowSec;
        return $host === null || $host === ''
            ? $this->snapshotGlobal($instanceName, $sinceTs)
            : $this->snapshotHostDetail($instanceName, $host, $sinceTs);
    }

    public function flushDueBuckets(bool $force = false): array
    {
        $now = \time();
        if (!$force && ($now - self::$lastFlushAt) < self::FLUSH_INTERVAL_SECONDS) {
            return ['flushed' => 0, 'retry_queued' => \count(self::$retryQueue)];
        }
        self::$lastFlushAt = $now;

        $flushed = 0;
        $closedBefore = $force ? PHP_INT_MAX : ($this->bucketTs($now) - self::BUCKET_SECONDS);

        foreach (self::$buckets as $key => $bucket) {
            $bucketTs = (int)($bucket['bucket_ts'] ?? 0);
            if ($bucketTs > $closedBefore) {
                continue;
            }
            $ok = $this->flushScheduler->upsertMetric(
                (string)($bucket['instance'] ?? 'default'),
                (string)($bucket['host'] ?? '*'),
                $bucketTs,
                'traffic',
                $bucket
            );
            if ($ok) {
                unset(self::$buckets[$key]);
                $flushed++;
            } else {
                self::$retryQueue[] = [
                    'instance' => (string)($bucket['instance'] ?? 'default'),
                    'host' => (string)($bucket['host'] ?? '*'),
                    'bucket_ts' => $bucketTs,
                    'metric_type' => 'traffic',
                    'payload' => $bucket,
                ];
            }
        }

        if (!empty(self::$retryQueue)) {
            $remaining = [];
            foreach (self::$retryQueue as $item) {
                $ok = $this->flushScheduler->upsertMetric(
                    $item['instance'],
                    $item['host'],
                    (int)$item['bucket_ts'],
                    $item['metric_type'],
                    $item['payload']
                );
                if ($ok) {
                    $flushed++;
                    continue;
                }
                $remaining[] = $item;
            }
            self::$retryQueue = $remaining;
        }

        return ['flushed' => $flushed, 'retry_queued' => \count(self::$retryQueue)];
    }

    private function recordBucket(
        string $instance,
        string $host,
        int $bucketTs,
        int $status,
        int $latencyMs,
        int $bytesOut
    ): void {
        $key = $this->buildKey($instance, $host, $bucketTs);
        if (!isset(self::$buckets[$key])) {
            self::$buckets[$key] = [
                'instance' => $instance,
                'host' => $host,
                'bucket_ts' => $bucketTs,
                'request_count' => 0,
                'error_count' => 0,
                'bytes_out' => 0,
                'latency_total_ms' => 0,
                'latency_max_ms' => 0,
            ];
        }
        self::$buckets[$key]['request_count'] = (int)self::$buckets[$key]['request_count'] + 1;
        if ($status >= 500) {
            self::$buckets[$key]['error_count'] = (int)self::$buckets[$key]['error_count'] + 1;
        }
        self::$buckets[$key]['bytes_out'] = (int)self::$buckets[$key]['bytes_out'] + $bytesOut;
        self::$buckets[$key]['latency_total_ms'] = (int)self::$buckets[$key]['latency_total_ms'] + $latencyMs;
        self::$buckets[$key]['latency_max_ms'] = \max((int)self::$buckets[$key]['latency_max_ms'], $latencyMs);
    }

    private function aggregateBuckets(string $instanceName, string $host, int $sinceTs): array
    {
        $aggregate = $this->emptyAggregate($instanceName, $host, $sinceTs);
        foreach (self::$buckets as $bucket) {
            if (($bucket['instance'] ?? '') !== $instanceName || ($bucket['host'] ?? '') !== $host) {
                continue;
            }
            if ((int)($bucket['bucket_ts'] ?? 0) < $sinceTs) {
                continue;
            }
            $this->mergeAggregate($aggregate, $bucket);
        }
        $req = (int)($aggregate['request_count'] ?? 0);
        $aggregate['latency_avg_ms'] = $req > 0
            ? \round((int)($aggregate['latency_total_ms'] ?? 0) / $req, 2)
            : 0.0;
        return $aggregate;
    }

    private function emptyAggregate(string $instanceName, string $host, int $sinceTs): array
    {
        return [
            'instance' => $instanceName,
            'host' => $host,
            'since_ts' => $sinceTs,
            'request_count' => 0,
            'error_count' => 0,
            'bytes_out' => 0,
            'latency_total_ms' => 0,
            'latency_max_ms' => 0,
            'latency_avg_ms' => 0.0,
        ];
    }

    private function mergeAggregate(array &$to, array $from): void
    {
        $to['request_count'] = (int)$to['request_count'] + (int)($from['request_count'] ?? 0);
        $to['error_count'] = (int)$to['error_count'] + (int)($from['error_count'] ?? 0);
        $to['bytes_out'] = (int)$to['bytes_out'] + (int)($from['bytes_out'] ?? 0);
        $to['latency_total_ms'] = (int)$to['latency_total_ms'] + (int)($from['latency_total_ms'] ?? 0);
        $to['latency_max_ms'] = \max((int)$to['latency_max_ms'], (int)($from['latency_max_ms'] ?? 0));
    }

    private function buildKey(string $instance, string $host, int $bucketTs): string
    {
        return $instance . '|' . $host . '|' . $bucketTs;
    }

    private function bucketTs(int $ts): int
    {
        return (int)(\floor($ts / self::BUCKET_SECONDS) * self::BUCKET_SECONDS);
    }
}
