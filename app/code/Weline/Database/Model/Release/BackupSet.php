<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Backup set produced by one operation inside one scope.
 */
#[Table(comment: 'Schema backup set per release operation')]
#[Index(name: 'uk_schema_backup_set_identity', columns: ['release_id', 'operation_id', 'scope_key'], type: 'UNIQUE', comment: 'One backup set per release operation and scope')]
class BackupSet extends Model
{
    public const schema_table = 'weline_schema_backup_set';
    public const schema_primary_key = 'backup_set_id';

    public const KIND_OPERATION = 'operation';
    public const KIND_RELEASE = 'release';
    public const KIND_SNAPSHOT = 'snapshot';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Backup set ID')]
    public const schema_fields_ID = 'backup_set_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Operation ID')]
    public const schema_fields_OPERATION_ID = 'operation_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'operation', comment: 'operation|release|snapshot')]
    public const schema_fields_KIND = 'kind';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Backup set status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Backup manifest JSON')]
    public const schema_fields_MANIFEST_JSON = 'manifest_json';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
