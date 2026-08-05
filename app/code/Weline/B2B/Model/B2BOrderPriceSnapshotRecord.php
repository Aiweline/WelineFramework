<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Write-once B2B Order price snapshot. */
#[Table(comment: 'Immutable B2B Order price snapshots')]
#[Index(name: 'uk_b2b_order_snapshot_order', columns: ['order_ref'], type: 'UNIQUE')]
#[Index(name: 'uk_b2b_order_snapshot_token', columns: ['token_id'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_order_snapshot_owner', columns: ['customer_id', 'website_id'])]
class B2BOrderPriceSnapshotRecord extends Model
{
    public const schema_table = 'weline_b2b_order_price_snapshot';
    public const schema_primary_key = 'snapshot_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Snapshot row ID')]
    public const schema_fields_ID = 'snapshot_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning Order reference')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 64, nullable: false, comment: 'Consumed quote token ID')]
    public const schema_fields_TOKEN_ID = 'token_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 128, nullable: false, comment: 'Canonical SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('bigint', 20, nullable: false, comment: 'Original retail minor amount')]
    public const schema_fields_RETAIL_AMOUNT_MINOR = 'retail_amount_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Frozen quoted minor amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('varchar', 32, nullable: false, comment: 'Frozen candidate source')]
    public const schema_fields_SOURCE = 'source';

    #[Col('varchar', 64, nullable: true, comment: 'Frozen group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('varchar', 64, nullable: true, comment: 'Frozen price list ID')]
    public const schema_fields_PRICE_LIST_ID = 'price_list_id';

    #[Col('bigint', 20, nullable: true, comment: 'Frozen price list version')]
    public const schema_fields_VERSION = 'list_version';

    #[Col('varchar', 64, nullable: true, comment: 'Frozen Channel ID')]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('text', nullable: false, comment: 'Frozen rule stack JSON')]
    public const schema_fields_RULE_STACK_JSON = 'rule_stack_json';

    #[Col('char', 64, nullable: false, comment: 'Canonical payload hash')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    #[Col('bigint', 20, nullable: false, comment: 'Created epoch second')]
    public const schema_fields_CREATED_AT_EPOCH = 'created_at_epoch';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        new B2BOrderPriceSnapshot(
            orderRef: (string)$this->getData(self::schema_fields_ORDER_REF),
            tokenId: (string)$this->getData(self::schema_fields_TOKEN_ID),
            customerId: (string)$this->getData(self::schema_fields_CUSTOMER_ID),
            websiteId: (int)$this->getData(self::schema_fields_WEBSITE_ID),
            sku: (string)$this->getData(self::schema_fields_SKU),
            retailAmountMinor: (int)$this->getData(self::schema_fields_RETAIL_AMOUNT_MINOR),
            amountMinor: (int)$this->getData(self::schema_fields_AMOUNT_MINOR),
            source: (string)$this->getData(self::schema_fields_SOURCE),
            groupId: $this->optionalString(self::schema_fields_GROUP_ID),
            priceListId: $this->optionalString(self::schema_fields_PRICE_LIST_ID),
            version: $this->optionalInt(self::schema_fields_VERSION),
            channelId: $this->optionalString(self::schema_fields_CHANNEL_ID),
            ruleStack: $this->rules(),
            hash: (string)$this->getData(self::schema_fields_PAYLOAD_HASH),
            createdAtEpoch: (int)$this->getData(self::schema_fields_CREATED_AT_EPOCH),
        );
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** @return list<string> */
    private function rules(): array
    {
        $decoded = json_decode(
            (string)$this->getData(self::schema_fields_RULE_STACK_JSON),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function optionalString(string $field): ?string
    {
        $value = $this->getData($field);
        return $value !== null && $value !== '' ? (string)$value : null;
    }

    private function optionalInt(string $field): ?int
    {
        $value = $this->getData($field);
        return $value !== null && $value !== '' ? (int)$value : null;
    }
}
