<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Framework\Database\Connection\Api\ConcurrentIndexBuildInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Read-only progress projection for the SSE endpoint.
 * PostgreSQL progress comes from pg_stat_progress_create_index; other adapters
 * fall back to the persisted job status.
 */
final class SchemaIndexProgressService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(int $jobId): array
    {
        $job = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();
        if (!$job->getId()) {
            throw new \RuntimeException((string)__('索引任务不存在：%{1}', [$jobId]));
        }

        $status = (string)$job->getData(SchemaIndexJob::schema_fields_STATUS);
        $startedAt = trim((string)$job->getData(SchemaIndexJob::schema_fields_STARTED_AT));
        $finishedAt = trim((string)$job->getData(SchemaIndexJob::schema_fields_FINISHED_AT));
        $progress = [];
        if ($status === SchemaIndexJob::STATUS_RUNNING) {
            $connector = $this->connectionFactory->getConnector();
            if ($connector instanceof ConcurrentIndexBuildInterface) {
                try {
                    $progress = $connector->indexBuildProgress('weline-index:' . $jobId);
                } catch (\Throwable $throwable) {
                    w_log_warning('Schema index progress query failed: ' . $throwable->getMessage(), [], 'database');
                    $progress = [];
                }
            }
        }

        $blocksTotal = (int)($progress['blocks_total'] ?? 0);
        $blocksDone = (int)($progress['blocks_done'] ?? 0);
        $tuplesTotal = (int)($progress['tuples_total'] ?? 0);
        $tuplesDone = (int)($progress['tuples_done'] ?? 0);
        $percent = match ($status) {
            SchemaIndexJob::STATUS_DONE => 100,
            SchemaIndexJob::STATUS_FAILED, SchemaIndexJob::STATUS_CANCELED => 0,
            default => $this->percent($blocksTotal, $blocksDone, $tuplesTotal, $tuplesDone),
        };
        $elapsedMs = 0;
        if ($startedAt !== '') {
            $start = strtotime($startedAt);
            $end = $finishedAt !== '' ? strtotime($finishedAt) : time();
            if ($start !== false && $end !== false && $end >= $start) {
                $elapsedMs = ($end - $start) * 1000;
            }
        }

        return [
            'job_id' => $jobId,
            'status' => $status,
            'action' => (string)$job->getData(SchemaIndexJob::schema_fields_ACTION),
            'module' => (string)$job->getData(SchemaIndexJob::schema_fields_MODULE),
            'table' => (string)$job->getData(SchemaIndexJob::schema_fields_TABLE_NAME),
            'index' => (string)$job->getData(SchemaIndexJob::schema_fields_INDEX_NAME),
            'physical_name' => (string)$job->getData(SchemaIndexJob::schema_fields_PHYSICAL_NAME),
            'phase' => (string)($progress['phase'] ?? $status),
            'percent' => $percent,
            'blocks_total' => $blocksTotal,
            'blocks_done' => $blocksDone,
            'tuples_total' => $tuplesTotal,
            'tuples_done' => $tuplesDone,
            'elapsed_ms' => $elapsedMs,
            'message' => (string)$job->getData(SchemaIndexJob::schema_fields_LAST_ERROR),
        ];
    }

    private function percent(int $blocksTotal, int $blocksDone, int $tuplesTotal, int $tuplesDone): int
    {
        if ($tuplesTotal > 0) {
            return max(0, min(99, (int)round($tuplesDone / $tuplesTotal * 100)));
        }
        if ($blocksTotal > 0) {
            return max(0, min(99, (int)round($blocksDone / $blocksTotal * 100)));
        }

        return 0;
    }
}
