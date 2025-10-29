<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Entity
 * 
 * Represents an AI model with its metadata, configuration, and capabilities.
 * Supports model copying functionality with origin tracking.
 * 
 * @package Weline_Ai
 */
class AiModel extends Model
{
    // 框架自动推导表名：AiModel → ai （遵循Constitution XI.A原则）
    // 禁止声明 protected $_table，让ORM自动推导
    public const table = 'ai_model';
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'supplier', 'model_code'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_SUPPLIER = 'supplier';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_NAME = 'name';
    public const fields_VERSION = 'version';
    public const fields_IS_COPY = 'is_copy';
    public const fields_ORIGIN_MODEL_ID = 'origin_model_id';
    public const fields_CONFIG = 'config';
    public const fields_CAPABILITIES = 'capabilities';
    public const fields_MAX_TOKENS = 'max_tokens';
    public const fields_COST_PER_TOKEN = 'cost_per_token';
    public const fields_TOKEN_PRICE_INPUT = 'token_price_input';  // 输入价格
    public const fields_TOKEN_PRICE_OUTPUT = 'token_price_output';  // 输出价格
    public const fields_PROXY_INFO = 'proxy_info';  // 代理信息
    public const fields_PROVIDER_CONFIG = 'provider_config';  // 提供商配置
    public const fields_STATUS = 'status';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_CONNECTION_TEST_STATUS = 'connection_test_status';  // 连通性测试状态
    public const fields_CONNECTION_TEST_TIME = 'connection_test_time';  // 连通性测试时间
    public const fields_SELF_CONFIG_TEST_STATUS = 'self_config_test_status';  // 自配置测试状态
    public const fields_SELF_CONFIG_TEST_TIME = 'self_config_test_time';  // 自配置测试时间
    public const fields_PROVIDER_TEST_STATUS = 'provider_test_status';  // 供应商测试状态
    public const fields_PROVIDER_TEST_TIME = 'provider_test_time';  // 供应商测试时间
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Model status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_MAINTENANCE = 'maintenance';

