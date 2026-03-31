<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B credit line')]
#[Index(name: 'idx_weshop_b2b_credit_customer', columns: ['customer_id'], comment: 'Customer')]
class Credit extends Model
{
    public const schema_table = 'weshop_b2b_credit';
    public const schema_primary_key = 'credit_id';
    public string $indexer = 'b2b_credit_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Credit ID')]
    public const schema_fields_ID = 'credit_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Credit limit')]
    public const schema_fields_CREDIT_LIMIT = 'credit_limit';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Used credit')]
    public const schema_fields_USED_CREDIT = 'used_credit';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Available credit')]
    public const schema_fields_AVAILABLE_CREDIT = 'available_credit';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Credit level')]
    public const schema_fields_CREDIT_LEVEL = 'credit_level';
    #[Col(type: 'datetime', nullable: true, comment: 'Valid from')]
    public const schema_fields_VALID_FROM = 'valid_from';
    #[Col(type: 'datetime', nullable: true, comment: 'Valid to')]
    public const schema_fields_VALID_TO = 'valid_to';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Status 1 active 0 frozen')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['credit_id'];
    public array $_index_sort_keys = ['customer_id', 'status', 'credit_limit'];
}
