<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 公司模型
 */
#[Table(comment: 'WeShop B2B公司表')]
#[Index(name: 'idx_name', columns: ['name'], comment: '公司名称索引')]
class Company extends Model
{
    public const schema_table = 'weshop_b2b_company';
    public const schema_primary_key = 'company_id';
    public string $indexer = 'b2b_company_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '公司ID')]
    public const schema_fields_ID = 'company_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '公司名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '税号')]
    public const schema_fields_TAX_ID = 'tax_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '地址')]
    public const schema_fields_ADDRESS = 'address';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: '电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['company_id'];
    public array $_index_sort_keys = ['name', 'status'];
}
