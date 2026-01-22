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
 * 字段结构定义备份模型
 *
 * 用于备份字段的「定义信息」（DDL 元数据），
 * 包括：类型、长度、是否可空、默认值、注释等，
 * 以 JSON 形式完整保存底层信息_schema / PRAGMA 返回的结构。
 *
 * 所有记录均按「模块 + 表名 + 字段名 + 模块版本」维度管理，
 * 与系统版本无关。
 */
class FieldDefinitionBackup extends Model
{
    public const table = 'weline_framework_field_definition_backup';

    public function _construct()
    {
        $this->init(self::table, self::fields_ID);
    }

    // 字段定义
    public const fields_ID = 'definition_id';
    public const fields_MODULE = 'module';
    public const fields_TABLE_NAME = 'table_name';
    public const fields_FIELD_NAME = 'field_name';
    public const fields_VERSION = 'version';
    public const fields_DEFINITION = 'definition';
    public const fields_BACKUP_TIME = 'backup_time';

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('框架字段结构定义备份表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '定义备份ID'
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
                self::fields_VERSION,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '模块版本号'
            )
            ->addColumn(
                self::fields_DEFINITION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '字段定义信息（JSON，来源于 information_schema/PRAGMA）'
            )
            ->addColumn(
                self::fields_BACKUP_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '备份时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_def_module_table_field',
                [self::fields_MODULE, self::fields_TABLE_NAME, self::fields_FIELD_NAME],
                '模块表字段定义索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_def_version',
                [self::fields_VERSION],
                '定义版本索引'
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

