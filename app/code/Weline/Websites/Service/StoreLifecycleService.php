<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Store 受控生命周期：删除只转 tombstone，不执行物理 DELETE。
 */
final class StoreLifecycleService
{
    public function __construct(
        private readonly Website $website,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly NamespaceGenerationRepository $namespaces,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    public function tombstone(Store $store): Store
    {
        if (!$store->hasData(Store::schema_fields_ID)) {
            throw new \RuntimeException(__('删除店铺前必须加载明确的店铺记录'));
        }
        $storeId = self::positiveInteger(
            $store->getData(Store::schema_fields_ID),
            __('删除店铺前必须提供有效店铺 ID'),
        );
        $connection = $store->getConnection();

        $tombstone = function () use ($store, $storeId, $connection): array {
                $this->namespaces->assertConnectionAffinity($connection);
                $probe = $this->findStore($store, $connection, $storeId, false);
                $websiteId = self::nonNegativeInteger(
                    $probe->getData(Store::schema_fields_WEBSITE_ID),
                    __('Store 缺少可验证的 website_id'),
                );
                $websiteCode = $this->resolveWebsiteCode($connection, $websiteId);
                $current = $this->findStore($store, $connection, $storeId, true);
                if ((int)$current->getData(Store::schema_fields_WEBSITE_ID) !== $websiteId) {
                    throw new \RuntimeException(__('店铺父 Website 在墓碑锁定期间发生变化'));
                }
                $this->assertDeletable($current);

                $lifecycle = (string)$current->getData(Store::schema_fields_LIFECYCLE_STATUS);
                if ($lifecycle === Store::LIFECYCLE_TOMBSTONE) {
                    $this->assertValidTombstone($current);
                    return $current->getModelData();
                }
                if ($lifecycle !== Store::LIFECYCLE_ACTIVE
                    || $current->getData(Store::schema_fields_TOMBSTONED_AT) !== null) {
                    throw new \RuntimeException(__('店铺生命周期状态与墓碑时间不一致'));
                }

                $tombstonedAt = gmdate('Y-m-d H:i:s');
                $update = $this->newStore($store, $connection);
                $update->where(Store::schema_fields_ID, $storeId)
                    ->where(Store::schema_fields_LIFECYCLE_STATUS, Store::LIFECYCLE_ACTIVE);
                $updated = $update->getQuery()
                    ->update([
                        Store::schema_fields_STATUS => 0,
                        Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE,
                        Store::schema_fields_TOMBSTONED_AT => $tombstonedAt,
                    ])
                    ->fetch();

                if (!($updated === true || (is_int($updated) && $updated === 1))) {
                    $afterConflict = $this->findStore($store, $connection, $storeId, false);
                    $this->assertDeletable($afterConflict);
                    if ((string)$afterConflict->getData(Store::schema_fields_LIFECYCLE_STATUS)
                        === Store::LIFECYCLE_TOMBSTONE) {
                        $this->assertValidTombstone($afterConflict);
                        // 某些驱动在 UPDATE 成功后仍返回 false；回读已是
                        // 合法 tombstone 时仍必须推进代际。并发重复 bump 只会
                        // 过度失效，不会产生脏读。
                        $this->namespaces->bump(
                            $this->namespacePath->website($websiteCode, ['catalog']),
                        );
                        return $afterConflict->getModelData();
                    }
                    throw new \RuntimeException(__('店铺墓碑更新发生并发冲突'));
                }

                $tombstone = $this->findStore($store, $connection, $storeId, false);
                $this->assertValidTombstone($tombstone, $tombstonedAt);
                $this->namespaces->bump($this->namespacePath->website($websiteCode, ['catalog']));
                return $tombstone->getModelData();
            };
        /** @var array<string,mixed> $row */
        if ($this->transactions->isActive($connection)) {
            try {
                if ($this->isSqlite($connection) && !$this->transactions->isWriteIntent($connection)) {
                    throw new \LogicException('websites_store_lifecycle_sqlite_write_intent_required');
                }
                $row = $tombstone();
            } catch (\Throwable $exception) {
                $this->transactions->markRollbackOnly($connection, $exception);
                throw $exception;
            }
        } else {
            $row = $this->transactions->runWrite($connection, $tombstone);
        }

        $store->clearData()->clearQuery()->setData($row);
        return $store;
    }

    private function findStore(
        Store $prototype,
        ConnectionFactory $connection,
        int $storeId,
        bool $lockingRead,
    ): Store {
        $store = $this->newStore($prototype, $connection);
        $store->where(Store::schema_fields_ID, $storeId);
        if ($lockingRead && $this->supportsForUpdate($connection)) {
            $store->additional('FOR UPDATE');
        }
        $store->find()->fetch();
        if (!$store->hasData(Store::schema_fields_ID)) {
            throw new \RuntimeException(__('要删除的店铺不存在'));
        }
        if ((int)$store->getData(Store::schema_fields_ID) !== $storeId) {
            throw new \RuntimeException(__('店铺墓碑回读身份不一致'));
        }
        return $store;
    }

    private function assertDeletable(Store $store): void
    {
        if ((int)$store->getData(Store::schema_fields_IS_DEFAULT) === 1
            || (string)$store->getData(Store::schema_fields_CODE) === Store::CODE_DEFAULT) {
            throw new \RuntimeException(__('默认店铺不允许删除'));
        }
    }

    private function assertValidTombstone(Store $store, ?string $expectedTimestamp = null): void
    {
        $timestamp = $store->getData(Store::schema_fields_TOMBSTONED_AT);
        if ((string)$store->getData(Store::schema_fields_LIFECYCLE_STATUS) !== Store::LIFECYCLE_TOMBSTONE
            || (int)$store->getData(Store::schema_fields_STATUS) !== 0
            || !is_string($timestamp)
            || !self::isUtcDatabaseTimestamp($timestamp)
            || ($expectedTimestamp !== null && $timestamp !== $expectedTimestamp)) {
            throw new \RuntimeException(__('店铺墓碑状态不完整'));
        }
    }

    private function resolveWebsiteCode(ConnectionFactory $connection, int $websiteId): string
    {
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \RuntimeException(__('Store 引用了非法 website_id'));
        }
        $website = clone $this->website;
        $website->setConnection($connection)->clearData()->clearQuery()
            ->where(Website::schema_fields_ID, $websiteId);
        if ($this->supportsForUpdate($connection)) {
            $website->additional('FOR UPDATE');
        }
        $website->find()->fetch();
        if (!$website->hasData(Website::schema_fields_ID)
            || (int)$website->getData(Website::schema_fields_ID) !== $websiteId) {
            throw new \RuntimeException(__('无法为店铺墓碑解析所属 Website'));
        }
        $code = trim((string)$website->getData(Website::schema_fields_CODE));
        if ($code === '') {
            throw new \RuntimeException(__('无法为店铺墓碑解析 Website code'));
        }

        // 正式 builder 会同时完成 UTF-8、NFC 与规范编码校验。
        $this->namespacePath->website($code, ['catalog']);
        return $code;
    }

    private function newStore(Store $prototype, ConnectionFactory $connection): Store
    {
        $store = clone $prototype;
        return $store->setConnection($connection)->clearData()->clearQuery();
    }

    private function supportsForUpdate(ConnectionFactory $connection): bool
    {
        $type = strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function isSqlite(ConnectionFactory $connection): bool
    {
        return strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType()) === 'sqlite';
    }

    private static function isUtcDatabaseTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d H:i:s') === $value;
    }

    private static function positiveInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }
        throw new \RuntimeException($message);
    }

    private static function nonNegativeInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int)$value;
        }
        throw new \RuntimeException($message);
    }
}
