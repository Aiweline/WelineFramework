<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Append-only schema journal event. Rows are INSERT-only; state is projected from the stream.
 */
#[Table(comment: 'Append-only schema operation event journal')]
#[Index(name: 'idx_schema_operation_event_release', columns: ['release_id', 'operation_id'], comment: 'Events of one operation')]
#[Index(name: 'idx_schema_operation_event_scope_node', columns: ['scope_key', 'node_id'], comment: 'Events of one scope node')]
#[Index(name: 'idx_schema_operation_event_state', columns: ['state'], comment: 'Events by state')]
class SchemaOperationEvent extends Model
{
    public const schema_table = 'weline_schema_operation_event';
    public const schema_primary_key = 'event_id';

    public const STATE_PLANNED = 'planned';
    public const STATE_EXPANDED = 'expanded';
    public const STATE_TRANSFORMED = 'transformed';
    public const STATE_CONTRACTED = 'contracted';
    public const STATE_VERIFIED = 'verified';
    public const STATE_COMMITTED = 'committed';
    public const STATE_FAILED = 'failed';
    public const STATE_COMPENSATED = 'compensated';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Event ID')]
    public const schema_fields_ID = 'event_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Operation ID')]
    public const schema_fields_OPERATION_ID = 'operation_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Node ID module:version:batch:sequence:table')]
    public const schema_fields_NODE_ID = 'node_id';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Module name')]
    public const schema_fields_MODULE_NAME = 'module_name';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Table name')]
    public const schema_fields_TABLE_NAME = 'table_name';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Event state')]
    public const schema_fields_STATE = 'state';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'SHA-256 of payload_json')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Event checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Actor identity')]
    public const schema_fields_ACTOR = 'actor';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'cli', comment: 'cli|web|deploy|cron')]
    public const schema_fields_SOURCE = 'source';

    #[Col(type: 'longtext', nullable: true, comment: 'Sanitized event detail JSON')]
    public const schema_fields_DETAIL_JSON = 'detail_json';

    #[Col(type: 'timestamp', nullable: true, comment: 'Event occurrence time')]
    public const schema_fields_OCCURRED_AT = 'occurred_at';
}
