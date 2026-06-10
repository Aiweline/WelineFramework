<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A provider scope submission before controlled execution')]
#[Index(name: 'uk_a2a_provider_scope_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public provider scope ID')]
#[Index(name: 'uk_a2a_provider_scope_order_public_id', columns: [self::schema_fields_ORDER_PUBLIC_ID], type: 'UNIQUE', comment: 'Source trade order public ID')]
#[Index(name: 'idx_a2a_provider_scope_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Provider scope status')]
class ProviderScopeSubmission extends Model
{
    public const schema_table = 'aiweline_a2a_provider_scope_submission';
    public const schema_primary_key = 'provider_scope_id';

    public const STATUS_SCOPE_SUBMITTED = 'scope_submitted';
    public const STATUS_DELIVERY_SUBMITTED = 'delivery_submitted';
    public const RISK_GATE_LIMITED_SCOPE = 'limited_scope_passed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Provider scope ID')]
    public const schema_fields_ID = 'provider_scope_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public provider scope ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name snapshot')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'longtext', nullable: false, comment: 'Execution scope JSON')]
    public const schema_fields_EXECUTION_SCOPE_JSON = 'execution_scope_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Tool permissions JSON')]
    public const schema_fields_TOOL_PERMISSIONS_JSON = 'tool_permissions_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Evidence checklist JSON')]
    public const schema_fields_EVIDENCE_CHECKLIST_JSON = 'evidence_checklist_json';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_SCOPE_SUBMITTED, comment: 'Provider scope status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 40, nullable: false, default: self::RISK_GATE_LIMITED_SCOPE, comment: 'Risk gate status')]
    public const schema_fields_RISK_GATE_STATUS = 'risk_gate_status';

    #[Col(type: 'longtext', nullable: true, comment: 'Provider scope metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Scope submitted at')]
    public const schema_fields_SUBMITTED_AT = 'submitted_at';

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
