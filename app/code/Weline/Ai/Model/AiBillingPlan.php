<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Billing Plan Entity
 * 
 * Defines billing plans and pricing strategies.
 * 
 * @package Weline_Ai
 */
class AiBillingPlan extends Model
{
    // 框架自动推导表名：AiBillingPlan → ai_billing_plan
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'plan_type'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_PLAN_NAME = 'plan_name';
    public const fields_PLAN_TYPE = 'plan_type';
    public const fields_PRICE = 'price';
    public const fields_CURRENCY = 'currency';
    public const fields_BILLING_CYCLE = 'billing_cycle';
    public const fields_FEATURES = 'features';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Plan type constants
     */
    public const PLAN_TYPE_FREE = 'free';
    public const PLAN_TYPE_BASIC = 'basic';
    public const PLAN_TYPE_PRO = 'pro';
    public const PLAN_TYPE_ENTERPRISE = 'enterprise';

    /**
     * Billing cycle constants
     */
    public const BILLING_CYCLE_MONTHLY = 'monthly';
    public const BILLING_CYCLE_YEARLY = 'yearly';

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
            $setup->createTable('AI Billing Plan')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '计划ID'
            )
            ->addColumn(
                self::fields_PLAN_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '计划名称'
            )
            ->addColumn(
                self::fields_PLAN_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '计划类型'
            )
            ->addColumn(
                self::fields_PRICE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,2',
                'not null default 0',
                '价格'
            )
            ->addColumn(
                self::fields_CURRENCY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                10,
                'not null default \'CNY\'',
                '货币单位'
            )
            ->addColumn(
                self::fields_BILLING_CYCLE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '计费周期'
            )
            ->addColumn(
                self::fields_FEATURES,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '功能列表（JSON）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_plan_type', self::fields_PLAN_TYPE)
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
