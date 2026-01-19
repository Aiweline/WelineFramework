<?php

declare(strict_types=1);

namespace WeShop\Membership\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 会员模型
 */
class Membership extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_membership';
    public const primary_key = 'membership_id';
    
    public const fields_ID = 'membership_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_LEVEL = 'level';
    public const fields_POINTS = 'points';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['membership_id'];
    public array $_index_sort_keys = ['customer_id', 'level'];
    
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
            $setup->createTable('WeShop会员表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '会员ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null unique', '客户ID')
                ->addColumn(self::fields_LEVEL, TableInterface::column_type_VARCHAR, 50, "default 'bronze'", '等级')
                ->addColumn(self::fields_POINTS, TableInterface::column_type_INTEGER, 0, 'not null default 0', '积分')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID唯一索引')
                ->create();
        }
    }
}
