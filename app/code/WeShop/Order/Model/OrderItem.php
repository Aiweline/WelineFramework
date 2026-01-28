<?php

declare(strict_types=1);

namespace WeShop\Order\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 订单项模型
 */
class OrderItem extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_order_item';
    public const primary_key = 'item_id';
    public string $indexer = 'order_item_indexer';
    
    public const fields_ID = 'item_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_PRODUCT_NAME = 'product_name';
    public const fields_PRODUCT_SKU = 'product_sku';
    public const fields_QUANTITY = 'quantity';
    public const fields_PRICE = 'price';
    public const fields_TOTAL = 'total';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['order_id', 'product_id'];
    
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
            $setup->createTable('WeShop订单项表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '订单项ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '订单ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_PRODUCT_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '产品名称')
                ->addColumn(self::fields_PRODUCT_SKU, TableInterface::column_type_VARCHAR, 100, '', '产品SKU')
                ->addColumn(self::fields_QUANTITY, TableInterface::column_type_INTEGER, 0, 'not null default 1', '数量')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '单价')
                ->addColumn(self::fields_TOTAL, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '小计')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID, '订单ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->create();
        }
    }
}
