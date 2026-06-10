<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment attempt table')]
#[Index(name: 'uniq_payment_attempt_code', columns: ['attempt_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_attempt_intent', columns: ['intent_code'])]
#[Index(name: 'idx_payment_attempt_method_provider', columns: ['method_code', 'provider_code'])]
#[Index(name: 'idx_payment_attempt_merchant', columns: ['merchant_account'])]
#[Index(name: 'idx_payment_attempt_status', columns: ['status'])]
#[Index(name: 'idx_payment_attempt_created_at', columns: ['created_at'])]
class PaymentAttempt extends Model
{
    public const schema_table = 'weline_payment_attempt';
    public const schema_primary_key = 'attempt_id';

    public const STATUS_CREATED = 'created';
    public const STATUS_PROVIDER_PENDING = 'provider_pending';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_LATE_SUCCESS_REVIEW = 'late_success_review';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Attempt ID')]
    public const schema_fields_ID = 'attempt_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 96, nullable: false, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: false, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 128, nullable: true, comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 160, nullable: false, default: 'default.default.default', comment: 'Payment config scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 3, nullable: false, comment: 'Payment currency code')]
    public const schema_fields_PAYMENT_CURRENCY_CODE = 'payment_currency_code';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 32, nullable: false, default: 'created', comment: 'Attempt status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 96, nullable: true, comment: 'Failure reason code')]
    public const schema_fields_FAILURE_REASON_CODE = 'failure_reason_code';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'User confirmed flag')]
    public const schema_fields_USER_CONFIRMED = 'user_confirmed';
    #[Col('varchar', 128, nullable: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('varchar', 160, nullable: true, comment: 'Provider reference')]
    public const schema_fields_PROVIDER_REFERENCE = 'provider_reference';
    #[Col('text', nullable: true, comment: 'Provider request snapshot JSON')]
    public const schema_fields_REQUEST_SNAPSHOT = 'request_snapshot';
    #[Col('text', nullable: true, comment: 'Provider response snapshot JSON')]
    public const schema_fields_RESPONSE_SNAPSHOT = 'response_snapshot';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Closed at')]
    public const schema_fields_CLOSED_AT = 'closed_at';
    #[Col('varchar', 96, nullable: true, comment: 'Superseding attempt code')]
    public const schema_fields_SUPERSEDED_BY_ATTEMPT_CODE = 'superseded_by_attempt_code';

    public array $_unit_primary_keys = ['attempt_id'];
    public array $_index_sort_keys = ['attempt_code', 'intent_code', 'method_code', 'provider_code', 'merchant_account', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
