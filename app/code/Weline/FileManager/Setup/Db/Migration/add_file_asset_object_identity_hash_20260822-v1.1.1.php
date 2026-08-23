<?php

declare(strict_types=1);

namespace Weline\FileManager\Setup\Db\Migration;

use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 补齐 weline_file_asset.object_identity_hash（1.1.0 Schema 已声明但库表可能未同步）。
 * 幂等：列/索引已存在则跳过；已有行按 disk_code + NUL + object_key 回填 SHA-256。
 */
final class AddFileAssetObjectIdentityHash20260822V111 extends AbstractMigration
{
    private const BATCH_SIZE = 500;
    private const MAX_PREFLIGHT_ROWS = 100000;

    public function getDescription(): string
    {
        return '为 FileAsset 补齐 object_identity_hash 列、回填哈希并建立唯一索引。';
    }

    public function getVersion(): string
    {
        return '1.1.1';
    }

    public function getDate(): string
    {
        return '2026-08-22';
    }

    /** @return list<string> */
    public function getAffectedTables(): array
    {
        return [FileAsset::schema_table];
    }

    public function requiresBackup(): bool
    {
        return true;
    }

    public function getBackupStrategy(): array
    {
        return ['strategy' => 'table', 'tables' => $this->getAffectedTables(), 'columns' => []];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $prototype = ObjectManager::getInstance(FileAsset::class);
        $table = $prototype->getTable();
        if (!$connection->tableExist($table)) {
            return true;
        }

        $field = FileAsset::schema_fields_OBJECT_IDENTITY_HASH;
        // MySQL may auto-commit ALTER TABLE. Validate every legacy identity and
        // collision before the first DDL so invalid data leaves the schema intact.
        $this->auditRows($prototype, false);
        if (!$this->columnExists($connection, $table, $field)) {
            $alter = $connection->alterTable()->forTable($table, FileAsset::schema_fields_ID, '');
            $alter->addColumn(
                $field,
                // Avoid the legacy SQLite column-order rebuild. The identity
                // contract depends on the field value, never its ordinal.
                '',
                TableInterface::column_type_VARCHAR,
                64,
                "NOT NULL DEFAULT ''",
                'disk_code + object_key 的 SHA-256',
            );
            $alter->alter();
        }

        $index = 'uk_file_asset_object_hash';
        if ($connection->hasIndex($table, $index)) {
            // A partially applied historical run may have indexed stale or
            // empty values. Rebuild after deterministic keyset backfill.
            $connection->query($connection->buildDropIndexSql(
                $connection->formatTableName($table),
                $index,
            ))->fetch();
        }
        $this->backfillHashes($prototype, $field);
        $this->auditRows($prototype, true);
        $connection->query($connection->buildAddIndexSql($connection->formatTableName($table), [
            'name' => $index,
            'type' => TableInterface::index_type_UNIQUE,
            'columns' => [$field],
        ]))->fetch();

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function backfillHashes(FileAsset $prototype, string $field): void
    {
        $lastId = '';
        while (true) {
            $query = (clone $prototype)->clearData()->reset()
                ->order(FileAsset::schema_fields_ID, 'ASC')
                ->limit(self::BATCH_SIZE);
            if ($lastId !== '') {
                $query->where(FileAsset::schema_fields_ID, $lastId, '>');
            }
            $rows = $query->select()->fetch()->getItems();
            if ($rows === []) {
                return;
            }
            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!$row instanceof FileAsset) {
                    throw new \RuntimeException((string)__('FileAsset 哈希回填读取到无效数据。'));
                }
                $assetId = $row->getAssetId();
                if ($assetId === '' || ($lastId !== '' && strcmp($assetId, $lastId) <= 0)) {
                    continue;
                }
                $hash = FileAsset::objectIdentityHash($row->getDiskCode(), $row->getObjectKey());
                if (!hash_equals($hash, (string)$row->getData($field))) {
                    (clone $prototype)->clearData()->reset()
                        ->where(FileAsset::schema_fields_ID, $assetId)
                        ->update([$field => $hash])
                        ->fetch();
                }
                $nextId = $assetId;
            }
            if ($nextId === $lastId) {
                throw new \RuntimeException((string)__('FileAsset 哈希回填无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < self::BATCH_SIZE) {
                return;
            }
        }
    }

    private function auditRows(FileAsset $prototype, bool $verifyStoredHash): void
    {
        $lastId = '';
        $processed = 0;
        $seenHashes = [];
        while (true) {
            $query = (clone $prototype)->clearData()->reset()
                ->order(FileAsset::schema_fields_ID, 'ASC')
                ->limit(self::BATCH_SIZE);
            if ($lastId !== '') {
                $query->where(FileAsset::schema_fields_ID, $lastId, '>');
            }
            $rows = $query->select()->fetch()->getItems();
            if ($rows === []) {
                return;
            }
            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!$row instanceof FileAsset) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据无效，升级已停止。'));
                }
                $assetId = $row->getAssetId();
                if ($assetId === '' || ($lastId !== '' && strcmp($assetId, $lastId) <= 0)) {
                    continue;
                }
                try {
                    $hash = FileAsset::objectIdentityHash($row->getDiskCode(), $row->getObjectKey());
                } catch (\Throwable $throwable) {
                    throw new \RuntimeException((string)__('FileAsset 旧存储身份无效，升级已停止。'), 0, $throwable);
                }
                if (isset($seenHashes[$hash])) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据存在重复存储对象身份，升级已停止。'));
                }
                if ($verifyStoredHash && !hash_equals($hash, (string)$row->getData(
                    FileAsset::schema_fields_OBJECT_IDENTITY_HASH,
                ))) {
                    throw new \RuntimeException((string)__('FileAsset 存储身份哈希回填校验失败。'));
                }
                $seenHashes[$hash] = true;
                if (++$processed > self::MAX_PREFLIGHT_ROWS) {
                    throw new \RuntimeException((string)__('FileAsset 旧数据超过安全升级上限。'));
                }
                $nextId = $assetId;
            }
            if ($nextId === $lastId) {
                throw new \RuntimeException((string)__('FileAsset 旧数据预检无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < self::BATCH_SIZE) {
                return;
            }
        }
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (\method_exists($connection, 'hasField')) {
            return (bool)$connection->hasField($table, $field);
        }
        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
            if (\strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
