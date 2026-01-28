<?php

declare(strict_types=1);

namespace WeShop\Compliance\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Cookie同意模型
 */
class CookieConsent extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_cookie_consent';
    public const primary_key = 'consent_id';
    public string $indexer = 'cookie_consent_indexer';
    
    public const fields_ID = 'consent_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_CONSENT_TYPE = 'consent_type';
    public const fields_IS_ACCEPTED = 'is_accepted';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['consent_id'];
    public array $_index_sort_keys = ['customer_id', 'consent_type'];
    
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
            $setup->createTable('WeShop Cookie同意表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '同意ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_CONSENT_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '同意类型')
                ->addColumn(self::fields_IS_ACCEPTED, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否同意')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->create();
        }
    }
}
