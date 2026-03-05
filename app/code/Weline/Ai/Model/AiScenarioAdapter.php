<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */
namespace Weline\Ai\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI场景适配器模型
 *
 * 功能：
 * - 管理场景适配器信息
 * - 存储适配器配置和元数据
 * - 支持适配器的启用/禁用
 * - 提供适配器查询和管理接口
 */
#[Table(comment: 'AI场景适配器')]
#[Index(name: 'idx_code', columns: ['code'], comment: '适配器代码索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '激活状态索引')]
class AiScenarioAdapter extends \Weline\Framework\Database\Model
{
    public const schema_table = 'ai_scenario_adapter';
    public const schema_primary_key = 'id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: '适配器代码')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '适配器名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '适配器描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '适配器版本')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'varchar', length: 500, nullable: false, comment: '适配器类名')]
    public const schema_fields_CLASS_NAME = 'class_name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '适配器文件路径（相对根目录）')]
    public const schema_fields_FILE_PATH = 'file_path';
    #[Col(type: 'text', nullable: true, comment: '支持的模型类型JSON')]
    public const schema_fields_SUPPORTED_MODELS = 'supported_models';
    #[Col(type: 'text', nullable: true, comment: '参数模板JSON')]
    public const schema_fields_PARAM_TEMPLATE = 'param_template';
    #[Col(type: 'text', nullable: true, comment: '使用示例JSON')]
    public const schema_fields_EXAMPLES = 'examples';
    #[Col(type: 'smallint', length: 1, nullable: true, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '默认模型代码')]
    public const schema_fields_DEFAULT_MODEL = 'default_model';
    #[Col(type: 'int', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';
    #[Col(type: 'int', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';
    /** @var array 主键字段 */
    public array $_unit_primary_keys = [self::schema_fields_ID];
    /** @var array 索引排序字段 */
    public array $_index_sort_keys = [self::schema_fields_CREATED_TIME];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    /**
     * 获取支持的模型类型
     *
     * @return array
     */
    public function getSupportedModels(): array
    {
        $supportedModels = $this->getData(self::schema_fields_SUPPORTED_MODELS);
        return $supportedModels ? json_decode($supportedModels, true) : [];
    }
    /**
     * 设置支持的模型类型
     *
     * @param array $models
     * @return $this
     */
    public function setSupportedModels(array $models): self
    {
        $this->setData(self::schema_fields_SUPPORTED_MODELS, json_encode($models));
        return $this;
    }
    /**
     * 获取参数模板
     *
     * @return array
     */
    public function getParamTemplate(): array
    {
        $template = $this->getData(self::schema_fields_PARAM_TEMPLATE);
        return $template ? json_decode($template, true) : [];
    }
    /**
     * 设置参数模板
     *
     * @param array $template
     * @return $this
     */
    public function setParamTemplate(array $template): self
    {
        $this->setData(self::schema_fields_PARAM_TEMPLATE, json_encode($template));
        return $this;
    }
    /**
     * 获取使用示例
     *
     * @return array
     */
    public function getExamples(): array
    {
        $examples = $this->getData(self::schema_fields_EXAMPLES);
        return $examples ? json_decode($examples, true) : [];
    }
    /**
     * 设置使用示例
     *
     * @param array $examples
     * @return $this
     */
    public function setExamples(array $examples): self
    {
        $this->setData(self::schema_fields_EXAMPLES, json_encode($examples));
        return $this;
    }
    /**
     * 检查是否激活
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }
    /**
     * 激活适配器
     *
     * @return $this
     */
    public function activate(): self
    {
        $this->setData(self::schema_fields_IS_ACTIVE, 1);
        return $this;
    }
    /**
     * 停用适配器
     *
     * @return $this
     */
    public function deactivate(): self
    {
        $this->setData(self::schema_fields_IS_ACTIVE, 0);
        return $this;
    }
    /**
     * 保存前处理
     *
     * @return $this
     */
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
