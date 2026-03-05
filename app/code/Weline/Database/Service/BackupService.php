<?php
/**
 * 数据库备份服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Model\MigrationBackup;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

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
    
    /**
     * 备份表数据
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @return array 备份数据
     */
    public function backupTableData(string $tableName, int $migrationId): array
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取表的所有数据
            $query = $connection->select()->from($tableName);
            $data = $connection->fetchAll($query);
            
            if (empty($data)) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                return [];
            }
            
            // 保存备份记录
            $this->backupModel->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => 'table',
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->backupModel->save();
            
            $this->printing->info(__("表 %{1} 数据备份完成，共 %{2} 条记录", [$tableName, count($data)]));
            
            return $data;
            
        } catch (\Exception $e) {
            $this->printing->error(__("备份表数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }
    
    /**
     * 备份列数据
     * 
     * @param string $tableName 表名
     * @param string $columnName 列名
     * @param int $migrationId 迁移ID
     * @return array 备份数据
     */
    public function backupColumnData(string $tableName, string $columnName, int $migrationId): array
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取列数据
            $query = $connection->select()
                ->from($tableName, ['id', $columnName])
                ->where($columnName, null, 'IS NOT NULL');
            $data = $connection->fetchAll($query);
            
            if (empty($data)) {
                $this->printing->info(__("表 %{1} 的列 %{2} 没有数据需要备份", [$tableName, $columnName]));
                return [];
            }
            
            // 保存备份记录
            $this->backupModel->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => 'column',
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->backupModel->save();
            
            $this->printing->info(__("表 %{1} 的列 %{2} 数据备份完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            
            return $data;
            
        } catch (\Exception $e) {
            $this->printing->error(__("备份列数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }
    
    /**
     * 恢复表数据
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @param bool $clearBeforeRestore 恢复前是否清空表
     * @return bool
     */
    public function restoreTableData(string $tableName, int $migrationId, bool $clearBeforeRestore = true): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取备份数据
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
            
            // 恢复前清空表数据，避免主键冲突
            if ($clearBeforeRestore) {
                $connection->query("DELETE FROM `{$tableName}`");
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }
            
            // 恢复数据
            foreach ($data as $row) {
                $connection->insert($tableName, $row);
            }
            
            $this->printing->info(__("表 %{1} 数据恢复完成，共 %{2} 条记录", [$tableName, count($data)]));
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 恢复列数据
     * 
     * @param string $tableName 表名
     * @param string $columnName 列名
     * @param int $migrationId 迁移ID
     * @return bool
     */
    public function restoreColumnData(string $tableName, string $columnName, int $migrationId): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取备份数据
            $backup = $this->getBackupData($migrationId, $tableName, 'column');
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 列 %{2} 的备份数据", [$tableName, $columnName]));
                return true;
            }
            
            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning(__("表 %{1} 列 %{2} 的备份数据为空", [$tableName, $columnName]));
                return true;
            }
            
            // 恢复数据
            foreach ($data as $row) {
                $connection->update(
                    $tableName,
                    [$columnName => $row[$columnName]],
                    ['id = ?' => $row['id']]
                );
            }
            
            $this->printing->info(__("表 %{1} 列 %{2} 数据恢复完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__("恢复列数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 获取备份数据
     * 
     * @param int $migrationId 迁移ID
     * @param string $tableName 表名
     * @param string $backupType 备份类型
     * @return MigrationBackup|null
     */
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
    
    /**
     * 清理备份数据
     * 
     * @param int $migrationId 迁移ID
     * @return bool
     */
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
    
    /**
     * 按 backup_id 恢复单条备份
     * 
     * @param int $backupId 备份记录主键
     * @return bool
     */
    public function restoreByBackupId(int $backupId): bool
    {
        try {
            $backup = clone $this->backupModel;
            $backup->load($backupId);
            if (!$backup->getId()) {
                throw new \Exception(__("备份记录不存在: %{1}", $backupId));
            }

            $tableName  = $backup->getData(MigrationBackup::schema_fields_TABLE_NAME);
            $backupType = $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            $migrationId = (int) $backup->getData(MigrationBackup::schema_fields_MIGRATION_ID);

            if ($backupType === MigrationBackup::TYPE_TABLE) {
                return $this->restoreTableData($tableName, $migrationId);
            }

            if ($backupType === MigrationBackup::TYPE_COLUMN) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (!empty($data) && is_array($data)) {
                    $firstRow = reset($data);
                    $columns  = array_keys($firstRow);
                    $columns  = array_filter($columns, fn(string $c) => strtolower($c) !== 'id');
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

    /**
     * 获取指定迁移的所有备份记录
     * 
     * @param int $migrationId 迁移ID
     * @return array MigrationBackup[]
     */
    public function getBackupsByMigrationId(int $migrationId): array
    {
        return $this->backupModel->getMigrationBackups($migrationId);
    }

    /**
     * 获取备份统计信息
     * 
     * @param int $migrationId 迁移ID
     * @return array
     */
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
    
    /**
     * 备份表结构（DDL）
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @return bool
     */
    public function backupTableStructure(string $tableName, int $migrationId): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取表结构 DDL
            $result = $connection->query("SHOW CREATE TABLE `{$tableName}`");
            $row = $result->fetch(\PDO::FETCH_ASSOC);
            
            if (empty($row)) {
                $this->printing->warning(__("无法获取表 %{1} 的结构", $tableName));
                return false;
            }
            
            $ddl = $row['Create Table'] ?? $row[1] ?? '';
            
            if (empty($ddl)) {
                $this->printing->warning(__("表 %{1} 的 DDL 为空", $tableName));
                return false;
            }
            
            // 保存备份记录
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
    
    /**
     * 恢复表结构
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @param bool $dropIfExists 如果表存在是否先删除
     * @return bool
     */
    public function restoreTableStructure(string $tableName, int $migrationId, bool $dropIfExists = false): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取结构备份
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
            
            // 如果需要先删除现有表
            if ($dropIfExists) {
                $connection->query("DROP TABLE IF EXISTS `{$tableName}`");
            }
            
            // 执行 DDL 创建表
            $connection->query($ddl);
            
            $this->printing->info(__("表 %{1} 结构恢复完成", $tableName));
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表结构失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 分批备份大表数据
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @param int $chunkSize 每批数量
     * @return array 备份统计信息
     */
    public function backupTableDataChunked(string $tableName, int $migrationId, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            $offset = 0;
            $totalRows = 0;
            $chunkIndex = 0;
            
            while (true) {
                // 分批查询数据
                $query = $connection->select()
                    ->from($tableName)
                    ->limit($chunkSize)
                    ->offset($offset);
                $chunk = $connection->fetchAll($query);
                
                if (empty($chunk)) {
                    break;
                }
                
                // 保存分块备份
                $this->saveBackupChunk($tableName, $chunk, $migrationId, $chunkIndex);
                
                $totalRows += count($chunk);
                $offset += $chunkSize;
                $chunkIndex++;
                
                // 防止内存溢出
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
     * 保存分块备份数据
     * 
     * @param string $tableName 表名
     * @param array $chunk 分块数据
     * @param int $migrationId 迁移ID
     * @param int $chunkIndex 分块索引
     * @return void
     */
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
    
    /**
     * 恢复分块备份的表数据
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @param bool $clearBeforeRestore 恢复前是否清空表
     * @return bool
     */
    public function restoreTableDataChunked(string $tableName, int $migrationId, bool $clearBeforeRestore = true): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取所有分块备份
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
            
            // 恢复前清空表数据
            if ($clearBeforeRestore) {
                $connection->query("DELETE FROM `{$tableName}`");
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }
            
            $totalRows = 0;
            foreach ($backups as $backup) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (empty($data)) {
                    continue;
                }
                
                foreach ($data as $row) {
                    $connection->insert($tableName, $row);
                }
                
                $totalRows += count($data);
                
                // 防止内存溢出
                unset($data);
            }
            
            $this->printing->info(__("表 %{1} 分块数据恢复完成，共 %{2} 条记录", [$tableName, $totalRows]));
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error(__("恢复分块表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * 智能备份：根据表大小自动选择备份策略
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @param bool $includeStructure 是否包含结构备份
     * @return array 备份信息
     */
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
            $connection = $this->connectionFactory->getConnection();
            
            // 备份结构
            if ($includeStructure) {
                $result['structure_backed_up'] = $this->backupTableStructure($tableName, $migrationId);
            }
            
            // 获取表行数
            $countResult = $connection->query("SELECT COUNT(*) as cnt FROM `{$tableName}`")->fetch(\PDO::FETCH_ASSOC);
            $rowCount = (int)($countResult['cnt'] ?? 0);
            $result['total_rows'] = $rowCount;
            
            if ($rowCount === 0) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                $result['strategy'] = 'empty';
                return $result;
            }
            
            // 根据行数选择策略
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
