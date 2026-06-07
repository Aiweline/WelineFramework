<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A Agent reputation snapshot derived from trade outcomes')]
#[Index(name: 'uk_a2a_agent_reputation_provider_key', columns: [self::schema_fields_PROVIDER_KEY], type: 'UNIQUE', comment: 'Provider reputation key')]
#[Index(name: 'idx_a2a_agent_reputation_tier', columns: [self::schema_fields_TIER_STATE], type: 'KEY', comment: 'Derived reputation tier')]
class AgentReputation extends Model
{
    public const schema_table = 'guolairen_a2a_agent_reputation';
    public const schema_primary_key = 'agent_reputation_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Agent reputation ID')]
    public const schema_fields_ID = 'agent_reputation_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Provider reputation key')]
    public const schema_fields_PROVIDER_KEY = 'provider_key';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: 'Derived tier label')]
    public const schema_fields_TIER = 'tier';

    #[Col(type: 'varchar', length: 24, nullable: false, default: '', comment: 'Derived tier state')]
    public const schema_fields_TIER_STATE = 'tier_state';

    #[Col(type: 'decimal', length: '6,2', nullable: false, default: '0.00', comment: 'Reputation score')]
    public const schema_fields_SCORE = 'score';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Total formal orders')]
    public const schema_fields_TOTAL_ORDERS = 'total_orders';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Accepted delivery records')]
    public const schema_fields_ACCEPTED_ORDERS = 'accepted_orders';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Refund review case count')]
    public const schema_fields_REFUND_CASES = 'refund_cases';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Dispute arbitration case count')]
    public const schema_fields_DISPUTE_CASES = 'dispute_cases';

    #[Col(type: 'decimal', length: '7,4', nullable: false, default: '0.0000', comment: 'Acceptance evidence rate')]
    public const schema_fields_ACCEPTANCE_RATE = 'acceptance_rate';

    #[Col(type: 'decimal', length: '7,4', nullable: false, default: '0.0000', comment: 'Dispute case rate')]
    public const schema_fields_DISPUTE_RATE = 'dispute_rate';

    #[Col(type: 'longtext', nullable: false, comment: 'Trust signals JSON')]
    public const schema_fields_TRUST_SIGNALS_JSON = 'trust_signals_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Risk signals JSON')]
    public const schema_fields_RISK_SIGNALS_JSON = 'risk_signals_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Source evidence snapshot JSON')]
    public const schema_fields_SOURCE_SNAPSHOT_JSON = 'source_snapshot_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Last calculated at')]
    public const schema_fields_CALCULATED_AT = 'calculated_at';

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

        $this->setData(self::schema_fields_PROVIDER_KEY, \strtolower(\trim((string)$this->getData(self::schema_fields_PROVIDER_KEY))));
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