    /**
     * Initialize model
     *
     * @return void
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 表名和主键已在属性声明时初始化
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::fields_ID;
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
        // 添加 is_active 字段
        if (!$setup->hasField(self::fields_IS_ACTIVE)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_IS_ACTIVE . " INT(1) DEFAULT 1 NULL COMMENT '是否激活' AFTER status;
            ");
        }

        // 添加 is_default 字段
        if (!$setup->hasField(self::fields_IS_DEFAULT)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_IS_DEFAULT . " INT(1) DEFAULT 0 NULL COMMENT '是否默认' AFTER is_active;
            ");
        }

        // 添加 provider_config 字段
        if (!$setup->hasField(self::fields_PROVIDER_CONFIG)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_PROVIDER_CONFIG . " TEXT NULL COMMENT '提供商配置JSON' AFTER proxy_info;
            ");
        }

        // 添加 connection_test_status 字段
        if (!$setup->hasField(self::fields_CONNECTION_TEST_STATUS)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_CONNECTION_TEST_STATUS . " VARCHAR(20) DEFAULT 'pending' NULL COMMENT '连通性测试状态: pending/success/failed' AFTER is_default;
            ");
        }

        // 添加 connection_test_time 字段
        if (!$setup->hasField(self::fields_CONNECTION_TEST_TIME)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_CONNECTION_TEST_TIME . " INT NULL COMMENT '连通性测试时间戳' AFTER connection_test_status;
            ");
        }

        // 添加自配置测试字段
        if (!$setup->hasField(self::fields_SELF_CONFIG_TEST_STATUS)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_SELF_CONFIG_TEST_STATUS . " VARCHAR(20) DEFAULT 'pending' NULL COMMENT '自配置测试状态: pending/success/failed/no_config' AFTER connection_test_time;
            ");
        }

        if (!$setup->hasField(self::fields_SELF_CONFIG_TEST_TIME)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_SELF_CONFIG_TEST_TIME . " INT NULL COMMENT '自配置测试时间戳' AFTER self_config_test_status;
            ");
        }

        // 添加供应商测试字段
        if (!$setup->hasField(self::fields_PROVIDER_TEST_STATUS)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_PROVIDER_TEST_STATUS . " VARCHAR(20) DEFAULT 'pending' NULL COMMENT '供应商测试状态: pending/success/failed' AFTER self_config_test_time;
            ");
        }

        if (!$setup->hasField(self::fields_PROVIDER_TEST_TIME)) {
            $setup->query("
                ALTER TABLE {$this->getTable()}
                ADD " . self::fields_PROVIDER_TEST_TIME . " INT NULL COMMENT '供应商测试时间戳' AFTER provider_test_status;
            ");
        }

        // 调整唯一索引：将 唯一(supplier, model_code) 改为 唯一(model_code)
        try {
            // 尝试删除旧的联合唯一索引（若存在）
            $setup->query("ALTER TABLE {$this->getTable()} DROP INDEX idx_supplier_model_code;");
        } catch (\Exception $e) {
            // 索引不存在时忽略
        }
        try {
            // 创建基于 model_code 的唯一索引（若已存在则忽略异常）
            $setup->query("CREATE UNIQUE INDEX idx_model_code ON {$this->getTable()} (" . self::fields_MODEL_CODE . ");");
        } catch (\Exception $e) {
            // 已存在时忽略
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // [已完成] 2025-10-12 表结构已修复，字段名已统一为 supplier/model_code/name/version
        // 如需重建表，取消下方注释（仅限开发环境）
        // if ($setup->tableExist()) {
        //     $setup->dropTable();
        // }

        if ($setup->tableExist() === false) {
            $setup->createTable('AI模型表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_SUPPLIER, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '供应商')
                ->addColumn(self::fields_MODEL_CODE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
                ->addColumn(self::fields_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '模型名称')
                ->addColumn(self::fields_VERSION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '版本')
                ->addColumn(self::fields_IS_COPY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否复制')
                ->addColumn(self::fields_ORIGIN_MODEL_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '原始模型ID')
                ->addColumn(self::fields_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '配置JSON')
                ->addColumn(self::fields_CAPABILITIES, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '能力JSON')
                ->addColumn(self::fields_MAX_TOKENS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '最大Token数')
                ->addColumn(self::fields_COST_PER_TOKEN, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, '', '每Token成本')
                ->addColumn(self::fields_TOKEN_PRICE_INPUT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输入令牌价格（每1000个令牌）')
                ->addColumn(self::fields_TOKEN_PRICE_OUTPUT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输出令牌价格（每1000个令牌）')
                ->addColumn(self::fields_PROXY_INFO, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '代理配置信息JSON')
                ->addColumn(self::fields_PROVIDER_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '提供商配置JSON')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'active\'', '状态')
                ->addColumn(self::fields_IS_ACTIVE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否激活')
                ->addColumn(self::fields_IS_DEFAULT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认')
                // 首次安装即包含连通性测试字段
                ->addColumn(self::fields_CONNECTION_TEST_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, "default 'pending'", '连通性测试状态: pending/success/failed')
                ->addColumn(self::fields_CONNECTION_TEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '连通性测试时间戳')
                // 自配置测试字段
                ->addColumn(self::fields_SELF_CONFIG_TEST_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, "default 'pending'", '自配置测试状态: pending/success/failed/no_config')
                ->addColumn(self::fields_SELF_CONFIG_TEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '自配置测试时间戳')
                // 供应商测试字段
                ->addColumn(self::fields_PROVIDER_TEST_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, "default 'pending'", '供应商测试状态: pending/success/failed')
                ->addColumn(self::fields_PROVIDER_TEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '供应商测试时间戳')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(self::fields_MODEL_CODE, '', 'UNIQUE', 'idx_model_code')
                ->create();
        }
    }

    /**
     * Check if this is a copy model
     *
     * @return bool
     */
    public function isCopy(): bool
    {
        return (bool) $this->getData(self::fields_IS_COPY);
    }

    /**
     * Check if this is an original model
     *
     * @return bool
     */
    public function isOriginal(): bool
    {
        return !$this->isCopy();
    }

    /**
     * Get origin model ID (if this is a copy)
     *
     * @return int|null
     */
    public function getOriginModelId(): ?int
    {
        $originId = $this->getData(self::fields_ORIGIN_MODEL_ID);
        return $originId ? (int) $originId : null;
    }

