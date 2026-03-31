<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B enterprise customer profile')]
#[Index(name: 'uk_weshop_b2b_customer_login', columns: ['customer_id'], type: 'UNIQUE', comment: 'One B2B profile per customer')]
#[Index(name: 'idx_weshop_b2b_customer_company', columns: ['company_id'], comment: 'Company link')]
class B2bCustomer extends Model
{
    public const schema_table = 'weshop_b2b_customer';
    public const schema_primary_key = 'b2b_customer_id';
    public string $indexer = 'b2b_customer_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'B2B customer ID')]
    public const schema_fields_ID = 'b2b_customer_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: true, comment: 'Linked company ID')]
    public const schema_fields_COMPANY_ID = 'company_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Company legal name')]
    public const schema_fields_COMPANY_NAME = 'company_name';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Registration number')]
    public const schema_fields_COMPANY_REG_NO = 'company_reg_no';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Business license')]
    public const schema_fields_BUSINESS_LICENSE = 'business_license';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Tax ID')]
    public const schema_fields_TAX_ID = 'tax_id';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Credit level')]
    public const schema_fields_CREDIT_LEVEL = 'credit_level';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Credit limit snapshot')]
    public const schema_fields_CREDIT_LIMIT = 'credit_limit';
    #[Col(type: 'int', nullable: true, comment: 'Default payment term')]
    public const schema_fields_PAYMENT_TERM_ID = 'payment_term_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['b2b_customer_id'];
    public array $_index_sort_keys = ['customer_id', 'company_name', 'status'];
}
