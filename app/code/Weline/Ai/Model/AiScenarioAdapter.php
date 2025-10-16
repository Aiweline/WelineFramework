<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI场景适配器模型
 * 
 * 功能：
 * - 管理场景适配器信息
 * - 存储适配器配置和元数据
 * - 支持适配器的启用/禁用
 * - 提供适配器查询和管理接口
 */
class AiScenarioAdapter extends \Weline\Framework\Database\Model
{
    // 框架自动推导表名：AiScenarioAdapter → ai_scenario_adapter
    // 禁止声明 protected $_table，让ORM自动推导
    
    public const fields_ID = 'id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_VERSION = 'version';
    public const fields_CLASS_NAME = 'class_name';
    public const fields_SUPPORTED_MODELS = 'supported_models'; // JSON
    public const fields_PARAM_TEMPLATE = 'param_template'; // JSON
    public const fields_EXAMPLES = 'examples'; // JSON
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
        // 表名和主键已在属性声明时由框架自动推导
    }

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_CODE, TableInterface::column_type_VARCHAR, 255, 'not null unique', '适配器代码')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '适配器名称')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', '适配器描述')
                ->addColumn(self::fields_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null', '适配器版本')
                ->addColumn(self::fields_CLASS_NAME, TableInterface::column_type_VARCHAR, 500, 'not null', '适配器类名')
                ->addColumn(self::fields_SUPPORTED_MODELS, TableInterface::column_type_TEXT, null, 'null', '支持的模型类型JSON')
                ->addColumn(self::fields_PARAM_TEMPLATE, TableInterface::column_type_TEXT, null, 'null', '参数模板JSON')
                ->addColumn(self::fields_EXAMPLES, TableInterface::column_type_TEXT, null, 'null', '使用示例JSON')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'default 1', '是否激活')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_CODE, '适配器代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->create();
        }
    }

    /**
     * 获取支持的模型类型
     * 
     * @return array
     */
    public function getSupportedModels(): array
    {
        $supportedModels = $this->getData(self::fields_SUPPORTED_MODELS);
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
        $this->setData(self::fields_SUPPORTED_MODELS, json_encode($models));
        return $this;
    }

    /**
     * 获取参数模板
     * 
     * @return array
     */
    public function getParamTemplate(): array
    {
        $template = $this->getData(self::fields_PARAM_TEMPLATE);
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
        $this->setData(self::fields_PARAM_TEMPLATE, json_encode($template));
        return $this;
    }

    /**
     * 获取使用示例
     * 
     * @return array
     */
    public function getExamples(): array
    {
        $examples = $this->getData(self::fields_EXAMPLES);
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
        $this->setData(self::fields_EXAMPLES, json_encode($examples));
        return $this;
    }

    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 激活适配器
     * 
     * @return $this
     */
    public function activate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 1);
        return $this;
    }

    /**
     * 停用适配器
     * 
     * @return $this
     */
    public function deactivate(): self
    {
        $this->setData(self::fields_IS_ACTIVE, 0);
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
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
