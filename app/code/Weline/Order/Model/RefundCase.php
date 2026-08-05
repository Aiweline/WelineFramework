<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Business refund intent（MOD-P2F-005 / DEC-018）。
 * 现金渠道状态在 PaymentRefund；本表保存顾客意图、服务端重算金额/数量与后处理步骤。
 */
#[Table(comment: 'Order refund case')]
#[Index(name: 'uniq_order_refund_case_uuid', columns: ['refund_case_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_order_refund_case_order', columns: ['order_uuid', 'status'])]
#[Index(name: 'uniq_order_refund_case_idem', columns: ['order_uuid', 'idempotency_key'], type: 'UNIQUE')]
class RefundCase extends Model
{
    public const schema_table = 'weline_order_refund_case';
    public const schema_primary_key = 'refund_case_id';

    public const STATUS_OPEN = 'open';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_LATE_SUCCESS_REVIEW = 'refund_late_success_review';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Refund case ID')]
    public const schema_fields_ID = 'refund_case_id';
    #[Col('varchar', 36, nullable: false, comment: 'Refund case UUID')]
    public const schema_fields_REFUND_CASE_UUID = 'refund_case_uuid';
    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('varchar', 96, nullable: true, comment: 'Linked payment refund code')]
    public const schema_fields_PAYMENT_REFUND_CODE = 'payment_refund_code';
    #[Col('varchar', 128, nullable: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('char', 64, nullable: true, comment: 'Idempotent request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Server recomputed amount minor')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Frozen cash-provider refund minor')]
    public const schema_fields_CASH_AMOUNT_MINOR = 'cash_amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Frozen asset-tender refund minor')]
    public const schema_fields_ASSET_AMOUNT_MINOR = 'asset_amount_minor';
    #[Col('text', nullable: true, comment: 'Frozen asset return allocations JSON')]
    public const schema_fields_ASSET_ALLOCATIONS_JSON = 'asset_allocations_json';
    #[Col('varchar', 3, nullable: false, default: 'CNY', comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('text', nullable: true, comment: 'Line qty reservations JSON')]
    public const schema_fields_ITEMS_JSON = 'items_json';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Shipping refund minor (owner only)')]
    public const schema_fields_SHIPPING_REFUND_MINOR = 'shipping_refund_minor';
    #[Col('varchar', 32, nullable: false, default: 'open', comment: 'Case status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 24, nullable: false, default: 'processing', comment: 'Customer view: processing|succeeded|failed')]
    public const schema_fields_CUSTOMER_VIEW = 'customer_view';
    #[Col('int', 11, nullable: false, default: 0, comment: 'RefundCase CAS version')]
    public const schema_fields_VERSION = 'version';
    #[Col('varchar', 255, nullable: true, comment: 'Reason')]
    public const schema_fields_REASON = 'reason';
    #[Col('text', nullable: true, comment: 'Step ledger JSON')]
    public const schema_fields_STEPS_JSON = 'steps_json';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['refund_case_id'];
    public array $_index_sort_keys = [
        'refund_case_uuid',
        'order_uuid',
        'idempotency_key',
        'status',
        'created_at',
    ];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
