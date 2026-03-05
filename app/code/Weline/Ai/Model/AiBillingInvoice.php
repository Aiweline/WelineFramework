<?php
declare(strict_types=1);
namespace Weline\Ai\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Billing Invoice Entity
 *
 * Records billing invoices.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI Billing Invoice')]
#[Index(name: 'uk_invoice_number', columns: ['invoice_number'], type: 'UNIQUE')]
#[Index(name: 'idx_tenant_id', columns: ['tenant_id'])]
#[Index(name: 'idx_plan_id', columns: ['plan_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class AiBillingInvoice extends Model
{
    public const schema_table = 'weline_ai_ai_billing_invoice';
    public const schema_primary_key = 'id';
    /** @var array Unit primary keys */
    public array $_unit_primary_keys = ['id'];
    /** @var array Index sort keys */
    public array $_index_sort_keys = ['id', 'tenant_id', 'status'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '发票ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '租户ID')]
    public const schema_fields_TENANT_ID = 'tenant_id';
    #[Col(type: 'int', nullable: false, comment: '计划ID')]
    public const schema_fields_PLAN_ID = 'plan_id';
    #[Col(type: 'varchar', length: 100, nullable: false, unique: true, comment: '发票号')]
    public const schema_fields_INVOICE_NUMBER = 'invoice_number';
    #[Col(type: 'decimal', length: '10,2', nullable: false, comment: '金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'varchar', length: 10, nullable: false, default: 'CNY', comment: '货币单位')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'date', nullable: false, comment: '到期日期')]
    public const schema_fields_DUE_DATE = 'due_date';
    #[Col(type: 'timestamp', nullable: true, comment: '支付时间')]
    public const schema_fields_PAID_AT = 'paid_at';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
}
