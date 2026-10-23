<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Declared versus physical readiness of one table inside one scope.
 */
#[Table(comment: 'Declared and physical schema state per table')]
#[Index(name: 'uk_schema_physical_state_scope_table', columns: ['scope_key', 'table_name'], type: 'UNIQUE', comment: 'One state row per scope and table')]
class SchemaPhysicalState extends Model
{
    public const schema_table = 'weline_schema_physical_state';
    public const schema_primary_key = 'state_id';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'State row ID')]
    public const schema_fields_ID = 'state_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Table name')]
    public const schema_fields_TABLE_NAME = 'table_name';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Declared schema fingerprint')]
    public const schema_fields_DECLARED_FINGERPRINT = 'declared_fingerprint';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Physical schema fingerprint')]
    public const schema_fields_PHYSICAL_FINGERPRINT = 'physical_fingerprint';

    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: 'Indexes physically ready')]
    public const schema_fields_INDEX_READY = 'index_ready';

    #[Col(type: 'timestamp', nullable: true, comment: 'Last checked at')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';
}
