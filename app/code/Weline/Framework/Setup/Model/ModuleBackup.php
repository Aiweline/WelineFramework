<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 模块卸载备份记录模型（Framework 内置）
 * 记录模块卸载时的数据库备份，用于恢复。表由 FrameworkDbBootstrapStage 创建。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: '模块卸载数据库备份记录表')]
#[Index(name: 'idx_module_name', columns: ['module_name'])]
#[Index(name: 'idx_backup_timestamp', columns: ['backup_timestamp'])]
#[Index(name: 'idx_status', columns: ['status'])]
class ModuleBackup extends Model
{
    public const schema_table = 'weline_module_backup';
    public const schema_primary_key = 'backup_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: '备份ID')]
    public const schema_fields_ID = 'backup_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '模块名称')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '备份时间戳')]
    public const schema_fields_BACKUP_TIMESTAMP = 'backup_timestamp';
    #[Col(type: 'datetime', nullable: false, comment: '备份时间')]
    public const schema_fields_BACKUP_DATE = 'backup_date';
    #[Col(type: 'integer', nullable: false, default: 0, comment: '备份的表数量')]
    public const schema_fields_TABLE_COUNT = 'table_count';
    #[Col(type: 'json', nullable: true, comment: '备份表信息JSON')]
    public const schema_fields_TABLES = 'tables';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: '备份状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '恢复时间')]
    public const schema_fields_RESTORED_AT = 'restored_at';

    public const status_active = 'active';
    public const status_restored = 'restored';
    public const status_deleted = 'deleted';

    public function setModuleName(string $moduleName): static
    {
        return $this->setData(self::schema_fields_MODULE_NAME, $moduleName);
    }

    public function getModuleName(): string
    {
        return (string) $this->getData(self::schema_fields_MODULE_NAME);
    }

    public function setBackupTimestamp(string $timestamp): static
    {
        return $this->setData(self::schema_fields_BACKUP_TIMESTAMP, $timestamp);
    }

    public function getBackupTimestamp(): string
    {
        return (string) $this->getData(self::schema_fields_BACKUP_TIMESTAMP);
    }

    public function setBackupDate(string $dateTime): static
    {
        return $this->setData(self::schema_fields_BACKUP_DATE, $dateTime);
    }

    public function getBackupDate(): string
    {
        return (string) $this->getData(self::schema_fields_BACKUP_DATE);
    }

    public function setTableCount(int $count): static
    {
        return $this->setData(self::schema_fields_TABLE_COUNT, $count);
    }

    public function getTableCount(): int
    {
        return (int) $this->getData(self::schema_fields_TABLE_COUNT);
    }

    /** @param array<string,mixed> $tables */
    public function setTables(array $tables): static
    {
        return $this->setData(self::schema_fields_TABLES, $tables);
    }

    /** @return array<string,mixed> */
    public function getTables(): array
    {
        $tables = $this->getData(self::schema_fields_TABLES);
        return is_array($tables) ? $tables : [];
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }

    public function setRestoredAt(?string $restoredAt): static
    {
        return $this->setData(self::schema_fields_RESTORED_AT, $restoredAt);
    }

    public function getRestoredAt(): ?string
    {
        $value = $this->getData(self::schema_fields_RESTORED_AT);
        return $value ? (string) $value : null;
    }
}
