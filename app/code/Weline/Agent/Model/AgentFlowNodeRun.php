<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 流程节点执行记录模型
 */
#[Table(comment: '智能体流程节点运行表')]
#[Index(name: 'idx_node_run_run', columns: ['run_id'])]
#[Index(name: 'idx_node_run_flow_node', columns: ['flow_id', 'node_key'])]
#[Index(name: 'idx_node_run_status', columns: ['status'])]
class AgentFlowNodeRun extends Model
{
    public const schema_table = 'weline_agent_flow_node_run';
    public const schema_primary_key = 'node_run_id';

    public array $_unit_primary_keys = ['node_run_id'];
    public array $_index_sort_keys = ['node_run_id', 'run_id', 'node_key'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '节点运行ID')]
    public const schema_fields_NODE_RUN_ID = 'node_run_id';

    #[Col('int', nullable: false, comment: '所属运行ID')]
    public const schema_fields_RUN_ID = 'run_id';

    #[Col('int', nullable: false, comment: '流程ID')]
    public const schema_fields_FLOW_ID = 'flow_id';

    #[Col('varchar', 64, nullable: false, comment: '节点标识（graph_json node_key）')]
    public const schema_fields_NODE_KEY = 'node_key';

    #[Col('int', comment: '角色ID快照')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('varchar', 64, comment: '智能体代码快照')]
    public const schema_fields_AGENT_CODE = 'agent_code';

    #[Col('varchar', 128, comment: '模型代码快照')]
    public const schema_fields_MODEL_CODE = 'model_code';

    #[Col('mediumtext', comment: '节点提示词覆盖')]
    public const schema_fields_PROMPT_OVERRIDE = 'prompt_override';

    #[Col('mediumtext', comment: '上游输出摘要（截断）')]
    public const schema_fields_INPUT_SUMMARY = 'input_summary';

    #[Col('mediumtext', comment: '节点最终回复')]
    public const schema_fields_OUTPUT = 'output';

    #[Col('varchar', 16, nullable: false, default: 'pending', comment: 'pending/running/done/failed/skipped')]
    public const schema_fields_STATUS = 'status';

    #[Col('mediumtext', comment: '工具调用摘要 JSON')]
    public const schema_fields_TOOL_CALLS_JSON = 'tool_calls_json';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_TIME = 'started_time';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_TIME = 'finished_time';

    #[Col('int', comment: '耗时毫秒')]
    public const schema_fields_DURATION_MS = 'duration_ms';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_NODE_RUN_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
        }

        $toolCalls = $this->getData(self::schema_fields_TOOL_CALLS_JSON);
        if (is_array($toolCalls)) {
            $this->setData(self::schema_fields_TOOL_CALLS_JSON, json_encode($toolCalls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

    }
}
