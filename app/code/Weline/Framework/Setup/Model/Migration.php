<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 数据库迁移记录模型（Framework 内置）
 * 表由 FrameworkDbBootstrapStage 创建（SchemaDiff 排除），不参与 Model setup/upgrade/install。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: 'Database migrations')]
class Migration extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_migrations';
    public const schema_primary_key = 'migration_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Migration ID')]
    public const schema_fields_ID = 'migration_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Module Name')]
    public const schema_fields_MODULE = 'module_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Version')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Migration File')]
    public const schema_fields_FILE = 'migration_file';
    #[Col(type: 'text', nullable: true, comment: 'Description')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 50, nullable: false, default: 'pending', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'timestamp', nullable: true, comment: 'Executed At')]
    public const schema_fields_EXECUTED_AT = 'executed_at';
    #[Col(type: 'timestamp', nullable: true, comment: 'Rollback At')]
    public const schema_fields_ROLLBACK_AT = 'rollback_at';
    #[Col(type: 'text', nullable: true, comment: 'Dependencies')]
    public const schema_fields_DEPENDENCIES = 'dependencies';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Checksum')]
    public const schema_fields_CHECKSUM = 'checksum';
    #[Col(type: 'timestamp', nullable: true, comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: 'Updated At')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'longtext', nullable: true, comment: 'Schema diff forward DDL')]
    public const schema_fields_FORWARD_DDL = 'forward_ddl';
    #[Col(type: 'longtext', nullable: true, comment: 'Schema diff rollback DDL')]
    public const schema_fields_ROLLBACK_DDL = 'rollback_ddl';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Schema diff table name')]
    public const schema_fields_SCHEMA_TABLE_NAME = 'schema_table_name';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Connection name for multi-db')]
    public const schema_fields_CONNECTION_NAME = 'connection_name';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL = 'manual';

    public function getModuleMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    public function getInstalledMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    public function isMigrationExists(string $moduleName, string $migrationFile): bool
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->total() > 0;
    }

    public function recordMigration(array $data): int
    {
        $this->clearData();
        $this->setData([
            self::schema_fields_MODULE => $data['module_name'],
            self::schema_fields_VERSION => $data['version'],
            self::schema_fields_FILE => $data['migration_file'],
            self::schema_fields_DESCRIPTION => $data['description'] ?? '',
            self::schema_fields_STATUS => $data['status'],
            self::schema_fields_DEPENDENCIES => json_encode($data['dependencies'] ?? []),
            self::schema_fields_CHECKSUM => $data['checksum'] ?? '',
            self::schema_fields_EXECUTED_AT => $data['executed_at'] ?? date('Y-m-d H:i:s')
        ]);

        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }

    public function updateStatus(string $status): bool
    {
        $this->setData(self::schema_fields_STATUS, $status);

        if ($status === self::STATUS_ROLLED_BACK) {
            $this->setData(self::schema_fields_ROLLBACK_AT, date('Y-m-d H:i:s'));
        }

        return (bool) $this->save();
    }

    /**
     * 记录 Schema Diff 执行的 DDL（用于回滚）。
     * 先以 status=running 插入并返回 migration_id，执行 DDL 后应调用 updateStatus(STATUS_INSTALLED)。
     */
    public function recordSchemaDdl(
        string $moduleName,
        string $tableName,
        string $connectionName,
        string $forwardDdl,
        string $rollbackDdl,
        ?string $modelClass = null,
    ): int {
        $this->clearData();
        $this->setData([
            self::schema_fields_MODULE => $moduleName,
            self::schema_fields_VERSION => date('Y-m-d H:i:s'),
            self::schema_fields_FILE => 'schema_diff',
            self::schema_fields_DESCRIPTION => $modelClass ?? $tableName,
            self::schema_fields_STATUS => self::STATUS_RUNNING,
            self::schema_fields_FORWARD_DDL => $forwardDdl,
            self::schema_fields_ROLLBACK_DDL => $rollbackDdl,
            self::schema_fields_SCHEMA_TABLE_NAME => $tableName,
            self::schema_fields_CONNECTION_NAME => $connectionName,
        ]);
        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }

    public function findMigrationId(string $moduleName, string $migrationFile): int
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $first = $items[0] ?? null;
        return $first && $first->getId() ? (int) $first->getId() : 0;
    }

    /** @return array{total: int, installed: int, failed: int, pending: int} */
    public function getMigrationStats(string $moduleName): array
    {
        $total = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->total();
        $installed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->total();
        $failed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_FAILED)
            ->total();
        return [
            'total' => $total,
            'installed' => $installed,
            'failed' => $failed,
            'pending' => $total - $installed - $failed
        ];
    }
}
