<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Append-only Scope maintenance audit')]
#[Index(name: 'idx_scope_maintenance_audit_scope', columns: ['scope_key', 'recorded_at'])]
#[Index(name: 'idx_scope_maintenance_audit_action', columns: ['action', 'recorded_at'])]
class ScopeMaintenanceAudit extends Model
{
    public const ACTION_ENABLED = 'enabled';
    public const ACTION_DISABLED = 'disabled';
    public const ACTION_TOKEN_ISSUED = 'token_issued';
    public const ACTION_TOKEN_REVOKED = 'token_revoked';
    public const ACTION_TOKENS_REVOKED = 'tokens_revoked';

    public const use_main_db_master = true;
    public const schema_table = 'websites_scope_maintenance_audit';
    public const schema_primary_key = 'audit_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Audit ID')]
    public const schema_fields_ID = 'audit_id';

    #[Col('varchar', 512, nullable: false, comment: 'Canonical Scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 32, nullable: false, comment: 'Maintenance audit action')]
    public const schema_fields_ACTION = 'action';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Maintenance generation')]
    public const schema_fields_GENERATION = 'generation';

    #[Col('char', 64, nullable: true, comment: 'Optional SHA-256 token digest')]
    public const schema_fields_TOKEN_HASH = 'token_hash';

    #[Col('varchar', 128, nullable: false, default: 'system', comment: 'Bounded actor/source')]
    public const schema_fields_ACTOR = 'actor';

    #[Col('datetime', nullable: false, comment: 'UTC audit time')]
    public const schema_fields_RECORDED_AT = 'recorded_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
