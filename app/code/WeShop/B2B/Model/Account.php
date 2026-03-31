<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B trade account')]
#[Index(name: 'uk_weshop_b2b_account_customer', columns: ['customer_id'], type: 'UNIQUE', comment: 'One account per customer')]
class Account extends Model
{
    public const schema_table = 'weshop_b2b_account';
    public const schema_primary_key = 'account_id';
    public string $indexer = 'b2b_account_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Account ID')]
    public const schema_fields_ID = 'account_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: true, comment: 'Default payment term ID')]
    public const schema_fields_PAYMENT_TERM_ID = 'payment_term_id';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Balance negative is AR')]
    public const schema_fields_ACCOUNT_BALANCE = 'account_balance';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Credit period days override')]
    public const schema_fields_CREDIT_PERIOD_DAYS = 'credit_period_days';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Auto approve order limit')]
    public const schema_fields_AUTO_APPROVE_LIMIT = 'auto_approve_limit';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['account_id'];
    public array $_index_sort_keys = ['customer_id', 'payment_term_id'];
}
