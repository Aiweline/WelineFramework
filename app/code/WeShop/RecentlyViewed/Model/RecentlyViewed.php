<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 最近浏览模型
 */
class RecentlyViewed extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_recently_viewed';
    public const primary_key = 'view_id';
    
    public const fields_ID = 'view_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_VIEWED_AT = 'viewed_at';
    
    public array $_unit_primary_keys = ['view_id'];
    public array $_index_sort_keys = ['customer_id', 'viewed_at'];
    
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
            $setup->createTable('WeShop最近浏览表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '浏览ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_VIEWED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '浏览时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_viewed_at', self::fields_VIEWED_AT, '浏览时间索引')
                ->create();
        }
    }
}
