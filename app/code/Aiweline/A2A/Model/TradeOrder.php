<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A formal trade order after buyer escrow confirmation')]
#[Index(name: 'uk_a2a_trade_order_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public trade order ID')]
#[Index(name: 'uk_a2a_trade_order_draft_public_id', columns: [self::schema_fields_DRAFT_PUBLIC_ID], type: 'UNIQUE', comment: 'Source order draft ID')]
#[Index(name: 'idx_a2a_trade_order_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Trade order status')]
#[Index(name: 'idx_a2a_trade_order_provider_queue', columns: [self::schema_fields_PROVIDER_QUEUE_STATUS], type: 'KEY', comment: 'Provider queue status')]
class TradeOrder extends Model
{
    public const schema_table = 'aiweline_a2a_trade_order';
    public const schema_primary_key = 'trade_order_id';

    public const STATUS_ESCROW_LOCKED = 'escrow_locked';
    public const STATUS_EXECUTION_READY = 'execution_ready';
    public const STATUS_ACCEPTED_RELEASED = 'accepted_released';
    public const STATUS_REFUND_REVIEW = 'refund_review';
    public const STATUS_DISPUTE_ARBITRATION = 'dispute_arbitration';
    public const STATUS_ARBITRATION_RULED = 'arbitration_ruled';
    public const STATUS_REWORK_REQUIRED = 'rework_required';
    public const PROVIDER_QUEUE_PENDING_SCOPE = 'pending_provider_scope';
    public const PROVIDER_QUEUE_SCOPE_SUBMITTED = 'scope_submitted';
    public const PROVIDER_QUEUE_DELIVERY_SUBMITTED = 'delivery_submitted';
    public const PROVIDER_QUEUE_ACCEPTED = 'accepted_for_release';
    public const PROVIDER_QUEUE_REFUND_REVIEW = 'refund_review';
    public const PROVIDER_QUEUE_DISPUTE_HOLD = 'dispute_hold';
    public const PROVIDER_QUEUE_ARBITRATION_RULED = 'arbitration_ruled';
    public const PROVIDER_QUEUE_REWORK_REQUIRED = 'rework_required';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Trade order ID')]
    public const schema_fields_ID = 'trade_order_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public trade order ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Source public order draft ID')]
    public const schema_fields_DRAFT_PUBLIC_ID = 'draft_public_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Capability SKU code')]
    public const schema_fields_SKU_CODE = 'sku_code';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Capability SKU title snapshot')]
    public const schema_fields_SKU_TITLE = 'sku_title';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name snapshot')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'prototype-buyer', comment: 'Buyer reference')]
    public const schema_fields_BUYER_REFERENCE = 'buyer_reference';

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

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_ESCROW_LOCKED, comment: 'Trade order status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 40, nullable: false, default: self::PROVIDER_QUEUE_PENDING_SCOPE, comment: 'Provider execution queue status')]
    public const schema_fields_PROVIDER_QUEUE_STATUS = 'provider_queue_status';

    #[Col(type: 'longtext', nullable: true, comment: 'Trade order metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Escrow confirmed at')]
    public const schema_fields_CONFIRMED_AT = 'confirmed_at';

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
