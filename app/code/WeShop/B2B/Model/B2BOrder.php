<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B order extension')]
#[Index(name: 'uk_weshop_b2b_order_order', columns: ['order_id'], type: 'UNIQUE', comment: 'One extension per order')]
class B2BOrder extends Model
{
    public const schema_table = 'weshop_b2b_order';
    public const schema_primary_key = 'b2b_order_id';
    public string $indexer = 'b2b_order_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'B2B order row ID')]
    public const schema_fields_ID = 'b2b_order_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop order ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Credit reserved')]
    public const schema_fields_CREDIT_USED = 'credit_used';
    #[Col(type: 'int', nullable: true, comment: 'Payment term ID')]
    public const schema_fields_PAYMENT_TERM_ID = 'payment_term_id';
    #[Col(type: 'date', nullable: true, comment: 'Due date')]
    public const schema_fields_DUE_DATE = 'due_date';
    #[Col(type: 'varchar', length: 32, nullable: true, default: 'pending', comment: 'Invoice status')]
    public const schema_fields_INVOICE_STATUS = 'invoice_status';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'none', comment: 'Approval status')]
    public const schema_fields_APPROVAL_STATUS = 'approval_status';
    #[Col(type: 'int', nullable: true, comment: 'Approver user ID')]
    public const schema_fields_APPROVER_ID = 'approver_id';
    #[Col(type: 'datetime', nullable: true, comment: 'Approved at')]
    public const schema_fields_APPROVED_AT = 'approved_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['b2b_order_id'];
    public array $_index_sort_keys = ['order_id', 'customer_id', 'approval_status'];
}