    /**
     * Check if model can be deleted
     * Original models (is_copy=false) cannot be deleted
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->isCopy();
    }

    /**
     * Get model configuration as array
     *
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * Get model capabilities as array
     *
     * @return array
     */
    public function getCapabilities(): array
    {
        $capabilities = $this->getData(self::fields_CAPABILITIES);
        if (is_string($capabilities)) {
            $decoded = json_decode($capabilities, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * Check if model is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getIsActive();
    }

    /**
     * Check if model is default
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->getIsDefault();
    }

    /**
     * Validate model data before save
     *
     * @return bool
     */
    public function validate(): bool
    {
        // Original models must not have origin_model_id
        if (!$this->isCopy() && $this->getOriginModelId() !== null) {
            throw new \InvalidArgumentException(
                'Original models (is_copy=false) cannot have origin_model_id'
            );
        }

        // Copy models must have origin_model_id
        if ($this->isCopy() && $this->getOriginModelId() === null) {
            throw new \InvalidArgumentException(
                'Copy models (is_copy=true) must have origin_model_id'
            );
        }

        // Required fields
        if (empty($this->getData(self::fields_SUPPLIER))) {
            throw new \InvalidArgumentException('Supplier is required');
        }

        if (empty($this->getData(self::fields_MODEL_CODE))) {
            throw new \InvalidArgumentException('Model code is required');
        }

        if (empty($this->getData(self::fields_NAME))) {
            throw new \InvalidArgumentException('Name is required');
        }

        return true;
    }

    /**
     * Before save callback
     *
     * @return $this
     */
    public function beforeSave(): self
    {
        $this->validate();
        
        // Ensure JSON fields are properly encoded
        if (is_array($this->getData(self::fields_CONFIG))) {
            $this->setData(self::fields_CONFIG, json_encode($this->getData(self::fields_CONFIG), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (is_array($this->getData(self::fields_CAPABILITIES))) {
            $this->setData(self::fields_CAPABILITIES, json_encode($this->getData(self::fields_CAPABILITIES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (is_array($this->getData(self::fields_PROVIDER_CONFIG))) {
            $this->setData(self::fields_PROVIDER_CONFIG, json_encode($this->getData(self::fields_PROVIDER_CONFIG), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return parent::beforeSave();
    }

    /**
     * Before delete callback
     *
     * @return $this
     */
    public function beforeDelete(): self
    {
        if (!$this->canDelete()) {
            throw new \RuntimeException(
                'Cannot delete original model. Only copy models can be deleted.'
            );
        }

        return parent::beforeDelete();
    }

    // ============================================
    // Getter and Setter Methods
    // ============================================

    /**
     * Get supplier
     *
     * @return string
     */
    public function getSupplier(): string
    {
        return (string) $this->getData(self::fields_SUPPLIER);
    }

    /**
     * Set supplier
     *
     * @param string $supplier
     * @return $this
     */
    public function setSupplier(string $supplier): self
    {
        return $this->setData(self::fields_SUPPLIER, $supplier);
    }

    /**
     * Get model code
     *
     * @return string
     */
    public function getModelCode(): string
    {
        return (string) $this->getData(self::fields_MODEL_CODE);
    }

    /**
     * Set model code
     *
     * @param string $modelCode
     * @return $this
     */
    public function setModelCode(string $modelCode): self
    {
        return $this->setData(self::fields_MODEL_CODE, $modelCode);
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName(): string
    {
        return (string) $this->getData(self::fields_NAME);
    }

    /**
     * Set name
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self
    {
        return $this->setData(self::fields_NAME, $name);
    }

    /**
     * Get version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return (string) $this->getData(self::fields_VERSION);
    }

    /**
     * Set version
     *
     * @param string $version
     * @return $this
     */
    public function setVersion(string $version): self
    {
        return $this->setData(self::fields_VERSION, $version);
    }

    /**
     * Set is copy
     *
     * @param bool $isCopy
     * @return $this
     */
    public function setIsCopy(bool $isCopy): self
    {
        return $this->setData(self::fields_IS_COPY, (int) $isCopy);
    }

    /**
     * Set origin model ID
     *
     * @param int|null $originModelId
     * @return $this
     */
    public function setOriginModelId(?int $originModelId): self
    {
        return $this->setData(self::fields_ORIGIN_MODEL_ID, $originModelId);
    }

    /**
     * Set config
     *
     * @param array|string $config
     * @return $this
     */
    public function setConfig(array|string $config): self
    {
        return $this->setData(self::fields_CONFIG, $config);
    }

    /**
     * Set capabilities
     *
     * @param array|string $capabilities
     * @return $this
     */
    public function setCapabilities(array|string $capabilities): self
    {
        return $this->setData(self::fields_CAPABILITIES, $capabilities);
    }

    /**
     * Get max tokens
     *
     * @return int
     */
    public function getMaxTokens(): int
    {
        return (int) $this->getData(self::fields_MAX_TOKENS);
    }

    /**
     * Set max tokens
     *
     * @param int $maxTokens
     * @return $this
     */
    public function setMaxTokens(int $maxTokens): self
    {
        return $this->setData(self::fields_MAX_TOKENS, $maxTokens);
    }

    /**
     * Get cost per token
     *
     * @return float
     */
    public function getCostPerToken(): float
    {
        return (float) $this->getData(self::fields_COST_PER_TOKEN);
    }

    /**
     * Set cost per token
     *
     * @param float $cost
     * @return $this
     */
    public function setCostPerToken(float $cost): self
    {
        return $this->setData(self::fields_COST_PER_TOKEN, $cost);
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    /**
     * Set status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    /**
     * Get created at
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::fields_CREATED_AT);
    }

    /**
     * Get updated at
     *
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return (string) $this->getData(self::fields_UPDATED_AT);
    }

    /**
     * Get is active
     *
     * @return bool
     */
    public function getIsActive(): bool
    {
        return (bool) $this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * Set is active
     *
     * @param bool $isActive
     * @return $this
     */
    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::fields_IS_ACTIVE, (int) $isActive);
    }

    /**
     * Get is default
     *
     * @return bool
     */
    public function getIsDefault(): bool
    {
        return (bool) $this->getData(self::fields_IS_DEFAULT);
    }

    /**
     * Set is default
     *
     * @param bool $isDefault
     * @return $this
     */
    public function setIsDefault(bool $isDefault): self
    {
        return $this->setData(self::fields_IS_DEFAULT, (int) $isDefault);
    }

    // ============================================
    // 别名 Getter 方法（用于模板兼容）
    // ============================================

    /**
     * Get model name (alias for getName)
     *
     * @return string
     */
    public function getModelName(): string
    {
        return $this->getName();
    }

    /**
     * Get vendor (alias for getSupplier)
     *
     * @return string
     */
    public function getVendor(): string
    {
        return $this->getSupplier();
    }

    /**
     * Get model version (alias for getVersion)
     *
     * @return string
     */
    public function getModelVersion(): string
    {
        return $this->getVersion();
    }

    /**
     * Get config as JSON string
     *
     * @return string
     */
    public function getConfigJson(): string
    {
        $config = $this->getData(self::fields_CONFIG);
        if (is_array($config)) {
            return json_encode($config);
        }
        return (string) $config;
    }

    /**
     * Get provider configuration as array
     *
     * @return array
     */
    public function getProviderConfig(): array
    {
        $config = $this->getData(self::fields_PROVIDER_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * Set provider configuration
     *
     * @param array|string $config
     * @return $this
     */
    public function setProviderConfig(array|string $config): self
    {
        return $this->setData(self::fields_PROVIDER_CONFIG, $config);
    }

    /**
     * Get provider config as JSON string
     *
     * @return string
     */
    public function getProviderConfigJson(): string
    {
        $config = $this->getData(self::fields_PROVIDER_CONFIG);
        if (is_array($config)) {
            return json_encode($config);
        }
        return (string) $config;
    }

    /**
     * Get proxy info as JSON string or array
     *
     * @return string|array
     */
    public function getProxyInfo()
    {
        return $this->getData(self::fields_PROXY_INFO) ?? '';
    }

    /**
     * Get token price input
     *
     * @return float
     */
    public function getTokenPriceInput(): float
    {
        return (float) ($this->getData(self::fields_TOKEN_PRICE_INPUT) ?? 0);
    }

    /**
     * Get token price output
     *
     * @return float
     */
    public function getTokenPriceOutput(): float
    {
        return (float) ($this->getData(self::fields_TOKEN_PRICE_OUTPUT) ?? 0);
    }

    /**
     * Check if this is a copied model (alias for isCopy)
     *
     * @return bool
     */
    public function isCopied(): bool
    {
        return $this->isCopy();
    }
}
