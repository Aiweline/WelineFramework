<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Db\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 字段备份记录模型
 * 
 * 存储字段删除前的数据备份，用于后续恢复
 */
class FieldBackup extends Model
{
    public const table = 'weline_framework_field_backup';
    
    public function _construct()
    {
        $this->init(self::table, self::fields_ID);
    }
    
    // 字段定义
    public const fields_ID = 'backup_id';
    public const fields_MODULE = 'module';
    public const fields_TABLE_NAME = 'table_name';
    public const fields_FIELD_NAME = 'field_name';
    public const fields_PRIMARY_KEY = 'primary_key';
    public const fields_PRIMARY_VALUE = 'primary_value';
    public const fields_FIELD_VALUE = 'field_value';
    public const fields_VERSION = 'version';
    public const fields_BACKUP_TIME = 'backup_time';
    public const fields_RESTORED = 'restored';
    public const fields_RESTORE_TIME = 'restore_time';
    
    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('框架字段备份表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '备份ID'
            )
            ->addColumn(
                self::fields_MODULE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '模块名称'
            )
            ->addColumn(
                self::fields_TABLE_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '表名'
            )
            ->addColumn(
                self::fields_FIELD_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '字段名'
            )
            ->addColumn(
                self::fields_PRIMARY_KEY,
                TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '主键字段名'
            )
            ->addColumn(
                self::fields_PRIMARY_VALUE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '主键值'
            )
            ->addColumn(
                self::fields_FIELD_VALUE,
                TableInterface::column_type_TEXT,
                0,
                '',
                '字段值（JSON格式）'
            )
            ->addColumn(
                self::fields_VERSION,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '模块版本号'
            )
            ->addColumn(
                self::fields_BACKUP_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '备份时间'
            )
            ->addColumn(
                self::fields_RESTORED,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否已恢复：0未恢复，1已恢复'
            )
            ->addColumn(
                self::fields_RESTORE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '恢复时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_module_table_field', 
                [self::fields_MODULE, self::fields_TABLE_NAME, self::fields_FIELD_NAME], 
                '模块表字段索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_primary', 
                [self::fields_TABLE_NAME, self::fields_PRIMARY_KEY, self::fields_PRIMARY_VALUE], 
                '主键索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_restored', 
                [self::fields_RESTORED], 
                '恢复状态索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_version', 
                [self::fields_VERSION], 
                '版本索引')
            ->create();
    }
    
    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 目前无需升级
    }
    
    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
