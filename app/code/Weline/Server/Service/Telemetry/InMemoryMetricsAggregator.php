<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

class InMemoryMetricsAggregator implements MetricsAggregatorInterface, MetricsQueryInterface
{
    private const BUCKET_SECONDS = 60;
    private const FLUSH_INTERVAL_SECONDS = 30;
    /** 最大保留的 bucket 数量（超过则清理最老的） */
    private const MAX_BUCKETS = 100;
    /** 最大保留时间（秒），超过则清理 */
    private const MAX_BUCKET_AGE_SECONDS = 600;
    /** 内存检查间隔（次） */
    private const MEMORY_CHECK_INTERVAL = 50;
    /** 内存使用阈值（MB），超过则强制清理 */
    private const MEMORY_THRESHOLD_MB = 48;
    /** 紧急清理阈值（MB），超过则立即丢弃最老的一半数据 */
    private const MEMORY_EMERGENCY_MB = 80;

    /** @var array<string,array<string,int|string>> */
    private static array $buckets = [];
    /** @var array<int,array{instance:string,host:string,bucket_ts:int,metric_type:string,payload:array}> */
    private static array $retryQueue = [];
    private static int $lastFlushAt = 0;
    /** @var int 记录计数，用于定期内存检查 */
    private static int $recordCount = 0;

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

        // 定期检查内存，超过阈值则强制清理最老的 buckets
        self::$recordCount++;
        if (self::$recordCount % self::MEMORY_CHECK_INTERVAL === 0) {
            $this->checkMemoryPressure();
        }

        // Do not flush to the database from the Master IPC hot path. A synchronous
        // metrics write can block status/reload control commands under load.
    }

    /**
     * 内存压力检测：超过阈值时清理最老的 buckets
     */
    private function checkMemoryPressure(): void
    {
        $currentMemMb = \memory_get_usage(true) / 1024 / 1024;

        // 紧急清理：内存超过紧急阈值时立即丢弃一半数据
        if ($currentMemMb > self::MEMORY_EMERGENCY_MB) {
            $this->emergencyEvict();
            return;
        }

        // 普通清理：超过阈值时清理最老的 buckets
        if ($currentMemMb > self::MEMORY_THRESHOLD_MB) {
            $this->evictOldBuckets();
        }
    }

    /**
     * 紧急清理：内存即将耗尽时快速释放一半 buckets
     */
    private function emergencyEvict(): void
    {
        $count = \count(self::$buckets);
        if ($count === 0) {
            return;
        }

        // 按时间戳排序，保留最新的一半
        $keysByTs = [];
        foreach (self::$buckets as $key => $bucket) {
            $bucketTs = (int)($bucket['bucket_ts'] ?? 0);
            if (!isset($keysByTs[$bucketTs])) {
                $keysByTs[$bucketTs] = [];
            }
            $keysByTs[$bucketTs][] = $key;
        }

        \krsort($keysByTs);  // 最新的排在前面
        $keepCount = 0;
        $keepKeys = [];

        foreach ($keysByTs as $keys) {
            foreach ($keys as $key) {
                if ($keepCount >= \ceil($count / 2)) {
                    break 2;
                }
                $keepKeys[$key] = true;
                $keepCount++;
            }
        }

        $evicted = 0;
        foreach (\array_keys(self::$buckets) as $key) {
            if (!isset($keepKeys[$key])) {
                unset(self::$buckets[$key]);
                $evicted++;
            }
        }

        // 清空重试队列
        self::$retryQueue = [];

        $currentMemMb = \round(\memory_get_usage(true) / 1024 / 1024, 1);
        \error_log("[Metrics] 紧急内存清理：丢弃 {$evicted} 个 buckets，保留 {$keepCount} 个，当前内存 {$currentMemMb}MB");
    }

    /**
     * 清理最老的 buckets（SOLID: 单一职责 - 只负责内存管理）
     */
    private function evictOldBuckets(): void
    {
        $now = \time();
        $maxAge = $now - self::MAX_BUCKET_AGE_SECONDS;
        $evicted = 0;

        // 按时间戳排序，删除最老的
        $keysByTs = [];
        foreach (self::$buckets as $key => $bucket) {
            $bucketTs = (int)($bucket['bucket_ts'] ?? 0);
            if (!isset($keysByTs[$bucketTs])) {
                $keysByTs[$bucketTs] = [];
            }
            $keysByTs[$bucketTs][] = $key;
        }

        \ksort($keysByTs);
        foreach ($keysByTs as $bucketTs => $keys) {
            // 只清理超过最大保留时间的
            if ($bucketTs >= $maxAge && \count(self::$buckets) <= self::MAX_BUCKETS) {
                break;
            }
            foreach ($keys as $key) {
                unset(self::$buckets[$key]);
                $evicted++;
            }
        }

        // 如果 bucket 数量仍然过多，直接删除最老的一半
        if (\count(self::$buckets) > self::MAX_BUCKETS) {
            $toKeep = \array_slice(\array_keys($keysByTs), -(int)\ceil(self::MAX_BUCKETS / 2), null, true);
            foreach (\array_keys(self::$buckets) as $key) {
                if (!isset($toKeep[$key])) {
                    unset(self::$buckets[$key]);
                    $evicted++;
                }
            }
        }

        // 清理重试队列（只保留最新的 100 条）
        if (\count(self::$retryQueue) > 100) {
            self::$retryQueue = \array_slice(self::$retryQueue, -100);
        }

        if ($evicted > 0) {
            $currentMemMb = \round(\memory_get_usage(true) / 1024 / 1024, 1);
            \error_log("[Metrics] 内存压力清理：释放 {$evicted} 个 buckets，当前内存 {$currentMemMb}MB");
        }
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

    public function getBufferedBucketCount(): int
    {
        return \count(self::$buckets);
    }

    public function getRetryQueueCount(): int
    {
        return \count(self::$retryQueue);
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
