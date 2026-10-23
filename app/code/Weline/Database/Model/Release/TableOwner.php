<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Canonical owner module of a physical table inside one scope.
 */
#[Table(comment: 'Canonical table owner per scope')]
#[Index(name: 'uk_schema_table_owner_scope_table', columns: ['scope_key', 'table_name'], type: 'UNIQUE', comment: 'One owner per scope and table')]
class TableOwner extends Model
{
    public const schema_table = 'weline_schema_table_owner';
    public const schema_primary_key = 'owner_id';

    public const SOURCE_DECLARED = 'declared';
    public const SOURCE_NAMESPACE = 'namespace';
    public const SOURCE_GRANT = 'grant';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Owner row ID')]
    public const schema_fields_ID = 'owner_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Physical table name')]
    public const schema_fields_TABLE_NAME = 'table_name';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Canonical owner module')]
    public const schema_fields_OWNER_MODULE = 'owner_module';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'declared', comment: 'Ownership source')]
    public const schema_fields_SOURCE = 'source';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Ownership checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
