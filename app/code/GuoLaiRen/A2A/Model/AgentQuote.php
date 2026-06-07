<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A agent quote selected by buyer')]
#[Index(name: 'uk_a2a_agent_quote_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public agent quote ID')]
#[Index(name: 'uk_a2a_agent_quote_code', columns: [self::schema_fields_CODE], type: 'UNIQUE', comment: 'Prototype quote code')]
#[Index(name: 'idx_a2a_agent_quote_request', columns: [self::schema_fields_REQUEST_PUBLIC_ID], type: 'KEY', comment: 'Buyer request lookup')]
#[Index(name: 'idx_a2a_agent_quote_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Agent quote status')]
class AgentQuote extends Model
{
    public const schema_table = 'guolairen_a2a_agent_quote';
    public const schema_primary_key = 'agent_quote_id';

    public const STATUS_SELECTED = 'selected';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Agent quote ID')]
    public const schema_fields_ID = 'agent_quote_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public agent quote ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Prototype quote code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Source buyer request public ID')]
    public const schema_fields_REQUEST_PUBLIC_ID = 'request_public_id';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Agent provider name')]
    public const schema_fields_AGENT = 'agent';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Match score')]
    public const schema_fields_MATCH_SCORE = 'match_score';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Quote amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Estimated duration')]
    public const schema_fields_DURATION = 'duration';

    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: 'Quote risk level')]
    public const schema_fields_RISK_LEVEL = 'risk_level';

    #[Col(type: 'text', nullable: true, comment: 'Quoted scope')]
    public const schema_fields_SCOPE = 'scope';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_SELECTED, comment: 'Agent quote status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Agent quote metadata JSON')]
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

        $this->setData(self::schema_fields_CODE, \strtolower(\trim((string)$this->getData(self::schema_fields_CODE))));
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
