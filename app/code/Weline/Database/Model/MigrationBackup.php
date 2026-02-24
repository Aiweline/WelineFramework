<?php
/**
 * 数据库迁移备份记录模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class MigrationBackup extends Model implements ModelInterface
{
    public const fields_ID = 'backup_id';
    public const fields_MIGRATION_ID = 'migration_id';
    public const fields_TABLE_NAME = 'table_name';
    public const fields_BACKUP_DATA = 'backup_data';
    public const fields_BACKUP_TYPE = 'backup_type';
    public const fields_CREATED_AT = 'created_at';
    
    // 备份类型常量
    public const TYPE_TABLE = 'table';
    public const TYPE_COLUMN = 'column';
    public const TYPE_INDEX = 'index';
    public const TYPE_CONSTRAINT = 'constraint';
    
    public function _construct()
    {
        $this->init('weline_database_backups', self::fields_ID);
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Database Migration Backups')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Backup ID')
                ->addColumn(self::fields_MIGRATION_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', 'Migration ID')
                ->addColumn(self::fields_TABLE_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'Table Name')
                ->addColumn(self::fields_BACKUP_DATA, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'default null', 'Backup Data')
                ->addColumn(self::fields_BACKUP_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', 'Backup Type')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
                ->create();
        }
    }

    /**
     * 获取迁移的所有备份记录
     * 
     * @param int $migrationId 迁移ID
     * @return array
     */
    public function getMigrationBackups(int $migrationId): array
    {
        return $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->order(self::fields_CREATED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取表备份记录
     * 
     * @param int $migrationId 迁移ID
     * @param string $tableName 表名
     * @return array
     */
    public function getTableBackups(int $migrationId, string $tableName): array
    {
        return $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->where(self::fields_TABLE_NAME, $tableName)
            ->order(self::fields_CREATED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取列备份记录
     * 
     * @param int $migrationId 迁移ID
     * @param string $tableName 表名
     * @param string $columnName 列名
     * @return array
     */
    public function getColumnBackups(int $migrationId, string $tableName, string $columnName): array
    {
        return $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->where(self::fields_TABLE_NAME, $tableName)
            ->where(self::fields_BACKUP_TYPE, self::TYPE_COLUMN)
            ->order(self::fields_CREATED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 检查备份是否存在
     * 
     * @param int $migrationId 迁移ID
     * @param string $tableName 表名
     * @param string $backupType 备份类型
     * @return bool
     */
    public function isBackupExists(int $migrationId, string $tableName, string $backupType): bool
    {
        return $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->where(self::fields_TABLE_NAME, $tableName)
            ->where(self::fields_BACKUP_TYPE, $backupType)
            ->total() > 0;
    }
    
    /**
     * 获取备份统计信息
     * 
     * @param int $migrationId 迁移ID
     * @return array
     */
    public function getBackupStats(int $migrationId): array
    {
        $backups = $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->select()
            ->fetch()
            ->getItems();
        $stats = [
            'total' => count($backups),
            'tables' => 0,
            'columns' => 0,
            'indexes' => 0,
            'constraints' => 0,
            'total_records' => 0
        ];
        
        foreach ($backups as $backup) {
            $backupType = $backup->getData(self::fields_BACKUP_TYPE);
            
            switch ($backupType) {
                case self::TYPE_TABLE:
                    $stats['tables']++;
                    break;
                case self::TYPE_COLUMN:
                    $stats['columns']++;
                    break;
                case self::TYPE_INDEX:
                    $stats['indexes']++;
                    break;
                case self::TYPE_CONSTRAINT:
                    $stats['constraints']++;
                    break;
            }
            
            $data = json_decode($backup->getData(self::fields_BACKUP_DATA), true);
            if (is_array($data)) {
                $stats['total_records'] += count($data);
            }
        }
        
        return $stats;
    }
    
    /**
     * 清理过期备份
     * 
     * @param int $days 保留天数
     * @return int 清理的记录数
     */
    public function cleanupExpiredBackups(int $days = 30): int
    {
        $expiredDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $backups = $this->reset()
            ->where(self::fields_CREATED_AT, $expiredDate, '<')
            ->select()
            ->fetch()
            ->getItems();
        $count = count($backups);
        foreach ($backups as $backup) {
            $backup->delete();
        }
        return $count;
    }
    
    /**
     * 获取备份数据大小
     * 
     * @param int $migrationId 迁移ID
     * @return int 字节数
     */
    public function getBackupDataSize(int $migrationId): int
    {
        $backups = $this->reset()
            ->where(self::fields_MIGRATION_ID, $migrationId)
            ->select()
            ->fetch()
            ->getItems();
        $totalSize = 0;
        foreach ($backups as $backup) {
            $data = $backup->getData(self::fields_BACKUP_DATA);
            $totalSize += strlen($data);
        }
        
        return $totalSize;
    }
}
