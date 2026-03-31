<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B payment term')]
#[Index(name: 'idx_weshop_b2b_payment_term_sort', columns: ['sort_order'], comment: 'Sort order')]
class PaymentTerm extends Model
{
    public const schema_table = 'weshop_b2b_payment_term';
    public const schema_primary_key = 'term_id';
    public string $indexer = 'b2b_payment_term_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Term ID')]
    public const schema_fields_ID = 'term_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Term name')]
    public const schema_fields_TERM_NAME = 'term_name';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Term days')]
    public const schema_fields_TERM_DAYS = 'term_days';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'arrears', comment: 'Term type')]
    public const schema_fields_TERM_TYPE = 'term_type';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Auto invoice')]
    public const schema_fields_AUTO_INVOICE = 'auto_invoice';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['term_id'];
    public array $_index_sort_keys = ['sort_order', 'term_name'];
}
