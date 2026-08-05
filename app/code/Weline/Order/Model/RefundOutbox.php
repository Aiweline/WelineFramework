<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Refund command/effect outbox owned by Order.
 *
 * Provider commands are claimed before the remote call and completed in a
 * second transaction. Post-cash effects use the same deterministic effect-key
 * fence and are retried independently.
 */
#[Table(comment: 'Order refund command and effect outbox')]
#[Index(name: 'uniq_order_refund_outbox_code', columns: ['outbox_code'], type: 'UNIQUE')]
#[Index(name: 'uniq_order_refund_effect_key', columns: ['effect_key'], type: 'UNIQUE')]
#[Index(name: 'idx_order_refund_outbox_status', columns: ['status', 'created_at'])]
#[Index(name: 'idx_order_refund_outbox_case', columns: ['refund_case_uuid', 'operation'])]
class RefundOutbox extends Model
{
    public const schema_table = 'weline_order_refund_outbox';
    public const schema_primary_key = 'outbox_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_DEAD = 'dead';

    public const OPERATION_PROVIDER_REFUND = 'provider_refund';
    public const OPERATION_INVENTORY_RESTOCK = 'inventory_restock';
    public const OPERATION_ASSET_RETURN = 'asset_return';
    public const OPERATION_NOTIFY_REFUNDED = 'notify_refunded';
    public const OPERATION_URGENT_REVIEW = 'urgent_review';

    public const CLAIM_LEASE_SECONDS = 30;

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Outbox ID')]
    public const schema_fields_ID = 'outbox_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable outbox code')]
    public const schema_fields_OUTBOX_CODE = 'outbox_code';
    #[Col('varchar', 191, nullable: false, comment: 'Deterministic effect key')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col('varchar', 36, nullable: false, comment: 'RefundCase UUID')]
    public const schema_fields_REFUND_CASE_UUID = 'refund_case_uuid';
    #[Col('varchar', 96, nullable: false, comment: 'Payment refund code')]
    public const schema_fields_REFUND_CODE = 'refund_code';
    #[Col('varchar', 48, nullable: false, comment: 'Outbox operation')]
    public const schema_fields_OPERATION = 'operation';
    #[Col('varchar', 160, nullable: true, comment: 'Stable Provider request key')]
    public const schema_fields_PROVIDER_REQUEST_KEY = 'provider_request_key';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Outbox status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('text', nullable: true, comment: 'Result JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col('varchar', 96, nullable: true, comment: 'Last error code')]
    public const schema_fields_ERROR_CODE = 'error_code';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Claim attempts')]
    public const schema_fields_ATTEMPT_COUNT = 'attempt_count';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Claim fencing token')]
    public const schema_fields_CLAIM_TOKEN = 'claim_token';
    #[Col('datetime', nullable: true, comment: 'Claimed at')]
    public const schema_fields_CLAIMED_AT = 'claimed_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Processed at')]
    public const schema_fields_PROCESSED_AT = 'processed_at';

    public array $_unit_primary_keys = ['outbox_id'];
    public array $_index_sort_keys = [
        'outbox_code',
        'refund_case_uuid',
        'operation',
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
