<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Coupon;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 优惠券模型
 * 
 * @package Weline_Marketing
 */
class Coupon extends Model
{
    // 框架自动推导表名：Coupon → weline_marketing_coupon
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'code', 'rule_id', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_RULE_ID = 'rule_id';
    public const fields_CODE = 'code';
    public const fields_TYPE = 'type';
    public const fields_DISCOUNT_VALUE = 'discount_value';
    public const fields_MIN_AMOUNT = 'min_amount';
    public const fields_MAX_DISCOUNT = 'max_discount';
    public const fields_USAGE_LIMIT = 'usage_limit';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_CUSTOMER_LIMIT = 'customer_limit';
    public const fields_STATUS = 'status';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Type constants
     */
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_FREE_SHIPPING = 'free_shipping';
    public const TYPE_GIFT = 'gift';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXHAUSTED = 'exhausted';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->_table = 'weline_marketing_coupon';
        $this->_id_field_name = 'id';
    }

    /**
     * Install database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->useMainDbMaster();
        
        if ($setup->tableExist() === false) {
            $setup->createTable('优惠券表')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '优惠券ID'
            )
            ->addColumn(
                self::fields_RULE_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '关联规则ID'
            )
            ->addColumn(
                self::fields_CODE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '优惠券代码'
            )
            ->addColumn(
                self::fields_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '优惠类型：percentage百分比, fixed_amount固定金额, free_shipping免运费, gift赠品'
            )
            ->addColumn(
                self::fields_DISCOUNT_VALUE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,4',
                'null',
                '折扣值（百分比或固定金额）'
            )
            ->addColumn(
                self::fields_MIN_AMOUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,4',
                'null',
                '最小订单金额'
            )
            ->addColumn(
                self::fields_MAX_DISCOUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,4',
                'null',
                '最大折扣金额（仅百分比类型）'
            )
            ->addColumn(
                self::fields_USAGE_LIMIT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '总使用次数'
            )
            ->addColumn(
                self::fields_USAGE_COUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '已使用次数'
            )
            ->addColumn(
                self::fields_CUSTOMER_LIMIT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'default 1',
                '每个客户使用次数'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'active\'',
                '状态：active激活, inactive未激活, expired已过期, exhausted已用完'
            )
            ->addColumn(
                self::fields_START_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'null',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'null',
                '结束时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp on update current_timestamp',
                '更新时间'
            )
            ->addIndex('UNIQUE', 'idx_code', [self::fields_CODE])
            ->addIndex('INDEX', 'idx_rule', [self::fields_RULE_ID])
            ->addIndex('INDEX', 'idx_status', [self::fields_STATUS, self::fields_START_DATE, self::fields_END_DATE])
            ->create();
        }
    }

    /**
     * Setup database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * Upgrade database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // Future upgrades will be added here
    }

    /**
     * Check if coupon is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        if ($this->getData(self::fields_STATUS) !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $startDate = $this->getData(self::fields_START_DATE);
        $endDate = $this->getData(self::fields_END_DATE);

        if ($startDate && $now < $startDate) {
            return false;
        }

        if ($endDate && $now > $endDate) {
            return false;
        }

        $usageLimit = $this->getData(self::fields_USAGE_LIMIT);
        $usageCount = $this->getData(self::fields_USAGE_COUNT);

        if ($usageLimit && $usageCount >= $usageLimit) {
            return false;
        }

        return true;
    }
}
