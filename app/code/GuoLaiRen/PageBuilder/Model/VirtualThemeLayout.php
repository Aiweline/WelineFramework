<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站虚拟主题布局：存储每个页面类型的布局配置（header/footer/content 部件）
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站虚拟主题布局')]
#[Index(name: 'idx_theme', columns: ['virtual_theme_id'], comment: '虚拟主题')]
#[Index(name: 'idx_page_type', columns: ['page_type'], comment: '页面类型')]
class VirtualThemeLayout extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme_layout';
    public const schema_primary_key = 'layout_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '布局主键')]
    public const schema_fields_ID = 'layout_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '虚拟主题ID')]
    public const schema_fields_VIRTUAL_THEME_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: '页面类型')]
    public const schema_fields_PAGE_TYPE = 'page_type';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'frontend', comment: '区域')]
    public const schema_fields_AREA = 'area';

    #[Col(type: 'longtext', nullable: true, comment: '布局配置JSON（header/footer/content 部件）')]
    public const schema_fields_CONFIG = 'config';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: '布局版本')]
    public const schema_fields_VERSION = 'version';

    #[Col(type: 'tinyint', nullable: false, default: 0, comment: '是否使用原始模板')]
    public const schema_fields_USE_ORIGINAL_TEMPLATE = 'use_original_template';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联PageBuilder页面ID')]
    public const schema_fields_PAGE_ID = 'page_id';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getVirtualThemeId(): int
    {
        return (int) ($this->getData(self::schema_fields_VIRTUAL_THEME_ID) ?: 0);
    }

    public function setVirtualThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_THEME_ID, $themeId);
    }

    public function getPageType(): string
    {
        return (string) ($this->getData(self::schema_fields_PAGE_TYPE) ?: '');
    }

    public function setPageType(string $pageType): static
    {
        return $this->setData(self::schema_fields_PAGE_TYPE, $pageType);
    }

    public function getArea(): string
    {
        return (string) ($this->getData(self::schema_fields_AREA) ?: 'frontend');
    }

    public function setArea(string $area): static
    {
        return $this->setData(self::schema_fields_AREA, $area);
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (\is_array($config)) {
            return $config;
        }
        if (\is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setConfig(array $config): static
    {
        return $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getVersion(): string
    {
        return (string) ($this->getData(self::schema_fields_VERSION) ?: '1.0');
    }

    public function setVersion(string $version): static
    {
        return $this->setData(self::schema_fields_VERSION, $version);
    }

    public function useOriginalTemplate(): bool
    {
        return (bool) ($this->getData(self::schema_fields_USE_ORIGINAL_TEMPLATE) ?: false);
    }

    public function setUseOriginalTemplate(bool $use): static
    {
        return $this->setData(self::schema_fields_USE_ORIGINAL_TEMPLATE, $use ? 1 : 0);
    }

    public function getPageId(): int
    {
        return (int) ($this->getData(self::schema_fields_PAGE_ID) ?: 0);
    }

    public function setPageId(int $pageId): static
    {
        return $this->setData(self::schema_fields_PAGE_ID, $pageId);
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
