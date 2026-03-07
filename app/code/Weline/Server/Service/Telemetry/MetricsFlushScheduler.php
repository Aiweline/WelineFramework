<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

use Weline\Server\Model\ServerTrafficMetric;

class MetricsFlushScheduler
{
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
        try {
            $row = $this->metricModel->clearQuery()
                ->where(ServerTrafficMetric::schema_fields_INSTANCE, $instance)
                ->where(ServerTrafficMetric::schema_fields_HOST, $host)
                ->where(ServerTrafficMetric::schema_fields_BUCKET_TS, $bucketTs)
                ->where(ServerTrafficMetric::schema_fields_METRIC_TYPE, $metricType)
                ->find()
                ->fetch();

            $requestCount = (int)($payload['request_count'] ?? 0);
            $errorCount = (int)($payload['error_count'] ?? 0);
            $bytesOut = (int)($payload['bytes_out'] ?? 0);
            $latencyTotal = (int)($payload['latency_total_ms'] ?? 0);
            $latencyMax = (int)($payload['latency_max_ms'] ?? 0);

            $model = $this->metricModel->clearQuery();
            if (\is_array($row) && !empty($row[ServerTrafficMetric::schema_fields_ID])) {
                $model->load($row[ServerTrafficMetric::schema_fields_ID]);
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
                $model->setData(ServerTrafficMetric::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
                $model->save();
                return true;
            }

            $model->setData(ServerTrafficMetric::schema_fields_INSTANCE, $instance);
            $model->setData(ServerTrafficMetric::schema_fields_HOST, $host);
            $model->setData(ServerTrafficMetric::schema_fields_BUCKET_TS, $bucketTs);
            $model->setData(ServerTrafficMetric::schema_fields_METRIC_TYPE, $metricType);
            $model->setData(ServerTrafficMetric::schema_fields_REQUEST_COUNT, $requestCount);
            $model->setData(ServerTrafficMetric::schema_fields_ERROR_COUNT, $errorCount);
            $model->setData(ServerTrafficMetric::schema_fields_BYTES_OUT, $bytesOut);
            $model->setData(ServerTrafficMetric::schema_fields_LATENCY_TOTAL_MS, $latencyTotal);
            $model->setData(ServerTrafficMetric::schema_fields_LATENCY_MAX_MS, $latencyMax);
            $model->setData(ServerTrafficMetric::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
            $model->setData(ServerTrafficMetric::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
            $model->save();
            return true;
        } catch (\Throwable) {
            return false;
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
