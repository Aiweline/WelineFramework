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

class ShippingService extends AbstractModel
{
    public const table = 'w_shipping_services';
    
    public const fields_ID = 'service_id';
    public const fields_SERVICE_NAME = 'service_name';
    public const fields_SERVICE_CODE = 'service_code';
    public const fields_CARRIER_ID = 'carrier_id';
    public const fields_ZONE_ID = 'zone_id';
    public const fields_RATE_TEMPLATE_ID = 'rate_template_id';
    public const fields_FREE_SHIPPING_RULE_ID = 'free_shipping_rule_id';
    public const fields_ESTIMATED_DAYS_MIN = 'estimated_days_min';
    public const fields_ESTIMATED_DAYS_MAX = 'estimated_days_max';
    public const fields_IS_FREE_SHIPPING = 'is_free_shipping';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['service_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['service_id', 'service_code', 'carrier_id', 'zone_id'];

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
            $setup->createTable('配送服务表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '服务ID'
                )
                ->addColumn(
                    self::fields_SERVICE_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '服务名称'
                )
                ->addColumn(
                    self::fields_SERVICE_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '服务代码'
                )
                ->addColumn(
                    self::fields_CARRIER_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '快递公司ID'
                )
                ->addColumn(
                    self::fields_ZONE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '配送区域ID'
                )
                ->addColumn(
                    self::fields_RATE_TEMPLATE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '费用模板ID'
                )
                ->addColumn(
                    self::fields_FREE_SHIPPING_RULE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '免邮规则ID'
                )
                ->addColumn(
                    self::fields_ESTIMATED_DAYS_MIN,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '预计配送天数（最小）'
                )
                ->addColumn(
                    self::fields_ESTIMATED_DAYS_MAX,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '预计配送天数（最大）'
                )
                ->addColumn(
                    self::fields_IS_FREE_SHIPPING,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否免邮：1-是，0-否'
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
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_service_code', self::fields_SERVICE_CODE, '服务代码唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_carrier_id', self::fields_CARRIER_ID, '快递公司ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_zone_id', self::fields_ZONE_ID, '配送区域ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_rate_template_id', self::fields_RATE_TEMPLATE_ID, '费用模板ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_free_shipping_rule_id', self::fields_FREE_SHIPPING_RULE_ID, '免邮规则ID索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

