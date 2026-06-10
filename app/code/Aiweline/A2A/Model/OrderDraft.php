<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A order draft created from capability SKU purchase intent')]
#[Index(name: 'uk_a2a_order_draft_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public order draft ID')]
#[Index(name: 'idx_a2a_order_draft_sku_code', columns: [self::schema_fields_SKU_CODE], type: 'KEY', comment: 'Capability SKU lookup')]
#[Index(name: 'idx_a2a_order_draft_request', columns: [self::schema_fields_REQUEST_PUBLIC_ID], type: 'KEY', comment: 'Source buyer request lookup')]
#[Index(name: 'idx_a2a_order_draft_quote', columns: [self::schema_fields_QUOTE_PUBLIC_ID], type: 'KEY', comment: 'Source agent quote lookup')]
#[Index(name: 'idx_a2a_order_draft_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Order draft status')]
class OrderDraft extends Model
{
    public const schema_table = 'aiweline_a2a_order_draft';
    public const schema_primary_key = 'order_draft_id';

    public const STATUS_PENDING_ESCROW = 'pending_escrow';
    public const STATUS_ESCROW_CONFIRMED = 'escrow_confirmed';

    public const SOURCE_SKU_PURCHASE = 'sku_purchase';
    public const SOURCE_QUOTE_SELECTION = 'quote_selection';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Order draft ID')]
    public const schema_fields_ID = 'order_draft_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public order draft ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Capability SKU code')]
    public const schema_fields_SKU_CODE = 'sku_code';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Capability SKU title snapshot')]
    public const schema_fields_SKU_TITLE = 'sku_title';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name snapshot')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'prototype-buyer', comment: 'Buyer reference')]
    public const schema_fields_BUYER_REFERENCE = 'buyer_reference';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::SOURCE_SKU_PURCHASE, comment: 'Order draft source type')]
    public const schema_fields_SOURCE_TYPE = 'source_type';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Source buyer request public ID')]
    public const schema_fields_REQUEST_PUBLIC_ID = 'request_public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Source agent quote public ID')]
    public const schema_fields_QUOTE_PUBLIC_ID = 'quote_public_id';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Escrow amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Platform fee')]
    public const schema_fields_PLATFORM_FEE = 'platform_fee';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Provider payout')]
    public const schema_fields_PROVIDER_PAYOUT = 'provider_payout';

    #[Col(type: 'decimal', length: '5,4', nullable: false, default: '0.0800', comment: 'Platform fee rate')]
    public const schema_fields_FEE_RATE = 'fee_rate';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_PENDING_ESCROW, comment: 'Order draft status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Acceptance rules JSON')]
    public const schema_fields_ACCEPTANCE_RULES_JSON = 'acceptance_rules_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Order draft metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }

        $this->setData(self::schema_fields_SKU_CODE, \strtolower(\trim((string)$this->getData(self::schema_fields_SKU_CODE))));
        $this->setData(self::schema_fields_CURRENCY_CODE, \strtoupper((string)($this->getData(self::schema_fields_CURRENCY_CODE) ?: 'USD')));
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getPublicId(): string
    {
        return (string)($this->getData(self::schema_fields_PUBLIC_ID) ?: '');
    }
}
