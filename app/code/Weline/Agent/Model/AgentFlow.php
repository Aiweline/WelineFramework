<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 可视化角色流程定义模型
 *
 * graph_json 内嵌节点与边：节点 = 角色 + 可选提示词覆盖；
 * v1 仅拓扑序串行执行，不支持条件分支。
 */
#[Table(comment: '智能体角色流程表')]
#[Index(name: 'idx_flow_project', columns: ['project_id'])]
#[Index(name: 'idx_flow_status', columns: ['status'])]
class AgentFlow extends Model
{
    public const schema_table = 'weline_agent_flow';
    public const schema_primary_key = 'flow_id';

    public array $_unit_primary_keys = ['flow_id'];
    public array $_index_sort_keys = ['flow_id', 'project_id', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '流程ID')]
    public const schema_fields_FLOW_ID = 'flow_id';

    #[Col('int', nullable: false, comment: '所属项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 191, nullable: false, comment: '流程名称')]
    public const schema_fields_NAME = 'name';

    #[Col('text', comment: '流程描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('mediumtext', comment: '画布图 JSON：nodes/edges/entry_node_key')]
    public const schema_fields_GRAPH_JSON = 'graph_json';

    #[Col('varchar', 16, nullable: false, default: 'draft', comment: 'draft/active/disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', nullable: false, default: 1, comment: '版本号（每次保存 +1）')]
    public const schema_fields_VERSION = 'version';

    #[Col('int', comment: '创建人 admin id')]
    public const schema_fields_CREATED_BY = 'created_by';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const MAX_NODES = 20;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_FLOW_ID;
    }

    /** @return array{nodes?:list<array<string,mixed>>,edges?:list<array<string,mixed>>,entry_node_key?:?string} */
    public function getGraph(): array
    {
        $raw = $this->getData(self::schema_fields_GRAPH_JSON);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /** @param array<string,mixed> $graph */
    public function setGraph(array $graph): self
    {
        return $this->setData(self::schema_fields_GRAPH_JSON, json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $now);

        $graph = $this->getData(self::schema_fields_GRAPH_JSON);
        if (is_array($graph)) {
            $this->setData(self::schema_fields_GRAPH_JSON, json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

    }
}
