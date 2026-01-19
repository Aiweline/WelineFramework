<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 公司模型
 */
class Company extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_b2b_company';
    public const primary_key = 'company_id';
    
    public const fields_ID = 'company_id';
    public const fields_NAME = 'name';
    public const fields_TAX_ID = 'tax_id';
    public const fields_ADDRESS = 'address';
    public const fields_PHONE = 'phone';
    public const fields_EMAIL = 'email';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['company_id'];
    public array $_index_sort_keys = ['name', 'status'];
    
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
            $setup->createTable('WeShop B2B公司表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '公司ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '公司名称')
                ->addColumn(self::fields_TAX_ID, TableInterface::column_type_VARCHAR, 50, '', '税号')
                ->addColumn(self::fields_ADDRESS, TableInterface::column_type_VARCHAR, 255, '', '地址')
                ->addColumn(self::fields_PHONE, TableInterface::column_type_VARCHAR, 20, '', '电话')
                ->addColumn(self::fields_EMAIL, TableInterface::column_type_VARCHAR, 100, '', '邮箱')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_NAME, '公司名称索引')
                ->create();
        }
    }
}
