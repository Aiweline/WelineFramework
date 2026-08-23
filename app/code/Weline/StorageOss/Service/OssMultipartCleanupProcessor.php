<?php

declare(strict_types=1);

namespace Weline\StorageOss\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\StorageOss\Model\MultipartCleanupTask;

final class OssMultipartCleanupProcessor
{
    private const MAX_ATTEMPTS = 12;

    public function __construct(
        private readonly MultipartCleanupTask $tasks,
        private readonly StorageRequestResourceRegistryInterface $resources,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
        private readonly OssMultipartCleanupRecorder $recorder,
        private readonly OssMultipartCleanupSnapshotCodec $snapshots,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    /** @return array{resolved:int,failed:int,dead:int} */
    public function process(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $connection = $this->tasks->getConnection();
        if ($this->transactions->isActive($connection)) {
            throw new \LogicException('oss_cleanup_batch_requires_root_transaction_boundary');
        }
        $now = date('Y-m-d H:i:s');
        $this->transactions->runWrite($connection, function () use ($now): void {
            (clone $this->tasks)->clearData()->reset()
                ->where(MultipartCleanupTask::schema_fields_STATUS, MultipartCleanupTask::STATUS_PROCESSING)
                ->where(MultipartCleanupTask::schema_fields_UPDATED_AT, date('Y-m-d H:i:s', time() - 600), '<=')
                ->update([
                    MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_PENDING,
                    MultipartCleanupTask::schema_fields_CLAIM_TOKEN => null,
                    MultipartCleanupTask::schema_fields_NEXT_ATTEMPT_AT => $now,
                    MultipartCleanupTask::schema_fields_UPDATED_AT => $now,
                ])->fetch();
        });
        $rows = (clone $this->tasks)->clearData()->reset()
            ->where(MultipartCleanupTask::schema_fields_STATUS, MultipartCleanupTask::STATUS_PENDING)
            ->where(MultipartCleanupTask::schema_fields_NEXT_ATTEMPT_AT, $now, '<=')
            ->order(MultipartCleanupTask::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $result = ['resolved' => 0, 'failed' => 0, 'dead' => 0];
        $cleanupFailure = null;
        foreach ($rows as $task) {
            if (!$task instanceof MultipartCleanupTask || !$task->getId()) {
                continue;
            }
            $claimToken = $this->claim($task);
            if ($claimToken === null) {
                continue;
            }
            try {
                $snapshot = $this->snapshots->reveal(
                    (string)$task->getData(MultipartCleanupTask::schema_fields_CONFIG_SNAPSHOT_REF),
                    (string)$task->getData(MultipartCleanupTask::schema_fields_DISK_CODE),
                    (int)$task->getData(
                        MultipartCleanupTask::schema_fields_CONFIG_REVISION,
                    ),
                );
                $clients = new AliyunOssClientFactory($snapshot, $this->resourceFactory);
                try {
                    $clients->client()->abortMultipartUpload(
                        $clients->bucket(),
                        $clients->prefixedKey((string)$task->getData(MultipartCleanupTask::schema_fields_OBJECT_KEY)),
                        (string)$task->getData(MultipartCleanupTask::schema_fields_UPLOAD_ID),
                    );
                } catch (\Throwable $abortFailure) {
                    // Abort is idempotently complete once OSS reports that the
                    // upload id no longer exists. Never inspect/log its message,
                    // which may contain request or object details.
                    if (!$this->isNoSuchUpload($abortFailure)) {
                        throw $abortFailure;
                    }
                }
                $updated = $this->updateClaimed($task, $claimToken, [
                    MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_RESOLVED,
                    MultipartCleanupTask::schema_fields_CLAIM_TOKEN => null,
                    MultipartCleanupTask::schema_fields_NEXT_ATTEMPT_AT => null,
                    MultipartCleanupTask::schema_fields_RESOLVED_AT => date('Y-m-d H:i:s'),
                    MultipartCleanupTask::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ]);
                if (!$updated) {
                    ++$result['failed'];
                    continue;
                }
                ++$result['resolved'];
            } catch (\Throwable $failure) {
                $attempts = (int)$task->getData(MultipartCleanupTask::schema_fields_ATTEMPTS) + 1;
                $dead = $attempts >= self::MAX_ATTEMPTS;
                $delay = min(86400, 60 * (2 ** min(10, $attempts - 1)));
                $updated = $this->updateClaimed($task, $claimToken, [
                    MultipartCleanupTask::schema_fields_ATTEMPTS => $attempts,
                    MultipartCleanupTask::schema_fields_STATUS => $dead
                        ? MultipartCleanupTask::STATUS_DEAD
                        : MultipartCleanupTask::STATUS_PENDING,
                    MultipartCleanupTask::schema_fields_CLAIM_TOKEN => null,
                    MultipartCleanupTask::schema_fields_NEXT_ATTEMPT_AT => date('Y-m-d H:i:s', time() + $delay),
                    MultipartCleanupTask::schema_fields_LAST_ERROR_CODE => $this->recorder->errorCode($failure),
                    MultipartCleanupTask::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ]);
                if ($updated) {
                    ++$result[$dead ? 'dead' : 'failed'];
                } else {
                    ++$result['failed'];
                }
            } finally {
                try {
                    $this->resources->closeAll();
                } catch (\Throwable $failure) {
                    // Continue processing already-claimed durable tasks, then
                    // surface one aggregate failure so the WLS worker drains.
                    $cleanupFailure ??= $failure;
                }
            }
        }
        if ($cleanupFailure !== null) {
            throw new \RuntimeException(
                (string)__('OSS 清理批次完成，但请求资源清理失败。'),
                0,
                $cleanupFailure,
            );
        }
        return $result;
    }

    private function claim(MultipartCleanupTask $task): ?string
    {
        $token = bin2hex(random_bytes(32));
        $updated = $this->conditionalUpdate(
            (int)$task->getId(),
            [
                MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_PENDING,
            ],
            [
                MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_PROCESSING,
                MultipartCleanupTask::schema_fields_CLAIM_TOKEN => $token,
                MultipartCleanupTask::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ],
        );
        return $updated ? $token : null;
    }

    /** @param array<string,mixed> $updates */
    private function updateClaimed(MultipartCleanupTask $task, string $claimToken, array $updates): bool
    {
        return $this->conditionalUpdate(
            (int)$task->getId(),
            [
                MultipartCleanupTask::schema_fields_STATUS => MultipartCleanupTask::STATUS_PROCESSING,
                MultipartCleanupTask::schema_fields_CLAIM_TOKEN => $claimToken,
            ],
            $updates,
        );
    }

    /**
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $updates
     */
    private function conditionalUpdate(int $taskId, array $conditions, array $updates): bool
    {
        $connection = $this->tasks->getConnection();
        return (bool)$this->transactions->runWrite($connection, function () use (
            $taskId,
            $conditions,
            $updates,
        ): bool {
            $query = (clone $this->tasks)->getQuery(false);
            $query->where(MultipartCleanupTask::schema_fields_ID, $taskId);
            foreach ($conditions as $field => $value) {
                $query->where($field, $value);
            }
            $query->update($updates);
            $statement = $query->PDOStatement ?? null;
            $bindings = $query->bound_values ?? null;
            if (!$statement instanceof \PDOStatement || !is_array($bindings)) {
                $query->clearQuery();
                throw new \RuntimeException('oss_multipart_cleanup_update_unavailable');
            }
            try {
                return $statement->execute($bindings) && $statement->rowCount() === 1;
            } finally {
                $query->clearQuery();
            }
        });
    }

    private function isNoSuchUpload(\Throwable $failure): bool
    {
        $cursor = $failure;
        for ($depth = 0; $depth < 4; ++$depth) {
            if (method_exists($cursor, 'getErrorCode')) {
                try {
                    $errorCode = strtolower(trim((string)$cursor->getErrorCode()));
                    if (in_array($errorCode, ['nosuchupload', 'no_such_upload'], true)) {
                        return true;
                    }
                } catch (\Throwable) {
                }
            }
            $previous = $cursor->getPrevious();
            if (!$previous instanceof \Throwable) {
                break;
            }
            $cursor = $previous;
        }
        return false;
    }
}
