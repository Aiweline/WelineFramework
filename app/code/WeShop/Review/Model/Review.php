<?php

declare(strict_types=1);

namespace WeShop\Review\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 商品评价模型
 */
class Review extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_review';
    public const primary_key = 'review_id';
    public string $indexer = 'review_indexer';
    
    public const fields_ID = 'review_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_RATING = 'rating';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    
    public array $_unit_primary_keys = ['review_id'];
    public array $_index_sort_keys = ['product_id', 'customer_id', 'status'];
    
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
            $setup->createTable('WeShop商品评价表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '评价ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_RATING, TableInterface::column_type_SMALLINT, 1, 'not null default 5', '评分（1-5）')
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 255, '', '评价标题')
                ->addColumn(self::fields_CONTENT, TableInterface::column_type_TEXT, 0, '', '评价内容')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态（pending/approved/rejected）')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->create();
        }
    }
}
