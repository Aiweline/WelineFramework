<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B billing statement')]
#[Index(name: 'idx_weshop_b2b_statement_customer', columns: ['customer_id'], comment: 'Customer')]
class Statement extends Model
{
    public const schema_table = 'weshop_b2b_statement';
    public const schema_primary_key = 'statement_id';
    public string $indexer = 'b2b_statement_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Statement ID')]
    public const schema_fields_ID = 'statement_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Statement number')]
    public const schema_fields_STATEMENT_NO = 'statement_no';
    #[Col(type: 'date', nullable: false, comment: 'Period start')]
    public const schema_fields_PERIOD_START = 'period_start';
    #[Col(type: 'date', nullable: false, comment: 'Period end')]
    public const schema_fields_PERIOD_END = 'period_end';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Total amount')]
    public const schema_fields_TOTAL_AMOUNT = 'total_amount';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'draft', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: 'Receivable IDs JSON')]
    public const schema_fields_LINE_DATA = 'line_data';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['statement_id'];
    public array $_index_sort_keys = ['customer_id', 'period_start', 'statement_no'];
}
