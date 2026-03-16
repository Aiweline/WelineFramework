<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Service;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Output\Cli\Printing;

/**
 * 数据库迁移备份服务（Framework 内置）
 * 表名与 API 与 Weline\Database\Service\BackupService 兼容。
 *
 * @package Weline\Framework\Database\Service
 */
class BackupService
{
    private ConnectionFactory $connectionFactory;
    private MigrationBackup $backupModel;
    private Printing $printing;

    public const DEFAULT_CHUNK_SIZE = 1000;
    public const LARGE_TABLE_THRESHOLD = 10000;

    public function __construct(
        ConnectionFactory $connectionFactory,
        MigrationBackup $backupModel,
        Printing $printing
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->backupModel = $backupModel;
        $this->printing = $printing;
    }

    public function backupTableData(string $tableName, int $migrationId): array
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable)->select();
            $data = $query->fetch();

            if (empty($data)) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                return [];
            }

            $this->backupModel->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_TABLE,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ])->save();

            $this->printing->info(__("表 %{1} 数据备份完成，共 %{2} 条记录", [$tableName, count($data)]));
            return $data;
        } catch (\Exception $e) {
            $this->printing->error(__("备份表数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    /**
     * @param ConnectorInterface|null $connector 若提供则用其获取主键与查询（确保与 Schema 升级同一连接）
     * @param string|null $modelClass 模型类名，用于从 schema_primary_keys/schema_primary_key 解析主键（DB 查询失败时的回退）
     * @param string|null $reason 备份原因前缀，用于日志可读性，如 DROP/ADD/ALTER
     */
    public function backupColumnData(string $tableName, string $columnName, int $migrationId, ?ConnectorInterface $connector = null, ?string $modelClass = null, ?string $reason = null): array
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $conn = $connector ?? $this->connectionFactory->getConnector();
            $pkCols = $this->resolvePrimaryKeyColumns($rawTable, $conn, $modelClass);
            $fields = array_merge($pkCols, [$columnName]);
            $query = $conn->getQuery()->clearQuery()
                ->table($rawTable)
                ->fields($fields)
                ->where($columnName, '', '!=')
                ->select();
            $data = $query->fetch();

            if (empty($data)) {
                $prefix = $reason !== null && $reason !== '' ? "[{$reason}] " : '';
                $this->printing->info($prefix . __("表 %{1} 的列 %{2} 没有数据需要备份", [$tableName, $columnName]));
                return [];
            }

            $this->backupModel->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_COLUMN,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ])->save();

            $prefix = $reason !== null && $reason !== '' ? "[{$reason}] " : '';
            $this->printing->info($prefix . __("表 %{1} 的列 %{2} 数据备份完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            return $data;
        } catch (\Exception $e) {
            $this->printing->error(__("备份列数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    public function restoreTableData(string $tableName, int $migrationId, bool $clearBeforeRestore = true): bool
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery();
            $backup = $this->getBackupData($migrationId, $tableName, MigrationBackup::TYPE_TABLE);
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 的备份数据", $tableName));
                return true;
            }

            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning(__("表 %{1} 的备份数据为空", $tableName));
                return true;
            }

            if ($clearBeforeRestore) {
                $query->clearQuery()->table($rawTable)->delete()->fetch();
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }

            foreach ($data as $row) {
                $query->clearQuery()->table($rawTable)->insert($row)->fetch();
            }
            $this->printing->info(__("表 %{1} 数据恢复完成，共 %{2} 条记录", [$tableName, count($data)]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreColumnData(string $tableName, string $columnName, int $migrationId): bool
    {
        try {
            $backup = $this->getBackupData($migrationId, $tableName, MigrationBackup::TYPE_COLUMN);
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 列 %{2} 的备份数据", [$tableName, $columnName]));
                return true;
            }

            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning(__("表 %{1} 列 %{2} 的备份数据为空", [$tableName, $columnName]));
                return true;
            }

            $rawTable = $this->toRawTableName($tableName);
            foreach ($data as $row) {
                $pkCols = $this->inferPrimaryKeyColumnsFromRow($row, $columnName);
                if ($pkCols === []) {
                    continue;
                }
                $query = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable);
                foreach ($pkCols as $pkCol) {
                    $query = $query->where($pkCol, $row[$pkCol]);
                }
                $query->update([$columnName => $row[$columnName]])->fetch();
            }
            $this->printing->info(__("表 %{1} 列 %{2} 数据恢复完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复列数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    private function getBackupData(int $migrationId, string $tableName, string $backupType): ?MigrationBackup
    {
        $items = $this->backupModel->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->where(MigrationBackup::schema_fields_TABLE_NAME, $tableName)
            ->where(MigrationBackup::schema_fields_BACKUP_TYPE, $backupType)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        return $items[0] ?? null;
    }

    public function cleanupBackupData(int $migrationId): bool
    {
        try {
            $backups = $this->backupModel->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($backups as $backup) {
                $backup->delete();
            }
            $this->printing->info(__("迁移 %{1} 的备份数据清理完成", $migrationId));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("清理备份数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreByBackupId(int $backupId): bool
    {
        try {
            $backup = clone $this->backupModel;
            $backup->load($backupId);
            if (!$backup->getId()) {
                throw new \Exception(__("备份记录不存在: %{1}", (string) $backupId));
            }

            $tableName = $backup->getData(MigrationBackup::schema_fields_TABLE_NAME);
            $backupType = $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            $migrationId = (int) $backup->getData(MigrationBackup::schema_fields_MIGRATION_ID);

            if ($backupType === MigrationBackup::TYPE_TABLE) {
                return $this->restoreTableData($tableName, $migrationId);
            }

            if ($backupType === MigrationBackup::TYPE_COLUMN) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (!empty($data) && is_array($data)) {
                    $firstRow = reset($data);
                    $conn = $this->connectionFactory->getConnector();
                    $pkCols = $this->resolvePrimaryKeyColumns($this->toRawTableName($tableName), $conn, null);
                    $pkSet = array_fill_keys(array_map('strtolower', $pkCols), true);
                    $columns = array_filter(
                        array_keys($firstRow),
                        fn (string $c) => !isset($pkSet[strtolower($c)])
                    );
                    foreach ($columns as $column) {
                        $this->restoreColumnData($tableName, $column, $migrationId);
                    }
                }
                return true;
            }

            $this->printing->warning(__("未知的备份类型: %{1}", $backupType));
            return false;
        } catch (\Exception $e) {
            $this->printing->error(__("按 backup_id 恢复失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    /** @return MigrationBackup[] */
    public function getBackupsByMigrationId(int $migrationId): array
    {
        return $this->backupModel->getMigrationBackups($migrationId);
    }

    public function getBackupStats(int $migrationId): array
    {
        $backups = $this->backupModel->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->select()
            ->fetch()
            ->getItems();
        $stats = [
            'total' => count($backups),
            'tables' => 0,
            'columns' => 0,
            'structures' => 0,
            'chunks' => 0,
            'total_records' => 0
        ];

        foreach ($backups as $backup) {
            $backupType = $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            switch ($backupType) {
                case MigrationBackup::TYPE_TABLE:
                    $stats['tables']++;
                    break;
                case MigrationBackup::TYPE_COLUMN:
                    $stats['columns']++;
                    break;
                case MigrationBackup::TYPE_STRUCTURE:
                    $stats['structures']++;
                    break;
                case MigrationBackup::TYPE_CHUNK:
                    $stats['chunks']++;
                    break;
            }
            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (is_array($data)) {
                $stats['total_records'] += count($data);
            }
        }
        return $stats;
    }

    public function backupTableStructure(string $tableName, int $migrationId): bool
    {
        try {
            $connector = $this->connectionFactory->getConnector();
            $rawTable = $this->toRawTableName($tableName);
            $ddl = $connector->getCreateTableSql($rawTable);
            if (empty($ddl)) {
                $this->printing->warning(__("表 %{1} 的 DDL 为空", $tableName));
                return false;
            }

            $this->backupModel->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => $ddl,
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_STRUCTURE,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ])->save();

            $this->printing->info(__("表 %{1} 结构备份完成", $tableName));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("备份表结构失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreTableStructure(string $tableName, int $migrationId, bool $dropIfExists = false): bool
    {
        try {
            $connection = $this->connectionFactory->getConnector();
            $backup = $this->getBackupData($migrationId, $tableName, MigrationBackup::TYPE_STRUCTURE);
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 的结构备份", $tableName));
                return false;
            }

            $ddl = $backup->getData(MigrationBackup::schema_fields_BACKUP_DATA);
            if (empty($ddl)) {
                $this->printing->warning(__("表 %{1} 的结构备份为空", $tableName));
                return false;
            }

            if ($dropIfExists) {
                $rawTable = $this->toRawTableName($tableName);
                $connection->dropTableIfExists($rawTable);
            }
            $connection->query($ddl);
            $this->printing->info(__("表 %{1} 结构恢复完成", $tableName));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表结构失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function backupTableDataChunked(string $tableName, int $migrationId, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        try {
            $offset = 0;
            $totalRows = 0;
            $chunkIndex = 0;

            $rawTable = $this->toRawTableName($tableName);
            while (true) {
                $query = $this->connectionFactory->getQuery()->clearQuery()
                    ->table($rawTable)
                    ->limit($chunkSize, $offset)
                    ->select();
                $chunk = $query->fetch();

                if (empty($chunk)) {
                    break;
                }
                $this->saveBackupChunk($tableName, $chunk, $migrationId, $chunkIndex);
                $totalRows += count($chunk);
                $offset += $chunkSize;
                $chunkIndex++;
                unset($chunk);
                gc_collect_cycles();
            }

            $this->printing->info(__("表 %{1} 分批备份完成，共 %{2} 条记录，%{3} 个分块", [$tableName, $totalRows, $chunkIndex]));
            return [
                'table' => $tableName,
                'total_rows' => $totalRows,
                'chunks' => $chunkIndex,
                'chunk_size' => $chunkSize,
            ];
        } catch (\Exception $e) {
            $this->printing->error(__("分批备份表数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    /**
     * 从 connector 解析主键列名（支持单主键与复合主键）。
     * 优先用 connector->getTableColumns 获取当前表的主键；无主键时从实际存在的列中选取：
     * 1) modelClass.schema_primary_key 仅当该列存在于表中时使用；
     * 2) 否则尝试 'id'；
     * 3) 否则用第一列（用于无主键表如 m_cache）。
     *
     * @return list<string>
     */
    private function resolvePrimaryKeyColumns(string $rawTable, ConnectorInterface $connector, ?string $modelClass = null): array
    {
        $columns = $connector->getTableColumns($rawTable);
        $pkCols = [];
        $allNames = [];
        foreach ($columns as $col) {
            $name = $col['name'] ?? '';
            if ($name !== '') {
                $allNames[] = $name;
                if (!empty($col['primary_key'])) {
                    $pkCols[] = $name;
                }
            }
        }
        if ($pkCols !== []) {
            return $pkCols;
        }
        $colSet = array_fill_keys(array_map('strtolower', $allNames), true);
        $exists = fn (string $c) => isset($colSet[strtolower($c)]);
        if ($modelClass !== null && class_exists($modelClass)) {
            if (defined($modelClass . '::schema_primary_key') && $exists((string) $modelClass::schema_primary_key)) {
                return [$modelClass::schema_primary_key];
            }
            if (defined($modelClass . '::schema_primary_keys')) {
                $pks = $modelClass::schema_primary_keys;
                if (is_array($pks)) {
                    $valid = array_filter($pks, fn ($c) => is_string($c) && $exists($c));
                    if ($valid !== []) {
                        return array_values($valid);
                    }
                }
            }
        }
        if ($exists('id')) {
            return ['id'];
        }
        return $allNames !== [] ? [reset($allNames)] : ['id'];
    }

    /**
     * 从备份行推断主键列名（该行仅含 pk 与被备份列）。
     *
     * @return list<string>
     */
    private function inferPrimaryKeyColumnsFromRow(array $row, string $columnName): array
    {
        $result = [];
        foreach (array_keys($row) as $k) {
            if (strcasecmp((string) $k, $columnName) !== 0) {
                $result[] = $k;
            }
        }
        return $result;
    }

    /**
     * 去除表名的方言引号，得到 Query::table() 可用的逻辑名。
     * PostgreSQL: "public"."m_acl_xxx" -> public.m_acl_xxx
     * MySQL: `m_acl_xxx` -> m_acl_xxx
     */
    private function toRawTableName(string $tableName): string
    {
        return trim(str_replace(['`', '"'], '', $tableName));
    }

    private function saveBackupChunk(string $tableName, array $chunk, int $migrationId, int $chunkIndex): void
    {
        $this->backupModel->reset()->setData([
            MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
            MigrationBackup::schema_fields_TABLE_NAME => "{$tableName}:chunk:{$chunkIndex}",
            MigrationBackup::schema_fields_BACKUP_DATA => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_CHUNK,
            MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
        ])->save();
    }

    public function restoreTableDataChunked(string $tableName, int $migrationId, bool $clearBeforeRestore = true): bool
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery();
            $backups = $this->backupModel->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
                ->where(MigrationBackup::schema_fields_TABLE_NAME, "{$tableName}:chunk:%", 'LIKE')
                ->where(MigrationBackup::schema_fields_BACKUP_TYPE, MigrationBackup::TYPE_CHUNK)
                ->order(MigrationBackup::schema_fields_TABLE_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems();

            if (empty($backups)) {
                $this->printing->warning(__("没有找到表 %{1} 的分块备份数据", $tableName));
                return false;
            }

            if ($clearBeforeRestore) {
                $query->clearQuery()->table($rawTable)->delete()->fetch();
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }

            $totalRows = 0;
            foreach ($backups as $backup) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (empty($data)) {
                    continue;
                }
                foreach ($data as $row) {
                    $query->clearQuery()->table($rawTable)->insert($row)->fetch();
                }
                $totalRows += count($data);
                unset($data);
            }
            $this->printing->info(__("表 %{1} 分块数据恢复完成，共 %{2} 条记录", [$tableName, $totalRows]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复分块表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function smartBackupTable(string $tableName, int $migrationId, bool $includeStructure = true): array
    {
        $result = [
            'table' => $tableName,
            'structure_backed_up' => false,
            'data_backed_up' => false,
            'strategy' => 'none',
            'total_rows' => 0,
        ];

        try {
            if ($includeStructure) {
                $result['structure_backed_up'] = $this->backupTableStructure($tableName, $migrationId);
            }

            $rawTable = $this->toRawTableName($tableName);
            $rowCount = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable)->total();
            $result['total_rows'] = $rowCount;

            if ($rowCount === 0) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                $result['strategy'] = 'empty';
                return $result;
            }

            if ($rowCount > self::LARGE_TABLE_THRESHOLD) {
                $this->printing->info(__("表 %{1} 数据量较大 (%{2} 行)，使用分批备份", [$tableName, $rowCount]));
                $this->backupTableDataChunked($tableName, $migrationId);
                $result['strategy'] = 'chunked';
            } else {
                $this->backupTableData($tableName, $migrationId);
                $result['strategy'] = 'full';
            }
            $result['data_backed_up'] = true;
        } catch (\Exception $e) {
            $this->printing->error(__("智能备份失败: %{1}", $e->getMessage()));
            $result['error'] = $e->getMessage();
        }
        return $result;
    }
}
