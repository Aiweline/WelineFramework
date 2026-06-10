<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment refund table')]
#[Index(name: 'uniq_payment_refund_code', columns: ['refund_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_refund_transaction', columns: ['transaction_code'])]
#[Index(name: 'idx_payment_refund_intent', columns: ['intent_code'])]
#[Index(name: 'idx_payment_refund_attempt', columns: ['attempt_code'])]
#[Index(name: 'idx_payment_refund_status', columns: ['status'])]
#[Index(name: 'idx_payment_refund_provider', columns: ['provider_code', 'provider_refund_id'])]
#[Index(name: 'idx_payment_refund_created_at', columns: ['created_at'])]
class PaymentRefund extends Model
{
    public const schema_table = 'weline_payment_refund';
    public const schema_primary_key = 'refund_id';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Refund ID')]
    public const schema_fields_ID = 'refund_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable refund code')]
    public const schema_fields_REFUND_CODE = 'refund_code';
    #[Col('varchar', 100, nullable: false, comment: 'Linked transaction code')]
    public const schema_fields_TRANSACTION_CODE = 'transaction_code';
    #[Col('bigint', 20, nullable: true, comment: 'Linked transaction row ID')]
    public const schema_fields_LINKED_TRANSACTION_ID = 'linked_transaction_id';
    #[Col('varchar', 96, nullable: true, comment: 'Linked intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Linked attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('bigint', 20, nullable: true, comment: 'Linked attempt row ID')]
    public const schema_fields_LINKED_ATTEMPT_ID = 'linked_attempt_id';
    #[Col('varchar', 96, nullable: true, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 128, nullable: true, comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 64, nullable: true, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: true, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 255, nullable: true, comment: 'Refund reason')]
    public const schema_fields_REASON = 'reason';
    #[Col('decimal', '12,4', nullable: false, default: '0.0000', comment: 'Requested refund amount')]
    public const schema_fields_REQUESTED_AMOUNT = 'requested_amount';
    #[Col('decimal', '12,4', nullable: false, default: '0.0000', comment: 'Approved refund amount')]
    public const schema_fields_APPROVED_AMOUNT = 'approved_amount';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Requested amount in minor units')]
    public const schema_fields_REQUESTED_AMOUNT_MINOR = 'requested_amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Approved amount in minor units')]
    public const schema_fields_APPROVED_AMOUNT_MINOR = 'approved_amount_minor';
    #[Col('varchar', 3, nullable: false, comment: 'Currency code')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 32, nullable: false, default: 'requested', comment: 'Refund status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 160, nullable: true, comment: 'Provider refund ID')]
    public const schema_fields_PROVIDER_REFUND_ID = 'provider_refund_id';
    #[Col('varchar', 128, nullable: true, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('text', nullable: true, comment: 'Provider response JSON')]
    public const schema_fields_PROVIDER_RESPONSE = 'provider_response';
    #[Col('text', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_METADATA = 'metadata';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col('datetime', nullable: true, comment: 'Requested at')]
    public const schema_fields_REQUESTED_AT = 'requested_at';
    #[Col('datetime', nullable: true, comment: 'Approved at')]
    public const schema_fields_APPROVED_AT = 'approved_at';
    #[Col('datetime', nullable: true, comment: 'Completed at')]
    public const schema_fields_COMPLETED_AT = 'completed_at';
    #[Col('datetime', nullable: true, comment: 'Failed at')]
    public const schema_fields_FAILED_AT = 'failed_at';

    public array $_unit_primary_keys = ['refund_id'];
    public array $_index_sort_keys = ['refund_code', 'transaction_code', 'intent_code', 'attempt_code', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function isActive(): bool
    {
        return \in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_REQUESTED,
            self::STATUS_APPROVED,
            self::STATUS_PROCESSING,
            self::STATUS_PENDING,
        ], true);
    }

    public function isRefunded(): bool
    {
        return (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_REFUNDED;
    }

    public function isTerminal(): bool
    {
        return \in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_REFUNDED,
            self::STATUS_FAILED,
            self::STATUS_UNSUPPORTED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderResponse(): array
    {
        return $this->decodeJsonField(self::schema_fields_PROVIDER_RESPONSE);
    }

    /**
     * @param array<string, mixed> $response
     */
    public function setProviderResponse(array $response): static
    {
        return $this->setData(self::schema_fields_PROVIDER_RESPONSE, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->decodeJsonField(self::schema_fields_METADATA);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        return $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonField(string $field): array
    {
        $value = $this->getData($field);
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
