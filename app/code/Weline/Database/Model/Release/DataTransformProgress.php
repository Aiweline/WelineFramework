<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Resumable data transform cursor per release, transform and scope.
 */
#[Table(comment: 'Resumable data transform progress')]
#[Index(name: 'uk_schema_data_transform_identity', columns: ['release_id', 'transform_id', 'scope_key'], type: 'UNIQUE', comment: 'One progress row per release, transform and scope')]
class DataTransformProgress extends Model
{
    public const schema_table = 'weline_schema_data_transform_progress';
    public const schema_primary_key = 'progress_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Progress row ID')]
    public const schema_fields_ID = 'progress_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Transform ID')]
    public const schema_fields_TRANSFORM_ID = 'transform_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Schema scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Table name')]
    public const schema_fields_TABLE_NAME = 'table_name';

    #[Col(type: 'longtext', nullable: true, comment: 'Resume cursor JSON')]
    public const schema_fields_CURSOR_JSON = 'cursor_json';

    #[Col(type: 'bigint', nullable: false, default: 0, comment: 'Rows already transformed')]
    public const schema_fields_ROWS_DONE = 'rows_done';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Progress checksum')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Transform status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
