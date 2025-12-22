<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\RuleUsage;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 规则使用记录模型
 * 
 * @package Weline_Marketing
 */
class RuleUsage extends Model
{
    // 框架自动推导表名：RuleUsage → weline_marketing_rule_usage
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'rule_id', 'customer_id', 'order_id', 'used_at'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_RULE_ID = 'rule_id';
    public const fields_COUPON_ID = 'coupon_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const fields_USED_AT = 'used_at';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->_table = 'weline_marketing_rule_usage';
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
            $setup->createTable('规则使用记录表')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '记录ID'
            )
            ->addColumn(
                self::fields_RULE_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '规则ID'
            )
            ->addColumn(
                self::fields_COUPON_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '优惠券ID（如果使用优惠券）'
            )
            ->addColumn(
                self::fields_CUSTOMER_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '客户ID'
            )
            ->addColumn(
                self::fields_ORDER_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '订单ID'
            )
            ->addColumn(
                self::fields_DISCOUNT_AMOUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,4',
                'default 0',
                '折扣金额'
            )
            ->addColumn(
                self::fields_USED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '使用时间'
            )
            ->addIndex('INDEX', 'idx_rule', [self::fields_RULE_ID])
            ->addIndex('INDEX', 'idx_customer', [self::fields_CUSTOMER_ID])
            ->addIndex('INDEX', 'idx_order', [self::fields_ORDER_ID])
            ->addIndex('INDEX', 'idx_coupon', [self::fields_COUPON_ID])
            ->addIndex('INDEX', 'idx_used_at', [self::fields_USED_AT])
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
}
