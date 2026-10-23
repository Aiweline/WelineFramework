<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * One restorable object (column, table or chunk) inside a backup set.
 */
#[Table(comment: 'Backup object inside a backup set')]
#[Index(name: 'idx_schema_backup_object_set_type', columns: ['backup_set_id', 'object_type'], comment: 'Objects of one backup set by type')]
#[Index(name: 'idx_schema_backup_object_table_column', columns: ['table_name', 'column_name'], comment: 'Objects by table and column')]
class BackupObject extends Model
{
    public const schema_table = 'weline_schema_backup_object';
    public const schema_primary_key = 'backup_object_id';

    public const OBJECT_TYPE_COLUMN = 'column';
    public const OBJECT_TYPE_TABLE = 'table';
    public const OBJECT_TYPE_CHUNK = 'chunk';

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Backup object ID')]
    public const schema_fields_ID = 'backup_object_id';

    #[Col(type: 'int', length: 11, nullable: false, comment: 'Owning backup set ID')]
    public const schema_fields_BACKUP_SET_ID = 'backup_set_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'column|table|chunk')]
    public const schema_fields_OBJECT_TYPE = 'object_type';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Table name')]
    public const schema_fields_TABLE_NAME = 'table_name';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Column name')]
    public const schema_fields_COLUMN_NAME = 'column_name';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Source schema version')]
    public const schema_fields_SOURCE_VERSION = 'source_version';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Target schema version')]
    public const schema_fields_TARGET_VERSION = 'target_version';

    #[Col(type: 'longtext', nullable: true, comment: 'Backup payload JSON')]
    public const schema_fields_DATA_JSON = 'data_json';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'SHA-256 of data_json')]
    public const schema_fields_DATA_CHECKSUM = 'data_checksum';

    #[Col(type: 'bigint', nullable: false, default: 0, comment: 'Backed up row count')]
    public const schema_fields_ROW_COUNT = 'row_count';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Backup object status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
