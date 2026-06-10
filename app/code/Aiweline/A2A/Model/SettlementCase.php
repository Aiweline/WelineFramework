<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A refund and dispute settlement case')]
#[Index(name: 'uk_a2a_settlement_case_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public settlement case ID')]
#[Index(name: 'uk_a2a_settlement_case_order_type', columns: [self::schema_fields_ORDER_PUBLIC_ID, self::schema_fields_CASE_TYPE], type: 'UNIQUE', comment: 'One case type per order')]
#[Index(name: 'idx_a2a_settlement_case_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Settlement case status')]
class SettlementCase extends Model
{
    public const schema_table = 'aiweline_a2a_settlement_case';
    public const schema_primary_key = 'settlement_case_id';

    public const TYPE_REFUND = 'refund';
    public const TYPE_DISPUTE = 'dispute';
    public const STATUS_REFUND_REVIEW = 'refund_review';
    public const STATUS_DISPUTE_ARBITRATION = 'dispute_arbitration';
    public const STATUS_ARBITRATION_RULED = 'arbitration_ruled';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Settlement case ID')]
    public const schema_fields_ID = 'settlement_case_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public settlement case ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Delivery acceptance public ID')]
    public const schema_fields_ACCEPTANCE_PUBLIC_ID = 'acceptance_public_id';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'Settlement case type')]
    public const schema_fields_CASE_TYPE = 'case_type';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Settlement case status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Arbitration decision summary')]
    public const schema_fields_DECISION = 'decision';

    #[Col(type: 'longtext', nullable: false, comment: 'Case evidence JSON')]
    public const schema_fields_EVIDENCE_JSON = 'evidence_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Ledger impact JSON')]
    public const schema_fields_LEDGER_IMPACT_JSON = 'ledger_impact_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Settlement case metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Case opened at')]
    public const schema_fields_OPENED_AT = 'opened_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Case resolved at')]
    public const schema_fields_RESOLVED_AT = 'resolved_at';

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
