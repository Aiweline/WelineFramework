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

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI助手提示词模板模型
 * 
 * 功能：
 * - 管理助手提示词模板
 * - 支持模板分类和标签
 * - 提供模板变量替换
 * - 支持模板版本管理
 */
class AiAssistantPromptTemplate extends \Weline\Framework\Database\Model
{
    public const table = 'ai_assistant_prompt_template';
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_CATEGORY = 'category';
    public const fields_TAGS = 'tags';
    public const fields_TEMPLATE_CONTENT = 'template_content';
    public const fields_VARIABLES = 'variables'; // JSON
    public const fields_LANGUAGE = 'language';
    public const fields_VERSION = 'version';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_RATING = 'rating';
    public const fields_CREATED_BY = 'created_by';
    public const fields_UPDATED_BY = 'updated_by';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

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
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '模板名称')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', '模板描述')
                ->addColumn(self::fields_CATEGORY, TableInterface::column_type_VARCHAR, 100, 'null', '模板分类')
                ->addColumn(self::fields_TAGS, TableInterface::column_type_VARCHAR, 500, 'null', '标签（逗号分隔）')
                ->addColumn(self::fields_TEMPLATE_CONTENT, TableInterface::column_type_TEXT, null, 'not null', '模板内容')
                ->addColumn(self::fields_VARIABLES, TableInterface::column_type_TEXT, null, 'null', '模板变量JSON')
                ->addColumn(self::fields_LANGUAGE, TableInterface::column_type_VARCHAR, 10, 'default "zh_Hans_CN"', '语言代码')
                ->addColumn(self::fields_VERSION, TableInterface::column_type_VARCHAR, 50, 'default "1.0.0"', '模板版本')
                ->addColumn(self::fields_IS_DEFAULT, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否默认模板')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'default 1', '是否激活')
                ->addColumn(self::fields_USAGE_COUNT, TableInterface::column_type_INTEGER, null, 'default 0', '使用次数')
                ->addColumn(self::fields_RATING, TableInterface::column_type_DECIMAL, '3,2', 'default 0.00', '评分（0-5）')
                ->addColumn(self::fields_CREATED_BY, TableInterface::column_type_INTEGER, null, 'null', '创建人ID')
                ->addColumn(self::fields_UPDATED_BY, TableInterface::column_type_INTEGER, null, 'null', '更新人ID')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_category', self::fields_CATEGORY, '分类索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_language', self::fields_LANGUAGE, '语言索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->create();
        }
    }

    /**
     * 获取模板变量
     * 
     * @return array
     */
    public function getVariables(): array
    {
        $variables = $this->getData(self::fields_VARIABLES);
        return $variables ? json_decode($variables, true) : [];
    }

    /**
     * 设置模板变量
     * 
     * @param array $variables
     * @return $this
     */
    public function setVariables(array $variables): self
    {
        $this->setData(self::fields_VARIABLES, json_encode($variables));
        return $this;
    }

    /**
     * 渲染模板（替换变量）
     * 
     * @param array $data
     * @return string
     */
    public function render(array $data = []): string
    {
        $template = $this->getData(self::fields_TEMPLATE_CONTENT);
        
        if (empty($template)) {
            return '';
        }

        // 替换变量 {{variable_name}}
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }

        return $template;
    }

    /**
     * 增加使用次数
     * 
     * @return $this
     */
    public function incrementUsage(): self
    {
        $this->setData(self::fields_USAGE_COUNT, $this->getData(self::fields_USAGE_COUNT) + 1);
        return $this;
    }

    /**
     * 更新评分
     * 
     * @param float $rating
     * @return $this
     */
    public function updateRating(float $rating): self
    {
        if ($rating < 0) {
            $rating = 0;
        } elseif ($rating > 5) {
            $rating = 5;
        }
        
        $this->setData(self::fields_RATING, $rating);
        return $this;
    }

    /**
     * 检查是否默认模板
     * 
     * @return bool
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
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

