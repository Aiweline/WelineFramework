<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable, expiring B2B quote token. */
#[Table(comment: 'Server-owned B2B quote tokens')]
#[Index(name: 'uk_b2b_quote_token_id', columns: ['token_id'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_quote_customer_scope', columns: ['customer_id', 'website_id', 'status'])]
#[Index(name: 'idx_b2b_quote_expiry', columns: ['status', 'expires_at_epoch'])]
class B2BQuoteTokenRecord extends Model
{
    public const schema_table = 'weline_b2b_quote_token';
    public const schema_primary_key = 'quote_token_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Quote token row ID')]
    public const schema_fields_ID = 'quote_token_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Opaque quote token ID')]
    public const schema_fields_TOKEN_ID = 'token_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 128, nullable: false, comment: 'Canonical SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('bigint', 20, nullable: false, comment: 'Original retail minor amount')]
    public const schema_fields_RETAIL_AMOUNT_MINOR = 'retail_amount_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Quoted minor amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('varchar', 32, nullable: false, comment: 'Candidate source')]
    public const schema_fields_SOURCE = 'source';

    #[Col('varchar', 64, nullable: true, comment: 'Server-derived group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('varchar', 64, nullable: true, comment: 'Server-derived price list ID')]
    public const schema_fields_PRICE_LIST_ID = 'price_list_id';

    #[Col('bigint', 20, nullable: true, comment: 'Price list version')]
    public const schema_fields_VERSION = 'list_version';

    #[Col('varchar', 64, nullable: true, comment: 'Optional Channel ID')]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('text', nullable: false, comment: 'Rule stack JSON')]
    public const schema_fields_RULE_STACK_JSON = 'rule_stack_json';

    #[Col('char', 64, nullable: false, comment: 'Canonical quote fingerprint')]
    public const schema_fields_FINGERPRINT = 'fingerprint';

    #[Col('bigint', 20, nullable: false, comment: 'Issued epoch second')]
    public const schema_fields_ISSUED_AT_EPOCH = 'issued_at_epoch';

    #[Col('bigint', 20, nullable: false, comment: 'Expiry epoch second')]
    public const schema_fields_EXPIRES_AT_EPOCH = 'expires_at_epoch';

    #[Col('varchar', 16, nullable: false, default: 'open', comment: 'open|consumed|invalidated')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: true, comment: 'Order ref that consumed the token')]
    public const schema_fields_CONSUMED_ORDER_REF = 'consumed_order_ref';

    #[Col('bigint', 20, nullable: true, comment: 'Consumed epoch second')]
    public const schema_fields_CONSUMED_AT_EPOCH = 'consumed_at_epoch';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        new B2BQuoteToken(
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
            fingerprint: (string)$this->getData(self::schema_fields_FINGERPRINT),
            issuedAtEpoch: (int)$this->getData(self::schema_fields_ISSUED_AT_EPOCH),
            expiresAtEpoch: (int)$this->getData(self::schema_fields_EXPIRES_AT_EPOCH),
            status: (string)$this->getData(self::schema_fields_STATUS),
            consumedOrderRef: $this->optionalString(self::schema_fields_CONSUMED_ORDER_REF),
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
