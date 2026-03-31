<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B order approval log')]
#[Index(name: 'idx_weshop_b2b_approval_order', columns: ['order_id'], comment: 'Order')]
class ApprovalLog extends Model
{
    public const schema_table = 'weshop_b2b_approval_log';
    public const schema_primary_key = 'log_id';
    public string $indexer = 'b2b_approval_log_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Log ID')]
    public const schema_fields_ID = 'log_id';
    #[Col(type: 'int', nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'int', nullable: true, comment: 'Approver admin user ID')]
    public const schema_fields_APPROVER_ID = 'approver_id';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Action')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'text', nullable: true, comment: 'Comment')]
    public const schema_fields_COMMENT = 'comment';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['log_id'];
    public array $_index_sort_keys = ['order_id', 'created_at'];
}
