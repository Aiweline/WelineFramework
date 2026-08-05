<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Payment reconciliation evidence.
 *
 * Approval references are stored as SHA-256 only. Business payloads are reduced
 * to the bounded reconciliation report; credentials and provider raw payloads
 * must never enter this table.
 */
#[Table(comment: 'Payment reconciliation audit evidence')]
#[Index(name: 'uniq_payment_reconcile_audit_code', columns: ['audit_code'], type: 'UNIQUE')]
#[Index(name: 'uniq_payment_reconcile_idempotency', columns: ['scope', 'idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_reconcile_mode_created', columns: ['mode', 'created_at'])]
#[Index(name: 'idx_payment_reconcile_retain_until', columns: ['retain_until'])]
class PaymentReconciliationAudit extends Model
{
    public const schema_table = 'weline_payment_reconciliation_audit';
    public const schema_primary_key = 'audit_id';

    public const STATUS_COMPLETED = 'completed';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Audit ID')]
    public const schema_fields_ID = 'audit_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable audit code')]
    public const schema_fields_AUDIT_CODE = 'audit_code';
    #[Col('varchar', 32, nullable: false, comment: 'dry-run or repair')]
    public const schema_fields_MODE = 'mode';
    #[Col('varchar', 160, nullable: false, comment: 'Canonical payment scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('bigint', 20, nullable: true, comment: 'Repair actor backend user ID')]
    public const schema_fields_ACTOR_USER_ID = 'actor_user_id';
    #[Col('bigint', 20, nullable: true, comment: 'Independent approver backend user ID')]
    public const schema_fields_APPROVER_USER_ID = 'approver_user_id';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Actor submit grant version')]
    public const schema_fields_ACTOR_GRANT_VERSION = 'actor_grant_version';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Approver submit grant version')]
    public const schema_fields_APPROVER_GRANT_VERSION = 'approver_grant_version';
    #[Col('char', 64, nullable: true, comment: 'SHA-256 of external approval reference')]
    public const schema_fields_APPROVAL_REFERENCE_HASH = 'approval_reference_hash';
    #[Col('varchar', 128, nullable: true, comment: 'Repair request idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Diff count before operation')]
    public const schema_fields_DIFF_COUNT = 'diff_count';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Inserted effect count')]
    public const schema_fields_REPAIRED_COUNT = 'repaired_count';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_COMPLETED, comment: 'Audit status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: false, comment: 'Bounded reconciliation report JSON')]
    public const schema_fields_REPORT_JSON = 'report_json';
    #[Col('datetime', nullable: false, comment: 'Evidence retention deadline')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['audit_id'];
    public array $_index_sort_keys = ['audit_code', 'mode', 'scope', 'idempotency_key', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
