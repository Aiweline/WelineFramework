<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Theme 虚拟布局资产表')]
#[Index(name: 'idx_theme_virtual_layout_unique', columns: ['theme_id', 'area', 'layout_type', 'layout_option', 'scope', 'target_type', 'target_id'], type: 'UNIQUE', comment: '虚拟布局身份唯一索引')]
#[Index(name: 'idx_theme_virtual_layout_lookup', columns: ['theme_id', 'area', 'layout_type', 'layout_option', 'is_active'], type: 'KEY', comment: '虚拟布局运行时查询索引')]
#[Index(name: 'idx_theme_virtual_layout_target', columns: ['target_type', 'target_id'], type: 'KEY', comment: '目标身份索引')]
class ThemeVirtualLayout extends Model
{
    public const schema_table = 'theme_virtual_layout';
    public const schema_primary_key = 'virtual_layout_id';

    public const SOURCE_TYPE_VIRTUAL = 'virtual';
    public const SOURCE_TYPE_AI = 'ai';
    public const SOURCE_TYPE_IMPORTED = 'imported';

    public const TARGET_GLOBAL = 'global';
    public const TARGET_PRODUCT = 'product';
    public const TARGET_CATEGORY = 'category';
    public const TARGET_CATEGORY_PRODUCT_DEFAULT = 'category_product_default';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_THEME_ID,
        self::schema_fields_AREA,
        self::schema_fields_LAYOUT_TYPE,
        self::schema_fields_LAYOUT_OPTION,
        self::schema_fields_SCOPE,
        self::schema_fields_TARGET_TYPE,
        self::schema_fields_TARGET_ID,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '虚拟布局ID')]
    public const schema_fields_ID = 'virtual_layout_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'frontend', comment: '区域')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '布局选项')]
    public const schema_fields_LAYOUT_OPTION = 'layout_option';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default.default.default', comment: 'scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 64, nullable: false, default: self::TARGET_GLOBAL, comment: '目标类型')]
    public const schema_fields_TARGET_TYPE = 'target_type';
    #[Col(type: 'int', nullable: false, default: 0, comment: '目标ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::SOURCE_TYPE_VIRTUAL, comment: '来源类型')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'int', nullable: true, comment: '已发布版本ID')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '当前版本号')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否AI生成')]
    public const schema_fields_IS_AI_GENERATED = 'is_ai_generated';
    #[Col(type: 'mediumtext', nullable: true, comment: '元信息JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
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

    public function getLayoutType(): string
    {
        return (string)($this->getData(self::schema_fields_LAYOUT_TYPE) ?: '');
    }

    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::schema_fields_LAYOUT_TYPE, $layoutType);
    }

    public function getLayoutOption(): string
    {
        return (string)($this->getData(self::schema_fields_LAYOUT_OPTION) ?: '');
    }

    public function setLayoutOption(string $layoutOption): static
    {
        return $this->setData(self::schema_fields_LAYOUT_OPTION, $layoutOption);
    }

    public function getScope(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE) ?: 'default.default.default');
    }

    public function setScope(string $scope): static
    {
        return $this->setData(self::schema_fields_SCOPE, $scope);
    }

    public function getTargetType(): string
    {
        return (string)($this->getData(self::schema_fields_TARGET_TYPE) ?: self::TARGET_GLOBAL);
    }

    public function setTargetType(string $targetType): static
    {
        return $this->setData(self::schema_fields_TARGET_TYPE, $targetType);
    }

    public function getTargetId(): int
    {
        return (int)($this->getData(self::schema_fields_TARGET_ID) ?: 0);
    }

    public function setTargetId(int $targetId): static
    {
        return $this->setData(self::schema_fields_TARGET_ID, $targetId);
    }

    public function getSourceType(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_TYPE) ?: self::SOURCE_TYPE_VIRTUAL);
    }

    public function setSourceType(string $sourceType): static
    {
        return $this->setData(self::schema_fields_SOURCE_TYPE, $sourceType);
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

    public function getPublishedVersionId(): int
    {
        return (int)($this->getData(self::schema_fields_PUBLISHED_VERSION_ID) ?: 0);
    }

    public function setPublishedVersionId(?int $versionId): static
    {
        return $this->setData(self::schema_fields_PUBLISHED_VERSION_ID, $versionId ?: null);
    }

    public function getVersion(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION) ?: 0);
    }

    public function setVersion(int $version): static
    {
        return $this->setData(self::schema_fields_VERSION, $version);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function setIsAiGenerated(bool $isAiGenerated): static
    {
        return $this->setData(self::schema_fields_IS_AI_GENERATED, $isAiGenerated ? 1 : 0);
    }

    public function getMetadata(): array
    {
        $value = $this->getData(self::schema_fields_METADATA_JSON);
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setMetadata(array $metadata): static
    {
        return $this->setData(self::schema_fields_METADATA_JSON, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
    }
}
