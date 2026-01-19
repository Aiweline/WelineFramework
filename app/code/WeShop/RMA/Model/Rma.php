<?php

declare(strict_types=1);

namespace WeShop\RMA\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 退货模型
 */
class Rma extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_rma';
    public const primary_key = 'rma_id';
    
    public const fields_ID = 'rma_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_REASON = 'reason';
    public const fields_DESCRIPTION = 'description';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['rma_id'];
    public array $_index_sort_keys = ['order_id', 'customer_id', 'status'];
    
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
            $setup->createTable('WeShop退货表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '退货ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '订单ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_REASON, TableInterface::column_type_VARCHAR, 255, 'not null', '退货原因')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, '', '描述')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID, '订单ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->create();
        }
    }
}
