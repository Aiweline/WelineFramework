<?php

declare(strict_types=1);

namespace WeShop\Compare\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 商品对比模型
 */
class Compare extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_compare';
    public const primary_key = 'compare_id';
    
    public const fields_ID = 'compare_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['compare_id'];
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
            $setup->createTable('WeShop商品对比表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '对比ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_customer_product', [self::fields_CUSTOMER_ID, self::fields_PRODUCT_ID], '客户产品唯一索引')
                ->create();
        }
    }
}
