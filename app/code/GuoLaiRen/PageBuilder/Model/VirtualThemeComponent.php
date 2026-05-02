<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI virtual theme component')]
#[Index(name: 'idx_virtual_theme_component_theme', columns: ['virtual_theme_id'], comment: 'Virtual theme lookup')]
#[Index(name: 'idx_virtual_theme_component_code', columns: ['virtual_theme_id', 'component_code', 'area'], comment: 'Component code lookup')]
#[Index(name: 'idx_virtual_theme_component_category', columns: ['virtual_theme_id', 'category', 'area'], comment: 'Component category lookup')]
class VirtualThemeComponent extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme_component';
    public const schema_primary_key = 'component_id';

    public const AREA_FRONTEND = 'frontend';
    public const CATEGORY_HEADER = 'header';
    public const CATEGORY_FOOTER = 'footer';
    public const CATEGORY_CONTENT = 'content';
    public const SOURCE_TYPE_VIRTUAL = 'virtual_theme';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Component ID')]
    public const schema_fields_ID = 'component_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Virtual theme ID')]
    public const schema_fields_VIRTUAL_THEME_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Component code')]
    public const schema_fields_COMPONENT_CODE = 'component_code';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::AREA_FRONTEND, comment: 'Render area')]
    public const schema_fields_AREA = 'area';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::CATEGORY_CONTENT, comment: 'Component category')]
    public const schema_fields_CATEGORY = 'category';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Component name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'mediumtext', nullable: true, comment: 'PHTML template content')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';

    #[Col(type: 'longtext', nullable: true, comment: 'Default config JSON')]
    public const schema_fields_DEFAULT_CONFIG = 'default_config';

    #[Col(type: 'longtext', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_META_JSON = 'meta';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Published version ID')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';

    #[Col(type: 'tinyint', nullable: false, default: 1, comment: 'Active flag')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col(type: 'tinyint', nullable: false, default: 1, comment: 'AI generated flag')]
    public const schema_fields_IS_AI_GENERATED = 'is_ai_generated';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getVirtualThemeId(): int
    {
        return (int)($this->getData(self::schema_fields_VIRTUAL_THEME_ID) ?: 0);
    }

    public function setVirtualThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_THEME_ID, $themeId);
    }

    public function getComponentCode(): string
    {
        return (string)($this->getData(self::schema_fields_COMPONENT_CODE) ?: '');
    }

    public function setComponentCode(string $code): static
    {
        return $this->setData(self::schema_fields_COMPONENT_CODE, $code);
    }

    public function getArea(): string
    {
        return (string)($this->getData(self::schema_fields_AREA) ?: self::AREA_FRONTEND);
    }

    public function setArea(string $area): static
    {
        return $this->setData(self::schema_fields_AREA, $area);
    }

    public function getCategory(): string
    {
        return (string)($this->getData(self::schema_fields_CATEGORY) ?: self::CATEGORY_CONTENT);
    }

    public function setCategory(string $category): static
    {
        return $this->setData(self::schema_fields_CATEGORY, $category);
    }

    public function getName(): string
    {
        return (string)($this->getData(self::schema_fields_NAME) ?: '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getTemplateContent(): string
    {
        return (string)($this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '');
    }

    public function setTemplateContent(string $content): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $content);
    }

    public function getDefaultConfig(): array
    {
        return $this->decodeArrayField(self::schema_fields_DEFAULT_CONFIG);
    }

    public function setDefaultConfig(array $config): static
    {
        return $this->setData(self::schema_fields_DEFAULT_CONFIG, self::encodeJson($config));
    }

    public function getMeta(): array
    {
        return $this->decodeArrayField(self::schema_fields_META_JSON);
    }

    public function setMeta(array $meta): static
    {
        return $this->setData(self::schema_fields_META_JSON, self::encodeJson($meta));
    }

    public function getPublishedVersionId(): int
    {
        return (int)($this->getData(self::schema_fields_PUBLISHED_VERSION_ID) ?: 0);
    }

    public function setPublishedVersionId(int $versionId): static
    {
        return $this->setData(self::schema_fields_PUBLISHED_VERSION_ID, $versionId);
    }

    public function isActive(): bool
    {
        return (bool)($this->getData(self::schema_fields_IS_ACTIVE) ?: false);
    }

    public function getIsActive(): int
    {
        return (int)($this->getData(self::schema_fields_IS_ACTIVE) ?: 0);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function isAiGenerated(): bool
    {
        return (bool)($this->getData(self::schema_fields_IS_AI_GENERATED) ?: false);
    }

    public function getIsAiGenerated(): int
    {
        return (int)($this->getData(self::schema_fields_IS_AI_GENERATED) ?: 0);
    }

    public function setIsAiGenerated(bool $isAiGenerated): static
    {
        return $this->setData(self::schema_fields_IS_AI_GENERATED, $isAiGenerated ? 1 : 0);
    }

    public function getCreateTime(): string
    {
        return (string)($this->getData(self::schema_fields_CREATE_TIME) ?: '');
    }

    public function getUpdateTime(): string
    {
        return (string)($this->getData(self::schema_fields_UPDATE_TIME) ?: '');
    }

    private function decodeArrayField(string $field): array
    {
        $raw = $this->getData($field);
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    private static function encodeJson(array $payload): string
    {
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        return \is_string($json) ? $json : '{}';
    }
}
