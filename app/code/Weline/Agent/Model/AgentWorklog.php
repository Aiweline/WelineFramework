<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 智能体工时记录模型
 *
 * 记录每个角色/任务的预期与实际工时、超时原因，汇入项目开发轨迹。
 */
#[Table(comment: '智能体工时记录表')]
#[Index(name: 'idx_worklog_project', columns: ['project_id'])]
#[Index(name: 'idx_worklog_role', columns: ['role_id'])]
#[Index(name: 'idx_worklog_ref', columns: ['ref_type', 'ref_id'])]
#[Index(name: 'idx_worklog_status', columns: ['status'])]
class AgentWorklog extends Model
{
    public const schema_table = 'weline_agent_worklog';
    public const schema_primary_key = 'worklog_id';

    public array $_unit_primary_keys = ['worklog_id'];
    public array $_index_sort_keys = ['worklog_id', 'project_id', 'role_id'];

    public const REF_TASK = 'task';
    public const REF_FLOW_RUN = 'flow_run';
    public const REF_NODE_RUN = 'node_run';
    public const REF_TURN = 'turn';

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '工时记录ID')]
    public const schema_fields_WORKLOG_ID = 'worklog_id';

    #[Col('int', nullable: false, comment: '所属项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 32, nullable: false, comment: '关联类型：task/flow_run/node_run/turn')]
    public const schema_fields_REF_TYPE = 'ref_type';

    #[Col('int', nullable: false, comment: '关联记录ID')]
    public const schema_fields_REF_ID = 'ref_id';

    #[Col('int', comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('varchar', 64, comment: '智能体代码')]
    public const schema_fields_AGENT_CODE = 'agent_code';

    #[Col('int', comment: '预期工时（分钟）')]
    public const schema_fields_EXPECTED_MINUTES = 'expected_minutes';

    #[Col('int', comment: '实际工时（分钟）')]
    public const schema_fields_ACTUAL_MINUTES = 'actual_minutes';

    #[Col('int', comment: '超出预期的分钟数')]
    public const schema_fields_OVERTIME_MINUTES = 'overtime_minutes';

    #[Col('varchar', 255, comment: '超时/异常原因')]
    public const schema_fields_OVERTIME_REASON = 'overtime_reason';

    #[Col('varchar', 16, nullable: false, default: 'running', comment: 'running/done/failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_TIME = 'started_time';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_TIME = 'finished_time';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $now);
    }
}
