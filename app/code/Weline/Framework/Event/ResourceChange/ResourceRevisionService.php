<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange;

use Weline\Framework\Database\Transaction\Exception\UnsupportedAsyncTransactionConnectionException;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Model\Event\ResourceRevision;

final class ResourceRevisionService
{
    private const MAX_CAS_ATTEMPTS = 8;

    public function __construct(
        private readonly ResourceRevision $revisionModel,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /**
     * 锁定资源修订行（FOR UPDATE）但不推进版本号。
     * 供「内容相同则跳过写盘/广播」在比较期间串行化发布者。
     */
    public function lockForUpdate(string $resourceType, string|int $resourceId): int
    {
        [$resourceKey, $resourceType, $resourceId] = $this->assertIdentity($resourceType, $resourceId);
        $this->assertSoleActiveTransaction();
        $this->ensureRow($resourceKey, $resourceType, $resourceId);
        $current = $this->find($resourceKey, true);
        if ($current === null) {
            throw new \RuntimeException(__('资源修订版原子建件后无法回读'));
        }
        if ((string)$current->getData(ResourceRevision::schema_fields_RESOURCE_TYPE) !== $resourceType
            || (string)$current->getData(ResourceRevision::schema_fields_RESOURCE_ID) !== $resourceId) {
            throw new \RuntimeException(__('资源键 SHA-256 碰撞，已拒绝锁定修订版'));
        }

        return (int)$current->getData(ResourceRevision::schema_fields_REVISION);
    }

    public function next(string $resourceType, string|int $resourceId): int
    {
        [$resourceKey, $resourceType, $resourceId] = $this->assertIdentity($resourceType, $resourceId);
        $this->assertSoleActiveTransaction();
        $this->ensureRow($resourceKey, $resourceType, $resourceId);
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->find($resourceKey, true);
            if ($current === null) {
                throw new \RuntimeException(__('资源修订版原子建件后无法回读'));
            }

            if ((string)$current->getData(ResourceRevision::schema_fields_RESOURCE_TYPE) !== $resourceType
                || (string)$current->getData(ResourceRevision::schema_fields_RESOURCE_ID) !== $resourceId) {
                throw new \RuntimeException(__('资源键 SHA-256 碰撞，已拒绝推进修订版'));
            }
            $old = (int)$current->getData(ResourceRevision::schema_fields_REVISION);
            $next = $old + 1;
            $update = $this->newModel();
            $update->where(ResourceRevision::schema_fields_ID, $resourceKey)
                ->where(ResourceRevision::schema_fields_REVISION, $old);
            $updated = $update->getQuery()
                ->update([
                    ResourceRevision::schema_fields_REVISION => $next,
                    ResourceRevision::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
                ])
                ->fetch();
            if ($updated === true || (is_int($updated) && $updated === 1)) {
                return $next;
            }
        }

        throw new \RuntimeException(__('资源修订版 CAS 冲突超过 %{1} 次', [self::MAX_CAS_ATTEMPTS]));
    }

    /**
     * @return array{0:string,1:string,2:string} resourceKey, resourceType, resourceId
     */
    private function assertIdentity(string $resourceType, string|int $resourceId): array
    {
        $resourceType = strtolower(trim($resourceType));
        $resourceId = (string)$resourceId;
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $resourceType) || $resourceId === '' || strlen($resourceId) > 191) {
            throw new \InvalidArgumentException(__('资源修订版身份无效'));
        }

        return [hash('sha256', $resourceType . "\0" . $resourceId), $resourceType, $resourceId];
    }

    private function assertSoleActiveTransaction(): void
    {
        $connection = $this->revisionModel->getConnection();
        $connector = $connection->getConnector();
        if (!$this->transactions->isActive($connection)
            || !TransactionContext::isSoleActiveConnector($connector)) {
            throw new UnsupportedAsyncTransactionConnectionException(
                __('可靠资源变更要求 Framework 默认主库是唯一活动事务连接')
            );
        }
    }

    /**
     * Atomically create a revision-zero row or no-op the existing identity.
     * The following fenced increment turns the first call into revision 1.
     * This avoids duplicate-key exception snapshots under MySQL REPEATABLE READ
     * while using the same upsert contract on MySQL, PostgreSQL and SQLite.
     */
    private function ensureRow(string $resourceKey, string $resourceType, string $resourceId): void
    {
        $model = $this->newModel();
        $result = $model->getQuery()
            ->insert([
                ResourceRevision::schema_fields_ID => $resourceKey,
                ResourceRevision::schema_fields_RESOURCE_TYPE => $resourceType,
                ResourceRevision::schema_fields_RESOURCE_ID => $resourceId,
                ResourceRevision::schema_fields_REVISION => 0,
                ResourceRevision::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
            ], [ResourceRevision::schema_fields_ID], ResourceRevision::schema_fields_ID)
            ->fetch();
        if ($result === false) {
            throw new \RuntimeException(__('资源修订版原子建件失败'));
        }
    }

    private function find(string $resourceKey, bool $lockingRead = false): ?ResourceRevision
    {
        $model = $this->newModel();
        $model->where(ResourceRevision::schema_fields_ID, $resourceKey);
        if ($lockingRead && $this->supportsForUpdate()) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();
        return $model->getId() ? $model : null;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->revisionModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function newModel(): ResourceRevision
    {
        $model = clone $this->revisionModel;
        return $model->clearData()->clearQuery();
    }
}
