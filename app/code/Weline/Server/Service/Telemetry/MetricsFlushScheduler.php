<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

use Weline\Server\Model\ServerTrafficMetric;

class MetricsFlushScheduler
{
    /** 内存警告阈值（MB），超过则清理 */
    private const MEMORY_WARNING_MB = 64;
    /** 内存危险阈值（MB），超过则直接返回 false 不写入 */
    private const MEMORY_DANGER_MB = 96;

    public function __construct(
        private readonly ServerTrafficMetric $metricModel
    ) {
    }

    public function upsertMetric(
        string $instance,
        string $host,
        int $bucketTs,
        string $metricType,
        array $payload
    ): bool {
        // 内存危险检测：超过危险阈值时直接拒绝写入，防止 OOM
        $currentMemMb = \memory_get_usage(true) / 1024 / 1024;
        if ($currentMemMb > self::MEMORY_DANGER_MB) {
            \error_log("[MetricsFlush] 内存危险：{$currentMemMb}MB > " . self::MEMORY_DANGER_MB . "MB，拒绝写入 metrics");
            return false;
        }

        // 内存压力检测：超过警告阈值时先清理旧数据
        if ($currentMemMb > self::MEMORY_WARNING_MB) {
            $this->cleanupOldMetrics();
        }

        try {
            $requestCount = (int)($payload['request_count'] ?? 0);
            $errorCount = (int)($payload['error_count'] ?? 0);
            $bytesOut = (int)($payload['bytes_out'] ?? 0);
            $latencyTotal = (int)($payload['latency_total_ms'] ?? 0);
            $latencyMax = (int)($payload['latency_max_ms'] ?? 0);
            $timestamp = \date('Y-m-d H:i:s');
            $row = $this->findBucketRow($instance, $host, $bucketTs, $metricType);

            if (\is_array($row) && !empty($row[ServerTrafficMetric::schema_fields_ID])) {
                $this->updateExistingBucket($row, $requestCount, $errorCount, $bytesOut, $latencyTotal, $latencyMax, $timestamp);
                return true;
            }

            $model = $this->metricModel->clear();
            $model->setData(ServerTrafficMetric::schema_fields_INSTANCE, $instance);
            $model->setData(ServerTrafficMetric::schema_fields_HOST, $host);
            $model->setData(ServerTrafficMetric::schema_fields_BUCKET_TS, $bucketTs);
            $model->setData(ServerTrafficMetric::schema_fields_METRIC_TYPE, $metricType);
            $model->setData(ServerTrafficMetric::schema_fields_REQUEST_COUNT, $requestCount);
            $model->setData(ServerTrafficMetric::schema_fields_ERROR_COUNT, $errorCount);
            $model->setData(ServerTrafficMetric::schema_fields_BYTES_OUT, $bytesOut);
            $model->setData(ServerTrafficMetric::schema_fields_LATENCY_TOTAL_MS, $latencyTotal);
            $model->setData(ServerTrafficMetric::schema_fields_LATENCY_MAX_MS, $latencyMax);
            $model->setData(ServerTrafficMetric::schema_fields_CREATED_AT, $timestamp);
            $model->setData(ServerTrafficMetric::schema_fields_UPDATED_AT, $timestamp);
            $model->save();
            return true;
        } catch (\Throwable $throwable) {
            // 唯一键冲突场景下回退为查询后累加更新，避免并发/脏状态导致同桶写入失败。
            if (!$this->isDuplicateBucketException($throwable)) {
                return false;
            }

            try {
                $row = $this->findBucketRow($instance, $host, $bucketTs, $metricType);
                if (!\is_array($row) || empty($row[ServerTrafficMetric::schema_fields_ID])) {
                    return false;
                }

                $this->updateExistingBucket(
                    $row,
                    (int)($payload['request_count'] ?? 0),
                    (int)($payload['error_count'] ?? 0),
                    (int)($payload['bytes_out'] ?? 0),
                    (int)($payload['latency_total_ms'] ?? 0),
                    (int)($payload['latency_max_ms'] ?? 0),
                    \date('Y-m-d H:i:s')
                );
                return true;
            } catch (\Throwable) {
                return false;
            }
        }
    }

