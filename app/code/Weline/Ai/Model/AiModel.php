<?php
declare(strict_types=1);
namespace Weline\Ai\Model;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Model Entity
 *
 * Represents an AI model with its metadata, configuration, and capabilities.
 * Supports model copying functionality with origin tracking.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI模型表')]
#[Index(name: 'idx_model_code', columns: ['model_code'], type: 'UNIQUE')]
class AiModel extends Model
{
    public const schema_table = 'ai_model';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'supplier', 'model_code'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '供应商')]
    public const schema_fields_SUPPLIER = 'supplier';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '模型名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '版本')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'int', length: 1, default: 0, comment: '是否复制')]
    public const schema_fields_IS_COPY = 'is_copy';
    #[Col(type: 'int', nullable: true, comment: '原始模型ID')]
    public const schema_fields_ORIGIN_MODEL_ID = 'origin_model_id';
    #[Col(type: 'text', nullable: true, comment: '配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col(type: 'text', nullable: true, comment: '能力JSON')]
    public const schema_fields_CAPABILITIES = 'capabilities';
    #[Col(type: 'int', nullable: true, comment: '最大Token数')]
    public const schema_fields_MAX_TOKENS = 'max_tokens';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: '每Token成本')]
    public const schema_fields_COST_PER_TOKEN = 'cost_per_token';
    #[Col(type: 'decimal', length: '10,6', default: 0, comment: '输入令牌价格（每1000个令牌）')]
    public const schema_fields_TOKEN_PRICE_INPUT = 'token_price_input';
    #[Col(type: 'decimal', length: '10,6', default: 0, comment: '输出令牌价格（每1000个令牌）')]
    public const schema_fields_TOKEN_PRICE_OUTPUT = 'token_price_output';
    #[Col(type: 'text', nullable: true, comment: '代理配置信息JSON')]
    public const schema_fields_PROXY_INFO = 'proxy_info';
    #[Col(type: 'text', nullable: true, comment: '提供商配置JSON')]
    public const schema_fields_PROVIDER_CONFIG = 'provider_config';
    #[Col(type: 'varchar', length: 20, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', length: 1, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', length: 1, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'varchar', length: 20, default: 'pending', comment: '连通性测试状态')]
    public const schema_fields_CONNECTION_TEST_STATUS = 'connection_test_status';
    #[Col(type: 'int', nullable: true, comment: '连通性测试时间戳')]
    public const schema_fields_CONNECTION_TEST_TIME = 'connection_test_time';
    #[Col(type: 'varchar', length: 20, default: 'pending', comment: '自配置测试状态')]
    public const schema_fields_SELF_CONFIG_TEST_STATUS = 'self_config_test_status';
    #[Col(type: 'int', nullable: true, comment: '自配置测试时间戳')]
    public const schema_fields_SELF_CONFIG_TEST_TIME = 'self_config_test_time';
    #[Col(type: 'varchar', length: 20, default: 'pending', comment: '供应商测试状态')]
    public const schema_fields_PROVIDER_TEST_STATUS = 'provider_test_status';
    #[Col(type: 'int', nullable: true, comment: '供应商测试时间戳')]
    public const schema_fields_PROVIDER_TEST_TIME = 'provider_test_time';
    #[Col(type: 'varchar', length: 20, default: 'remote', comment: '模型来源')]
    public const schema_fields_MODEL_SOURCE = 'model_source';
    #[Col(type: 'int', nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'int', nullable: true, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_REMOTE = 'remote';
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** Whether this model is a copy of another */
    public function isCopy(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_COPY);
    }
    public function isOriginal(): bool
    {
        return !$this->isCopy();
    }
    public function getOriginModelId(): ?int
    {
        $originId = $this->getData(self::schema_fields_ORIGIN_MODEL_ID);
        return $originId ? (int) $originId : null;
    }
    public function canDelete(): bool
    {
        return $this->isCopy();
    }
    public function isLocal(): bool
    {
        $source = $this->getData(self::schema_fields_MODEL_SOURCE);
        return $source === self::SOURCE_LOCAL;
    }
    public function isRemote(): bool
    {
        $source = $this->getData(self::schema_fields_MODEL_SOURCE);
        return $source === self::SOURCE_REMOTE || empty($source);
    }
    public function getModelSource(): string
    {
        $source = $this->getData(self::schema_fields_MODEL_SOURCE);
        return $source ?: self::SOURCE_REMOTE;
    }
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        $objId = spl_object_id($this);
        Env::log('ai_model_debug.log', sprintf(
            '[AiModel::getConfig] objId=%d, modelCode=%s, config_type=%s, api_key=%s',
            $objId,
            $this->getData(self::schema_fields_MODEL_CODE) ?? 'unknown',
            gettype($config),
            is_string($config)
                ? (($decoded = json_decode($config, true)) && isset($decoded['api_key'])
                    ? (empty($decoded['api_key']) ? '(empty)' : '...' . substr($decoded['api_key'], -4))
                    : '(not set)')
                : (is_array($config) && isset($config['api_key'])
                    ? (empty($config['api_key']) ? '(empty)' : '...' . substr($config['api_key'], -4))
                    : '(not set)')
        ), 'DEBUG');
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    public function getCapabilities(): array
    {
        $capabilities = $this->getData(self::schema_fields_CAPABILITIES);
        if (is_string($capabilities)) {
            $decoded = json_decode($capabilities, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($capabilities) ? $capabilities : [];
    }
    public function isActive(): bool
    {
        return $this->getIsActive();
    }
    public function isDefault(): bool
    {
        return $this->getIsDefault();
    }
    public function validate(): bool
    {
        if (!$this->isCopy() && $this->getOriginModelId() !== null) {
            throw new \InvalidArgumentException(
                'Original models (is_copy=false) cannot have origin_model_id'
            );
        }
        if ($this->isCopy() && $this->getOriginModelId() === null) {
            throw new \InvalidArgumentException(
                'Copy models (is_copy=true) must have origin_model_id'
            );
        }
        if (empty($this->getData(self::schema_fields_SUPPLIER))) {
            throw new \InvalidArgumentException('Supplier is required');
        }
        if (empty($this->getData(self::schema_fields_MODEL_CODE))) {
            throw new \InvalidArgumentException('Model code is required');
        }
        if (empty($this->getData(self::schema_fields_NAME))) {
            throw new \InvalidArgumentException('Name is required');
        }
        return true;
    }
    public function beforeSave(): self
    {
        $this->validate();
        if (is_array($this->getData(self::schema_fields_CONFIG))) {
            $this->setData(self::schema_fields_CONFIG, json_encode($this->getData(self::schema_fields_CONFIG), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (is_array($this->getData(self::schema_fields_CAPABILITIES))) {
            $this->setData(self::schema_fields_CAPABILITIES, json_encode($this->getData(self::schema_fields_CAPABILITIES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (is_array($this->getData(self::schema_fields_PROVIDER_CONFIG))) {
            $this->setData(self::schema_fields_PROVIDER_CONFIG, json_encode($this->getData(self::schema_fields_PROVIDER_CONFIG), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return parent::beforeSave();
    }
    public function beforeDelete(): self
    {
        if (!$this->canDelete()) {
            throw new \RuntimeException(
                'Cannot delete original model. Only copy models can be deleted.'
            );
        }
        return parent::beforeDelete();
    }
    public function getSupplier(): string
    {
        return (string) $this->getData(self::schema_fields_SUPPLIER);
    }
    public function setSupplier(string $supplier): self
    {
        return $this->setData(self::schema_fields_SUPPLIER, $supplier);
    }
    public function getModelCode(): string
    {
        return (string) $this->getData(self::schema_fields_MODEL_CODE);
    }
    public function setModelCode(string $modelCode): self
    {
        return $this->setData(self::schema_fields_MODEL_CODE, $modelCode);
    }
    public function getName(): string
    {
        return (string) $this->getData(self::schema_fields_NAME);
    }
    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
    public function getVersion(): string
    {
        return (string) $this->getData(self::schema_fields_VERSION);
    }
    public function setVersion(string $version): self
    {
        return $this->setData(self::schema_fields_VERSION, $version);
    }
    public function setIsCopy(bool $isCopy): self
    {
        return $this->setData(self::schema_fields_IS_COPY, (int) $isCopy);
    }
    public function setOriginModelId(?int $originModelId): self
    {
        return $this->setData(self::schema_fields_ORIGIN_MODEL_ID, $originModelId);
    }
    public function setConfig(array|string $config): self
    {
        return $this->setData(self::schema_fields_CONFIG, $config);
    }
    public function setCapabilities(array|string $capabilities): self
    {
        return $this->setData(self::schema_fields_CAPABILITIES, $capabilities);
    }
    public function getMaxTokens(): int
    {
        return (int) $this->getData(self::schema_fields_MAX_TOKENS);
    }
    public function setMaxTokens(int $maxTokens): self
    {
        return $this->setData(self::schema_fields_MAX_TOKENS, $maxTokens);
    }
    public function getCostPerToken(): float
    {
        return (float) $this->getData(self::schema_fields_COST_PER_TOKEN);
    }
    public function setCostPerToken(float $cost): self
    {
        return $this->setData(self::schema_fields_COST_PER_TOKEN, $cost);
    }
    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }
    public function setStatus(string $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
    public function getCreatedAt(): string
    {
        return (string) $this->getData(self::schema_fields_CREATED_AT);
    }
    public function getUpdatedAt(): string
    {
        return (string) $this->getData(self::schema_fields_UPDATED_AT);
    }
    public function getIsActive(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_ACTIVE);
    }
    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, (int) $isActive);
    }
    public function getIsDefault(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_DEFAULT);
    }
    public function setIsDefault(bool $isDefault): self
    {
        return $this->setData(self::schema_fields_IS_DEFAULT, (int) $isDefault);
    }
    public function getModelName(): string
    {
        return $this->getName();
    }
    public function getVendor(): string
    {
        return $this->getSupplier();
    }
    public function getModelVersion(): string
    {
        return $this->getVersion();
    }
    public function getConfigJson(): string
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_array($config)) {
            return json_encode($config);
        }
        return (string) $config;
    }
    public function getProviderConfig(): array
    {
        $config = $this->getData(self::schema_fields_PROVIDER_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    public function setProviderConfig(array|string $config): self
    {
        return $this->setData(self::schema_fields_PROVIDER_CONFIG, $config);
    }
    public function getProviderConfigJson(): string
    {
        $config = $this->getData(self::schema_fields_PROVIDER_CONFIG);
        if (is_array($config)) {
            return json_encode($config);
        }
        return (string) $config;
    }
    public function getProxyInfo(): mixed
    {
        return $this->getData(self::schema_fields_PROXY_INFO) ?? '';
    }
    public function getTokenPriceInput(): float
    {
        return (float) ($this->getData(self::schema_fields_TOKEN_PRICE_INPUT) ?? 0);
    }
    public function getTokenPriceOutput(): float
    {
        return (float) ($this->getData(self::schema_fields_TOKEN_PRICE_OUTPUT) ?? 0);
    }
    public function isCopied(): bool
    {
        return $this->isCopy();
    }
}
