<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 礼品卡模型
 */
class GiftCard extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_gift_card';
    public const primary_key = 'card_id';
    
    public const fields_ID = 'card_id';
    public const fields_CARD_NUMBER = 'card_number';
    public const fields_AMOUNT = 'amount';
    public const fields_BALANCE = 'balance';
    public const fields_STATUS = 'status';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['card_id'];
    public array $_index_sort_keys = ['card_number', 'status'];
    
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
            $setup->createTable('WeShop礼品卡表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '礼品卡ID')
                ->addColumn(self::fields_CARD_NUMBER, TableInterface::column_type_VARCHAR, 50, 'not null unique', '卡号')
                ->addColumn(self::fields_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '面额')
                ->addColumn(self::fields_BALANCE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '余额')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
                ->addColumn(self::fields_EXPIRES_AT, TableInterface::column_type_DATETIME, 0, '', '过期时间')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_card_number', self::fields_CARD_NUMBER, '卡号唯一索引')
                ->create();
        }
    }
}