    private function findBucketRow(string $instance, string $host, int $bucketTs, string $metricType): array
    {
        return $this->metricModel->clear()
            ->where(ServerTrafficMetric::schema_fields_INSTANCE, $instance)
            ->where(ServerTrafficMetric::schema_fields_HOST, $host)
            ->where(ServerTrafficMetric::schema_fields_BUCKET_TS, $bucketTs)
            ->where(ServerTrafficMetric::schema_fields_METRIC_TYPE, $metricType)
            ->find()
            ->fetch();
    }

    private function updateExistingBucket(
        array $row,
        int $requestCount,
        int $errorCount,
        int $bytesOut,
        int $latencyTotal,
        int $latencyMax,
        string $timestamp
    ): void {
        $model = $this->metricModel->clear();
        $model->load((int)$row[ServerTrafficMetric::schema_fields_ID]);
        $model->setData(
            ServerTrafficMetric::schema_fields_REQUEST_COUNT,
            (int)($row[ServerTrafficMetric::schema_fields_REQUEST_COUNT] ?? 0) + $requestCount
        );
        $model->setData(
            ServerTrafficMetric::schema_fields_ERROR_COUNT,
            (int)($row[ServerTrafficMetric::schema_fields_ERROR_COUNT] ?? 0) + $errorCount
        );
        $model->setData(
            ServerTrafficMetric::schema_fields_BYTES_OUT,
            (int)($row[ServerTrafficMetric::schema_fields_BYTES_OUT] ?? 0) + $bytesOut
        );
        $model->setData(
            ServerTrafficMetric::schema_fields_LATENCY_TOTAL_MS,
            (int)($row[ServerTrafficMetric::schema_fields_LATENCY_TOTAL_MS] ?? 0) + $latencyTotal
        );
        $model->setData(
            ServerTrafficMetric::schema_fields_LATENCY_MAX_MS,
            \max((int)($row[ServerTrafficMetric::schema_fields_LATENCY_MAX_MS] ?? 0), $latencyMax)
        );
        $model->setData(ServerTrafficMetric::schema_fields_UPDATED_AT, $timestamp);
        $model->save();
    }

    private function isDuplicateBucketException(\Throwable $throwable): bool
    {
        $message = $throwable->getMessage();
        return \str_contains($message, 'SQLSTATE[23505]')
            || \str_contains($message, 'uk_bucket')
            || \str_contains($message, 'duplicate key value violates unique constraint');
    }

    /**
     * 内存压力时清理旧的 metrics 数据（SOLID: 单一职责）
     */
    private function cleanupOldMetrics(): void
    {
        try {
            $cutoffTs = \time() - 86400; // 保留 24 小时内的数据
            $deleted = $this->metricModel->clear()
                ->where(ServerTrafficMetric::schema_fields_BUCKET_TS, $cutoffTs, '<')
                ->delete()
                ->rowCount();

            if ($deleted > 0) {
                $currentMemMb = \round(\memory_get_usage(true) / 1024 / 1024, 1);
                \error_log("[MetricsFlush] 内存压力清理：删除 {$deleted} 条旧 metrics，当前内存 {$currentMemMb}MB");
            }
        } catch (\Throwable $e) {
            // 忽略清理错误
        }
    }

    public function queryHistory(string $instance, int $fromTs, int $toTs, ?string $host = null, int $limit = 300): array
    {
        $query = $this->metricModel->clearQuery()
            ->where(ServerTrafficMetric::schema_fields_INSTANCE, $instance)
            ->where(ServerTrafficMetric::schema_fields_BUCKET_TS, $fromTs, '>=')
            ->where(ServerTrafficMetric::schema_fields_BUCKET_TS, $toTs, '<=')
            ->order(ServerTrafficMetric::schema_fields_BUCKET_TS, 'DESC')
            ->pagination(1, $limit);

        if ($host !== null && $host !== '') {
            $query->where(ServerTrafficMetric::schema_fields_HOST, \strtolower($host));
        }

        return $query->select()->fetchArray();
    }
}
