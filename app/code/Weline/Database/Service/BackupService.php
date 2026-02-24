<?php
/**
 * 数据库备份服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

namespace Weline\Database\Service;

use Weline\Database\Model\MigrationBackup;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

class BackupService
{
    private ConnectionFactory $connectionFactory;
    private MigrationBackup $backupModel;
    private Printing $printing;
    
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
                $this->printing->info("表 {$tableName} 没有数据需要备份");
                return [];
            }
            
            // 保存备份记录
            $this->backupModel->setData([
                MigrationBackup::fields_MIGRATION_ID => $migrationId,
                MigrationBackup::fields_TABLE_NAME => $tableName,
                MigrationBackup::fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::fields_BACKUP_TYPE => 'table',
                MigrationBackup::fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->backupModel->save();
            
            $this->printing->info("表 {$tableName} 数据备份完成，共 " . count($data) . " 条记录");
            
            return $data;
            
        } catch (\Exception $e) {
            $this->printing->error("备份表数据失败: " . $e->getMessage());
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
                $this->printing->info("表 {$tableName} 的列 {$columnName} 没有数据需要备份");
                return [];
            }
            
            // 保存备份记录
            $this->backupModel->setData([
                MigrationBackup::fields_MIGRATION_ID => $migrationId,
                MigrationBackup::fields_TABLE_NAME => $tableName,
                MigrationBackup::fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::fields_BACKUP_TYPE => 'column',
                MigrationBackup::fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->backupModel->save();
            
            $this->printing->info("表 {$tableName} 的列 {$columnName} 数据备份完成，共 " . count($data) . " 条记录");
            
            return $data;
            
        } catch (\Exception $e) {
            $this->printing->error("备份列数据失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 恢复表数据
     * 
     * @param string $tableName 表名
     * @param int $migrationId 迁移ID
     * @return bool
     */
    public function restoreTableData(string $tableName, int $migrationId): bool
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            
            // 获取备份数据
            $backup = $this->getBackupData($migrationId, $tableName, 'table');
            if (empty($backup)) {
                $this->printing->warning("没有找到表 {$tableName} 的备份数据");
                return true;
            }
            
            $data = json_decode($backup->getData(MigrationBackup::fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning("表 {$tableName} 的备份数据为空");
                return true;
            }
            
            // 恢复数据
            foreach ($data as $row) {
                $connection->insert($tableName, $row);
            }
            
            $this->printing->info("表 {$tableName} 数据恢复完成，共 " . count($data) . " 条记录");
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error("恢复表数据失败: " . $e->getMessage());
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
                $this->printing->warning("没有找到表 {$tableName} 列 {$columnName} 的备份数据");
                return true;
            }
            
            $data = json_decode($backup->getData(MigrationBackup::fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning("表 {$tableName} 列 {$columnName} 的备份数据为空");
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
            
            $this->printing->info("表 {$tableName} 列 {$columnName} 数据恢复完成，共 " . count($data) . " 条记录");
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error("恢复列数据失败: " . $e->getMessage());
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
        $collection = $this->backupModel->getCollection();
        $collection->addFieldToFilter(MigrationBackup::fields_MIGRATION_ID, $migrationId);
        $collection->addFieldToFilter(MigrationBackup::fields_TABLE_NAME, $tableName);
        $collection->addFieldToFilter(MigrationBackup::fields_BACKUP_TYPE, $backupType);
        
        return $collection->getFirstItem();
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
            $collection = $this->backupModel->getCollection();
            $collection->addFieldToFilter(MigrationBackup::fields_MIGRATION_ID, $migrationId);
            
            $backups = $collection->getItems();
            foreach ($backups as $backup) {
                $backup->delete();
            }
            
            $this->printing->info("迁移 {$migrationId} 的备份数据清理完成");
            
            return true;
            
        } catch (\Exception $e) {
            $this->printing->error("清理备份数据失败: " . $e->getMessage());
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

            $tableName  = $backup->getData(MigrationBackup::fields_TABLE_NAME);
            $backupType = $backup->getData(MigrationBackup::fields_BACKUP_TYPE);
            $migrationId = (int) $backup->getData(MigrationBackup::fields_MIGRATION_ID);

            if ($backupType === MigrationBackup::TYPE_TABLE) {
                return $this->restoreTableData($tableName, $migrationId);
            }

            if ($backupType === MigrationBackup::TYPE_COLUMN) {
                $data = json_decode($backup->getData(MigrationBackup::fields_BACKUP_DATA), true);
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
        $collection = $this->backupModel->getCollection();
        $collection->addFieldToFilter(MigrationBackup::fields_MIGRATION_ID, $migrationId);
        
        $backups = $collection->getItems();
        $stats = [
            'total' => count($backups),
            'tables' => 0,
            'columns' => 0,
            'total_records' => 0
        ];
        
        foreach ($backups as $backup) {
            $backupType = $backup->getData(MigrationBackup::fields_BACKUP_TYPE);
            if ($backupType === 'table') {
                $stats['tables']++;
            } elseif ($backupType === 'column') {
                $stats['columns']++;
            }
            
            $data = json_decode($backup->getData(MigrationBackup::fields_BACKUP_DATA), true);
            if (is_array($data)) {
                $stats['total_records'] += count($data);
            }
        }
        
        return $stats;
    }
}
