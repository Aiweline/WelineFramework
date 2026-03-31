<?php

declare(strict_types=1);

namespace WeShop\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop B2B accounts receivable')]
#[Index(name: 'idx_weshop_b2b_receivable_customer', columns: ['customer_id'], comment: 'Customer')]
#[Index(name: 'idx_weshop_b2b_receivable_order', columns: ['order_id'], comment: 'Order')]
#[Index(name: 'idx_weshop_b2b_receivable_invoice', columns: ['invoice_no'], comment: 'Invoice number')]
class Receivable extends Model
{
    public const schema_table = 'weshop_b2b_receivable';
    public const schema_primary_key = 'receivable_id';
    public string $indexer = 'b2b_receivable_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Receivable ID')]
    public const schema_fields_ID = 'receivable_id';
    #[Col(type: 'int', nullable: false, comment: 'WeShop customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Invoice number')]
    public const schema_fields_INVOICE_NO = 'invoice_no';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Amount due')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'decimal', length: '15,2', nullable: false, default: '0.00', comment: 'Paid amount')]
    public const schema_fields_PAID_AMOUNT = 'paid_amount';
    #[Col(type: 'date', nullable: true, comment: 'Due date')]
    public const schema_fields_DUE_DATE = 'due_date';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Overdue days')]
    public const schema_fields_OVERDUE_DAYS = 'overdue_days';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'unpaid', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['receivable_id'];
    public array $_index_sort_keys = ['customer_id', 'order_id', 'status', 'due_date'];
}
