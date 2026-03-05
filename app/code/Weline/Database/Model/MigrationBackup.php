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

/**
 * 表由 Weline\Database\Setup\Install 创建。
 */
class MigrationBackup extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_backups';
    public const schema_primary_key = 'backup_id';

    public const schema_fields_ID = 'backup_id';
    public const schema_fields_MIGRATION_ID = 'migration_id';
    public const schema_fields_TABLE_NAME = 'table_name';
    public const schema_fields_BACKUP_DATA = 'backup_data';
    public const schema_fields_BACKUP_TYPE = 'backup_type';
    public const schema_fields_CREATED_AT = 'created_at';

    // 备份类型常量
    public const TYPE_TABLE = 'table';
    public const TYPE_COLUMN = 'column';
    public const TYPE_STRUCTURE = 'structure';
    public const TYPE_INDEX = 'index';
    public const TYPE_CONSTRAINT = 'constraint';
    public const TYPE_CHUNK = 'chunk';

    /**
     * 获取迁移的所有备份记录
     *
     * @param int $migrationId 迁移ID
     * @return array
     */
    public function getMigrationBackups(int $migrationId): array
    {
        return $this->reset()
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->order(self::schema_fields_CREATED_AT, 'ASC')
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
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->where(self::schema_fields_TABLE_NAME, $tableName)
            ->order(self::schema_fields_CREATED_AT, 'ASC')
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
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->where(self::schema_fields_TABLE_NAME, $tableName)
            ->where(self::schema_fields_BACKUP_TYPE, self::TYPE_COLUMN)
            ->order(self::schema_fields_CREATED_AT, 'ASC')
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
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->where(self::schema_fields_TABLE_NAME, $tableName)
            ->where(self::schema_fields_BACKUP_TYPE, $backupType)
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
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
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
            $backupType = $backup->getData(self::schema_fields_BACKUP_TYPE);
            
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
            
            $data = json_decode($backup->getData(self::schema_fields_BACKUP_DATA), true);
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
            ->where(self::schema_fields_CREATED_AT, $expiredDate, '<')
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
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->select()
            ->fetch()
            ->getItems();
        $totalSize = 0;
        foreach ($backups as $backup) {
            $data = $backup->getData(self::schema_fields_BACKUP_DATA);
            $totalSize += strlen($data);
        }
        
        return $totalSize;
    }
}
