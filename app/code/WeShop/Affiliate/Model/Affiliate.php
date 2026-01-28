<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 分销联盟模型
 */
class Affiliate extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_affiliate';
    public const primary_key = 'affiliate_id';
    public string $indexer = 'affiliate_indexer';
    
    public const fields_ID = 'affiliate_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_REFERRAL_CODE = 'referral_code';
    public const fields_COMMISSION_RATE = 'commission_rate';
    public const fields_TOTAL_COMMISSION = 'total_commission';
    public const fields_PAID_COMMISSION = 'paid_commission';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['affiliate_id'];
    public array $_index_sort_keys = ['customer_id', 'referral_code'];
    
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
            $setup->createTable('WeShop分销联盟表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '分销ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null unique', '客户ID')
                ->addColumn(self::fields_REFERRAL_CODE, TableInterface::column_type_VARCHAR, 50, 'not null unique', '推荐码')
                ->addColumn(self::fields_COMMISSION_RATE, TableInterface::column_type_DECIMAL, '5,2', 'not null default 0.00', '佣金比例')
                ->addColumn(self::fields_TOTAL_COMMISSION, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '总佣金')
                ->addColumn(self::fields_PAID_COMMISSION, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '已支付佣金')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID唯一索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_referral_code', self::fields_REFERRAL_CODE, '推荐码唯一索引')
                ->create();
        }
    }
}
