<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A final arbitration ruling')]
#[Index(name: 'uk_a2a_arbitration_ruling_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public arbitration ruling ID')]
#[Index(name: 'uk_a2a_arbitration_ruling_order_type', columns: [self::schema_fields_ORDER_PUBLIC_ID, self::schema_fields_RULING_TYPE], type: 'UNIQUE', comment: 'One ruling type per order in prototype mode')]
#[Index(name: 'idx_a2a_arbitration_ruling_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Arbitration ruling status')]
class ArbitrationRuling extends Model
{
    public const schema_table = 'aiweline_a2a_arbitration_ruling';
    public const schema_primary_key = 'arbitration_ruling_id';

    public const TYPE_FULL_RELEASE = 'full_release';
    public const TYPE_PARTIAL_RELEASE = 'partial_release';
    public const TYPE_REFUND = 'refund';
    public const TYPE_REWORK = 'rework';
    public const STATUS_ISSUED = 'issued';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Arbitration ruling ID')]
    public const schema_fields_ID = 'arbitration_ruling_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public arbitration ruling ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Settlement case public ID')]
    public const schema_fields_SETTLEMENT_CASE_PUBLIC_ID = 'settlement_case_public_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Ruling type')]
    public const schema_fields_RULING_TYPE = 'ruling_type';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Ruling status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 120, nullable: false, default: '', comment: 'Decision summary')]
    public const schema_fields_DECISION = 'decision';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Buyer refund amount')]
    public const schema_fields_BUYER_REFUND_AMOUNT = 'buyer_refund_amount';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Platform fee amount')]
    public const schema_fields_PLATFORM_FEE_AMOUNT = 'platform_fee_amount';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Provider payout amount')]
    public const schema_fields_PROVIDER_PAYOUT_AMOUNT = 'provider_payout_amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'longtext', nullable: false, comment: 'Ruling evidence JSON')]
    public const schema_fields_EVIDENCE_JSON = 'evidence_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Wallet instruction plan JSON')]
    public const schema_fields_WALLET_PLAN_JSON = 'wallet_plan_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Ruling metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Ruled at')]
    public const schema_fields_RULED_AT = 'ruled_at';

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
