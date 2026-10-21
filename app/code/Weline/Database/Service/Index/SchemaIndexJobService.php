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
    public function nextPendingJob(int $cooldownSeconds = 0, int $maxPerTableWindow = 0): ?array
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
            $table = (string)($row[SchemaIndexJob::schema_fields_TABLE_NAME] ?? '');
            if ($table === '' || $this->isTableActive($table)) {
                continue;
            }
            if ($cooldownSeconds > 0 && $this->isTableCooling($table, $cooldownSeconds)) {
                continue;
            }
            if ($maxPerTableWindow > 0 && $this->tableDoneCountSince($table, 86400) >= $maxPerTableWindow) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function runningJobs(): array
    {
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_RUNNING)
            ->order(SchemaIndexJob::schema_fields_STARTED_AT, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function markPendingAfterCancel(int $jobId, string $message): void
    {
        $this->update($jobId, [
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_LAST_ERROR => mb_substr($message, 0, 2000),
            SchemaIndexJob::schema_fields_SCHEDULED_AT => date('Y-m-d H:i:s', time() + 60),
            SchemaIndexJob::schema_fields_HEARTBEAT_AT => date('Y-m-d H:i:s'),
        ]);
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

    public function resetAttempts(int $jobId): void
    {
        $this->update($jobId, [
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_SCHEDULED_AT => null,
        ]);
    }

    public function markPriority(int $jobId, int $priority): void
    {
        $this->update($jobId, [
            SchemaIndexJob::schema_fields_PRIORITY => max(0, min(9999, $priority)),
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

    private function isTableActive(string $table): bool
    {
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->select()
            ->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)($row[SchemaIndexJob::schema_fields_STATUS] ?? '');
            if (in_array($status, [SchemaIndexJob::STATUS_QUEUED, SchemaIndexJob::STATUS_RUNNING], true)) {
                return true;
            }
        }

        return false;
    }

    private function isTableCooling(string $table, int $cooldownSeconds): bool
    {
        $last = $this->lastTableBuildAt($table);
        if ($last <= 0) {
            return false;
        }

        return (time() - $last) < $cooldownSeconds;
    }

    private function lastTableBuildAt(string $table): int
    {
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_DONE)
            ->order(SchemaIndexJob::schema_fields_FINISHED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $first = is_array($rows) ? ($rows[0] ?? null) : null;
        if (is_array($first)) {
            $finished = trim((string)($first[SchemaIndexJob::schema_fields_FINISHED_AT] ?? ''));
            $time = $finished !== '' ? strtotime($finished) : false;
            if ($time !== false) {
                return $time;
            }
        }

        $stateModel = ObjectManager::getInstance(\Weline\Database\Model\SchemaIndexState::class);
        $states = $stateModel->clear()->reset()
            ->where(\Weline\Database\Model\SchemaIndexState::schema_fields_TABLE_NAME, $table)
            ->order(\Weline\Database\Model\SchemaIndexState::schema_fields_APPLIED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $state = is_array($states) ? ($states[0] ?? null) : null;
        if (is_array($state)) {
            $applied = trim((string)($state[\Weline\Database\Model\SchemaIndexState::schema_fields_APPLIED_AT] ?? ''));
            $time = $applied !== '' ? strtotime($applied) : false;
            if ($time !== false) {
                return $time;
            }
        }

        return 0;
    }

    private function tableDoneCountSince(string $table, int $seconds): int
    {
        $threshold = time() - max(1, $seconds);
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->where(SchemaIndexJob::schema_fields_TABLE_NAME, $table)
            ->where(SchemaIndexJob::schema_fields_STATUS, SchemaIndexJob::STATUS_DONE)
            ->select()
            ->fetchArray();
        $count = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $finished = trim((string)($row[SchemaIndexJob::schema_fields_FINISHED_AT] ?? ''));
            $time = $finished !== '' ? strtotime($finished) : false;
            if ($time !== false && $time >= $threshold) {
                $count++;
            }
        }

        return $count;
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
