<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Model\Module;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 模块卸载数据库备份记录表
 *
 * 记录每次模块卸载时的数据库表备份信息，支持后续按批次恢复。
 */
class Backup extends Model
{
    public const fields_ID               = 'backup_id';
    public const fields_MODULE_NAME      = 'module_name';
    public const fields_BACKUP_TIMESTAMP = 'backup_timestamp';
    public const fields_BACKUP_DATE      = 'backup_date';
    public const fields_TABLE_COUNT      = 'table_count';
    public const fields_TABLES           = 'tables';
    public const fields_STATUS           = 'status';
    public const fields_CREATED_AT       = 'created_at';
    public const fields_RESTORED_AT      = 'restored_at';

    public const status_active   = 'active';
    public const status_restored = 'restored';
    public const status_deleted  = 'deleted';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 预留将来结构升级使用，目前无升级逻辑
    }

    /**
     * 安装备份记录表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('模块卸载数据库备份记录表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '备份ID'
            )
            ->addColumn(
                self::fields_MODULE_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '模块名称'
            )
            ->addColumn(
                self::fields_BACKUP_TIMESTAMP,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '备份时间戳（YYYYMMDD_HHMMSS）'
            )
            ->addColumn(
                self::fields_BACKUP_DATE,
                TableInterface::column_type_DATETIME,
                null,
                'not null',
                '备份时间'
            )
            ->addColumn(
                self::fields_TABLE_COUNT,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '备份的表数量'
            )
            ->addColumn(
                self::fields_TABLES,
                TableInterface::column_type_JSON,
                null,
                '',
                '备份表信息(JSON)'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "not null default '" . self::status_active . "'",
                '备份状态：active/restored/deleted'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_RESTORED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'null',
                '恢复时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_module_name',
                self::fields_MODULE_NAME,
                '模块名称索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_backup_timestamp',
                self::fields_BACKUP_TIMESTAMP,
                '备份时间戳索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                self::fields_STATUS,
                '状态索引'
            )
            ->create();
    }

    public function setModuleName(string $moduleName): static
    {
        return $this->setData(self::fields_MODULE_NAME, $moduleName);
    }

    public function getModuleName(): string
    {
        return (string)$this->getData(self::fields_MODULE_NAME);
    }

    public function setBackupTimestamp(string $timestamp): static
    {
        return $this->setData(self::fields_BACKUP_TIMESTAMP, $timestamp);
    }

    public function getBackupTimestamp(): string
    {
        return (string)$this->getData(self::fields_BACKUP_TIMESTAMP);
    }

    public function setBackupDate(string $dateTime): static
    {
        return $this->setData(self::fields_BACKUP_DATE, $dateTime);
    }

    public function getBackupDate(): string
    {
        return (string)$this->getData(self::fields_BACKUP_DATE);
    }

    public function setTableCount(int $count): static
    {
        return $this->setData(self::fields_TABLE_COUNT, $count);
    }

    public function getTableCount(): int
    {
        return (int)$this->getData(self::fields_TABLE_COUNT);
    }

    /**
     * @param array<string,mixed> $tables
     */
    public function setTables(array $tables): static
    {
        return $this->setData(self::fields_TABLES, $tables);
    }

    /**
     * @return array<string,mixed>
     */
    public function getTables(): array
    {
        $tables = $this->getData(self::fields_TABLES);
        return is_array($tables) ? $tables : [];
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::fields_STATUS);
    }

    public function setRestoredAt(?string $restoredAt): static
    {
        return $this->setData(self::fields_RESTORED_AT, $restoredAt);
    }

    public function getRestoredAt(): ?string
    {
        $value = $this->getData(self::fields_RESTORED_AT);
        return $value ?: null;
    }
}


