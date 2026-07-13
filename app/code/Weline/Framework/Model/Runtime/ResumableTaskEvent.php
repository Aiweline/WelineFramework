<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Runtime;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '可恢复运行时事件日志')]
#[Index(name: 'uk_runtime_event_task_sequence', columns: ['task_id', 'sequence'], type: 'UNIQUE')]
#[Index(name: 'idx_runtime_event_task_created', columns: ['task_id', 'created_at'])]
#[Index(name: 'idx_runtime_event_coalesce', columns: ['task_id', 'coalesce_key'])]
class ResumableTaskEvent extends Model
{
    public const schema_table = 'weline_runtime_task_event';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '内部主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '任务标识')]
    public const schema_fields_TASK_ID = 'task_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: '任务内严格递增序号')]
    public const schema_fields_SEQUENCE = 'sequence';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '事件名称')]
    public const schema_fields_EVENT = 'event';
    #[Col(type: 'longtext', nullable: false, comment: '事件载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '载荷字节数')]
    public const schema_fields_PAYLOAD_BYTES = 'payload_bytes';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '关联检查点版本')]
    public const schema_fields_CHECKPOINT_VERSION = 'checkpoint_version';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '执行尝试')]
    public const schema_fields_ATTEMPT = 'attempt';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '防护代际')]
    public const schema_fields_FENCING_GENERATION = 'fencing_generation';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '可合并事件键')]
    public const schema_fields_COALESCE_KEY = 'coalesce_key';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否可压缩')]
    public const schema_fields_IS_COMPRESSIBLE = 'is_compressible';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_TASK_ID, self::schema_fields_SEQUENCE];
}
