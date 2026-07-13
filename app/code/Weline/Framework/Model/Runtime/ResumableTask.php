<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Runtime;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Durable coordination record for a resumable task.
 *
 * This model deliberately has no Queue dependency.  The runner lease and the
 * fencing generation are persisted here so a stale process cannot write after
 * a replacement runner has claimed the task.
 */
#[Table(comment: '可恢复运行时任务')]
#[Index(name: 'uk_runtime_task_task_id', columns: ['task_id'], type: 'UNIQUE')]
#[Index(name: 'uk_runtime_task_business_owner', columns: ['type_code', 'business_key', 'owner_principal', 'owner_area'], type: 'UNIQUE')]
#[Index(name: 'idx_runtime_task_status_lease', columns: ['status', 'execution_lease_until'])]
#[Index(name: 'idx_runtime_task_retention', columns: ['retain_until', 'status'])]
class ResumableTask extends Model
{
    public const schema_table = 'weline_runtime_task';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '内部主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '公开任务标识')]
    public const schema_fields_TASK_ID = 'task_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '任务类型')]
    public const schema_fields_TYPE_CODE = 'type_code';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '声明模块')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '业务幂等键')]
    public const schema_fields_BUSINESS_KEY = 'business_key';
    #[Col(type: 'longtext', nullable: false, comment: '任务输入 JSON')]
    public const schema_fields_INPUT_JSON = 'input_json';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '所有者 area')]
    public const schema_fields_OWNER_AREA = 'owner_area';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '所有者主体')]
    public const schema_fields_OWNER_PRINCIPAL = 'owner_principal';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '所有者会话')]
    public const schema_fields_OWNER_SESSION = 'owner_session';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '网站范围，0 为系统默认站点')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否绑定网站范围')]
    public const schema_fields_OWNER_WEBSITE_SCOPED = 'owner_website_scoped';
    #[Col(type: 'varchar', length:128, nullable: false, default: '', comment: '租户范围')]
    public const schema_fields_TENANT_SCOPE = 'tenant_scope';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否绑定租户范围')]
    public const schema_fields_OWNER_TENANT_SCOPED = 'owner_tenant_scoped';
    #[Col(type: 'longtext', nullable: true, comment: 'ACL 快照 JSON')]
    public const schema_fields_ACL_JSON = 'acl_json';
    #[Col(type: 'longtext', nullable: false, comment: '任务策略快照 JSON')]
    public const schema_fields_POLICY_JSON = 'policy_json';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'starting', comment: '任务状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'longtext', nullable: true, comment: '终态结果 JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '失败码')]
    public const schema_fields_FAILURE_CODE = 'failure_code';
    #[Col(type: 'text', nullable: true, comment: '失败信息')]
    public const schema_fields_FAILURE_MESSAGE = 'failure_message';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '终止原因')]
    public const schema_fields_TERMINATION_REASON = 'termination_reason';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: 'Runner PID')]
    public const schema_fields_RUNNER_PID = 'runner_pid';
    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: 'Runner 进程身份')]
    public const schema_fields_RUNNER_IDENTITY = 'runner_identity';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Runner 预占标识')]
    public const schema_fields_RUNNER_ID = 'runner_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Runner 启动标识')]
    public const schema_fields_RUNNER_LAUNCH_ID = 'runner_launch_id';
    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: 'Runner 受管进程名')]
    public const schema_fields_RUNNER_PROCESS_NAME = 'runner_process_name';
    #[Col(type: 'text', nullable: true, comment: 'Runner 已验证命令行')]
    public const schema_fields_RUNNER_LIVE_COMMAND = 'runner_live_command';
    #[Col(type: 'datetime', nullable: true, comment: '执行租约到期时间')]
    public const schema_fields_EXECUTION_LEASE_UNTIL = 'execution_lease_until';
    #[Col(type: 'datetime', nullable: true, comment: 'Runner 心跳时间')]
    public const schema_fields_HEARTBEAT_AT = 'heartbeat_at';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: 'Runner 预占令牌已释放')]
    public const schema_fields_RUNNER_LEASE_RELEASED = 'runner_lease_released';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '防护代际')]
    public const schema_fields_FENCING_GENERATION = 'fencing_generation';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '当前尝试次数')]
    public const schema_fields_ATTEMPT = 'attempt';
    #[Col(type: 'int', length: 11, nullable: false, default: 4, comment: '最大执行尝试次数（首次加恢复）')]
    public const schema_fields_MAX_ATTEMPTS = 'max_attempts';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '当前检查点版本')]
    public const schema_fields_CURRENT_CHECKPOINT_VERSION = 'current_checkpoint_version';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '最新事件序号')]
    public const schema_fields_LATEST_EVENT_SEQUENCE = 'latest_event_sequence';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '终态事件序号')]
    public const schema_fields_TERMINAL_EVENT_SEQUENCE = 'terminal_event_sequence';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '持久事件数量')]
    public const schema_fields_EVENT_COUNT = 'event_count';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '持久事件载荷总字节')]
    public const schema_fields_EVENT_PAYLOAD_BYTES = 'event_payload_bytes';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '已压缩事件边界')]
    public const schema_fields_COMPACTED_BEFORE_SEQUENCE = 'compacted_before_sequence';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '取消意图标识')]
    public const schema_fields_CANCEL_INTENT_ID = 'cancel_intent_id';
    #[Col(type: 'text', nullable: true, comment: '取消原因')]
    public const schema_fields_CANCEL_REASON = 'cancel_reason';
    #[Col(type: 'datetime', nullable: true, comment: '取消请求时间')]
    public const schema_fields_CANCEL_REQUESTED_AT = 'cancel_requested_at';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否因恢复请求协作停止')]
    public const schema_fields_RECOVERY_STOP_REQUESTED = 'recovery_stop_requested';
    #[Col(type: 'datetime', nullable: true, comment: '协作停止截止时间')]
    public const schema_fields_STOP_DEADLINE_AT = 'stop_deadline_at';
    #[Col(type: 'datetime', nullable: true, comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col(type: 'datetime', nullable: true, comment: '终态保留截止时间')]
    public const schema_fields_RETAIN_UNTIL = 'retain_until';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_TASK_ID, self::schema_fields_STATUS, self::schema_fields_RETAIN_UNTIL];

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
