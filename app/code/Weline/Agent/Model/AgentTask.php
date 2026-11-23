<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 智能体间派单/审批任务模型
 *
 * project_id 必填：跨项目派单在服务层拒绝；
 * require_approval=1 时完成前进入 awaiting_approval。
 */
#[Table(comment: '智能体派单任务表')]
#[Index(name: 'idx_agent_task_project', columns: ['project_id'])]
#[Index(name: 'idx_agent_task_status', columns: ['status'])]
#[Index(name: 'idx_agent_task_session', columns: ['session_id'])]
#[Index(name: 'idx_agent_task_idem', columns: ['idempotency_key'], type: 'UNIQUE')]
class AgentTask extends Model
{
    public const schema_table = 'weline_agent_task';
    public const schema_primary_key = 'task_id';

    public array $_unit_primary_keys = ['task_id'];
    public array $_index_sort_keys = ['task_id', 'project_id', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '任务ID')]
    public const schema_fields_TASK_ID = 'task_id';

    #[Col('int', nullable: false, comment: '所属项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('int', comment: '来源会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('int', comment: '来源流程运行ID')]
    public const schema_fields_FLOW_RUN_ID = 'flow_run_id';

    #[Col('int', comment: '被派角色ID')]
    public const schema_fields_ASSIGNEE_ROLE_ID = 'assignee_role_id';

    #[Col('varchar', 64, comment: '被派智能体代码')]
    public const schema_fields_ASSIGNEE_AGENT_CODE = 'assignee_agent_code';

    #[Col('mediumtext', nullable: false, comment: '任务提示词')]
    public const schema_fields_PROMPT = 'prompt';

    #[Col('int', 1, default: 0, comment: '是否需要审批')]
    public const schema_fields_REQUIRE_APPROVAL = 'require_approval';

    #[Col('varchar', 16, nullable: false, default: 'queued', comment: 'queued/running/awaiting_approval/done/failed/cancelled')]
    public const schema_fields_STATUS = 'status';

    #[Col('mediumtext', comment: '结果 JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';

    #[Col('varchar', 64, comment: '错误信息')]
    public const schema_fields_ERROR = 'error';

    #[Col('varchar', 128, nullable: false, comment: '幂等键')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 16, nullable: false, default: 'system', comment: '创建者类型：admin/customer/system')]
    public const schema_fields_CREATED_BY_TYPE = 'created_by_type';

    #[Col('varchar', 64, comment: '创建者标识')]
    public const schema_fields_CREATED_BY_ID = 'created_by_id';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_TIME = 'started_time';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_TIME = 'finished_time';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_TASK_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
        }

        $result = $this->getData(self::schema_fields_RESULT_JSON);
        if (is_array($result)) {
            $this->setData(self::schema_fields_RESULT_JSON, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

    }
}
