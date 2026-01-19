<?php

declare(strict_types=1);

namespace WeShop\Cart\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 购物车模型
 */
class Cart extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_cart';
    public const primary_key = 'cart_id';
    
    public const fields_ID = 'cart_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_QUANTITY = 'quantity';
    public const fields_PRICE = 'price';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['cart_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id'];
    
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
            $setup->createTable('WeShop购物车表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '购物车ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_QUANTITY, TableInterface::column_type_INTEGER, 0, 'not null default 1', '数量')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '单价')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_customer_product', [self::fields_CUSTOMER_ID, self::fields_PRODUCT_ID], '客户产品唯一索引')
                ->create();
        }
    }
}
