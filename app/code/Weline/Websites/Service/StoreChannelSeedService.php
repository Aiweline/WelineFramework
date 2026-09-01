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
 * 系统默认站的默认店铺/渠道主键固定为 store_id=0、channel_id=0。
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
     * website_id=0 的默认店铺固定 store_id=0；其默认渠道固定 channel_id=0。
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
            $store = $this->assertDefaultStore($store, $websiteId);
            return $this->canonicalizeSystemDefaultStoreId($connection, $store, $websiteId);
        }

        $name = $this->defaultStoreName($websiteName);
        if ($websiteId === Website::ID_DEFAULT) {
            $this->insertForcedIdRow(
                $connection,
                $this->newStore($connection)->getTable(),
                [
                    Store::schema_fields_ID => Store::ID_DEFAULT,
                    Store::schema_fields_WEBSITE_ID => $websiteId,
                    Store::schema_fields_CODE => Store::CODE_DEFAULT,
                    Store::schema_fields_NAME => $name,
                    Store::schema_fields_STORE_MODE => Store::MODE_NORMAL,
                    Store::schema_fields_IS_DEFAULT => 1,
                    Store::schema_fields_STATUS => 1,
                    Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
                ],
                'websites_default_store_id0',
            );
            ++$created;
        } else {
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
        }

        $store = $this->loadDefaultStore($connection, $websiteId, true);
        if (!$store->hasData(Store::schema_fields_ID)) {
            throw new \RuntimeException(__('默认店铺并发补种后无法回读'));
        }
        $store = $this->assertDefaultStore($store, $websiteId);
        return $this->canonicalizeSystemDefaultStoreId($connection, $store, $websiteId);
    }

    private function ensureDefaultChannel(
        ConnectionFactory $connection,
        int $websiteId,
        int $storeId,
        int &$created,
    ): SalesChannel
    {
        if ($storeId < 0) {
            throw new \RuntimeException(__('默认渠道补种缺少有效 store_id'));
        }
        $channel = $this->loadDefaultChannel($connection, $storeId);
        if ($channel->hasData(SalesChannel::schema_fields_ID)) {
            $channel = $this->assertDefaultChannel($channel, $websiteId, $storeId);
            return $this->canonicalizeSystemDefaultChannelId($connection, $channel, $websiteId, $storeId);
        }

        if ($websiteId === Website::ID_DEFAULT && $storeId === Store::ID_DEFAULT) {
            $this->insertForcedIdRow(
                $connection,
                $this->newChannel($connection)->getTable(),
                [
                    SalesChannel::schema_fields_ID => SalesChannel::ID_DEFAULT,
                    SalesChannel::schema_fields_WEBSITE_ID => $websiteId,
                    SalesChannel::schema_fields_STORE_ID => $storeId,
                    SalesChannel::schema_fields_CODE => SalesChannel::CODE_DEFAULT,
                    SalesChannel::schema_fields_NAME => (string)__('默认渠道'),
                    SalesChannel::schema_fields_IS_DEFAULT => 1,
                    SalesChannel::schema_fields_STATUS => 1,
                ],
                'websites_default_channel_id0',
            );
            ++$created;
        } else {
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
        }

        $channel = $this->loadDefaultChannel($connection, $storeId, true);
        if (!$channel->hasData(SalesChannel::schema_fields_ID)) {
            throw new \RuntimeException(__('默认渠道并发补种后无法回读'));
        }
        $channel = $this->assertDefaultChannel($channel, $websiteId, $storeId);
        return $this->canonicalizeSystemDefaultChannelId($connection, $channel, $websiteId, $storeId);
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
            $storeId = (int)($row[Store::schema_fields_ID] ?? -1);
            if ($storeId < 0) {
                throw new \RuntimeException(__('默认渠道补种读取到无效 store_id'));
            }
            $this->ensureDefaultChannel($connection, $websiteId, $storeId, $created);
        }
    }

    private function canonicalizeSystemDefaultStoreId(
        ConnectionFactory $connection,
        Store $store,
        int $websiteId,
    ): Store {
        if ($websiteId !== Website::ID_DEFAULT) {
            return $store;
        }
        $oldId = (int)$store->getData(Store::schema_fields_ID);
        if ($oldId === Store::ID_DEFAULT) {
            return $store;
        }
        $this->movePrimaryKeyAndReferences(
            $connection,
            $store->getTable(),
            Store::schema_fields_ID,
            $oldId,
            Store::ID_DEFAULT,
            Store::schema_fields_ID,
            (string)__('默认店铺 ID %{1} 已被非 default 店铺占用，无法迁移。', [(string)Store::ID_DEFAULT]),
            Store::schema_fields_CODE,
            Store::CODE_DEFAULT,
        );
        $store = $this->loadDefaultStore($connection, $websiteId, true);
        if (!$store->hasData(Store::schema_fields_ID)
            || (int)$store->getData(Store::schema_fields_ID) !== Store::ID_DEFAULT) {
            throw new \RuntimeException(__('默认店铺迁移到 store_id=0 后无法回读'));
        }
        return $this->assertDefaultStore($store, $websiteId);
    }

    private function canonicalizeSystemDefaultChannelId(
        ConnectionFactory $connection,
        SalesChannel $channel,
        int $websiteId,
        int $storeId,
    ): SalesChannel {
        if ($websiteId !== Website::ID_DEFAULT || $storeId !== Store::ID_DEFAULT) {
            return $channel;
        }
        $oldId = (int)$channel->getData(SalesChannel::schema_fields_ID);
        if ($oldId === SalesChannel::ID_DEFAULT) {
            return $channel;
        }
        $this->movePrimaryKeyAndReferences(
            $connection,
            $channel->getTable(),
            SalesChannel::schema_fields_ID,
            $oldId,
            SalesChannel::ID_DEFAULT,
            SalesChannel::schema_fields_ID,
            (string)__('默认渠道 ID %{1} 已被非 default 渠道占用，无法迁移。', [(string)SalesChannel::ID_DEFAULT]),
            SalesChannel::schema_fields_CODE,
            SalesChannel::CODE_DEFAULT,
        );
        $channel = $this->loadDefaultChannel($connection, $storeId, true);
        if (!$channel->hasData(SalesChannel::schema_fields_ID)
            || (int)$channel->getData(SalesChannel::schema_fields_ID) !== SalesChannel::ID_DEFAULT) {
            throw new \RuntimeException(__('默认渠道迁移到 channel_id=0 后无法回读'));
        }
        return $this->assertDefaultChannel($channel, $websiteId, $storeId);
    }

    /**
     * @param array<string, int|string> $data
     */
    private function insertForcedIdRow(
        ConnectionFactory $connection,
        string $tableName,
        array $data,
        string $savepoint,
    ): void {
        $this->transactions->withSavepoint(
            $connection,
            $savepoint,
            function () use ($connection, $tableName, $data): bool {
                $db = $connection->getConnector()->getWrappedConnection();
                $driver = strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType());
                $columns = array_keys($data);
                $quotedColumns = array_map(fn(string $c): string => $this->quoteIdentifier($c, $driver), $columns);
                $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
                $sql = 'INSERT INTO ' . $this->quoteIdentifier($tableName, $driver)
                    . ' (' . implode(', ', $quotedColumns) . ')'
                    . ' VALUES (' . implode(', ', $placeholders) . ')';
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $db->execute('SET @WELINE_OLD_SQL_MODE=@@SESSION.sql_mode');
                    $db->execute("SET SESSION sql_mode=CONCAT_WS(',', @@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO')");
                }
                try {
                    $statement = $db->prepare($sql);
                    $statement->execute($data);
                } finally {
                    if ($driver === 'mysql' || $driver === 'mariadb') {
                        $db->execute('SET SESSION sql_mode=@WELINE_OLD_SQL_MODE');
                    }
                }
                return true;
            },
        );
    }

    private function movePrimaryKeyAndReferences(
        ConnectionFactory $connection,
        string $primaryTable,
        string $primaryColumn,
        int $oldId,
        int $newId,
        string $referenceColumn,
        string $occupiedMessage,
        string $codeColumn,
        string $expectedCode,
    ): void {
        $driver = strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType());
        $db = $connection->getConnector()->getWrappedConnection();
        $rowAtNew = $this->fetchRow($connection, $primaryTable, [$primaryColumn => $newId], true);
        if ($rowAtNew !== null && (string)($rowAtNew[$codeColumn] ?? '') !== $expectedCode) {
            throw new \RuntimeException($occupiedMessage);
        }
        if ($rowAtNew === null) {
            $sql = 'UPDATE ' . $this->quoteIdentifier($primaryTable, $driver)
                . ' SET ' . $this->quoteIdentifier($primaryColumn, $driver) . ' = :new_id'
                . ' WHERE ' . $this->quoteIdentifier($primaryColumn, $driver) . ' = :old_id';
            $statement = $db->prepare($sql);
            $statement->execute(['new_id' => $newId, 'old_id' => $oldId]);
        }
        foreach ($this->tablesWithColumn($connection, $referenceColumn) as $table) {
            if ($table === $primaryTable) {
                continue;
            }
            $this->rewriteReferenceColumn($connection, $driver, $table, $referenceColumn, $oldId, $newId);
        }
    }

    /**
     * 将引用列从 oldId 改写为 newId。若目标 ID 已有同行唯一键（常见于历史把 0 当哨兵），
     * 则删除仍指向 oldId 的冲突行，保留 newId 侧数据。
     * 每次尝试使用框架 savepoint，避免 PostgreSQL 唯一冲突污染外层事务。
     */
    private function rewriteReferenceColumn(
        ConnectionFactory $connection,
        string $driver,
        string $table,
        string $column,
        int $oldId,
        int $newId,
    ): void {
        $db = $connection->getConnector()->getWrappedConnection();
        $quotedTable = $this->quoteIdentifier($table, $driver);
        $quotedColumn = $this->quoteIdentifier($column, $driver);
        $bulkSql = 'UPDATE ' . $quotedTable
            . ' SET ' . $quotedColumn . ' = :new_id'
            . ' WHERE ' . $quotedColumn . ' = :old_id';
        if ($this->tryInSavepoint($connection, 'websites_ref_bulk', function () use ($db, $bulkSql, $newId, $oldId): void {
            $statement = $db->prepare($bulkSql);
            $statement->execute(['new_id' => $newId, 'old_id' => $oldId]);
        })) {
            return;
        }

        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $selectSql = 'SELECT ctid::text AS row_id FROM ' . $quotedTable
                . ' WHERE ' . $quotedColumn . ' = :old_id';
            $statement = $db->prepare($selectSql);
            $statement->execute(['old_id' => $oldId]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $rowId = (string)($row['row_id'] ?? '');
                if ($rowId === '') {
                    continue;
                }
                $updated = $this->tryInSavepoint(
                    $connection,
                    'websites_ref_one',
                    function () use ($db, $quotedTable, $quotedColumn, $newId, $rowId): void {
                        $update = $db->prepare(
                            'UPDATE ' . $quotedTable
                            . ' SET ' . $quotedColumn . ' = :new_id WHERE ctid = :row_id::tid'
                        );
                        $update->execute(['new_id' => $newId, 'row_id' => $rowId]);
                    },
                );
                if ($updated) {
                    continue;
                }
                $this->tryInSavepoint(
                    $connection,
                    'websites_ref_del',
                    function () use ($db, $quotedTable, $rowId): void {
                        $delete = $db->prepare('DELETE FROM ' . $quotedTable . ' WHERE ctid = :row_id::tid');
                        $delete->execute(['row_id' => $rowId]);
                    },
                );
            }
            return;
        }

        $deleteSql = 'DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedColumn . ' = :old_id';
        $statement = $db->prepare($deleteSql);
        $statement->execute(['old_id' => $oldId]);
    }

    /** @param callable():void $callback */
    private function tryInSavepoint(ConnectionFactory $connection, string $name, callable $callback): bool
    {
        try {
            $this->transactions->withSavepoint($connection, $name, function () use ($callback): bool {
                $callback();
                return true;
            });
            return true;
        } catch (\Throwable $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }
            return false;
        }
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        if ($exception instanceof \PDOException) {
            $sqlState = (string)($exception->errorInfo[0] ?? $exception->getCode());
            if (in_array($sqlState, ['23505', '23000'], true)) {
                return true;
            }
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'duplicate') || str_contains($message, 'unique')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function tablesWithColumn(ConnectionFactory $connection, string $columnName): array
    {
        $driver = strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType());
        $pdo = $connection->getConnector()->getWrappedConnection();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $statement = $pdo->prepare(
                'SELECT TABLE_NAME AS table_name FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['column_name' => $columnName]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $statement = $pdo->prepare(
                "SELECT table_schema || '.' || table_name AS table_name "
                . 'FROM information_schema.columns '
                . 'WHERE column_name = :column_name '
                . "AND table_schema NOT IN ('pg_catalog', 'information_schema')"
            );
            $statement->execute(['column_name' => $columnName]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $tables = $pdo->query(
                "SELECT name AS table_name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $rows = [];
            foreach ($tables as $tableRow) {
                $table = (string)($tableRow['table_name'] ?? '');
                if ($table === '') {
                    continue;
                }
                $columns = $pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table, 'sqlite') . ')')
                    ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($columns as $column) {
                    if ((string)($column['name'] ?? '') === $columnName) {
                        $rows[] = ['table_name' => $table];
                        break;
                    }
                }
            }
        }
        $tables = [];
        foreach ($rows as $row) {
            $table = (string)($row['table_name'] ?? '');
            if ($table !== '') {
                $tables[] = $table;
            }
        }
        return array_values(array_unique($tables));
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $identifier = str_replace(['`', '"'], '', trim($identifier));
        $quote = ($driver === 'mysql' || $driver === 'mariadb') ? '`' : '"';
        $escapedQuote = $quote . $quote;
        $parts = explode('.', $identifier);
        $quoted = array_map(
            static fn(string $part): string => $quote . str_replace($quote, $escapedQuote, $part) . $quote,
            $parts
        );
        return implode('.', $quoted);
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
