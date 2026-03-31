<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B approval and risk rules')]
#[Index(name: 'idx_weshop_b2b_approval_rule_customer', columns: ['customer_id'], comment: 'Customer scope')]
class ApprovalRule extends Model
{
    public const schema_table = 'weshop_b2b_approval_rule';
    public const schema_primary_key = 'rule_id';
    public string $indexer = 'b2b_approval_rule_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Rule ID')]
    public const schema_fields_ID = 'rule_id';
    #[Col(type: 'int', nullable: true, comment: 'Customer ID 0 = global')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Rule code')]
    public const schema_fields_RULE_CODE = 'rule_code';
    #[Col(type: 'decimal', length: '15,2', nullable: true, comment: 'Threshold amount')]
    public const schema_fields_THRESHOLD_AMOUNT = 'threshold_amount';
    #[Col(type: 'int', nullable: true, comment: 'Overdue days threshold')]
    public const schema_fields_OVERDUE_DAYS = 'overdue_days';
    #[Col(type: 'decimal', length: '5,2', nullable: true, comment: 'Credit usage ratio warn')]
    public const schema_fields_CREDIT_USAGE_RATIO = 'credit_usage_ratio';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['rule_id'];
    public array $_index_sort_keys = ['customer_id', 'rule_code'];
}
