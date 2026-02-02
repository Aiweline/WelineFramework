<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Theme\Service\LayoutDataService;

/**
 * 主题布局模型
 * 存储主题中各个区域的部件配置
 */
class ThemeLayout extends Model
{
    // 设置主键字段
    public string $_primary_key = 'layout_id';
    
    public const fields_ID = 'layout_id';
    public const fields_THEME_ID = 'theme_id';
    public const fields_PAGE_TYPE = 'page_type';
    public const fields_AREA = 'area';
    public const fields_SLOT_ID = 'slot_id';
    public const fields_WIDGET_CODE = 'widget_code';
    public const fields_WIDGET_MODULE = 'widget_module';
    public const fields_WIDGET_TYPE = 'widget_type';
    public const fields_CONFIG = 'config';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_STATUS = 'status';

    // 状态常量
    public const STATUS_DRAFT = 'draft';         // 草稿状态（后台编辑）
    public const STATUS_PUBLISHED = 'published'; // 已发布状态（前端可见）

    // 页面类型常量（与 layouts 目录名对应）
    public const PAGE_TYPE_HOME = 'homepage';         // layouts/homepage/
    public const PAGE_TYPE_CATEGORY = 'category';     // layouts/category/
    public const PAGE_TYPE_PRODUCT = 'product';       // layouts/product/
    public const PAGE_TYPE_PRODUCT_LIST = 'product_list'; // layouts/product_list/
    public const PAGE_TYPE_CMS = 'cms_page';          // layouts/cms_page/
    public const PAGE_TYPE_CART = 'cart';             // layouts/cart/
    public const PAGE_TYPE_CHECKOUT = 'checkout';     // layouts/checkout/
    public const PAGE_TYPE_ACCOUNT = 'account';       // layouts/account/
    public const PAGE_TYPE_SEARCH = 'search';         // layouts/search/ (待创建)
    public const PAGE_TYPE_DEFAULT = 'default';       // layouts/default/

    // 区域常量
    public const AREA_HEADER = 'header';
    public const AREA_BANNER = 'banner';
    public const AREA_LEFT_SIDEBAR = 'left_sidebar';
    public const AREA_CONTENT = 'content';
    public const AREA_RIGHT_SIDEBAR = 'right_sidebar';
    public const AREA_FOOTER = 'footer';

    /**
     * 获取所有支持的页面类型（布局类型）
     * 
     * 动态从 LayoutDataService 获取，支持子主题新增布局
     * 如果服务不可用，返回默认的硬编码列表作为回退
     */
    public static function getPageTypes(): array
    {
        try {
            /** @var LayoutDataService $layoutDataService */
            $layoutDataService = ObjectManager::getInstance(LayoutDataService::class);
            $types = $layoutDataService->getAllLayoutTypes();
            if (!empty($types)) {
                return $types;
            }
        } catch (\Throwable $e) {
            // 服务不可用，使用默认列表
        }

        // 回退：返回默认的硬编码列表
        return [
            self::PAGE_TYPE_HOME => __('首页'),
            self::PAGE_TYPE_CATEGORY => __('分类页'),
            self::PAGE_TYPE_PRODUCT => __('产品页'),
            self::PAGE_TYPE_PRODUCT_LIST => __('产品列表页'),
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
        // 添加 slot_id 字段（独占插槽支持）
        if (!$setup->hasField(self::fields_SLOT_ID)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_SLOT_ID,
                    self::fields_AREA,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'DEFAULT NULL',
                    '插槽ID（用于独占插槽）'
                )
                ->alter();
            
            // 添加索引
            if (!$setup->hasIndex('idx_theme_slot')) {
                $setup->alterTable()
                    ->addIndex(
                        TableInterface::index_type_KEY,
                        'idx_theme_slot',
                        [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_AREA, self::fields_SLOT_ID]
                    )
                    ->alter();
            }
        }

        // 添加 status 字段（草稿/发布状态）
        if (!$setup->hasField(self::fields_STATUS)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_STATUS,
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "NOT NULL DEFAULT '" . self::STATUS_DRAFT . "'",
                    '状态：draft=草稿，published=已发布'
                )
                ->alter();
            
            // 添加索引
            if (!$setup->hasIndex('idx_theme_status')) {
                $setup->alterTable()
                    ->addIndex(
                        TableInterface::index_type_KEY,
                        'idx_theme_status',
                        [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_STATUS]
                    )
                    ->alter();
            }

            // 将现有数据标记为已发布（兼容旧数据）
            try {
                $setup->getConnection()->query(
                    "UPDATE {$setup->getTable()} SET " . self::fields_STATUS . " = '" . self::STATUS_PUBLISHED . "' WHERE " . self::fields_STATUS . " IS NULL OR " . self::fields_STATUS . " = ''"
                );
            } catch (\Exception $e) {
                // 忽略更新错误
            }
        }
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
                self::fields_SLOT_ID,
                TableInterface::column_type_VARCHAR,
                50,
                'DEFAULT NULL',
                '插槽ID（用于独占插槽）'
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
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "NOT NULL DEFAULT '" . self::STATUS_DRAFT . "'",
                '状态：draft=草稿，published=已发布'
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
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_theme_status',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_STATUS]
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

    public function getSlotId(): ?string
    {
        $slotId = $this->getData(self::fields_SLOT_ID);
        return $slotId ? (string)$slotId : null;
    }

    public function setSlotId(?string $slotId): self
    {
        return $this->setData(self::fields_SLOT_ID, $slotId);
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

    public function getStatus(): string
    {
        return (string)($this->getData(self::fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    /**
     * 检查是否为草稿状态
     */
    public function isDraft(): bool
    {
        return $this->getStatus() === self::STATUS_DRAFT;
    }

    /**
     * 检查是否为已发布状态
     */
    public function isPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }

    /**
     * 获取部件的唯一标识（用于前端）
     */
    public function getWidgetUniqueId(): string
    {
        return $this->getWidgetModule() . '::' . $this->getWidgetCode();
    }

    /**
     * 获取所有支持的状态
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => __('草稿'),
            self::STATUS_PUBLISHED => __('已发布'),
        ];
    }
}
