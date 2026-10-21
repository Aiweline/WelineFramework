<?php

declare(strict_types=1);

namespace Weline\Database\Extends\Module\Weline_Framework\Query;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Service\Index\SchemaIndexJobService;
use Weline\Database\Service\Index\SchemaIndexProgressService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Backend BinQuery bridge for the index management page.
 * Mutations stay here (not in the page controller), so the page remains a
 * read-only renderer and the operation carries explicit backend ACL metadata.
 */
final class DatabaseIndexQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'database_index';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'enqueue' => $this->enqueue($params),
            'progress' => $this->progress($params),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'database_index',
            'name' => 'Weline_Database async index bridge',
            'module' => 'Weline_Database',
            'operations' => [
                [
                    'name' => 'enqueue',
                    'description' => '把一条待索引任务立即投入队列',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'job_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'progress',
                    'description' => '读取一条索引任务的构建进度',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'job_id', 'type' => 'int', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function enqueue(array $params): array
    {
        $jobId = (int)($params['job_id'] ?? 0);
        if ($jobId < 1) {
            return ['success' => false, 'message' => (string)__('缺少 job_id')];
        }

        $job = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();
        if (!$job->getId()) {
            return ['success' => false, 'message' => (string)__('索引任务不存在：%{1}', [$jobId])];
        }

        $status = (string)$job->getData(SchemaIndexJob::schema_fields_STATUS);
        if (in_array($status, [
            SchemaIndexJob::STATUS_QUEUED,
            SchemaIndexJob::STATUS_RUNNING,
            SchemaIndexJob::STATUS_DONE,
        ], true)) {
            return [
                'success' => true,
                'job_id' => $jobId,
                'status' => $status,
                'queue_id' => 0,
                'message' => (string)__('任务已在队列/运行/完成，无需重复入队'),
            ];
        }

        $jobService = ObjectManager::getInstance(SchemaIndexJobService::class);
        $jobService->resetAttempts($jobId);
        $jobService->markPriority($jobId, 0);
        try {
            $queueId = $jobService->enqueueApplyJob($jobId);
            $jobService->markQueued($jobId);
        } catch (\Throwable $throwable) {
            $jobService->markPendingAgain($jobId, $throwable->getMessage());

            return ['success' => false, 'message' => $throwable->getMessage()];
        }

        return [
            'success' => true,
            'job_id' => $jobId,
            'status' => SchemaIndexJob::STATUS_QUEUED,
            'queue_id' => $queueId,
            'message' => (string)__('已入队 #%{1}', [$queueId]),
        ];
    }

    /** @param array<string,mixed> $params */
    private function progress(array $params): array
    {
        $jobId = (int)($params['job_id'] ?? 0);
        if ($jobId < 1) {
            return ['success' => false, 'message' => (string)__('缺少 job_id')];
        }

        try {
            $snapshot = ObjectManager::getInstance(SchemaIndexProgressService::class)->snapshot($jobId);
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }

        return ['success' => true] + $snapshot;
    }
}
