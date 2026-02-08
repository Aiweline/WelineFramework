<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI 智能体模型
 * 
 * 功能：
 * - 管理智能体注册信息
 * - 存储智能体元数据（名称、描述、场景、版本等）
 * - 支持智能体的启用/禁用
 * - 按场景查询可用智能体
 */
class AiAgent extends \Weline\Framework\Database\Model
{
    // 框架自动推导表名：AiAgent → ai_agent

    public const fields_ID = 'id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_VERSION = 'version';
    public const fields_CLASS_NAME = 'class_name';
    public const fields_FILE_PATH = 'file_path';
    public const fields_SCENARIOS = 'scenarios';           // JSON: 支持的场景码列表
    public const fields_TOOLS_COUNT = 'tools_count';       // 工具数量
    public const fields_MAX_ITERATIONS = 'max_iterations'; // 最大循环轮次
    public const fields_MODULE = 'module';                 // 来源模块
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    /**
     * @var array 主键字段
     */
    public array $_unit_primary_keys = [self::fields_ID];

    /**
     * @var array 索引排序字段
     */
    public array $_index_sort_keys = [self::fields_CREATED_TIME];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            $setup->dropTable();
        }

        $setup->createTable(__('AI 智能体注册表'))
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn(self::fields_CODE, TableInterface::column_type_VARCHAR, 255, 'not null unique', __('智能体代码'))
            ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', __('智能体名称'))
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', __('智能体描述'))
            ->addColumn(self::fields_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null', __('版本'))
            ->addColumn(self::fields_CLASS_NAME, TableInterface::column_type_VARCHAR, 500, 'not null', __('类名'))
            ->addColumn(self::fields_FILE_PATH, TableInterface::column_type_VARCHAR, 500, 'null', __('文件路径'))
            ->addColumn(self::fields_SCENARIOS, TableInterface::column_type_TEXT, null, 'null', __('支持的场景码 JSON'))
            ->addColumn(self::fields_TOOLS_COUNT, TableInterface::column_type_INTEGER, null, 'default 0', __('工具数量'))
            ->addColumn(self::fields_MAX_ITERATIONS, TableInterface::column_type_INTEGER, null, 'default 5', __('最大循环轮次'))
            ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 255, 'null', __('来源模块'))
            ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'default 1', __('是否激活'))
            ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', __('创建时间'))
            ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', __('更新时间'))
            ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_CODE, __('智能体代码索引'))
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, __('激活状态索引'))
            ->addIndex(TableInterface::index_type_KEY, 'idx_module', self::fields_MODULE, __('来源模块索引'))
            ->create();
    }

    /**
     * 获取支持的场景列表
     */
    public function getScenarios(): array
    {
        $scenarios = $this->getData(self::fields_SCENARIOS);
        if (is_string($scenarios)) {
            $decoded = json_decode($scenarios, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($scenarios) ? $scenarios : [];
    }

    /**
     * 检查是否支持指定场景
     */
    public function supportsScenario(string $scenarioCode): bool
    {
        return in_array($scenarioCode, $this->getScenarios(), true);
    }

    /**
     * 保存前处理
     */
    public function beforeSave(): self
    {
        parent::beforeSave();

        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);

        return $this;
    }
}
