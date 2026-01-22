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
 * 字段备份冲突记录模型
 *
 * 用于记录字段恢复时与现有数据发生冲突的情况，
 * 便于审计和后续人工处理，保证数据不被悄悄丢弃。
 */
class FieldBackupConflict extends Model
{
    public const table = 'weline_framework_field_backup_conflict';

    public function _construct()
    {
        $this->init(self::table, self::fields_ID);
    }

    // 字段定义
    public const fields_ID = 'conflict_id';
    public const fields_MODULE = 'module';
    public const fields_TABLE_NAME = 'table_name';
    public const fields_FIELD_NAME = 'field_name';
    public const fields_PRIMARY_KEY = 'primary_key';
    public const fields_PRIMARY_VALUE = 'primary_value';
    public const fields_BACKUP_VALUE = 'backup_value';
    public const fields_CURRENT_VALUE = 'current_value';
    public const fields_VERSION = 'version';
    public const fields_CONFLICT_TIME = 'conflict_time';
    public const fields_NOTE = 'note';

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('框架字段备份冲突记录表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '冲突ID'
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
                self::fields_BACKUP_VALUE,
                TableInterface::column_type_TEXT,
                0,
                '',
                '备份中的字段值（JSON格式）'
            )
            ->addColumn(
                self::fields_CURRENT_VALUE,
                TableInterface::column_type_TEXT,
                0,
                '',
                '当前表中的字段值（JSON格式）'
            )
            ->addColumn(
                self::fields_VERSION,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '尝试恢复的模块版本号'
            )
            ->addColumn(
                self::fields_CONFLICT_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '产生冲突的时间'
            )
            ->addColumn(
                self::fields_NOTE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '备注（例如：primary 已存在，跳过覆盖）'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_conflict_module_table_field',
                [self::fields_MODULE, self::fields_TABLE_NAME, self::fields_FIELD_NAME],
                '模块表字段冲突索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_conflict_primary',
                [self::fields_TABLE_NAME, self::fields_PRIMARY_KEY, self::fields_PRIMARY_VALUE],
                '冲突主键索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_conflict_version',
                [self::fields_VERSION],
                '冲突版本索引'
            )
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无升级逻辑
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

