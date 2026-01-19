<?php

declare(strict_types=1);

namespace WeShop\QA\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 问题模型
 */
class Question extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_question';
    public const primary_key = 'question_id';
    
    public const fields_ID = 'question_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_QUESTION = 'question';
    public const fields_ANSWER = 'answer';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['question_id'];
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
            $setup->createTable('WeShop问题表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '问题ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null', '产品ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_QUESTION, TableInterface::column_type_TEXT, 0, 'not null', '问题')
                ->addColumn(self::fields_ANSWER, TableInterface::column_type_TEXT, 0, '', '答案')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->create();
        }
    }
}
