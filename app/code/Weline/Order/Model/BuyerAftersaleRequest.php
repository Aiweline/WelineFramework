<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 买家售后申请。现金退款不在本表推进，只在联动事件里交给订单退款协调。
 */
#[Table(comment: 'Buyer after-sale request')]
#[Index(name: 'uniq_buyer_aftersale_request_uuid', columns: ['request_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_buyer_aftersale_order', columns: ['order_uuid', 'status'])]
#[Index(name: 'uniq_buyer_aftersale_idem', columns: ['idempotency_key'], type: 'UNIQUE')]
class BuyerAftersaleRequest extends Model
{
    public const schema_table = 'weline_order_buyer_aftersale_request';
    public const schema_primary_key = 'request_id';

    public const RESOLUTION_RETURN = 'return';
    public const RESOLUTION_REFUND_ONLY = 'refund_only';
    public const RESOLUTION_EXCHANGE = 'exchange';

    public const REASON_QUALITY = 'quality';
    public const REASON_WRONG_OR_MISSING = 'wrong_or_missing';
    public const REASON_TRANSIT_DAMAGE = 'transit_damage';

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RETURN_RECEIVED = 'return_received';
    public const STATUS_AWAITING_RESHIP = 'awaiting_reship';
    public const STATUS_MANUAL_DONE = 'manual_done';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'After-sale request ID')]
    public const schema_fields_ID = 'request_id';
    #[Col('varchar', 36, nullable: false, comment: 'After-sale request UUID')]
    public const schema_fields_REQUEST_UUID = 'request_uuid';
    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 32, nullable: false, comment: 'Resolution return|refund_only|exchange')]
    public const schema_fields_RESOLUTION = 'resolution';
    #[Col('varchar', 32, nullable: false, comment: 'Reason quality|wrong_or_missing|transit_damage')]
    public const schema_fields_REASON_CODE = 'reason_code';
    #[Col('text', nullable: true, comment: 'Customer reason text')]
    public const schema_fields_REASON_TEXT = 'reason_text';
    #[Col('varchar', 32, nullable: false, default: 'submitted', comment: 'Request status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 3, nullable: false, default: 'CNY', comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Requested amount minor')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Requested item count')]
    public const schema_fields_ITEM_COUNT = 'item_count';
    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Both gates open at submit')]
    public const schema_fields_LINK_PAYMENT_SNAPSHOT = 'link_payment_snapshot';
    #[Col('varchar', 128, nullable: false, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('text', nullable: true, comment: 'Return address snapshot')]
    public const schema_fields_RETURN_ADDRESS_SNAPSHOT = 'return_address_snapshot';
    #[Col('varchar', 128, nullable: true, comment: 'Customer return tracking number')]
    public const schema_fields_RETURN_TRACKING_NO = 'return_tracking_no';
    #[Col('varchar', 128, nullable: true, comment: 'Exchange reship tracking number')]
    public const schema_fields_RESHIP_TRACKING_NO = 'reship_tracking_no';
    #[Col('varchar', 64, nullable: true, comment: 'Payment link error code')]
    public const schema_fields_PAYMENT_ERROR_CODE = 'payment_error_code';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['request_id'];
    public array $_index_sort_keys = [
        'request_uuid',
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
