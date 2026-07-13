<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Runtime;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '可恢复运行时外部副作用账本')]
#[Index(name: 'uk_runtime_effect_task_key', columns: ['task_id', 'effect_key'], type: 'UNIQUE')]
#[Index(name: 'idx_runtime_effect_status', columns: ['task_id', 'status'])]
class ResumableTaskEffect extends Model
{
    public const schema_table = 'weline_runtime_task_effect';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '内部主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '任务标识')]
    public const schema_fields_TASK_ID = 'task_id';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '副作用逻辑键')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'reserved', comment: '副作用状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: '外部幂等键')]
    public const schema_fields_EXTERNAL_IDEMPOTENCY_KEY = 'external_idempotency_key';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '外部引用')]
    public const schema_fields_EXTERNAL_REFERENCE = 'external_reference';
    #[Col(type: 'longtext', nullable: true, comment: '副作用结果 JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '执行尝试')]
    public const schema_fields_ATTEMPT = 'attempt';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '防护代际')]
    public const schema_fields_FENCING_GENERATION = 'fencing_generation';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_TASK_ID, self::schema_fields_EFFECT_KEY];

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        parent::save_before();
    }
}
