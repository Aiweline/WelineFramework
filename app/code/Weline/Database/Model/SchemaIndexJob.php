<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Durable queue row for an asynchronous schema-index change.
 *
 * The row is written during setup:upgrade (or a manual enqueue) and consumed by
 * the cron/queue applier. It is intentionally not a schema checkpoint: the
 * declared schema is already recorded by SchemaDiff, while this table tracks
 * physical index application.
 */
#[Table(comment: 'Schema index async job queue')]
#[Index(name: 'uk_job_identity', columns: ['table_name', 'index_name', 'action', 'definition_hash'], type: 'UNIQUE', comment: 'Idempotent index job identity')]
#[Index(name: 'idx_job_status', columns: ['status', 'priority', 'scheduled_at'], comment: 'Scheduler claim order')]
#[Index(name: 'idx_job_table', columns: ['table_name'], comment: 'Per-table serialization')]
class SchemaIndexJob extends Model
{
    public const schema_table = 'weline_schema_index_job';
    public const schema_primary_key = 'job_id';

    public const ACTION_CREATE = 'create';
    public const ACTION_DROP = 'drop';
    public const ACTION_REBUILD = 'rebuild';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_SKIPPED = 'skipped';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Job ID')]
    public const schema_fields_ID = 'job_id';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Change set / diff batch ID')]
    public const schema_fields_CHANGE_SET_ID = 'change_set_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Owning module')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Physical/logical table name')]
    public const schema_fields_TABLE_NAME = 'table_name';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Logical index name')]
    public const schema_fields_INDEX_NAME = 'index_name';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'create|drop|rebuild')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'text', nullable: false, comment: 'New/current index definition JSON')]
    public const schema_fields_DEFINITION_JSON = 'definition_json';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'SHA-256 of definition_json')]
    public const schema_fields_DEFINITION_HASH = 'definition_hash';
    #[Col(type: 'text', nullable: true, comment: 'Old index definition JSON for rebuild/drop')]
    public const schema_fields_OLD_DEFINITION_JSON = 'old_definition_json';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'SHA-256 of old_definition_json')]
    public const schema_fields_OLD_DEFINITION_HASH = 'old_definition_hash';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Resolved physical index name')]
    public const schema_fields_PHYSICAL_NAME = 'physical_name';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'pending', comment: 'Job status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: false, default: 100, comment: 'Lower runs first')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Attempts already made')]
    public const schema_fields_ATTEMPTS = 'attempts';
    #[Col(type: 'int', nullable: false, default: 3, comment: 'Attempt limit')]
    public const schema_fields_MAX_ATTEMPTS = 'max_attempts';
    #[Col(type: 'text', nullable: true, comment: 'Last failure message')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'datetime', nullable: true, comment: 'Earliest eligible time')]
    public const schema_fields_SCHEDULED_AT = 'scheduled_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Build start time')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Build finish time')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Worker heartbeat')]
    public const schema_fields_HEARTBEAT_AT = 'heartbeat_at';
}
