<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI 智能体工具注册表')]
#[Index(name: 'uniq_agent_tool', columns: ['agent_code', 'tool_name'], type: 'UNIQUE', comment: '智能体工具唯一')]
#[Index(name: 'idx_agent_code', columns: ['agent_code'], comment: '智能体代码索引')]
#[Index(name: 'idx_tool_enabled', columns: ['is_enabled'], comment: '工具启用状态索引')]
class AiAgentTool extends Model
{
    public const schema_table = 'weline_ai_ai_agent_tool';
    public const schema_primary_key = 'id';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 255, nullable: false, comment: '智能体代码')]
    public const schema_fields_AGENT_CODE = 'agent_code';

    #[Col('varchar', 255, nullable: false, comment: '工具名称')]
    public const schema_fields_TOOL_NAME = 'tool_name';

    #[Col('text', nullable: true, comment: '源工具描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('text', nullable: true, comment: '后台描述覆盖')]
    public const schema_fields_DESCRIPTION_OVERRIDE = 'description_override';

    #[Col('longtext', nullable: true, comment: '参数 JSON Schema')]
    public const schema_fields_PARAMETERS_JSON = 'parameters_json';

    #[Col('varchar', 500, nullable: true, comment: '工具类名')]
    public const schema_fields_CLASS_NAME = 'class_name';

    #[Col('int', null, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('smallint', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';

    #[Col('smallint', 1, default: 1, comment: '扫描是否仍存在')]
    public const schema_fields_IS_PRESENT = 'is_present';

    #[Col('int', null, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', null, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['sort_order'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function save_before(): void
    {
        parent::save_before();
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $currentTime);
    }
}
