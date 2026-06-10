<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment allocation table')]
#[Index(name: 'uniq_payment_allocation_code', columns: ['allocation_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_allocation_payable', columns: ['payable_type', 'payable_id'])]
#[Index(name: 'idx_payment_allocation_intent', columns: ['intent_code', 'status'])]
#[Index(name: 'idx_payment_allocation_source', columns: ['source_type', 'source_code', 'role'])]
#[Index(name: 'idx_payment_allocation_created_at', columns: ['created_at'])]
class PaymentAllocation extends Model
{
    public const schema_table = 'weline_payment_allocation';
    public const schema_primary_key = 'allocation_id';

    public const ROLE_PAYMENT = 'payment';
    public const ROLE_DISCOUNT = 'discount';
    public const ROLE_SURCHARGE = 'surcharge';
    public const ROLE_FEE = 'fee';

    public const SOURCE_CASH = 'cash';
    public const SOURCE_ASSET = 'asset';
    public const SOURCE_GIFT_CARD = 'gift_card';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PARTIALLY_COMMITTED = 'partially_committed';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_PARTIALLY_RELEASED = 'partially_released';
    public const STATUS_RELEASED = 'released';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Allocation ID')]
    public const schema_fields_ID = 'allocation_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable allocation code')]
    public const schema_fields_ALLOCATION_CODE = 'allocation_code';
    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('varchar', 64, nullable: false, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: false, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('varchar', 96, nullable: true, comment: 'Checkout session code')]
    public const schema_fields_CHECKOUT_SESSION_CODE = 'checkout_session_code';
    #[Col('varchar', 96, nullable: true, comment: 'Intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Transaction code')]
    public const schema_fields_TRANSACTION_CODE = 'transaction_code';
    #[Col('varchar', 96, nullable: true, comment: 'Refund code')]
    public const schema_fields_REFUND_CODE = 'refund_code';
    #[Col('varchar', 32, nullable: false, default: 'cash', comment: 'Allocation source type')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col('varchar', 96, nullable: false, comment: 'Allocation source code')]
    public const schema_fields_SOURCE_CODE = 'source_code';
    #[Col('varchar', 96, nullable: true, comment: 'Asset code')]
    public const schema_fields_ASSET_CODE = 'asset_code';
    #[Col('varchar', 32, nullable: false, default: 'payment', comment: 'Allocation role')]
    public const schema_fields_ROLE = 'role';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Allocation amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('varchar', 3, nullable: false, comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Reserved amount in minor units')]
    public const schema_fields_RESERVED_AMOUNT_MINOR = 'reserved_amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Committed amount in minor units')]
    public const schema_fields_COMMITTED_AMOUNT_MINOR = 'committed_amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Released amount in minor units')]
    public const schema_fields_RELEASED_AMOUNT_MINOR = 'released_amount_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Refunded amount in minor units')]
    public const schema_fields_REFUNDED_AMOUNT_MINOR = 'refunded_amount_minor';
    #[Col('varchar', 32, nullable: false, default: 'draft', comment: 'Allocation status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: 'Allocation snapshot JSON')]
    public const schema_fields_ALLOCATION_SNAPSHOT = 'allocation_snapshot';
    #[Col('text', nullable: true, comment: 'Allocation metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['allocation_id'];
    public array $_index_sort_keys = ['allocation_code', 'payable_type', 'payable_id', 'intent_code', 'source_type', 'source_code', 'role', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function isReserved(): bool
    {
        return (string) $this->getData(self::schema_fields_STATUS) === self::STATUS_RESERVED;
    }

    public function isCommitted(): bool
    {
        return \in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_PARTIALLY_COMMITTED,
            self::STATUS_COMMITTED,
        ], true);
    }

    public function getReservableRemainderMinor(): int
    {
        return max(0,
            (int) $this->getData(self::schema_fields_RESERVED_AMOUNT_MINOR)
            - (int) $this->getData(self::schema_fields_COMMITTED_AMOUNT_MINOR)
            - (int) $this->getData(self::schema_fields_RELEASED_AMOUNT_MINOR)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->decodeJsonField(self::schema_fields_METADATA_JSON);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        return $this->setJsonField(self::schema_fields_METADATA_JSON, $metadata);
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
