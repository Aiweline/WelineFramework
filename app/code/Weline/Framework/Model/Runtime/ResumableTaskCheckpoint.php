<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Runtime;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '可恢复运行时检查点')]
#[Index(name: 'uk_runtime_checkpoint_task_version', columns: ['task_id', 'version'], type: 'UNIQUE')]
#[Index(name: 'idx_runtime_checkpoint_task_created', columns: ['task_id', 'created_at'])]
class ResumableTaskCheckpoint extends Model
{
    public const schema_table = 'weline_runtime_task_checkpoint';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '内部主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '任务标识')]
    public const schema_fields_TASK_ID = 'task_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: '递增版本')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '逻辑游标')]
    public const schema_fields_CURSOR = 'cursor';
    #[Col(type: 'longtext', nullable: false, comment: '检查点状态 JSON')]
    public const schema_fields_STATE_JSON = 'state_json';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '检查点 schema 版本')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';
    #[Col(type: 'char', length: 64, nullable: false, comment: '状态校验和')]
    public const schema_fields_CHECKSUM = 'checksum';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '执行尝试')]
    public const schema_fields_ATTEMPT = 'attempt';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '防护代际')]
    public const schema_fields_FENCING_GENERATION = 'fencing_generation';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_TASK_ID, self::schema_fields_VERSION];
}
