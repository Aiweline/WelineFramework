<?php

declare(strict_types=1);

namespace Weline\Ops\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI 运营任务')]
#[Index(name: 'idx_ops_task_website_status', columns: ['website_id', 'status'])]
#[Index(name: 'idx_ops_task_session_status', columns: ['session_id', 'status'])]
class OpsTask extends Model
{
    public const schema_table = 'weline_ops_task';
    public const schema_primary_key = 'task_id';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const AGENT_OPS_ANALYST = 'ops_analyst';
    public const AGENT_BLOG_PUBLISH = 'blog_publish';
    public const AGENT_SOCIAL_OPS = 'social_ops';

    public array $_unit_primary_keys = ['task_id'];
    public array $_index_sort_keys = ['task_id', 'website_id', 'status', 'scope', 'session_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '任务ID')]
    public const schema_fields_TASK_ID = 'task_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '运营会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'site', comment: '范围 code')]
    public const schema_fields_SCOPE = 'scope';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: '任务类型')]
    public const schema_fields_TASK_TYPE = 'task_type';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '指派智能体 code')]
    public const schema_fields_ASSIGNEE_AGENT = 'assignee_agent';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '归属模块')]
    public const schema_fields_OWNER_MODULE = 'owner_module';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'queued', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '标题')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'text', comment: '说明')]
    public const schema_fields_NOTE = 'note';

    #[Col(type: 'text', comment: '载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '队列幂等键')]
    public const schema_fields_QUEUE_IDEMPOTENCY_KEY = 'queue_idempotency_key';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_AWAITING_APPROVAL,
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }
}
