<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Physical schema scope identity: connection, database, schema, shard and tenant.
 */
#[Table(comment: 'Schema scope identity')]
#[Index(name: 'uk_schema_scope_key', columns: ['scope_key'], type: 'UNIQUE', comment: 'Scope key is unique')]
class SchemaScopeRecord extends Model
{
    public const schema_table = 'weline_schema_scope';
    public const schema_primary_key = 'scope_id';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Scope row ID')]
    public const schema_fields_ID = 'scope_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'default', comment: 'Connection name')]
    public const schema_fields_CONNECTION_NAME = 'connection_name';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Database name')]
    public const schema_fields_DATABASE_NAME = 'database_name';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Schema name')]
    public const schema_fields_SCHEMA_NAME = 'schema_name';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Shard key')]
    public const schema_fields_SHARD_KEY = 'shard_key';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Tenant key')]
    public const schema_fields_TENANT_KEY = 'tenant_key';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
