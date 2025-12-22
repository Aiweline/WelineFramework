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

class FreeShippingRule extends AbstractModel
{
    public const table = 'w_shipping_free_shipping_rules';
    
    public const fields_ID = 'rule_id';
    public const fields_RULE_NAME = 'rule_name';
    public const fields_RULE_CODE = 'rule_code';
    public const fields_CONDITION_TYPE = 'condition_type';
    public const fields_MIN_ORDER_AMOUNT = 'min_order_amount';
    public const fields_MEMBER_LEVEL_IDS = 'member_level_ids';
    public const fields_REGION_IDS = 'region_ids';
    public const fields_COUPON_CODES = 'coupon_codes';
    public const fields_MIXED_CONFIG = 'mixed_config';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_PRIORITY = 'priority';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 条件类型常量
    public const CONDITION_ORDER_AMOUNT = 'order_amount';
    public const CONDITION_MEMBER_LEVEL = 'member_level';
    public const CONDITION_REGION = 'region';
    public const CONDITION_COUPON = 'coupon';
    public const CONDITION_MIXED = 'mixed';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['rule_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['rule_id', 'rule_code', 'priority'];

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
            $setup->createTable('免邮规则表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '规则ID'
                )
                ->addColumn(
                    self::fields_RULE_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '规则名称'
                )
                ->addColumn(
                    self::fields_RULE_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '规则代码'
                )
                ->addColumn(
                    self::fields_CONDITION_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '条件类型：order_amount/member_level/region/coupon/mixed'
                )
                ->addColumn(
                    self::fields_MIN_ORDER_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default null',
                    '最小订单金额'
                )
                ->addColumn(
                    self::fields_MEMBER_LEVEL_IDS,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '会员等级ID列表（JSON数组）'
                )
                ->addColumn(
                    self::fields_REGION_IDS,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '适用地区ID列表（JSON数组）'
                )
                ->addColumn(
                    self::fields_COUPON_CODES,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '优惠券代码列表（JSON数组）'
                )
                ->addColumn(
                    self::fields_MIXED_CONFIG,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '混合条件配置（JSON格式）'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '优先级（数字越大优先级越高）'
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
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_rule_code', self::fields_RULE_CODE, '规则代码唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_condition_type', self::fields_CONDITION_TYPE, '条件类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_priority', self::fields_PRIORITY, '优先级索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 获取会员等级ID列表
     * 
     * @return array
     */
    public function getMemberLevelIds(): array
    {
        $ids = $this->getData(self::fields_MEMBER_LEVEL_IDS);
        if (empty($ids)) {
            return [];
        }
        return json_decode($ids, true) ?: [];
    }

    /**
     * 设置会员等级ID列表
     * 
     * @param array $ids
     * @return $this
     */
    public function setMemberLevelIds(array $ids): self
    {
        $this->setData(self::fields_MEMBER_LEVEL_IDS, json_encode($ids, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取地区ID列表
     * 
     * @return array
     */
    public function getRegionIds(): array
    {
        $ids = $this->getData(self::fields_REGION_IDS);
        if (empty($ids)) {
            return [];
        }
        return json_decode($ids, true) ?: [];
    }

    /**
     * 设置地区ID列表
     * 
     * @param array $ids
     * @return $this
     */
    public function setRegionIds(array $ids): self
    {
        $this->setData(self::fields_REGION_IDS, json_encode($ids, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取优惠券代码列表
     * 
     * @return array
     */
    public function getCouponCodes(): array
    {
        $codes = $this->getData(self::fields_COUPON_CODES);
        if (empty($codes)) {
            return [];
        }
        return json_decode($codes, true) ?: [];
    }

    /**
     * 设置优惠券代码列表
     * 
     * @param array $codes
     * @return $this
     */
    public function setCouponCodes(array $codes): self
    {
        $this->setData(self::fields_COUPON_CODES, json_encode($codes, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取混合条件配置
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
     * 设置混合条件配置
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

