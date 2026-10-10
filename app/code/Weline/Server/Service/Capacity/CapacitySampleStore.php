<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\ServerCapacitySample;

final class CapacitySampleStore
{
    /**
     * @param array<string, mixed> $sample
     */
    public function persist(string $instance, array $sample): bool
    {
        try {
            /** @var ServerCapacitySample $model */
            $model = ObjectManager::getInstance(ServerCapacitySample::class);
            $now = \date('Y-m-d H:i:s');
            $codes = $sample['advice_codes'] ?? [];
            if (!\is_array($codes)) {
                $codes = [];
            }
            $model->clearData()->clearQuery()
                ->setData(ServerCapacitySample::schema_fields_INSTANCE, $instance !== '' ? $instance : 'default')
                ->setData(ServerCapacitySample::schema_fields_SAMPLED_AT, $now)
                ->setData(ServerCapacitySample::schema_fields_HOST_PRESSURE, (float)($sample['host_pressure'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_WORKER_PRESSURE_AVG, (float)($sample['worker_pressure_avg'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_WORKER_PRESSURE_MAX, (float)($sample['worker_pressure_max'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_CONNECTIONS_SUM, (int)($sample['connections_sum'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_ACTIVE_REQUESTS_SUM, (int)($sample['active_requests_sum'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_COMPACT_DELTA, (int)($sample['compact_delta'] ?? 0))
                ->setData(ServerCapacitySample::schema_fields_ADVICE_CODES, \json_encode(\array_values($codes), \JSON_UNESCAPED_UNICODE))
                ->setData(ServerCapacitySample::schema_fields_CONFIG_SOFT_PRESSURE, (float)($sample['config_soft_pressure'] ?? 0.7))
                ->setData(ServerCapacitySample::schema_fields_CREATED_AT, $now)
                ->save();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function cleanup(int $retainDays): int
    {
        $retainDays = \max(1, $retainDays);
        $cutoff = \date('Y-m-d H:i:s', \time() - ($retainDays * 86400));
        try {
            /** @var ServerCapacitySample $model */
            $model = ObjectManager::getInstance(ServerCapacitySample::class);
            $rows = (clone $model)->clearData()->reset()
                ->where(ServerCapacitySample::schema_fields_SAMPLED_AT, $cutoff, '<')
                ->select()
                ->fetchArray();
            $deleted = 0;
            foreach ($rows as $row) {
                $id = (int)($row[ServerCapacitySample::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                (clone $model)->clearData()->reset()
                    ->where(ServerCapacitySample::schema_fields_ID, $id)
                    ->delete()
                    ->fetch();
                $deleted++;
            }

            return $deleted;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{
     *   labels:list<string>,
     *   host_pressure:list<float>,
     *   worker_pressure_avg:list<float>,
     *   worker_pressure_max:list<float>,
     *   connections_sum:list<int>,
     *   compact_delta:list<int>,
     *   soft_pressure:float,
     *   latest_advice:list<string>
     * }
     */
    public function series(string $instance, int $windowSec): array
    {
        $windowSec = \max(60, $windowSec);
        $since = \date('Y-m-d H:i:s', \time() - $windowSec);
        $empty = [
            'labels' => [],
            'host_pressure' => [],
            'worker_pressure_avg' => [],
            'worker_pressure_max' => [],
            'connections_sum' => [],
            'compact_delta' => [],
            'soft_pressure' => 0.70,
            'latest_advice' => [],
        ];
        try {
            /** @var ServerCapacitySample $model */
            $model = ObjectManager::getInstance(ServerCapacitySample::class);
            $query = (clone $model)->clearData()->reset()
                ->where(ServerCapacitySample::schema_fields_SAMPLED_AT, $since, '>=');
            if ($instance !== '' && $instance !== '*') {
                $query->where(ServerCapacitySample::schema_fields_INSTANCE, $instance);
            }
            $rows = $query
                ->order(ServerCapacitySample::schema_fields_SAMPLED_AT, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $empty;
        }

        $out = $empty;
        $latestAdvice = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out['labels'][] = (string)($row[ServerCapacitySample::schema_fields_SAMPLED_AT] ?? '');
            $out['host_pressure'][] = (float)($row[ServerCapacitySample::schema_fields_HOST_PRESSURE] ?? 0);
            $out['worker_pressure_avg'][] = (float)($row[ServerCapacitySample::schema_fields_WORKER_PRESSURE_AVG] ?? 0);
            $out['worker_pressure_max'][] = (float)($row[ServerCapacitySample::schema_fields_WORKER_PRESSURE_MAX] ?? 0);
            $out['connections_sum'][] = (int)($row[ServerCapacitySample::schema_fields_CONNECTIONS_SUM] ?? 0);
            $out['compact_delta'][] = (int)($row[ServerCapacitySample::schema_fields_COMPACT_DELTA] ?? 0);
            $out['soft_pressure'] = (float)($row[ServerCapacitySample::schema_fields_CONFIG_SOFT_PRESSURE] ?? 0.7);
            $decoded = \json_decode((string)($row[ServerCapacitySample::schema_fields_ADVICE_CODES] ?? '[]'), true);
            if (\is_array($decoded) && $decoded !== []) {
                $latestAdvice = \array_values(\array_map('strval', $decoded));
            }
        }
        $out['latest_advice'] = $latestAdvice;

        return $out;
    }
}
