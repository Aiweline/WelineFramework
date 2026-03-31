<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B company contact')]
#[Index(name: 'idx_weshop_b2b_contact_profile', columns: ['b2b_customer_id'], comment: 'B2B customer')]
class Contact extends Model
{
    public const schema_table = 'weshop_b2b_contact';
    public const schema_primary_key = 'contact_id';
    public string $indexer = 'b2b_contact_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Contact ID')]
    public const schema_fields_ID = 'contact_id';
    #[Col(type: 'int', nullable: false, comment: 'B2B customer profile ID')]
    public const schema_fields_B2B_CUSTOMER_ID = 'b2b_customer_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Name')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Email')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Phone')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Position')]
    public const schema_fields_POSITION = 'position';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Primary contact')]
    public const schema_fields_IS_PRIMARY = 'is_primary';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['contact_id'];
    public array $_index_sort_keys = ['b2b_customer_id', 'email'];
}
