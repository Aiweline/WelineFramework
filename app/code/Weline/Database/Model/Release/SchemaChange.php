<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Declarative schema change node with forward and rollback payload.
 */
#[Table(comment: 'Schema change node with forward and rollback payload')]
#[Index(name: 'uk_schema_change_release_node', columns: ['release_id', 'node_id'], type: 'UNIQUE', comment: 'One change per release node')]
class SchemaChange extends Model
{
    public const schema_table = 'weline_schema_change';
    public const schema_primary_key = 'change_id';

    public const PHASE_EXPAND = 'expand';
    public const PHASE_TRANSFORM = 'transform';
    public const PHASE_CONTRACT = 'contract';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Change ID')]
    public const schema_fields_ID = 'change_id';

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

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Change type')]
    public const schema_fields_CHANGE_TYPE = 'change_type';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'expand|transform|contract')]
    public const schema_fields_PHASE = 'phase';

    #[Col(type: 'longtext', nullable: false, comment: 'Forward change payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Rollback payload JSON')]
    public const schema_fields_ROLLBACK_PAYLOAD_JSON = 'rollback_payload_json';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Change checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'planned', comment: 'Change status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
