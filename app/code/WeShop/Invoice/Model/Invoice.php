<?php

declare(strict_types=1);

namespace WeShop\Invoice\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 发票模型
 */
class Invoice extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_invoice';
    public const primary_key = 'invoice_id';
    
    public const fields_ID = 'invoice_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_INVOICE_NUMBER = 'invoice_number';
    public const fields_AMOUNT = 'amount';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['invoice_id'];
    public array $_index_sort_keys = ['order_id', 'invoice_number'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop发票表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '发票ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '订单ID')
                ->addColumn(self::fields_INVOICE_NUMBER, TableInterface::column_type_VARCHAR, 50, 'not null unique', '发票号')
                ->addColumn(self::fields_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '金额')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID, '订单ID索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_invoice_number', self::fields_INVOICE_NUMBER, '发票号唯一索引')
                ->create();
        }
    }
}
