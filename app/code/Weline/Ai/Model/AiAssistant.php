<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Assistant Entity
 * 
 * @package Weline_Ai
 */
class AiAssistant extends Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_PROMPT = 'prompt';
    public const fields_DESCRIPTION = 'description';
    public const fields_MODEL_CONFIG = 'model_config';
    public const fields_MCP_CONFIG = 'mcp_config';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';
    
    public array $_unit_primary_keys = ['id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
        // 表名由框架自动推导：AiAssistant -> ai_assistant
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('AI助手表')
                ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '助手名称')
                ->addColumn('model_code', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '使用的AI模型代码')
                ->addColumn('prompt', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', '系统提示词')
                ->addColumn('description', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '描述')
                ->addColumn('mcp_config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', 'MCP配置JSON')
                ->addColumn('is_active', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否激活')
                ->addColumn('created_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '创建时间（时间戳）')
                ->addColumn('updated_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '更新时间（时间戳）')
                ->addIndex('name', '', '', 'idx_name')
                ->addIndex('model_code', '', '', 'idx_model_code')
                ->addIndex('is_active', '', '', 'idx_is_active')
                ->create();
        }
    }

    public function isActive(): bool
    {
        return $this->getData('status') === self::STATUS_ACTIVE;
    }

    public function getConfig(): array
    {
        $config = $this->getData('config');
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    public function incrementUsageCount(): void
    {
        $this->setData('usage_count', $this->getData('usage_count') + 1);
    }

    public function validate(): bool
    {
        if (empty($this->getData('name'))) {
            throw new \InvalidArgumentException('Assistant name is required');
        }

        // 支持prompt或prompt_template字段
        if (empty($this->getData('prompt')) && empty($this->getData('prompt_template'))) {
            throw new \InvalidArgumentException('Prompt is required');
        }

        // 支持model_code或model_id字段
        if (empty($this->getData('model_code')) && empty($this->getData('model_id'))) {
            throw new \InvalidArgumentException('Model is required');
        }

        // tenant_id为可选字段，如果为空则不验证

        return true;
    }

    public function beforeSave(): self
    {
        $this->validate();
        
        // 处理mcp_config的JSON编码
        if (is_array($this->getData('mcp_config'))) {
            $this->setData('mcp_config', json_encode($this->getData('mcp_config')));
        }
        
        // 兼容处理：如果提供了prompt但没有prompt_template，则复制
        if (!empty($this->getData('prompt')) && empty($this->getData('prompt_template'))) {
            $this->setData('prompt_template', $this->getData('prompt'));
        }
        
        // 兼容处理：如果提供了config但没有mcp_config，则使用config
        if (is_array($this->getData('config'))) {
            $this->setData('config', json_encode($this->getData('config')));
        }

        return parent::beforeSave();
    }
}
