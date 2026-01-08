<?php

declare(strict_types=1);

/*
 * 备份记录模型
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Model
 */

namespace Weline\Maintenance\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Backup extends Model
{
    public const table = 'weline_maintenance_backup';
    
    public const fields_backup_id = 'backup_id';
    public const fields_backup_type = 'backup_type';
    public const fields_backup_name = 'backup_name';
    public const fields_file_path = 'file_path';
    public const fields_file_size = 'file_size';
    public const fields_status = 'status';
    public const fields_created_at = 'created_at';
    public const fields_created_by = 'created_by';

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
        // TODO: 实现升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('维护模式备份记录表')
            ->addColumn(self::fields_backup_id, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '备份ID')
            ->addColumn(self::fields_backup_type, TableInterface::column_type_VARCHAR, 20, 'not null', '备份类型: full/database/code')
            ->addColumn(self::fields_backup_name, TableInterface::column_type_VARCHAR, 255, 'not null', '备份文件名')
            ->addColumn(self::fields_file_path, TableInterface::column_type_VARCHAR, 500, 'not null', '备份文件路径')
            ->addColumn(self::fields_file_size, TableInterface::column_type_BIGINT, 0, 'default 0', '文件大小(字节)')
            ->addColumn(self::fields_status, TableInterface::column_type_VARCHAR, 20, 'default \'completed\'', '状态: pending/completed/failed')
            ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'not null', '创建时间')
            ->addColumn(self::fields_created_by, TableInterface::column_type_INTEGER, 0, 'default 0', '创建人ID')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', self::fields_backup_type, '备份类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_created', self::fields_created_at, '创建时间索引')
            ->create();
    }

    /**
     * 获取备份类型
     * 
     * @return string
     */
    public function getBackupType(): string
    {
        return $this->getData(self::fields_backup_type) ?? '';
    }

    /**
     * 设置备份类型
     * 
     * @param string $type
     * @return $this
     */
    public function setBackupType(string $type): static
    {
        return $this->setData(self::fields_backup_type, $type);
    }

    /**
     * 获取备份名称
     * 
     * @return string
     */
    public function getBackupName(): string
    {
        return $this->getData(self::fields_backup_name) ?? '';
    }

    /**
     * 设置备份名称
     * 
     * @param string $name
     * @return $this
     */
    public function setBackupName(string $name): static
    {
        return $this->setData(self::fields_backup_name, $name);
    }

    /**
     * 获取文件路径
     * 
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->getData(self::fields_file_path) ?? '';
    }

    /**
     * 设置文件路径
     * 
     * @param string $path
     * @return $this
     */
    public function setFilePath(string $path): static
    {
        return $this->setData(self::fields_file_path, $path);
    }

    /**
     * 获取文件大小
     * 
     * @return int
     */
    public function getFileSize(): int
    {
        return (int)($this->getData(self::fields_file_size) ?? 0);
    }

    /**
     * 设置文件大小
     * 
     * @param int $size
     * @return $this
     */
    public function setFileSize(int $size): static
    {
        return $this->setData(self::fields_file_size, $size);
    }

    /**
     * 获取状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData(self::fields_status) ?? 'pending';
    }

    /**
     * 设置状态
     * 
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): static
    {
        return $this->setData(self::fields_status, $status);
    }
}
