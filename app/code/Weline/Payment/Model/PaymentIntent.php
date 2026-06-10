<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment intent table')]
#[Index(name: 'uniq_payment_intent_code', columns: ['intent_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_intent_payable', columns: ['environment', 'payable_type', 'payable_id', 'active_flag'])]
#[Index(name: 'idx_payment_intent_method_status', columns: ['method_code', 'provider_code', 'status'])]
#[Index(name: 'idx_payment_intent_scope', columns: ['scope', 'scope_version'])]
#[Index(name: 'idx_payment_intent_created_at', columns: ['created_at'])]
class PaymentIntent extends Model
{
    public const schema_table = 'weline_payment_intent';
    public const schema_primary_key = 'intent_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ZERO_AMOUNT_READY = 'zero_amount_ready';
    public const STATUS_REDIRECT_PENDING = 'redirect_pending';
    public const STATUS_QR_PENDING = 'qr_pending';
    public const STATUS_AUTHENTICATION_REQUIRED = 'authentication_required';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_PAID = 'paid';
    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REFUNDING = 'refunding';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_REVIEW_REQUIRED = 'review_required';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Intent ID')]
    public const schema_fields_ID = 'intent_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: false, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 96, nullable: true, comment: 'Checkout session code')]
    public const schema_fields_CHECKOUT_SESSION_CODE = 'checkout_session_code';
    #[Col('varchar', 96, nullable: true, comment: 'Payment group code')]
    public const schema_fields_PAYMENT_GROUP_CODE = 'payment_group_code';
    #[Col('varchar', 96, nullable: true, comment: 'Payment link code')]
    public const schema_fields_PAYMENT_LINK_CODE = 'payment_link_code';
    #[Col('varchar', 96, nullable: true, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 128, nullable: true, comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 160, nullable: false, default: 'default.default.default', comment: 'Effective payment config scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 64, nullable: true, comment: 'Scope chain hash')]
    public const schema_fields_SCOPE_CHAIN_HASH = 'scope_chain_hash';
    #[Col('varchar', 64, nullable: true, comment: 'Scope version')]
    public const schema_fields_SCOPE_VERSION = 'scope_version';
    #[Col('varchar', 96, nullable: true, comment: 'Effective config snapshot code')]
    public const schema_fields_EFFECTIVE_CONFIG_SNAPSHOT_CODE = 'effective_config_snapshot_code';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('varchar', 3, nullable: false, comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 32, nullable: false, default: 'draft', comment: 'Intent status')]
    public const schema_fields_STATUS = 'status';
    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Active intent flag')]
    public const schema_fields_ACTIVE_FLAG = 'active_flag';
    #[Col('varchar', 96, nullable: true, comment: 'Failure reason code')]
    public const schema_fields_FAILURE_REASON_CODE = 'failure_reason_code';
    #[Col('varchar', 128, nullable: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('text', nullable: true, comment: 'Frozen amount snapshot JSON')]
    public const schema_fields_AMOUNT_SNAPSHOT = 'amount_snapshot';
    #[Col('text', nullable: true, comment: 'Frozen config snapshot JSON')]
    public const schema_fields_CONFIG_SNAPSHOT = 'config_snapshot';
    #[Col('text', nullable: true, comment: 'Frozen terms snapshot JSON')]
    public const schema_fields_TERMS_SNAPSHOT = 'terms_snapshot';
    #[Col('datetime', nullable: true, comment: 'Intent expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: true, comment: 'Resume expires at')]
    public const schema_fields_RESUME_EXPIRES_AT = 'resume_expires_at';
    #[Col('datetime', nullable: true, comment: 'Auto close at')]
    public const schema_fields_AUTO_CLOSE_AT = 'auto_close_at';
    #[Col('datetime', nullable: true, comment: 'Provider expires at')]
    public const schema_fields_PROVIDER_EXPIRES_AT = 'provider_expires_at';
    #[Col('datetime', nullable: true, comment: 'Authorization expires at')]
    public const schema_fields_AUTHORIZATION_EXPIRES_AT = 'authorization_expires_at';
    #[Col('datetime', nullable: true, comment: 'Closed at')]
    public const schema_fields_CLOSED_AT = 'closed_at';
    #[Col('varchar', 96, nullable: true, comment: 'Close reason code')]
    public const schema_fields_CLOSE_REASON = 'close_reason';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['intent_id'];
    public array $_index_sort_keys = ['intent_code', 'payable_type', 'payable_id', 'method_code', 'provider_code', 'status', 'scope', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
