<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 智能体巡检任务模型
 *
 * scope=node：流程节点级巡检（ref_id = flow_id:node_key，间隔来自节点 inspect_interval）
 * scope=project：项目级巡检（ref_id = project_id）
 * 探针为硬编码状态快照 hash；变化→AI 巡检；过期+连续3次无变化→深度巡检。
 */
#[Table(comment: '智能体巡检任务表')]
#[Index(name: 'idx_patrol_due', columns: ['enabled', 'next_check_at'])]
#[Index(name: 'idx_patrol_scope', columns: ['scope', 'ref_id'])]
class AgentPatrol extends Model
{
    public const schema_table = 'weline_agent_patrol';
    public const schema_primary_key = 'patrol_id';

    public array $_unit_primary_keys = ['patrol_id'];
    public array $_index_sort_keys = ['patrol_id', 'scope', 'ref_id'];

    public const SCOPE_NODE = 'node';
    public const SCOPE_PROJECT = 'project';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '巡检ID')]
    public const schema_fields_PATROL_ID = 'patrol_id';

    #[Col('varchar', 16, nullable: false, comment: 'node/project')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('varchar', 64, nullable: false, comment: '引用标识（flow_id:node_key 或 project_id）')]
    public const schema_fields_REF_ID = 'ref_id';

    #[Col('int', comment: '巡检角色ID（执行巡检的角色）')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('int', nullable: false, comment: '巡检间隔（秒）')]
    public const schema_fields_INTERVAL_SECONDS = 'interval_seconds';

    #[Col('varchar', 16, nullable: false, default: 'enabled', comment: 'enabled/disabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('int', comment: '预期完成时间（超过后进入深度巡检判定）')]
    public const schema_fields_EXPECT_BY = 'expect_by';

    #[Col('varchar', 64, comment: '上次状态快照 hash')]
    public const schema_fields_LAST_STATE_HASH = 'last_state_hash';

    #[Col('int', 11, default: 0, comment: '连续无变化次数')]
    public const schema_fields_UNCHANGED_COUNT = 'unchanged_count';

    #[Col('int', comment: '上次巡检时间')]
    public const schema_fields_LAST_CHECK_AT = 'last_check_at';

    #[Col('int', comment: '下次巡检时间')]
    public const schema_fields_NEXT_CHECK_AT = 'next_check_at';

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
