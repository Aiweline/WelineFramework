<?php

declare(strict_types=1);

namespace WeShop\Social\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 社交分享模型
 */
class SocialShare extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_social_share';
    public const primary_key = 'share_id';
    
    public const fields_ID = 'share_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_PLATFORM = 'platform';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['share_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id', 'platform'];
    
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
            $setup->createTable('WeShop社交分享表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '分享ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_PLATFORM, TableInterface::column_type_VARCHAR, 50, 'not null', '平台')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->create();
        }
    }
}
