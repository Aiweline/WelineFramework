<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Current physical/deployment state of one declared schema index.
 *
 * Job rows are history/queue; this table answers “what is the index state now”
 * for the backend and for drift checks without parsing every Model.
 */
#[Table(comment: 'Schema index state')]
#[Index(name: 'uk_state_identity', columns: ['table_name', 'index_name'], type: 'UNIQUE', comment: 'One state row per index')]
#[Index(name: 'idx_state_status', columns: ['status'], comment: 'Status filters')]
#[Index(name: 'idx_state_table', columns: ['table_name'], comment: 'Table filters')]
class SchemaIndexState extends Model
{
    public const schema_table = 'weline_schema_index_state';
    public const schema_primary_key = 'state_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DRIFTED = 'drifted';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_UNTRACKED = 'untracked';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'State ID')]
    public const schema_fields_ID = 'state_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Owning module')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Table name')]
    public const schema_fields_TABLE_NAME = 'table_name';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Index name')]
    public const schema_fields_INDEX_NAME = 'index_name';
    #[Col(type: 'text', nullable: false, comment: 'Declared index definition JSON')]
    public const schema_fields_DEFINITION_JSON = 'definition_json';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'SHA-256 of definition_json')]
    public const schema_fields_DEFINITION_HASH = 'definition_hash';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Resolved physical index name')]
    public const schema_fields_PHYSICAL_NAME = 'physical_name';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'pending', comment: 'Current state')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: true, comment: 'Last related job ID')]
    public const schema_fields_LAST_JOB_ID = 'last_job_id';
    #[Col(type: 'datetime', nullable: true, comment: 'Last successful apply')]
    public const schema_fields_APPLIED_AT = 'applied_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Last physical check')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';
    #[Col(type: 'text', nullable: true, comment: 'Last error')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'int', nullable: true, comment: 'Estimated table rows')]
    public const schema_fields_ESTIMATED_ROWS = 'estimated_rows';
    #[Col(type: 'int', nullable: true, comment: 'Table total size in bytes')]
    public const schema_fields_SIZE_BYTES = 'size_bytes';
}
