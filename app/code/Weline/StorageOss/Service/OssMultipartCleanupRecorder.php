<?php

declare(strict_types=1);

namespace Weline\StorageOss\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\StorageOss\Model\MultipartCleanupTask;

final class OssMultipartCleanupRecorder
{
    public function __construct(
        private readonly MultipartCleanupTask $tasks,
        private readonly OssMultipartCleanupSnapshotCodec $snapshots,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function record(
        StorageConfigSnapshot $snapshot,
        string $objectKey,
        string $uploadId,
        \Throwable $failure,
    ): void {
        $now = date('Y-m-d H:i:s');
        $data = [
            MultipartCleanupTask::schema_fields_DISK_CODE => $snapshot->diskCode,
            MultipartCleanupTask::schema_fields_CONFIG_REVISION => $snapshot->configRevision,
            MultipartCleanupTask::schema_fields_CONFIG_SNAPSHOT_REF => $this->snapshots->seal($snapshot),
            MultipartCleanupTask::schema_fields_OBJECT_KEY => $objectKey,
            MultipartCleanupTask::schema_fields_UPLOAD_ID => $uploadId,
            MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_PENDING,
            MultipartCleanupTask::schema_fields_CLAIM_TOKEN => null,
            MultipartCleanupTask::schema_fields_ATTEMPTS => 0,
            MultipartCleanupTask::schema_fields_NEXT_ATTEMPT_AT => $now,
            MultipartCleanupTask::schema_fields_LAST_ERROR_CODE => $this->errorCode($failure),
            MultipartCleanupTask::schema_fields_CREATED_AT => $now,
            MultipartCleanupTask::schema_fields_UPDATED_AT => $now,
        ];
        $connection = $this->tasks->getConnection();
        $persist = fn () => $this->persist($data);

        if (!$this->transactions->isActive($connection)) {
            $this->transactions->runWrite($connection, $persist);
            return;
        }

        $key = 'oss_multipart_cleanup_' . hash(
            'sha256',
            $snapshot->diskCode . "\0" . $snapshot->configRevision . "\0" . $objectKey . "\0" . $uploadId,
        );
        if ($this->transactions->isWriteIntent($connection)) {
            // The row commits with the surrounding write. If that write rolls
            // back, persist the exact sealed payload after the connection has
            // detached from its transaction.
            $this->transactions->afterRollback($connection, $key, $persist);
            $this->persist($data);
            return;
        }

        // A read transaction cannot be upgraded. Persist immediately after
        // either terminal outcome instead of issuing a hidden write inside it.
        $this->transactions->afterCommit($connection, $key, $persist);
        $this->transactions->afterRollback($connection, $key, $persist);
    }

    /** @param array<string,mixed> $data */
    private function persist(array $data): void
    {
        $connection = $this->tasks->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException('cleanup_debt_requires_write_intent');
            }
            $this->saveTask($data);
            return;
        }
        $this->transactions->runWrite($connection, fn () => $this->saveTask($data));
    }

    /** @param array<string,mixed> $data */
    private function saveTask(array $data): void
    {
        $task = clone $this->tasks;
        $task->clearData();
        $task->addData($data);
        $task->save();
    }

    public function errorCode(\Throwable $failure): string
    {
        $class = preg_replace('/[^A-Za-z0-9_.-]+/', '_', str_replace('\\', '.', $failure::class)) ?: 'Throwable';
        return substr($class, 0, 96);
    }
}
