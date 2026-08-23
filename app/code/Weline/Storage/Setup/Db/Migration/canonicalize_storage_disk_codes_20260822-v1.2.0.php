<?php

declare(strict_types=1);

namespace Weline\Storage\Setup\Db\Migration;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Model\StorageConfig;

final class CanonicalizeStorageDiskCodes20260822V120 extends AbstractMigration
{
    private const MAX_CONFIGS = 1000;

    public function getDescription(): string
    {
        return '将旧存储名迁移为 type::vendor::instance 三段式代码，并保留只读别名。';
    }

    public function getVersion(): string { return '1.2.0'; }
    public function getDate(): string { return '2026-08-22'; }
    public function getAffectedTables(): array { return [StorageConfig::schema_table]; }
    public function requiresBackup(): bool { return true; }
    public function getBackupStrategy(): array
    {
        return ['strategy' => 'table', 'tables' => $this->getAffectedTables(), 'columns' => []];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $prototype = ObjectManager::getInstance(StorageConfig::class);
        if (!$connection->tableExist($prototype->getTable())) {
            return true;
        }
        $rows = (clone $prototype)->clearData()->reset()
            ->order(StorageConfig::schema_fields_CONFIG_ID, 'ASC')
            ->limit(self::MAX_CONFIGS + 1)
            ->select()
            ->fetch()
            ->getItems();
        if (count($rows) > self::MAX_CONFIGS) {
            throw new \RuntimeException((string)__('存储磁盘配置数量超过迁移上限。'));
        }
        $plan = [];
        $owners = [];
        foreach ($rows as $row) {
            if (!$row instanceof StorageConfig || !$row->getId()) {
                continue;
            }
            $old = strtolower(trim((string)$row->getData(StorageConfig::schema_fields_NAME)));
            $driver = (string)$row->getData(StorageConfig::schema_fields_DRIVER);
            $provider = StorageConfig::providerCodeForDriver($driver);
            $canonical = StorageConfig::canonicalDiskCode($driver, $old);
            if (isset($owners[$canonical])) {
                throw new \RuntimeException((string)__('存储磁盘迁移冲突：%{1}', [$canonical]));
            }
            $rawConfig = trim((string)$row->getData(StorageConfig::schema_fields_CONFIG));
            try {
                $config = $rawConfig === ''
                    ? []
                    : json_decode($rawConfig, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException((string)__('存储配置 JSON 无效。'), 0, $exception);
            }
            if (!is_array($config)) {
                throw new \RuntimeException((string)__('存储配置 JSON 无效。'));
            }
            $owners[$canonical] = (int)$row->getId();
            $plan[] = [$row, $old, $canonical, $provider, $config, $rawConfig];
        }

        // A legacy alias is also an addressable disk identity. Validate its
        // ownership together with every canonical code before the first DDL;
        // otherwise a successful migration could leave two disks addressable
        // by the same old name and make reads ambiguous at runtime.
        $claims = [];
        foreach ($owners as $canonical => $configId) {
            $claims[$canonical] = $configId;
        }
        foreach ($plan as $offset => [$row, $old, $canonical, $provider, $config, $sourceConfigJson]) {
            $aliases = is_array($config['legacy_aliases'] ?? null) ? $config['legacy_aliases'] : [];
            if ($old !== '' && $old !== $canonical) {
                $aliases[] = $old;
            }
            $normalized = [];
            foreach ($aliases as $alias) {
                if (!is_string($alias)) {
                    throw new \RuntimeException((string)__('存储磁盘旧别名格式无效。'));
                }
                $alias = strtolower(trim($alias));
                if ($alias !== ''
                    && $alias !== $canonical
                    && strlen($alias) <= 190
                    && preg_match('/[\x00-\x1F\x7F]/', $alias) !== 1
                ) {
                    $normalized[$alias] = $alias;
                } elseif ($alias !== '' && $alias !== $canonical) {
                    throw new \RuntimeException((string)__('存储磁盘旧别名格式无效。'));
                }
                if (count($normalized) > 100) {
                    throw new \RuntimeException((string)__('单个存储磁盘的旧别名数量超过迁移上限。'));
                }
            }
            $configId = (int)$row->getId();
            foreach ($normalized as $alias) {
                if (in_array($alias, ['local', '__local__'], true)
                    && $canonical !== StorageDiskCode::BUILTIN_LOCAL_MEDIA
                ) {
                    throw new \RuntimeException((string)__('存储磁盘旧别名与内置本地磁盘冲突：%{1}', [$alias]));
                }
                if (isset($claims[$alias]) && $claims[$alias] !== $configId) {
                    throw new \RuntimeException((string)__('存储磁盘旧别名存在归属冲突：%{1}', [$alias]));
                }
                $claims[$alias] = $configId;
            }
            $config['legacy_aliases'] = array_values($normalized);
            // Seal legacy plaintext secrets and canonicalize JSON before DDL,
            // so keyring/config failures cannot leave a half-upgraded schema.
            $row->setConfigArray($config);
            $plan[$offset] = [
                $row,
                $old,
                $canonical,
                $provider,
                (string)$row->getData(StorageConfig::schema_fields_CONFIG),
                $sourceConfigJson,
            ];
        }

        if (!$this->columnExists(
            $connection,
            $prototype->getTable(),
            StorageConfig::schema_fields_CONFIG_REVISION,
        )) {
            $alter = $connection->alterTable()->forTable(
                $prototype->getTable(),
                StorageConfig::schema_fields_CONFIG_ID,
                '',
            );
            $alter->addColumn(
                StorageConfig::schema_fields_CONFIG_REVISION,
                '',
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL DEFAULT 1',
                '配置快照版本',
            );
            $alter->alter();
        }

        // SQLite does not enforce VARCHAR lengths, while the framework's
        // legacy SQLite alter-column implementation rebuilds the table via
        // CREATE TABLE AS SELECT and loses constraints/indexes. Only engines
        // that need the physical width change should execute this DDL.
        if (!$this->isSqlite($connection)) {
            $alter = $connection->alterTable()->forTable(
                $prototype->getTable(),
                StorageConfig::schema_fields_CONFIG_ID,
                '',
            );
            $alter->alterColumn(
                StorageConfig::schema_fields_NAME,
                StorageConfig::schema_fields_NAME,
                '',
                TableInterface::column_type_VARCHAR,
                190,
                'NOT NULL',
                '三段式存储磁盘代码',
            );
            $alter->alterColumn(
                StorageConfig::schema_fields_DRIVER,
                StorageConfig::schema_fields_DRIVER,
                '',
                TableInterface::column_type_VARCHAR,
                130,
                'NOT NULL',
                '存储驱动 Provider 代码',
            );
            $alter->alter();
        }

        foreach ($plan as $offset => [$row, $old, $canonical, $provider, $targetConfigJson, $sourceConfigJson]) {
            $currentDriver = strtolower(trim((string)$row->getData(StorageConfig::schema_fields_DRIVER)));
            $currentRevision = (int)$row->getData(StorageConfig::schema_fields_CONFIG_REVISION);
            if ($old === $canonical
                && $currentDriver === $provider
                && $sourceConfigJson === $targetConfigJson
                && $currentRevision >= 1
            ) {
                continue;
            }
            // Upgrade data must not depend on an optional runtime Provider being
            // installed. Perform a bounded direct row update without invoking
            // save_before(), whose runtime validation requires the Provider.
            $updater = clone $prototype;
            $updater->clearData()->reset()
                ->where(StorageConfig::schema_fields_CONFIG_ID, (int)$row->getId())
                ->update([
                    StorageConfig::schema_fields_NAME => $canonical,
                    StorageConfig::schema_fields_DRIVER => $provider,
                    StorageConfig::schema_fields_CONFIG => $targetConfigJson,
                    StorageConfig::schema_fields_CONFIG_REVISION => max(
                        1,
                        $currentRevision,
                    ) + 1,
                    StorageConfig::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->fetch();
            if (($offset + 1) % 50 === 0) {
                SchedulerSystem::yield();
            }
        }
        return true;
    }

    public function uninstall(): bool { return true; }

    private function isSqlite(object $connection): bool
    {
        return str_contains(strtolower($connection::class), 'sqlite');
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return (bool)$connection->hasField($table, $field);
        }
        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }
        return false;
    }
}
