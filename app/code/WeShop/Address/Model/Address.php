<?php

declare(strict_types=1);

namespace WeShop\Address\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 地址模型
 */
#[Table(comment: 'WeShop地址表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
#[Index(name: 'idx_is_default', columns: ['is_default'], comment: '默认地址索引')]
class Address extends Model
{
    public const schema_table = 'weshop_address';
    public const schema_primary_key = 'address_id';
    public string $indexer = 'address_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '地址ID')]
    public const schema_fields_ID = 'address_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '名')]
    public const schema_fields_FIRST_NAME = 'first_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '姓')]
    public const schema_fields_LAST_NAME = 'last_name';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '国家')]
    public const schema_fields_COUNTRY = 'country';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '省份')]
    public const schema_fields_PROVINCE = 'province';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '城市')]
    public const schema_fields_CITY = 'city';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '区县')]
    public const schema_fields_DISTRICT = 'district';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '街道地址')]
    public const schema_fields_STREET = 'street';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: '邮编')]
    public const schema_fields_POSTCODE = 'postcode';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否默认地址')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['address_id'];
    public array $_index_sort_keys = ['customer_id', 'is_default'];
}
