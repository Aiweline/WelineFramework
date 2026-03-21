<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '主题部件版本表')]
#[Index(name: 'idx_theme_component_version_component', columns: ['component_id', 'version_no'])]
#[Index(name: 'idx_theme_component_version_status', columns: ['component_id', 'status'])]
class ThemeComponentVersion extends Model
{
    public const schema_table = 'theme_component_version';
    public const schema_primary_key = 'version_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键ID')]
    public const schema_fields_ID = 'version_id';
    #[Col(type: 'int', nullable: false, comment: '部件ID')]
    public const schema_fields_COMPONENT_ID = 'component_id';
    #[Col(type: 'int', nullable: false, default: 1, comment: '版本号')]
    public const schema_fields_VERSION_NO = 'version_no';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_DRAFT, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'mediumtext', nullable: true, comment: '模板内容')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';
    #[Col(type: 'mediumtext', nullable: true, comment: '配置结构JSON')]
    public const schema_fields_CONFIG_SCHEMA_JSON = 'config_schema_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '默认配置JSON')]
    public const schema_fields_DEFAULT_CONFIG_JSON = 'default_config_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '生成元数据JSON')]
    public const schema_fields_GENERATION_META_JSON = 'generation_meta_json';
    #[Col(type: 'mediumtext', nullable: true, comment: 'Prompt')]
    public const schema_fields_PROMPT = 'prompt';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '智能体代码')]
    public const schema_fields_AGENT_CODE = 'agent_code';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col(type: 'mediumtext', nullable: true, comment: '校验结果JSON')]
    public const schema_fields_VALIDATION_JSON = 'validation_json';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0)
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getComponentId(): int
    {
        return (int)($this->getData(self::schema_fields_COMPONENT_ID) ?: 0);
    }

    public function setComponentId(int $componentId): static
    {
        return $this->setData(self::schema_fields_COMPONENT_ID, $componentId);
    }

    public function getVersionNo(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION_NO) ?: 1);
    }

    public function setVersionNo(int $versionNo): static
    {
        return $this->setData(self::schema_fields_VERSION_NO, $versionNo);
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getTemplateContent(): string
    {
        return (string)($this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '');
    }

    public function setTemplateContent(string $templateContent): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $templateContent);
    }

    public function getConfigSchema(): array
    {
        return $this->decodeJsonField(self::schema_fields_CONFIG_SCHEMA_JSON);
    }

    public function setConfigSchema(array $configSchema): static
    {
        return $this->setData(self::schema_fields_CONFIG_SCHEMA_JSON, json_encode($configSchema, JSON_UNESCAPED_UNICODE));
    }

    public function getDefaultConfig(): array
    {
        return $this->decodeJsonField(self::schema_fields_DEFAULT_CONFIG_JSON);
    }

    public function setDefaultConfig(array $defaultConfig): static
    {
        return $this->setData(self::schema_fields_DEFAULT_CONFIG_JSON, json_encode($defaultConfig, JSON_UNESCAPED_UNICODE));
    }

    public function getGenerationMeta(): array
    {
        return $this->decodeJsonField(self::schema_fields_GENERATION_META_JSON);
    }

    public function setGenerationMeta(array $generationMeta): static
    {
        return $this->setData(self::schema_fields_GENERATION_META_JSON, json_encode($generationMeta, JSON_UNESCAPED_UNICODE));
    }

    public function getPrompt(): string
    {
        return (string)($this->getData(self::schema_fields_PROMPT) ?: '');
    }

    public function setPrompt(string $prompt): static
    {
        return $this->setData(self::schema_fields_PROMPT, $prompt);
    }

    public function getAgentCode(): string
    {
        return (string)($this->getData(self::schema_fields_AGENT_CODE) ?: '');
    }

    public function setAgentCode(string $agentCode): static
    {
        return $this->setData(self::schema_fields_AGENT_CODE, $agentCode);
    }

    public function getModelCode(): string
    {
        return (string)($this->getData(self::schema_fields_MODEL_CODE) ?: '');
    }

    public function setModelCode(string $modelCode): static
    {
        return $this->setData(self::schema_fields_MODEL_CODE, $modelCode);
    }

    public function getValidation(): array
    {
        return $this->decodeJsonField(self::schema_fields_VALIDATION_JSON);
    }

    public function setValidation(array $validation): static
    {
        return $this->setData(self::schema_fields_VALIDATION_JSON, json_encode($validation, JSON_UNESCAPED_UNICODE));
    }

    private function decodeJsonField(string $field): array
    {
        $value = $this->getData($field);
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
