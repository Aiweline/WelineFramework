<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Exception\UniqueConstraintViolationDetector;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * 幂等补种：确保每个 Website（含 website_id=0 系统默认站）
 * 恰有一个 default/normal Store，且每个 active Store 下恰有一个 default SalesChannel。
 *
 * 可重复执行；第二次执行新增行数为 0。
 */
class StoreChannelSeedService
{
    public function __construct(
        private readonly Website $website,
        private readonly Store $store,
        private readonly SalesChannel $channel,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly UniqueConstraintViolationDetector $uniqueViolation,
    ) {
    }

    /**
     * @return array{stores_created: int, channels_created: int, websites: int}
     */
    public function ensureDefaults(?ConnectionFactory $connection = null): array
    {
        $connection ??= $this->website->getConnection();
        return $this->withinWriteTransaction(
            $connection,
            fn(): array => $this->ensureDefaultsInTransaction($connection),
        );
    }

    /**
     * 为单个 Website 幂等补种（新建站点时由 Website::save_after 调用）。
     *
     * @return array{stores_created: int, channels_created: int}
     */
    public function ensureDefaultsForWebsite(
        int $websiteId,
        string $websiteName = '',
        ?ConnectionFactory $connection = null,
    ): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数（0 是合法默认站）'));
        }
        $connection ??= $this->website->getConnection();
        return $this->withinWriteTransaction(
            $connection,
            fn(): array => $this->ensureDefaultsForWebsiteInTransaction(
                $connection,
                $websiteId,
                $websiteName,
            ),
        );
    }

    /** @return array{stores_created: int, channels_created: int, websites: int} */
    private function ensureDefaultsInTransaction(ConnectionFactory $connection): array
    {
        $rows = $this->newWebsite($connection)
            ->order(Website::schema_fields_ID, 'ASC')
            ->select()->fetchArray();
        $websites = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && array_key_exists(Website::schema_fields_ID, $row)) {
                $websites[] = $row;
            }
        }

        $storesCreated = 0;
        $channelsCreated = 0;
        foreach ($websites as $websiteRow) {
            $websiteId = (int)$websiteRow[Website::schema_fields_ID];
            $websiteName = (string)($websiteRow[Website::schema_fields_NAME] ?? '');
            $this->ensureDefaultStore($connection, $websiteId, $websiteName, $storesCreated);
            $this->ensureDefaultChannelsForWebsite($connection, $websiteId, $channelsCreated);
        }

        return [
            'stores_created' => $storesCreated,
            'channels_created' => $channelsCreated,
            'websites' => count($websites),
        ];
    }

    /** @return array{stores_created: int, channels_created: int} */
    private function ensureDefaultsForWebsiteInTransaction(
        ConnectionFactory $connection,
        int $websiteId,
        string $websiteName,
    ): array {
        $website = $this->requireWebsite($connection, $websiteId, false);
        if ($websiteName === '') {
            $websiteName = $website->getName();
        }
        $storesCreated = 0;
        $channelsCreated = 0;
        $this->ensureDefaultStore($connection, $websiteId, $websiteName, $storesCreated);
        $this->ensureDefaultChannelsForWebsite($connection, $websiteId, $channelsCreated);
        return ['stores_created' => $storesCreated, 'channels_created' => $channelsCreated];
    }

    private function withinWriteTransaction(ConnectionFactory $connection, callable $callback): mixed
    {
        if ($this->transactions->isActive($connection)) {
            try {
                if ($this->isSqlite($connection) && !$this->transactions->isWriteIntent($connection)) {
                    throw new \LogicException('websites_seed_sqlite_write_intent_required');
                }
                return $callback();
            } catch (\Throwable $exception) {
                $this->transactions->markRollbackOnly($connection, $exception);
                throw $exception;
            }
        }
        return $this->transactions->runWrite($connection, $callback);
    }

    /**
     * 在保存点内普通 INSERT；仅目标唯一冲突可视为并发幂等命中。
     */
    private function ensureDefaultStore(
        ConnectionFactory $connection,
        int $websiteId,
        string $websiteName,
        int &$created,
    ): Store
    {
        $store = $this->loadDefaultStore($connection, $websiteId);
        if ($store->hasData(Store::schema_fields_ID)) {
            // Lock in parent→child order and then discard the pre-lock snapshot.
            $this->requireWebsite($connection, $websiteId, true);
            $store = $this->loadDefaultStore($connection, $websiteId, true);
            if (!$store->hasData(Store::schema_fields_ID)) {
                throw new \RuntimeException(__('默认店铺在锁定复核期间消失'));
            }
            return $this->assertDefaultStore($store, $websiteId);
        }

        $name = $this->defaultStoreName($websiteName);
        $candidate = $this->newStore($connection)->setData([
            Store::schema_fields_WEBSITE_ID => $websiteId,
            Store::schema_fields_CODE => Store::CODE_DEFAULT,
            Store::schema_fields_NAME => $name,
            Store::schema_fields_STORE_MODE => Store::MODE_NORMAL,
            Store::schema_fields_IS_DEFAULT => 1,
            Store::schema_fields_STATUS => 1,
        ]);
        try {
            $result = $this->transactions->withSavepoint(
                $connection,
                'websites_default_store',
                static fn(): bool|int => $candidate->save(),
            );
            if ($result === false) {
                throw new \RuntimeException(__('默认店铺补种失败'));
            }
            ++$created;
        } catch (\Throwable $exception) {
            if (!$this->uniqueViolation->matchesExactColumns(
                $exception,
                'uk_website_store_code',
                $candidate->getTable(),
                [Store::schema_fields_WEBSITE_ID, Store::schema_fields_CODE],
            )) {
                throw $exception;
            }
        }

        $store = $this->loadDefaultStore($connection, $websiteId, true);
        if (!$store->hasData(Store::schema_fields_ID)) {
            throw new \RuntimeException(__('默认店铺并发补种后无法回读'));
        }
        return $this->assertDefaultStore($store, $websiteId);
    }

    private function ensureDefaultChannel(
        ConnectionFactory $connection,
        int $websiteId,
        int $storeId,
        int &$created,
    ): SalesChannel
    {
        if ($storeId <= 0) {
            throw new \RuntimeException(__('默认渠道补种缺少有效 store_id'));
        }
        $channel = $this->loadDefaultChannel($connection, $storeId);
        if ($channel->hasData(SalesChannel::schema_fields_ID)) {
            return $this->assertDefaultChannel($channel, $websiteId, $storeId);
        }

        $candidate = $this->newChannel($connection)->setData([
            SalesChannel::schema_fields_WEBSITE_ID => $websiteId,
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => SalesChannel::CODE_DEFAULT,
            SalesChannel::schema_fields_NAME => __('默认渠道'),
            SalesChannel::schema_fields_IS_DEFAULT => 1,
            SalesChannel::schema_fields_STATUS => 1,
        ]);
        try {
            $result = $this->transactions->withSavepoint(
                $connection,
                'websites_default_channel',
                static fn(): bool|int => $candidate->save(),
            );
            if ($result === false) {
                throw new \RuntimeException(__('默认渠道补种失败'));
            }
            ++$created;
        } catch (\Throwable $exception) {
            if (!$this->uniqueViolation->matchesExactColumns(
                $exception,
                'uk_store_channel_code',
                $candidate->getTable(),
                [SalesChannel::schema_fields_STORE_ID, SalesChannel::schema_fields_CODE],
            )) {
                throw $exception;
            }
        }

        $channel = $this->loadDefaultChannel($connection, $storeId, true);
        if (!$channel->hasData(SalesChannel::schema_fields_ID)) {
            throw new \RuntimeException(__('默认渠道并发补种后无法回读'));
        }
        return $this->assertDefaultChannel($channel, $websiteId, $storeId);
    }

    private function ensureDefaultChannelsForWebsite(
        ConnectionFactory $connection,
        int $websiteId,
        int &$created,
    ): void {
        $rows = $this->newStore($connection)
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->order(Store::schema_fields_ID, 'ASC')
            ->select()->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lifecycle = (string)($row[Store::schema_fields_LIFECYCLE_STATUS] ?? Store::LIFECYCLE_ACTIVE);
            $tombstonedAt = $row[Store::schema_fields_TOMBSTONED_AT] ?? null;
            if ($lifecycle !== Store::LIFECYCLE_ACTIVE || $tombstonedAt !== null) {
                continue;
            }
            $storeId = (int)($row[Store::schema_fields_ID] ?? 0);
            if ($storeId <= 0) {
                throw new \RuntimeException(__('默认渠道补种读取到无效 store_id'));
            }
            $this->ensureDefaultChannel($connection, $websiteId, $storeId, $created);
        }
    }

    private function loadDefaultStore(
        ConnectionFactory $connection,
        int $websiteId,
        bool $lockingRead = false,
    ): Store
    {
        $store = $this->newStore($connection);
        $row = $this->fetchRow($connection, $store->getTable(), [
            Store::schema_fields_WEBSITE_ID => $websiteId,
            Store::schema_fields_CODE => Store::CODE_DEFAULT,
        ], $lockingRead);
        if ($row !== null) {
            $store->setData($row);
        }
        return $store;
    }

    private function assertDefaultStore(Store $store, int $websiteId): Store
    {
        if ($store->getWebsiteId() !== $websiteId
            || !$store->isDefault()
            || !$store->isEnabled()
            || $store->getStoreMode() !== Store::MODE_NORMAL
            || (string)$store->getData(Store::schema_fields_LIFECYCLE_STATUS) !== Store::LIFECYCLE_ACTIVE
            || $store->getData(Store::schema_fields_TOMBSTONED_AT) !== null) {
            throw new \RuntimeException(__('既有默认店铺不满足 default/normal/启用/active 不变量'));
        }
        return $store;
    }

    private function loadDefaultChannel(
        ConnectionFactory $connection,
        int $storeId,
        bool $lockingRead = false,
    ): SalesChannel
    {
        $channel = $this->newChannel($connection);
        $row = $this->fetchRow($connection, $channel->getTable(), [
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => SalesChannel::CODE_DEFAULT,
        ], $lockingRead);
        if ($row !== null) {
            $channel->setData($row);
        }
        return $channel;
    }

    private function assertDefaultChannel(
        SalesChannel $channel,
        int $websiteId,
        int $storeId,
    ): SalesChannel {
        if ($channel->getWebsiteId() !== $websiteId
            || $channel->getStoreId() !== $storeId
            || !$channel->isDefault()
            || !$channel->isEnabled()) {
            throw new \RuntimeException(__('既有默认渠道不满足父 Scope/default/启用不变量'));
        }
        return $channel;
    }

    private function newWebsite(ConnectionFactory $connection): Website
    {
        $model = clone $this->website;
        return $model->setConnection($connection)->clearData()->clearQuery();
    }

    private function requireWebsite(
        ConnectionFactory $connection,
        int $websiteId,
        bool $lockingRead,
    ): Website {
        $website = $this->newWebsite($connection);
        $sql = 'SELECT * FROM ' . $website->getTable()
            . ' WHERE ' . Website::schema_fields_ID . ' = :website_id';
        if ($lockingRead && $this->supportsForUpdate($connection)) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $connection->getConnector()->getWrappedConnection()->prepare($sql);
        $statement->execute(['website_id' => $websiteId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !array_key_exists(Website::schema_fields_ID, $row)
            || (int)$row[Website::schema_fields_ID] !== $websiteId) {
            throw new \RuntimeException(__('补种目标 Website 不存在'));
        }
        $website->setData($row);
        return $website;
    }

    private function defaultStoreName(string $websiteName): string
    {
        $suffix = trim((string)__('默认店铺'));
        $suffix = mb_substr($suffix, 0, Store::NAME_MAX_LENGTH, 'UTF-8');
        $websiteName = trim($websiteName);
        if ($websiteName === '' || mb_strlen($suffix, 'UTF-8') >= Store::NAME_MAX_LENGTH) {
            return $suffix;
        }

        $prefixLength = Store::NAME_MAX_LENGTH - mb_strlen($suffix, 'UTF-8') - 1;
        $prefix = mb_substr($websiteName, 0, $prefixLength, 'UTF-8');
        return $prefix !== '' ? $prefix . ' ' . $suffix : $suffix;
    }

    private function newStore(ConnectionFactory $connection): Store
    {
        $model = clone $this->store;
        return $model->setConnection($connection)->clearData()->clearQuery();
    }

    private function newChannel(ConnectionFactory $connection): SalesChannel
    {
        $model = clone $this->channel;
        return $model->setConnection($connection)->clearData()->clearQuery();
    }

    /** @param array<string, int|string> $conditions */
    private function fetchRow(
        ConnectionFactory $connection,
        string $table,
        array $conditions,
        bool $lockingRead,
    ): ?array {
        $where = [];
        $params = [];
        foreach ($conditions as $field => $value) {
            $placeholder = 'value_' . count($params);
            $where[] = $field . ' = :' . $placeholder;
            $params[$placeholder] = $value;
        }
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        if ($lockingRead && $this->supportsForUpdate($connection)) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $connection->getConnector()->getWrappedConnection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
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
}
