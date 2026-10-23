<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable declared-schema checkpoint bound to a release, module and scope.
 */
#[Table(comment: 'Schema checkpoint per release, module and scope')]
#[Index(name: 'uk_schema_checkpoint_identity', columns: ['release_id', 'module_name', 'scope_key'], type: 'UNIQUE', comment: 'One checkpoint per release, module and scope')]
class SchemaCheckpoint extends Model
{
    public const schema_table = 'weline_schema_checkpoint';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Checkpoint row ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Owning module name')]
    public const schema_fields_MODULE_NAME = 'module_name';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Module version')]
    public const schema_fields_MODULE_VERSION = 'module_version';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: 'Checkpoint format version')]
    public const schema_fields_FORMAT = 'format';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Checkpoint checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'longtext', nullable: false, comment: 'Table name to checksum map JSON')]
    public const schema_fields_TABLES_JSON = 'tables_json';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
