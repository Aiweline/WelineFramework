<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Zone extends AbstractModel
{
    public const table = 'w_shipping_zones';
    
    public const fields_ID = 'zone_id';
    public const fields_ZONE_NAME = 'zone_name';
    public const fields_ZONE_CODE = 'zone_code';
    public const fields_DESCRIPTION = 'description';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['zone_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['zone_id', 'zone_code'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('配送区域表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '配送区域ID'
                )
                ->addColumn(
                    self::fields_ZONE_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '区域名称'
                )
                ->addColumn(
                    self::fields_ZONE_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '区域代码'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '描述'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_zone_code', self::fields_ZONE_CODE, '区域代码唯一索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

