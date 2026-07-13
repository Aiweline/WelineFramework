<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Module version rollback operations')]
#[Index(name: 'uniq_operation_id', columns: ['operation_id'], type: 'UNIQUE')]
#[Index(name: 'idx_operation_status', columns: ['status'], type: 'KEY')]
class ModuleVersionOperation extends Model
{
    public const schema_table = 'weline_database_module_version_operation';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 64, nullable: false, comment: 'Operation ID')]
    public const schema_fields_OPERATION_ID = 'operation_id';
    #[Col('varchar', 100, nullable: false, comment: 'Root Module')]
    public const schema_fields_ROOT_MODULE = 'root_module';
    #[Col('varchar', 50, nullable: false, comment: 'Root Target Version')]
    public const schema_fields_TARGET_VERSION = 'target_version';
    #[Col('varchar', 64, nullable: false, comment: 'Immutable Plan Hash')]
    public const schema_fields_PLAN_HASH = 'plan_hash';
    #[Col('longtext', nullable: false, comment: 'Rollback Plan JSON')]
    public const schema_fields_PLAN_JSON = 'plan_json';
    #[Col('varchar', 32, nullable: false, default: 'planned', comment: 'Operation Status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 32, nullable: false, default: 'preflight', comment: 'Current Phase')]
    public const schema_fields_PHASE = 'phase';
    #[Col('varchar', 128, nullable: true, comment: 'Operator')]
    public const schema_fields_OPERATOR = 'operator';
    #[Col('text', nullable: true, comment: 'Last Error')]
    public const schema_fields_ERROR = 'error_message';
    #[Col('longtext', nullable: true, comment: 'Recovery Information JSON')]
    public const schema_fields_RECOVERY_JSON = 'recovery_json';
    #[Col('timestamp', nullable: true, comment: 'Plan Expires At')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated At')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPENSATING = 'compensating';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED_RECOVERED = 'failed_recovered';
    public const STATUS_MANUAL_RECOVERY = 'manual_recovery_required';
}
