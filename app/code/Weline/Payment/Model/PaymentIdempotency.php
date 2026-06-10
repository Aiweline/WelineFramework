<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment idempotency table')]
#[Index(name: 'uniq_payment_idempotency_scope', columns: ['idempotency_scope_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_idempotency_status', columns: ['status'])]
#[Index(name: 'idx_payment_idempotency_request_hash', columns: ['request_hash'])]
#[Index(name: 'idx_payment_idempotency_expires_at', columns: ['expires_at'])]
class PaymentIdempotency extends Model
{
    public const schema_table = 'weline_payment_idempotency';
    public const schema_primary_key = 'idempotency_id';

    public const STATUS_STARTED = 'started';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_EXPIRED = 'expired';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Idempotency ID')]
    public const schema_fields_ID = 'idempotency_id';
    #[Col('varchar', 64, nullable: false, comment: 'Normalized idempotency scope hash')]
    public const schema_fields_IDEMPOTENCY_SCOPE_HASH = 'idempotency_scope_hash';
    #[Col('varchar', 128, nullable: false, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('varchar', 48, nullable: false, comment: 'Payment operation')]
    public const schema_fields_OPERATION = 'operation';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 128, nullable: false, default: '', comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 64, nullable: false, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: false, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 96, nullable: true, comment: 'Checkout session code')]
    public const schema_fields_CHECKOUT_SESSION_CODE = 'checkout_session_code';
    #[Col('varchar', 96, nullable: true, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 96, nullable: true, comment: 'Transaction code')]
    public const schema_fields_TRANSACTION_CODE = 'transaction_code';
    #[Col('varchar', 96, nullable: true, comment: 'Refund code')]
    public const schema_fields_REFUND_CODE = 'refund_code';
    #[Col('varchar', 128, nullable: false, comment: 'Request fingerprint')]
    public const schema_fields_REQUEST_FINGERPRINT = 'request_fingerprint';
    #[Col('varchar', 128, nullable: true, comment: 'Request body hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';
    #[Col('varchar', 32, nullable: false, default: 'started', comment: 'Idempotency status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 96, nullable: true, comment: 'Result code')]
    public const schema_fields_RESULT_CODE = 'result_code';
    #[Col('varchar', 96, nullable: true, comment: 'Failure reason code')]
    public const schema_fields_FAILURE_REASON_CODE = 'failure_reason_code';
    #[Col('text', nullable: true, comment: 'Successful result payload JSON')]
    public const schema_fields_RESULT_PAYLOAD = 'result_payload';
    #[Col('text', nullable: true, comment: 'Failure payload JSON')]
    public const schema_fields_FAILURE_PAYLOAD = 'failure_payload';
    #[Col('text', nullable: true, comment: 'Response snapshot JSON')]
    public const schema_fields_RESPONSE_SNAPSHOT = 'response_snapshot';
    #[Col('datetime', nullable: true, comment: 'Lock expires at')]
    public const schema_fields_LOCKED_UNTIL = 'locked_until';
    #[Col('datetime', nullable: true, comment: 'Idempotency record expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col('datetime', nullable: true, comment: 'Completed at')]
    public const schema_fields_COMPLETED_AT = 'completed_at';
    #[Col('datetime', nullable: true, comment: 'Failed at')]
    public const schema_fields_FAILED_AT = 'failed_at';

    public array $_unit_primary_keys = ['idempotency_id'];
    public array $_index_sort_keys = ['idempotency_key', 'operation', 'payable_type', 'payable_id', 'method_code', 'status', 'expires_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function isCompleted(): bool
    {
        return \in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CONFLICT,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function isConflict(): bool
    {
        return (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_CONFLICT;
    }

    public function hasSameRequestFingerprint(string $fingerprint): bool
    {
        return hash_equals((string) $this->getData(self::schema_fields_REQUEST_FINGERPRINT), $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseSnapshot(): array
    {
        return $this->decodeJsonField(self::schema_fields_RESPONSE_SNAPSHOT);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function setResponseSnapshot(array $snapshot): static
    {
        return $this->setJsonField(self::schema_fields_RESPONSE_SNAPSHOT, $snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function getResultPayload(): array
    {
        return $this->decodeJsonField(self::schema_fields_RESULT_PAYLOAD);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setResultPayload(array $payload): static
    {
        return $this->setJsonField(self::schema_fields_RESULT_PAYLOAD, $payload)
            ->setResponseSnapshot($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFailurePayload(): array
    {
        return $this->decodeJsonField(self::schema_fields_FAILURE_PAYLOAD);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setFailurePayload(array $payload): static
    {
        return $this->setJsonField(self::schema_fields_FAILURE_PAYLOAD, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonField(string $field): array
    {
        $raw = $this->getData($field);
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setJsonField(string $field, array $data): static
    {
        return $this->setData($field, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
