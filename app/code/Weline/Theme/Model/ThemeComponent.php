<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '主题部件表')]
#[Index(name: 'idx_theme_component_theme', columns: ['theme_id', 'area', 'component_code'])]
#[Index(name: 'idx_theme_component_source', columns: ['source_type', 'is_active'])]
class ThemeComponent extends Model
{
    public const schema_table = 'theme_component';
    public const schema_primary_key = 'component_id';

    public const SOURCE_TYPE_FILE = 'file';
    public const SOURCE_TYPE_WIDGET = 'widget';
    public const SOURCE_TYPE_VIRTUAL = 'virtual';

    public const RENDER_MODE_TEMPLATE_PATH = 'template_path';
    public const RENDER_MODE_TEMPLATE_CONTENT = 'template_content';
    public const RENDER_MODE_BLOCK_CLASS = 'block_class';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键ID')]
    public const schema_fields_ID = 'component_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '部件代码')]
    public const schema_fields_COMPONENT_CODE = 'component_code';
    #[Col(type: 'int', nullable: false, default: 0, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'frontend', comment: '区域')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'basic', comment: '分类')]
    public const schema_fields_CATEGORY = 'category';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::SOURCE_TYPE_FILE, comment: '来源类型')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::RENDER_MODE_TEMPLATE_PATH, comment: '渲染模式')]
    public const schema_fields_RENDER_MODE = 'render_mode';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '图标')]
    public const schema_fields_ICON = 'icon';
    #[Col(type: 'mediumtext', nullable: true, comment: '配置结构JSON')]
    public const schema_fields_CONFIG_SCHEMA_JSON = 'config_schema_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '默认配置JSON')]
    public const schema_fields_DEFAULT_CONFIG_JSON = 'default_config_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '元信息JSON')]
    public const schema_fields_META_JSON = 'meta_json';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否AI生成')]
    public const schema_fields_IS_AI_GENERATED = 'is_ai_generated';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', nullable: true, comment: '已发布版本ID')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0)
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getComponentCode(): string
    {
        return (string)($this->getData(self::schema_fields_COMPONENT_CODE) ?: '');
    }

    public function setComponentCode(string $componentCode): static
    {
        return $this->setData(self::schema_fields_COMPONENT_CODE, $componentCode);
    }

    public function getThemeId(): int
    {
        return (int)($this->getData(self::schema_fields_THEME_ID) ?: 0);
    }

    public function setThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_THEME_ID, $themeId);
    }

    public function getArea(): string
    {
        return (string)($this->getData(self::schema_fields_AREA) ?: 'frontend');
    }

    public function setArea(string $area): static
    {
        return $this->setData(self::schema_fields_AREA, $area);
    }

    public function getCategory(): string
    {
        return (string)($this->getData(self::schema_fields_CATEGORY) ?: 'basic');
    }

    public function setCategory(string $category): static
    {
        return $this->setData(self::schema_fields_CATEGORY, $category);
    }

    public function getSourceType(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_TYPE) ?: self::SOURCE_TYPE_FILE);
    }

    public function setSourceType(string $sourceType): static
    {
        return $this->setData(self::schema_fields_SOURCE_TYPE, $sourceType);
    }

    public function getRenderMode(): string
    {
        return (string)($this->getData(self::schema_fields_RENDER_MODE) ?: self::RENDER_MODE_TEMPLATE_PATH);
    }

    public function setRenderMode(string $renderMode): static
    {
        return $this->setData(self::schema_fields_RENDER_MODE, $renderMode);
    }

    public function getName(): string
    {
        return (string)($this->getData(self::schema_fields_NAME) ?: '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getDescription(): string
    {
        return (string)($this->getData(self::schema_fields_DESCRIPTION) ?: '');
    }

    public function setDescription(string $description): static
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    public function getIcon(): string
    {
        return (string)($this->getData(self::schema_fields_ICON) ?: '');
    }

    public function setIcon(string $icon): static
    {
        return $this->setData(self::schema_fields_ICON, $icon);
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

    public function getMeta(): array
    {
        return $this->decodeJsonField(self::schema_fields_META_JSON);
    }

    public function setMeta(array $meta): static
    {
        return $this->setData(self::schema_fields_META_JSON, json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    public function isAiGenerated(): bool
    {
        return (bool)($this->getData(self::schema_fields_IS_AI_GENERATED) ?: false);
    }

    public function setIsAiGenerated(bool $isAiGenerated): static
    {
        return $this->setData(self::schema_fields_IS_AI_GENERATED, $isAiGenerated ? 1 : 0);
    }

    public function isActive(): bool
    {
        return (bool)($this->getData(self::schema_fields_IS_ACTIVE) ?: false);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getPublishedVersionId(): int
    {
        return (int)($this->getData(self::schema_fields_PUBLISHED_VERSION_ID) ?: 0);
    }

    public function setPublishedVersionId(?int $versionId): static
    {
        return $this->setData(self::schema_fields_PUBLISHED_VERSION_ID, $versionId ?: null);
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
