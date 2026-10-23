<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Whole-database consistency snapshot bound to a release and scope.
 */
#[Table(comment: 'Database snapshot per release')]
#[Index(name: 'uk_schema_snapshot_key', columns: ['snapshot_key'], type: 'UNIQUE', comment: 'External snapshot key')]
#[Index(name: 'idx_schema_snapshot_release', columns: ['release_id'], comment: 'Snapshots of one release')]
class SchemaSnapshot extends Model
{
    public const schema_table = 'weline_schema_snapshot';
    public const schema_primary_key = 'snapshot_id';

    public const ENGINE_PGSQL = 'pgsql';
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_SQLITE = 'sqlite';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Snapshot ID')]
    public const schema_fields_ID = 'snapshot_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'External snapshot key')]
    public const schema_fields_SNAPSHOT_KEY = 'snapshot_key';

    #[Col(type: 'int', length: 11, nullable: true, comment: 'Related backup set ID')]
    public const schema_fields_BACKUP_SET_ID = 'backup_set_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'pgsql|mysql|sqlite')]
    public const schema_fields_ENGINE = 'engine';

    #[Col(type: 'varchar', length: 512, nullable: false, comment: 'Snapshot artifact path')]
    public const schema_fields_PATH = 'path';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Snapshot checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'LSN / GTID / binlog position')]
    public const schema_fields_POSITION = 'position';

    #[Col(type: 'bigint', nullable: false, default: 0, comment: 'Snapshot size in bytes')]
    public const schema_fields_SIZE_BYTES = 'size_bytes';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Snapshot status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: true, comment: 'Restored at')]
    public const schema_fields_RESTORED_AT = 'restored_at';
}
