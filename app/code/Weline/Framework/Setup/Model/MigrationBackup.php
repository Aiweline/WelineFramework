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
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Backed up column name')]
    public const schema_fields_COLUMN_NAME = 'column_name';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'upgrade', comment: 'Backup lifecycle scope')]
    public const schema_fields_BACKUP_SCOPE = 'backup_scope';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Owning rollback operation')]
    public const schema_fields_OPERATION_ID = 'operation_id';
    #[Col(type: 'integer', nullable: true, comment: 'Source backup for a conflict record')]
    public const schema_fields_SOURCE_BACKUP_ID = 'source_backup_id';
    #[Col(type: 'timestamp', nullable: true, comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'protected', comment: 'Backup retention state')]
    public const schema_fields_RETENTION_STATE = 'retention_state';
    #[Col(type: 'timestamp', nullable: true, comment: 'Earliest automatic cleanup time')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col(type: 'timestamp', nullable: true, comment: 'Successful restore time')]
    public const schema_fields_RESTORED_AT = 'restored_at';

    public const TYPE_TABLE = 'table';
    public const TYPE_COLUMN = 'column';
    public const TYPE_STRUCTURE = 'structure';
    public const TYPE_INDEX = 'index';
    public const TYPE_CONSTRAINT = 'constraint';
    public const TYPE_CHUNK = 'chunk';
    public const TYPE_CONFLICT = 'conflict';

    public const RETENTION_PROTECTED = 'protected';
    public const RETENTION_EXPIRING = 'expiring';

    public const SCOPE_UPGRADE = 'upgrade';
    public const SCOPE_ROLLBACK = 'rollback';

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

    public function getColumnBackups(
        int $migrationId,
        string $tableName,
        string $columnName,
        ?string $backupScope = null,
    ): array
    {
        $query = $this->reset()
            ->where(self::schema_fields_MIGRATION_ID, $migrationId)
            ->where(self::schema_fields_TABLE_NAME, $tableName)
            ->where(self::schema_fields_COLUMN_NAME, $columnName)
            ->where(self::schema_fields_BACKUP_TYPE, self::TYPE_COLUMN)
            ->order(self::schema_fields_ID, 'DESC');
        if ($backupScope !== null && $backupScope !== '') {
            $query = $query->where(self::schema_fields_BACKUP_SCOPE, $backupScope);
        }
        return $query
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
        // 默认 protected 的回滚/人工恢复备份永不自动删除。
        // days 仅作为旧调用方的容错值；实际清理时间由恢复成功时写入 retain_until。
        $expiredDate = date('Y-m-d H:i:s');
        $backups = $this->reset()
            ->where(self::schema_fields_RETENTION_STATE, self::RETENTION_EXPIRING)
            ->where(self::schema_fields_RETAIN_UNTIL, $expiredDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        $count = count($backups);
        foreach ($backups as $backup) {
            $backup->delete();
        }
        return $count;
    }

    public function markRestored(int $backupId, int $retentionDays = 30): bool
    {
        if ($backupId <= 0) {
            return false;
        }
        $backup = clone $this;
        $backup->load($backupId);
        if (!$backup->getId()) {
            return false;
        }
        $backup->addData([
            self::schema_fields_RETENTION_STATE => self::RETENTION_EXPIRING,
            self::schema_fields_RESTORED_AT => date('Y-m-d H:i:s'),
            self::schema_fields_RETAIN_UNTIL => date('Y-m-d H:i:s', time() + max(1, $retentionDays) * 86400),
        ])->save();

        return (clone $this)->reset()
            ->where(self::schema_fields_ID, $backupId)
            ->where(self::schema_fields_RETENTION_STATE, self::RETENTION_EXPIRING)
            ->total() === 1;
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
