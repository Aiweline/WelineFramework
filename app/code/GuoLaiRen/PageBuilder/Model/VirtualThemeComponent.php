<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站虚拟主题组件：存储虚拟主题的部件（header、footer、content）
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站虚拟主题组件')]
#[Index(name: 'idx_theme', columns: ['virtual_theme_id'], comment: '虚拟主题')]
#[Index(name: 'idx_code', columns: ['component_code'], comment: '组件代码')]
class VirtualThemeComponent extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme_component';
    public const schema_primary_key = 'component_id';

    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';

    public const CATEGORY_HEADER = 'header';
    public const CATEGORY_FOOTER = 'footer';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_OTHER = 'other';

    public const SOURCE_TYPE_VIRTUAL = 'virtual';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '组件主键')]
    public const schema_fields_ID = 'component_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: '组件代码')]
    public const schema_fields_COMPONENT_CODE = 'component_code';

    #[Col(type: 'int', nullable: false, default: 0, comment: '虚拟主题ID')]
    public const schema_fields_VIRTUAL_THEME_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'frontend', comment: '区域')]
    public const schema_fields_AREA = 'area';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'other', comment: '类别')]
    public const schema_fields_CATEGORY = 'category';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '组件名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'longtext', nullable: true, comment: '模板内容')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';

    #[Col(type: 'longtext', nullable: true, comment: '默认配置JSON')]
    public const schema_fields_DEFAULT_CONFIG = 'default_config';

    #[Col(type: 'longtext', nullable: true, comment: '元数据JSON')]
    public const schema_fields_META = 'meta';

    #[Col(type: 'tinyint', nullable: false, default: 0, comment: '是否AI生成')]
    public const schema_fields_IS_AI_GENERATED = 'is_ai_generated';

    #[Col(type: 'tinyint', nullable: false, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col(type: 'int', nullable: false, default: 0, comment: '已发布版本ID')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getComponentCode(): string
    {
        return (string) ($this->getData(self::schema_fields_COMPONENT_CODE) ?: '');
    }

    public function setComponentCode(string $code): static
    {
        return $this->setData(self::schema_fields_COMPONENT_CODE, $code);
    }

    public function getVirtualThemeId(): int
    {
        return (int) ($this->getData(self::schema_fields_VIRTUAL_THEME_ID) ?: 0);
    }

    public function setVirtualThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_THEME_ID, $themeId);
    }

    public function getArea(): string
    {
        return (string) ($this->getData(self::schema_fields_AREA) ?: self::AREA_FRONTEND);
    }

    public function setArea(string $area): static
    {
        return $this->setData(self::schema_fields_AREA, $area);
    }

    public function getCategory(): string
    {
        return (string) ($this->getData(self::schema_fields_CATEGORY) ?: self::CATEGORY_OTHER);
    }

    public function setCategory(string $category): static
    {
        return $this->setData(self::schema_fields_CATEGORY, $category);
    }

    public function getName(): string
    {
        return (string) ($this->getData(self::schema_fields_NAME) ?: '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getTemplateContent(): string
    {
        return (string) ($this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '');
    }

    public function setTemplateContent(string $content): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $content);
    }

    public function getDefaultConfig(): array
    {
        $config = $this->getData(self::schema_fields_DEFAULT_CONFIG);
        if (\is_array($config)) {
            return $config;
        }
        if (\is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setDefaultConfig(array $config): static
    {
        return $this->setData(self::schema_fields_DEFAULT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getMeta(): array
    {
        $meta = $this->getData(self::schema_fields_META);
        if (\is_array($meta)) {
            return $meta;
        }
        if (\is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setMeta(array $meta): static
    {
        return $this->setData(self::schema_fields_META, json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    public function isAiGenerated(): bool
    {
        return (bool) ($this->getData(self::schema_fields_IS_AI_GENERATED) ?: false);
    }

    public function setIsAiGenerated(bool $isAi): static
    {
        return $this->setData(self::schema_fields_IS_AI_GENERATED, $isAi ? 1 : 0);
    }

    public function isActive(): bool
    {
        return (bool) ($this->getData(self::schema_fields_IS_ACTIVE) ?: false);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getPublishedVersionId(): int
    {
        return (int) ($this->getData(self::schema_fields_PUBLISHED_VERSION_ID) ?: 0);
    }

    public function setPublishedVersionId(int $versionId): static
    {
        return $this->setData(self::schema_fields_PUBLISHED_VERSION_ID, $versionId);
    }

    public function getCreateTime(): string
    {
        return (string) ($this->getData(self::schema_fields_CREATE_TIME) ?: '');
    }

    public function getUpdateTime(): string
    {
        return (string) ($this->getData(self::schema_fields_UPDATE_TIME) ?: '');
    }
}
