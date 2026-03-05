<?php
declare(strict_types=1);
namespace Weline\Ai\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI 智能体模型
 *
 * 功能：
 * - 管理智能体注册信息
 * - 存储智能体元数据（名称、描述、场景、版本等）
 * - 支持智能体的启用/禁用
 * - 按场景查询可用智能体
 */
#[Table(comment: 'AI 智能体注册表')]
#[Index(name: 'idx_code', columns: ['code'], comment: '智能体代码索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '激活状态索引')]
#[Index(name: 'idx_module', columns: ['module'], comment: '来源模块索引')]
class AiAgent extends Model
{
    public const schema_table = 'weline_ai_ai_agent';
    public const schema_primary_key = 'id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 255, nullable: false, unique: true, comment: '智能体代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 255, nullable: false, comment: '智能体名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '智能体描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 50, nullable: false, comment: '版本')]
    public const schema_fields_VERSION = 'version';
    #[Col('varchar', 500, nullable: false, comment: '类名')]
    public const schema_fields_CLASS_NAME = 'class_name';
    #[Col('varchar', 500, comment: '文件路径')]
    public const schema_fields_FILE_PATH = 'file_path';
    #[Col('text', comment: '支持的场景码 JSON')]
    public const schema_fields_SCENARIOS = 'scenarios';
    #[Col('int', null, default: 0, comment: '工具数量')]
    public const schema_fields_TOOLS_COUNT = 'tools_count';
    #[Col('int', null, default: 5, comment: '最大循环轮次')]
    public const schema_fields_MAX_ITERATIONS = 'max_iterations';
    #[Col('varchar', 255, comment: '来源模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('smallint', 1, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';
    #[Col('int', null, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['created_time'];
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
public function getScenarios(): array
    {
        $scenarios = $this->getData(self::schema_fields_SCENARIOS);
        if (is_string($scenarios)) {
            $decoded = json_decode($scenarios, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($scenarios) ? $scenarios : [];
    }
    public function supportsScenario(string $scenarioCode): bool
    {
        return in_array($scenarioCode, $this->getScenarios(), true);
    }
    public function beforeSave(): self
    {
        parent::beforeSave();
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $currentTime);
        return $this;
    }
}
