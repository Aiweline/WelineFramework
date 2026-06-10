<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment lock table')]
#[Index(name: 'uniq_payment_lock_active_key', columns: ['environment', 'lock_scope_hash', 'active_flag'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_lock_code', columns: ['lock_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_lock_payable', columns: ['payable_type', 'payable_id'])]
#[Index(name: 'idx_payment_lock_status', columns: ['status'])]
#[Index(name: 'idx_payment_lock_expires_at', columns: ['expires_at'])]
class PaymentLock extends Model
{
    public const schema_table = 'weline_payment_lock';
    public const schema_primary_key = 'lock_id';

    public const SCOPE_PAYABLE_ACTIVE_INTENT = 'payable_active_intent';
    public const SCOPE_INTENT_ACTIVE_ATTEMPT = 'intent_active_attempt';
    public const SCOPE_PROVIDER_REFERENCE = 'provider_reference';
    public const SCOPE_REFUND = 'refund';

    public const STATUS_ACQUIRED = 'acquired';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_STOLEN = 'stolen';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Lock ID')]
    public const schema_fields_ID = 'lock_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable lock code')]
    public const schema_fields_LOCK_CODE = 'lock_code';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, comment: 'Lock scope')]
    public const schema_fields_LOCK_SCOPE = 'lock_scope';
    #[Col('varchar', 191, nullable: false, comment: 'Lock key')]
    public const schema_fields_LOCK_KEY = 'lock_key';
    #[Col('varchar', 64, nullable: false, comment: 'Normalized lock scope hash')]
    public const schema_fields_LOCK_SCOPE_HASH = 'lock_scope_hash';
    #[Col('varchar', 128, nullable: false, default: 'default', comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 96, nullable: true, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 48, nullable: false, default: 'payment', comment: 'Payment operation')]
    public const schema_fields_OPERATION = 'operation';
    #[Col('varchar', 128, nullable: false, comment: 'Owner token hash')]
    public const schema_fields_OWNER_TOKEN_HASH = 'owner_token_hash';
    #[Col('varchar', 32, nullable: true, comment: 'Owner type')]
    public const schema_fields_OWNER_TYPE = 'owner_type';
    #[Col('varchar', 128, nullable: true, comment: 'Owner ID')]
    public const schema_fields_OWNER_ID = 'owner_id';
    #[Col('varchar', 64, nullable: true, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: true, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 96, nullable: true, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 32, nullable: false, default: 'acquired', comment: 'Lock status')]
    public const schema_fields_STATUS = 'status';
    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Active lock flag')]
    public const schema_fields_ACTIVE_FLAG = 'active_flag';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Lock TTL seconds')]
    public const schema_fields_TTL_SECONDS = 'ttl_seconds';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Acquired at')]
    public const schema_fields_ACQUIRED_AT = 'acquired_at';
    #[Col('datetime', nullable: true, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: true, comment: 'Released at')]
    public const schema_fields_RELEASED_AT = 'released_at';
    #[Col('varchar', 96, nullable: true, comment: 'Release reason code')]
    public const schema_fields_RELEASE_REASON = 'release_reason';
    #[Col('text', nullable: true, comment: 'Lock metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['lock_id'];
    public array $_index_sort_keys = ['lock_code', 'lock_scope', 'lock_key', 'payable_type', 'payable_id', 'status', 'expires_at'];

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
        return (int) $this->getData(self::schema_fields_ACTIVE_FLAG) === 1
            && (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_ACQUIRED;
    }

    public function isReleased(): bool
    {
        return (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_RELEASED;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $raw = $this->getData(self::schema_fields_METADATA_JSON);
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
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        return $this->setData(self::schema_fields_METADATA_JSON, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
