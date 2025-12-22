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

class RateTemplate extends AbstractModel
{
    public const table = 'w_shipping_rate_templates';
    
    public const fields_ID = 'template_id';
    public const fields_TEMPLATE_NAME = 'template_name';
    public const fields_TEMPLATE_CODE = 'template_code';
    public const fields_CALCULATION_TYPE = 'calculation_type';
    public const fields_BASE_FEE = 'base_fee';
    public const fields_WEIGHT_UNIT = 'weight_unit';
    public const fields_WEIGHT_RATE = 'weight_rate';
    public const fields_VOLUME_UNIT = 'volume_unit';
    public const fields_VOLUME_RATE = 'volume_rate';
    public const fields_QUANTITY_RATE = 'quantity_rate';
    public const fields_MIXED_CONFIG = 'mixed_config';
    public const fields_CURRENCY_CODE = 'currency_code';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 计算类型常量
    public const CALC_TYPE_WEIGHT = 'weight';
    public const CALC_TYPE_VOLUME = 'volume';
    public const CALC_TYPE_QUANTITY = 'quantity';
    public const CALC_TYPE_FIXED = 'fixed';
    public const CALC_TYPE_MIXED = 'mixed';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['template_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['template_id', 'template_code'];

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
            $setup->createTable('配送费用模板表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '模板ID'
                )
                ->addColumn(
                    self::fields_TEMPLATE_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '模板名称'
                )
                ->addColumn(
                    self::fields_TEMPLATE_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '模板代码'
                )
                ->addColumn(
                    self::fields_CALCULATION_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '计算类型：weight/volume/quantity/fixed/mixed'
                )
                ->addColumn(
                    self::fields_BASE_FEE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '基础费用'
                )
                ->addColumn(
                    self::fields_WEIGHT_UNIT,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default \'kg\'',
                    '重量单位：kg/lb'
                )
                ->addColumn(
                    self::fields_WEIGHT_RATE,
                    TableInterface::column_type_DECIMAL,
                    '10,4',
                    'default null',
                    '每单位重量费用'
                )
                ->addColumn(
                    self::fields_VOLUME_UNIT,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default \'m3\'',
                    '体积单位：m3/ft3'
                )
                ->addColumn(
                    self::fields_VOLUME_RATE,
                    TableInterface::column_type_DECIMAL,
                    '10,4',
                    'default null',
                    '每单位体积费用'
                )
                ->addColumn(
                    self::fields_QUANTITY_RATE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default null',
                    '每件费用'
                )
                ->addColumn(
                    self::fields_MIXED_CONFIG,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '混合模式配置（JSON格式）'
                )
                ->addColumn(
                    self::fields_CURRENCY_CODE,
                    TableInterface::column_type_VARCHAR,
                    3,
                    'default \'USD\'',
                    '货币代码（ISO 4217）'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
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
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_template_code', self::fields_TEMPLATE_CODE, '模板代码唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_calculation_type', self::fields_CALCULATION_TYPE, '计算类型索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 获取混合模式配置
     * 
     * @return array
     */
    public function getMixedConfig(): array
    {
        $config = $this->getData(self::fields_MIXED_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }

    /**
     * 设置混合模式配置
     * 
     * @param array $config
     * @return $this
     */
    public function setMixedConfig(array $config): self
    {
        $this->setData(self::fields_MIXED_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}

