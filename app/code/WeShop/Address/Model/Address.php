<?php

declare(strict_types=1);

namespace WeShop\Address\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 地址模型
 */
class Address extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_address';
    public const primary_key = 'address_id';
    public string $indexer = 'address_indexer';
    
    public const fields_ID = 'address_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_FIRST_NAME = 'first_name';
    public const fields_LAST_NAME = 'last_name';
    public const fields_PHONE = 'phone';
    public const fields_COUNTRY = 'country';
    public const fields_PROVINCE = 'province';
    public const fields_CITY = 'city';
    public const fields_DISTRICT = 'district';
    public const fields_STREET = 'street';
    public const fields_POSTCODE = 'postcode';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['address_id'];
    public array $_index_sort_keys = ['customer_id', 'is_default'];
    
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
            $setup->createTable('WeShop地址表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '地址ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_FIRST_NAME, TableInterface::column_type_VARCHAR, 50, 'not null', '名')
                ->addColumn(self::fields_LAST_NAME, TableInterface::column_type_VARCHAR, 50, 'not null', '姓')
                ->addColumn(self::fields_PHONE, TableInterface::column_type_VARCHAR, 20, 'not null', '电话')
                ->addColumn(self::fields_COUNTRY, TableInterface::column_type_VARCHAR, 50, 'not null', '国家')
                ->addColumn(self::fields_PROVINCE, TableInterface::column_type_VARCHAR, 50, 'not null', '省份')
                ->addColumn(self::fields_CITY, TableInterface::column_type_VARCHAR, 50, 'not null', '城市')
                ->addColumn(self::fields_DISTRICT, TableInterface::column_type_VARCHAR, 50, '', '区县')
                ->addColumn(self::fields_STREET, TableInterface::column_type_VARCHAR, 255, 'not null', '街道地址')
                ->addColumn(self::fields_POSTCODE, TableInterface::column_type_VARCHAR, 20, '', '邮编')
                ->addColumn(self::fields_IS_DEFAULT, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否默认地址')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_default', self::fields_IS_DEFAULT, '默认地址索引')
                ->create();
        }
    }
}
