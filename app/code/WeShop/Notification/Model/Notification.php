<?php

declare(strict_types=1);

namespace WeShop\Notification\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 通知模型
 */
class Notification extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_notification';
    public const primary_key = 'notification_id';
    
    public const fields_ID = 'notification_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_TYPE = 'type';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_IS_READ = 'is_read';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['notification_id'];
    public array $_index_sort_keys = ['customer_id', 'is_read', 'created_at'];
    
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
            $setup->createTable('WeShop通知表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '通知ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '类型')
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 255, 'not null', '标题')
                ->addColumn(self::fields_CONTENT, TableInterface::column_type_TEXT, 0, '', '内容')
                ->addColumn(self::fields_IS_READ, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否已读')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_read', self::fields_IS_READ, '已读状态索引')
                ->create();
        }
    }
}
