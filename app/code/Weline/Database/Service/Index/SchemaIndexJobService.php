<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Queue\SchemaIndexApplyQueue;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * Job queue boundary shared by the cron scheduler, queue consumer and CLI.
 */
final class SchemaIndexJobService
{
    public function __construct(
        private readonly IdempotentQueueAdmission $admission,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function nextPendingJob(): ?array
    {
        $model = ObjectManager::getInstance(SchemaIndexJob::class);
        $rows = $model->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_PENDING)
            ->order(SchemaIndexJob::schema_fields_PRIORITY, 'ASC')
            ->order(SchemaIndexJob::schema_fields_ID, 'ASC')
            ->limit(30)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return null;
        }

        $now = time();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $attempts = (int)($row[SchemaIndexJob::schema_fields_ATTEMPTS] ?? 0);
            $maxAttempts = max(1, (int)($row[SchemaIndexJob::schema_fields_MAX_ATTEMPTS] ?? 3));
            if ($attempts >= $maxAttempts) {
                continue;
            }
            $scheduled = trim((string)($row[SchemaIndexJob::schema_fields_SCHEDULED_AT] ?? ''));
            if ($scheduled !== '' && strtotime($scheduled) > $now) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function activeCount(): int
    {
        $model = ObjectManager::getInstance(SchemaIndexJob::class);

        return (int)$model->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_STATUS, [
                SchemaIndexJob::STATUS_QUEUED,
                SchemaIndexJob::STATUS_RUNNING,
            ], 'IN')
            ->total();
    }

    public function enqueueApplyJob(int $jobId): int
    {
        $bizKey = 'schema_index:job:' . $jobId;

        return $this->admission->admit([
            'class' => SchemaIndexApplyQueue::class,
            'name' => 'Schema index #' . $jobId,
            'module' => SchemaIndexConfig::MODULE,
            'content' => ['job_id' => $jobId],
            'biz_key' => $bizKey,
            'idempotency_scope' => 'schema_index_apply',
            'idempotency_key' => $bizKey,
            'auto' => true,
        ]);
    }

    public function markQueued(int $jobId): void
    {
        $this->update($jobId, [
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_QUEUED,
            SchemaIndexJob::schema_fields_LAST_ERROR => null,
        ]);
    }

    public function markPendingAgain(int $jobId, string $error): void
    {
        $this->update($jobId, [
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_LAST_ERROR => mb_substr($error, 0, 2000),
            SchemaIndexJob::schema_fields_SCHEDULED_AT => date('Y-m-d H:i:s', time() + 60),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function update(int $jobId, array $data): void
    {
        $data[SchemaIndexJob::schema_fields_UPDATE_TIME] = date('Y-m-d H:i:s');
        ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_ID, $jobId)
            ->update($data)
            ->fetch();
    }
}
