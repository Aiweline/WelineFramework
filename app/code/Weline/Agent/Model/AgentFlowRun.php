<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 流程运行记录模型
 */
#[Table(comment: '智能体流程运行表')]
#[Index(name: 'idx_run_flow', columns: ['flow_id'])]
#[Index(name: 'idx_run_project', columns: ['project_id'])]
#[Index(name: 'idx_run_status', columns: ['status'])]
class AgentFlowRun extends Model
{
    public const schema_table = 'weline_agent_flow_run';
    public const schema_primary_key = 'run_id';

    public array $_unit_primary_keys = ['run_id'];
    public array $_index_sort_keys = ['run_id', 'flow_id', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '运行ID')]
    public const schema_fields_RUN_ID = 'run_id';

    #[Col('int', nullable: false, comment: '流程ID')]
    public const schema_fields_FLOW_ID = 'flow_id';

    #[Col('int', nullable: false, comment: '流程版本快照')]
    public const schema_fields_FLOW_VERSION = 'flow_version';

    #[Col('int', nullable: false, comment: '所属项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 64, comment: '关联 Resumable Task ID')]
    public const schema_fields_TASK_ID = 'task_id';

    #[Col('varchar', 16, nullable: false, default: 'queued', comment: 'queued/running/awaiting_approval/done/failed/cancelled')]
    public const schema_fields_STATUS = 'status';

    #[Col('mediumtext', comment: '用户原始需求')]
    public const schema_fields_INPUT_TEXT = 'input_text';

    #[Col('mediumtext', comment: '汇总结果 JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_TIME = 'started_time';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_TIME = 'finished_time';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

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
        return self::schema_fields_RUN_ID;
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
