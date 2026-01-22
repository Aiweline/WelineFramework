<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 主题布局模型
 * 存储主题中各个区域的部件配置
 */
class ThemeLayout extends Model
{
    public const fields_ID = 'layout_id';
    public const fields_THEME_ID = 'theme_id';
    public const fields_PAGE_TYPE = 'page_type';
    public const fields_AREA = 'area';
    public const fields_WIDGET_CODE = 'widget_code';
    public const fields_WIDGET_MODULE = 'widget_module';
    public const fields_WIDGET_TYPE = 'widget_type';
    public const fields_CONFIG = 'config';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_IS_ACTIVE = 'is_active';

    // 页面类型常量
    public const PAGE_TYPE_HOME = 'home';
    public const PAGE_TYPE_CATEGORY = 'category';
    public const PAGE_TYPE_PRODUCT = 'product';
    public const PAGE_TYPE_CMS = 'cms';
    public const PAGE_TYPE_CART = 'cart';
    public const PAGE_TYPE_CHECKOUT = 'checkout';
    public const PAGE_TYPE_ACCOUNT = 'account';
    public const PAGE_TYPE_SEARCH = 'search';
    public const PAGE_TYPE_DEFAULT = 'default'; // 通用布局

    // 区域常量
    public const AREA_HEADER = 'header';
    public const AREA_BANNER = 'banner';
    public const AREA_LEFT_SIDEBAR = 'left_sidebar';
    public const AREA_CONTENT = 'content';
    public const AREA_RIGHT_SIDEBAR = 'right_sidebar';
    public const AREA_FOOTER = 'footer';

    /**
     * 获取所有支持的页面类型
     */
    public static function getPageTypes(): array
    {
        return [
            self::PAGE_TYPE_HOME => __('首页'),
            self::PAGE_TYPE_CATEGORY => __('分类页'),
            self::PAGE_TYPE_PRODUCT => __('产品页'),
            self::PAGE_TYPE_CMS => __('CMS页面'),
            self::PAGE_TYPE_CART => __('购物车'),
            self::PAGE_TYPE_CHECKOUT => __('结算页'),
            self::PAGE_TYPE_ACCOUNT => __('账户中心'),
            self::PAGE_TYPE_SEARCH => __('搜索页'),
            self::PAGE_TYPE_DEFAULT => __('默认布局'),
        ];
    }

    /**
     * 获取所有支持的区域
     */
    public static function getAreas(): array
    {
        return [
            self::AREA_HEADER => __('头部区域'),
            self::AREA_BANNER => __('横幅区域'),
            self::AREA_LEFT_SIDEBAR => __('左侧栏'),
            self::AREA_CONTENT => __('内容区域'),
            self::AREA_RIGHT_SIDEBAR => __('右侧栏'),
            self::AREA_FOOTER => __('底部区域'),
        ];
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
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('主题布局配置表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key AUTO_INCREMENT',
                '布局ID'
            )
            ->addColumn(
                self::fields_THEME_ID,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL',
                '主题ID'
            )
            ->addColumn(
                self::fields_PAGE_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                "NOT NULL DEFAULT 'default'",
                '页面类型'
            )
            ->addColumn(
                self::fields_AREA,
                TableInterface::column_type_VARCHAR,
                50,
                'NOT NULL',
                '区域标识'
            )
            ->addColumn(
                self::fields_WIDGET_CODE,
                TableInterface::column_type_VARCHAR,
                100,
                'NOT NULL',
                '部件代码'
            )
            ->addColumn(
                self::fields_WIDGET_MODULE,
                TableInterface::column_type_VARCHAR,
                100,
                'NOT NULL',
                '部件所属模块'
            )
            ->addColumn(
                self::fields_WIDGET_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                "NOT NULL DEFAULT ''",
                '部件类型'
            )
            ->addColumn(
                self::fields_CONFIG,
                TableInterface::column_type_TEXT,
                null,
                '',
                '部件配置(JSON)'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL DEFAULT 0',
                '排序'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'NOT NULL DEFAULT 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_theme_page',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE]
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_area_sort',
                [self::fields_AREA, self::fields_SORT_ORDER]
            )
            ->create();
    }

    // Getters & Setters

    public function getLayoutId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function setLayoutId(int $id): self
    {
        return $this->setData(self::fields_ID, $id);
    }

    public function getThemeId(): int
    {
        return (int)$this->getData(self::fields_THEME_ID);
    }

    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::fields_THEME_ID, $themeId);
    }

    public function getPageType(): string
    {
        return (string)$this->getData(self::fields_PAGE_TYPE);
    }

    public function setPageType(string $pageType): self
    {
        return $this->setData(self::fields_PAGE_TYPE, $pageType);
    }

    public function getArea(): string
    {
        return (string)$this->getData(self::fields_AREA);
    }

    public function setArea(string $area): self
    {
        return $this->setData(self::fields_AREA, $area);
    }

    public function getWidgetCode(): string
    {
        return (string)$this->getData(self::fields_WIDGET_CODE);
    }

    public function setWidgetCode(string $code): self
    {
        return $this->setData(self::fields_WIDGET_CODE, $code);
    }

    public function getWidgetModule(): string
    {
        return (string)$this->getData(self::fields_WIDGET_MODULE);
    }

    public function setWidgetModule(string $module): self
    {
        return $this->setData(self::fields_WIDGET_MODULE, $module);
    }

    public function getWidgetType(): string
    {
        return (string)$this->getData(self::fields_WIDGET_TYPE);
    }

    public function setWidgetType(string $type): self
    {
        return $this->setData(self::fields_WIDGET_TYPE, $type);
    }

    public function getWidgetConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    public function setWidgetConfig(array $config): self
    {
        return $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getSortOrder(): int
    {
        return (int)$this->getData(self::fields_SORT_ORDER);
    }

    public function setSortOrder(int $order): self
    {
        return $this->setData(self::fields_SORT_ORDER, $order);
    }

    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    public function setIsActive(bool $active): self
    {
        return $this->setData(self::fields_IS_ACTIVE, $active ? 1 : 0);
    }

    /**
     * 获取部件的唯一标识（用于前端）
     */
    public function getWidgetUniqueId(): string
    {
        return $this->getWidgetModule() . '::' . $this->getWidgetCode();
    }
}
