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
    // 字段常量 - 与数据库表字段对应
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_PROMPT_TEMPLATE = 'prompt_template';
    public const fields_MODEL_ID = 'model_id';
    public const fields_USER_ID = 'user_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_CONFIG = 'config';
    public const fields_IS_PUBLIC = 'is_public';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 市场相关字段
    public const fields_IS_RENTABLE = 'is_rentable';
    public const fields_CATEGORY = 'category';
    public const fields_RENTAL_PRICE = 'rental_price';
    public const fields_RENTAL_TYPE = 'rental_type';
    public const fields_RATING_AVERAGE = 'rating_average';
    public const fields_RENTAL_COUNT = 'rental_count';
    public const fields_AUDIT_STATUS = 'audit_status';
    
    // 向后兼容的字段别名
    public const fields_MODEL_CODE = 'model_code';
    public const fields_PROMPT = 'prompt';
    public const fields_MODEL_CONFIG = 'model_config';
    public const fields_MCP_CONFIG = 'mcp_config';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // API密钥和代理配置字段
    public const fields_API_KEY = 'api_key';
    public const fields_PROXY_CONFIG = 'proxy_config';
    
    // 场景适配器字段
    public const fields_ADAPTER_CODE = 'adapter_code';
    public const fields_ADAPTER_PARAMS = 'adapter_params';
    
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
        if (!$setup->tableExist()) {
            $setup->createTable('AI助手表')
                ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '助手名称')
                ->addColumn('description', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '描述')
                ->addColumn('prompt_template', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', '提示词模板')
                ->addColumn('model_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '关联的AI模型ID')
                ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '用户ID')
                ->addColumn('tenant_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
                ->addColumn('config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '配置JSON')
                ->addColumn('is_public', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否公开')
                ->addColumn('usage_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '使用次数')
                ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'active\'', '状态')
                // 市场相关字段
                ->addColumn('is_rentable', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否可租用')
                ->addColumn('category', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'other\'', '分类')
                ->addColumn('rental_price', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL . '(10,2)', 0, 'default 0.00', '租赁价格')
                ->addColumn('rental_type', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'per_use\'', '租赁类型')
                ->addColumn('rating_average', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL . '(3,2)', 0, 'default 0.00', '平均评分')
                ->addColumn('rental_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '租用次数')
                ->addColumn('audit_status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '审核状态')
                // API密钥和代理配置字段
                ->addColumn('api_key', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, '', 'API密钥')
                ->addColumn('proxy_config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '代理配置JSON')
                // 场景适配器字段
                ->addColumn('adapter_code', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'default\'', '场景适配器代码')
                ->addColumn('adapter_params', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '适配器参数JSON')
                ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_tenant_id', 'tenant_id')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_status', 'status')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_is_rentable', 'is_rentable')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_category', 'category')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_audit_status', 'audit_status')
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
        
        // 处理proxy_config的JSON编码
        if (is_array($this->getData('proxy_config'))) {
            $this->setData('proxy_config', json_encode($this->getData('proxy_config'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        
        // 处理adapter_params的JSON编码
        if (is_array($this->getData('adapter_params'))) {
            $this->setData('adapter_params', json_encode($this->getData('adapter_params'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
