<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 数据库迁移备份记录模型（Framework 内置）
 * 表由 FrameworkDbBootstrapStage 创建（SchemaDiff 排除），不参与 Model setup/upgrade/install。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: 'Database migration backups')]
class MigrationBackup extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_backups';
    public const schema_primary_key = 'backup_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Backup ID')]
    public const schema_fields_ID = 'backup_id';
    #[Col(type: 'integer', nullable: false, comment: 'Migration ID')]
    public const schema_fields_MIGRATION_ID = 'migration_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Table Name')]
    public const schema_fields_TABLE_NAME = 'table_name';
    #[Col(type: 'text', nullable: true, comment: 'Backup Data')]
    public const schema_fields_BACKUP_DATA = 'backup_data';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Backup Type')]
    public const schema_fields_BACKUP_TYPE = 'backup_type';
    #[Col(type: 'timestamp', nullable: true, comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const TYPE_TABLE = 'table';
    public const TYPE_COLUMN = 'column';
    public const TYPE_STRUCTURE = 'structure';
    public const TYPE_INDEX = 'index';
    public const TYPE_CONSTRAINT = 'constraint';
    public const TYPE_CHUNK = 'chunk';
    public const TYPE_CONFLICT = 'conflict';

    public function getMigrationBackups(int $migrationId): array
    {
        return $this->reset()
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->order(self::schema_fields_CREATED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

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

    public function isBackupExists(int $migrationId, string $tableName, string $backupType): bool
    {
        return $this->reset()
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->where(self::schema_fields_TABLE_NAME, $tableName)
            ->where(self::schema_fields_BACKUP_TYPE, $backupType)
            ->total() > 0;
    }

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
