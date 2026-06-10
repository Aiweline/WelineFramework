<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment checkout session table')]
#[Index(name: 'uniq_payment_checkout_session_code', columns: ['checkout_session_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_checkout_payable', columns: ['environment', 'payable_type', 'payable_id', 'status'])]
#[Index(name: 'idx_payment_checkout_scope', columns: ['scope', 'scope_version'])]
#[Index(name: 'idx_payment_checkout_payer', columns: ['payer_type', 'payer_id'])]
#[Index(name: 'idx_payment_checkout_expires_at', columns: ['expires_at'])]
class PaymentCheckoutSession extends Model
{
    public const schema_table = 'weline_payment_checkout_session';
    public const schema_primary_key = 'checkout_session_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Checkout session ID')]
    public const schema_fields_ID = 'checkout_session_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable checkout session code')]
    public const schema_fields_CHECKOUT_SESSION_CODE = 'checkout_session_code';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: false, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 32, nullable: true, comment: 'Payer type')]
    public const schema_fields_PAYER_TYPE = 'payer_type';
    #[Col('varchar', 128, nullable: true, comment: 'Payer ID')]
    public const schema_fields_PAYER_ID = 'payer_id';
    #[Col('varchar', 128, nullable: true, comment: 'Guest token hash')]
    public const schema_fields_GUEST_TOKEN_HASH = 'guest_token_hash';
    #[Col('varchar', 128, nullable: true, comment: 'Resume token hash')]
    public const schema_fields_RESUME_TOKEN_HASH = 'resume_token_hash';
    #[Col('varchar', 191, nullable: false, default: 'default.default.default', comment: 'Effective payment config scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 64, nullable: true, comment: 'Scope version')]
    public const schema_fields_SCOPE_VERSION = 'scope_version';
    #[Col('int', 0, nullable: false, default: 1, comment: 'Frontend session version')]
    public const schema_fields_SESSION_VERSION = 'session_version';
    #[Col('varchar', 2, nullable: true, comment: 'Resolved country code')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 16, nullable: true, comment: 'Checkout locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 3, nullable: false, comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 96, nullable: true, comment: 'Selected payment method code')]
    public const schema_fields_SELECTED_METHOD_CODE = 'selected_method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Selected provider code')]
    public const schema_fields_SELECTED_PROVIDER_CODE = 'selected_provider_code';
    #[Col('varchar', 96, nullable: true, comment: 'Active intent code')]
    public const schema_fields_ACTIVE_INTENT_CODE = 'active_intent_code';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'Checkout session status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: 'Amount snapshot JSON')]
    public const schema_fields_AMOUNT_SNAPSHOT = 'amount_snapshot';
    #[Col('text', nullable: true, comment: 'Available method snapshot JSON')]
    public const schema_fields_METHODS_SNAPSHOT = 'methods_snapshot';
    #[Col('text', nullable: true, comment: 'Terms acceptance snapshot JSON')]
    public const schema_fields_TERMS_SNAPSHOT = 'terms_snapshot';
    #[Col('text', nullable: true, comment: 'Checkout context snapshot JSON')]
    public const schema_fields_CONTEXT_SNAPSHOT = 'context_snapshot';
    #[Col('datetime', nullable: true, comment: 'Session expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: true, comment: 'Completed at')]
    public const schema_fields_COMPLETED_AT = 'completed_at';
    #[Col('datetime', nullable: true, comment: 'Closed at')]
    public const schema_fields_CLOSED_AT = 'closed_at';
    #[Col('varchar', 96, nullable: true, comment: 'Close reason code')]
    public const schema_fields_CLOSE_REASON = 'close_reason';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['checkout_session_id'];
    public array $_index_sort_keys = ['checkout_session_code', 'payable_type', 'payable_id', 'scope', 'status', 'expires_at'];

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
        return (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_ACTIVE;
    }

    public function isClosed(): bool
    {
        return \in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_COMPLETED,
            self::STATUS_SUPERSEDED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSnapshot(): array
    {
        return $this->decodeJsonField(self::schema_fields_CONTEXT_SNAPSHOT);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function setContextSnapshot(array $snapshot): static
    {
        return $this->setJsonField(self::schema_fields_CONTEXT_SNAPSHOT, $snapshot);
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
