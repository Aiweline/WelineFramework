<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 项目开发轨迹模型（append-only）
 *
 * 项目维度的事件流：派单/完成/超时/汇报/巡检/反思/SOP，
 * 是工时分析、巡检判断与 RAG 知识来源的统一落点。
 */
#[Table(comment: '智能体项目轨迹表')]
#[Index(name: 'idx_traj_project', columns: ['project_id', 'created_time'])]
#[Index(name: 'idx_traj_kind', columns: ['kind'])]
#[Index(name: 'idx_traj_ref', columns: ['ref_type', 'ref_id'])]
class AgentTrajectory extends Model
{
    public const schema_table = 'weline_agent_trajectory';
    public const schema_primary_key = 'trajectory_id';

    public array $_unit_primary_keys = ['trajectory_id'];
    public array $_index_sort_keys = ['trajectory_id', 'project_id'];

    public const KIND_TASK_ASSIGNED = 'task_assigned';
    public const KIND_TASK_DONE = 'task_done';
    public const KIND_OVERTIME = 'overtime';
    public const KIND_REPORT = 'report';
    public const KIND_INSPECTION = 'inspection';
    public const KIND_REFLECTION = 'reflection';
    public const KIND_SOP = 'sop';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '轨迹ID')]
    public const schema_fields_TRAJECTORY_ID = 'trajectory_id';

    #[Col('int', nullable: false, comment: '所属项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 32, nullable: false, comment: '事件类型')]
    public const schema_fields_KIND = 'kind';

    #[Col('varchar', 32, comment: '关联类型：task/flow_run/node_run/turn/worklog/patrol')]
    public const schema_fields_REF_TYPE = 'ref_type';

    #[Col('int', comment: '关联记录ID')]
    public const schema_fields_REF_ID = 'ref_id';

    #[Col('int', comment: '行为角色ID')]
    public const schema_fields_ACTOR_ROLE_ID = 'actor_role_id';

    #[Col('varchar', 64, comment: '行为智能体代码')]
    public const schema_fields_ACTOR_AGENT_CODE = 'actor_agent_code';

    #[Col('varchar', 255, nullable: false, comment: '轨迹摘要')]
    public const schema_fields_SUMMARY = 'summary';

    #[Col('mediumtext', comment: '扩展数据 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    public function save_before(): void
    {
        parent::save_before();
        if (!$this->getId() && !$this->getData(self::schema_fields_CREATED_TIME)) {
            $this->setData(self::schema_fields_CREATED_TIME, time());
        }

        $payload = $this->getData(self::schema_fields_PAYLOAD_JSON);
        if (is_array($payload)) {
            $this->setData(self::schema_fields_PAYLOAD_JSON, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
