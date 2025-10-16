<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Billing Invoice Entity
 * 
 * Records billing invoices.
 * 
 * @package Weline_Ai
 */
class AiBillingInvoice extends Model
{
    // 框架自动推导表名：AiBillingInvoice → ai_billing_invoice
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'tenant_id', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_PLAN_ID = 'plan_id';
    public const fields_INVOICE_NUMBER = 'invoice_number';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_STATUS = 'status';
    public const fields_DUE_DATE = 'due_date';
    public const fields_PAID_AT = 'paid_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';

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
            $setup->createTable('AI Billing Invoice')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '发票ID'
            )
            ->addColumn(
                self::fields_TENANT_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '租户ID'
            )
            ->addColumn(
                self::fields_PLAN_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '计划ID'
            )
            ->addColumn(
                self::fields_INVOICE_NUMBER,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '发票号'
            )
            ->addColumn(
                self::fields_AMOUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,2',
                'not null',
                '金额'
            )
            ->addColumn(
                self::fields_CURRENCY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                10,
                'not null default \'CNY\'',
                '货币单位'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'pending\'',
                '状态'
            )
            ->addColumn(
                self::fields_DUE_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATE,
                0,
                'not null',
                '到期日期'
            )
            ->addColumn(
                self::fields_PAID_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '支付时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('UNIQUE', 'uk_invoice_number', self::fields_INVOICE_NUMBER)
            ->addIndex('INDEX', 'idx_tenant_id', self::fields_TENANT_ID)
            ->addIndex('INDEX', 'idx_plan_id', self::fields_PLAN_ID)
            ->addIndex('INDEX', 'idx_status', self::fields_STATUS)
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
