<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;

/**
 * 默认布局种子生成器
 *
 * 当某 theme+pageType 没有任何 widget 时，写入 scoped draft patch。
 */
class DefaultLayoutSeeder
{
    private ThemeLayoutService $layoutService;
    private WelineTheme $welineTheme;
    private ThemeRuntimeLayoutResolver $runtimeLayoutResolver;
    private ThemeScopedLayoutWriteService $layoutWriter;

    public function __construct(
        ThemeLayoutService $layoutService,
        WelineTheme $welineTheme,
        ThemeRuntimeLayoutResolver $runtimeLayoutResolver,
        ThemeScopedLayoutWriteService $layoutWriter,
    ) {
        $this->layoutService = $layoutService;
        $this->welineTheme = $welineTheme;
        $this->runtimeLayoutResolver = $runtimeLayoutResolver;
        $this->layoutWriter = $layoutWriter;
    }

    /**
     * 为指定主题和页面类型生成默认布局（如果尚未配置）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param bool $forceReseed 是否强制重新生成（覆盖现有配置）
     * @return bool 是否生成了新的布局
     */
    public function seedDefaultLayout(int $themeId, string $pageType, bool $forceReseed = false): bool
    {
        // 检查是否已有布局配置
        if (!$forceReseed && $this->hasLayout($themeId, $pageType)) {
            return false;
        }

        // 获取该页面类型的默认布局配置
        $defaultConfig = $this->getDefaultLayoutConfig($pageType, $themeId);
        
        if (empty($defaultConfig)) {
            return false;
        }

        // 如果强制重新生成，先清除现有配置
        if ($forceReseed) {
            $this->clearLayout($themeId, $pageType);
        }

        foreach ($defaultConfig as $widgetData) {
            try {
                $context = $this->runtimeLayoutResolver->buildContext($themeId, $pageType, 'frontend', [
                    'layout_option' => 'default',
                    'scope' => 'default.default.default',
                    'target_type' => 'global',
                    'target_id' => 0,
                    'locale_code' => '',
                ]);
                $this->layoutWriter->addWidget(
                    $context,
                    \array_merge($widgetData, [
                        'theme_id' => $themeId,
                        'page_type' => $pageType,
                        'status' => ThemeLayout::STATUS_DRAFT,
                        'is_active' => true,
                    ]),
                    'system:default-layout-seeder',
                    'DefaultLayoutSeeder',
                );
            } catch (\Throwable $e) {
                // 单部件失败不得中断整页/全站布局播种（例：依赖已卸载模块）
                w_log_warning(
                    '[DefaultLayoutSeeder] skip widget on seed: ' . ($widgetData['widget_module'] ?? '')
                    . '::' . ($widgetData['widget_code'] ?? '') . ' — ' . $e->getMessage(),
                    [],
                    'theme_layout_seed.log'
                );
            }
        }

        return true;
    }

    /**
     * 为所有页面类型生成默认布局
     *
     * @param int $themeId 主题ID
     * @param bool $forceReseed 是否强制重新生成
     * @return array 返回已生成布局的页面类型列表
     */
    public function seedAllDefaultLayouts(int $themeId, bool $forceReseed = false): array
    {
        $seededPageTypes = [];

        foreach (ThemeLayout::getPageTypes() as $pageType => $label) {
            try {
                if ($this->seedDefaultLayout($themeId, $pageType, $forceReseed)) {
                    $seededPageTypes[] = $pageType;
                }
            } catch (\Throwable $e) {
                w_log_warning(
                    '[DefaultLayoutSeeder] skip pageType on seed: ' . $pageType . ' — ' . $e->getMessage(),
                    [],
                    'theme_layout_seed.log'
                );
            }
        }

        return $seededPageTypes;
    }

    /**
     * 为当前激活主题生成默认布局
     *
     * @param string|null $pageType 页面类型，null 则生成所有
     * @param bool $forceReseed 是否强制重新生成
     * @return bool|array
     */
    public function seedActiveThemeLayout(?string $pageType = null, bool $forceReseed = false)
    {
        $activeTheme = $this->welineTheme->getActiveTheme();
        if (!$activeTheme || !$activeTheme->getId()) {
            return false;
        }

        $themeId = (int)$activeTheme->getId();

        if ($pageType) {
            return $this->seedDefaultLayout($themeId, $pageType, $forceReseed);
        }

        return $this->seedAllDefaultLayouts($themeId, $forceReseed);
    }

    /**
     * 检查主题是否已有布局配置
     */
    private function hasLayout(int $themeId, string $pageType): bool
    {
        $identity = [
            'layout_option' => 'default',
            'scope' => 'default.default.default',
            'target_type' => 'global',
            'target_id' => 0,
            'locale_code' => '',
        ];
        try {
            $draftLayout = $this->runtimeLayoutResolver->resolveLayout(
                $themeId,
                $pageType,
                ThemeLayout::STATUS_DRAFT,
                'frontend',
                $identity,
            );
            $publishedLayout = $this->runtimeLayoutResolver->resolveLayout(
                $themeId,
                $pageType,
                ThemeLayout::STATUS_PUBLISHED,
                'frontend',
                $identity,
            );
        } catch (\Throwable) {
            return false;
        }

        foreach ([$draftLayout, $publishedLayout] as $layout) {
            foreach ($layout as $areaData) {
                if (!empty($areaData['widgets'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 清除指定主题和页面类型的布局配置
     */
    private function clearLayout(int $themeId, string $pageType): void
    {
        $context = $this->runtimeLayoutResolver->buildContext($themeId, $pageType, 'frontend', [
            'layout_option' => 'default',
            'scope' => 'default.default.default',
            'target_type' => 'global',
            'target_id' => 0,
            'locale_code' => '',
        ]);
        $this->layoutWriter->clearDraftNodes(
            $context,
            'system:default-layout-seeder',
            'DefaultLayoutSeeder',
        );
    }

    /**
     * 获取指定页面类型的默认布局配置
     * 
     * @param string $pageType 页面类型
     * @return array 默认配置数组
     */
    private function getDefaultLayoutConfig(string $pageType, ?int $themeId = null): array
    {
        if ($themeId && ($themeConfig = $this->getThemeDefaultLayoutConfig($themeId, $pageType))) {
            return $themeConfig;
        }

        $configs = [
            // ==================== 首页默认布局 ====================
            ThemeLayout::PAGE_TYPE_HOME => [
                // Banner 区域 - Hero Slider
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'homepage-hero',
                    'widget_code' => 'hero-slider',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'banner',
                    'config' => [
                        'title' => $this->resolveWebsiteBrandTitle(),
                        'subtitle' => '为日常与仪式感而作 · Made for everyday rituals',
                        'auto_play' => true,
                        'interval' => 5000,
                    ],
                    'sort_order' => 0,
                ],
                // 主内容区域 - 特色产品
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'homepage-featured',
                    'widget_code' => 'featured-products',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '本季精选 · Seasonal Edit',
                        'limit' => 8,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
                // 产品推荐区域 - 新品上市
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'homepage-new-arrivals',
                    'widget_code' => 'new-arrivals',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '新品上市 · New Arrivals',
                        'limit' => 8,
                        'columns' => 4,
                    ],
                    'sort_order' => 1,
                ],
            ],

            // ==================== 产品详情页默认布局 ====================
            ThemeLayout::PAGE_TYPE_PRODUCT => [
                // 产品主要信息（Product 拥有）
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-main',
                    'widget_code' => 'product-info',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [],
                    'sort_order' => 0,
                ],
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-purchase-actions',
                    'widget_code' => 'product-add-to-cart',
                    'widget_module' => 'Weline_Cart',
                    'widget_type' => 'product',
                    'config' => [],
                    'sort_order' => 0,
                ],
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-purchase-actions',
                    'widget_code' => 'product-buy-now',
                    'widget_module' => 'Weline_Checkout',
                    'widget_type' => 'product',
                    'config' => [],
                    'sort_order' => 10,
                ],
                // 相关产品推荐
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-related-products',
                    'widget_code' => 'related-products',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '同风格推荐 · You May Also Like',
                        'limit' => 4,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
                // 猜你喜欢（Weline_Product you-may-like default_injections → 常显槽）
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-you-may-like',
                    'widget_code' => 'you-may-like',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '猜你喜欢',
                        'limit' => 8,
                        'columns' => 4,
                        'layout' => 'grid',
                    ],
                    'sort_order' => 0,
                ],
                // 最近浏览（Weline_RecentlyViewed default_injections → 常显槽）
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-recently-viewed',
                    'widget_code' => 'recently-viewed',
                    'widget_module' => 'Weline_RecentlyViewed',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '最近浏览 · Recently Viewed',
                        'limit' => 24,
                        'columns' => 4,
                    ],
                    'sort_order' => 1,
                ],
                // 热销产品（全宽推荐区，避免挤在右侧栏导致布局溢出）
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-bestsellers',
                    'widget_code' => 'bestsellers',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '热卖精选 · Best Sellers',
                        'limit' => 4,
                        'columns' => 4,
                        'layout' => 'carousel',
                    ],
                    'sort_order' => 2,
                ],
            ],

            // ==================== 产品列表页默认布局 ====================
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST => [
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'list-recommendations',
                    'widget_code' => 'recommended-products',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '为你推荐 · Recommended',
                        'limit' => 8,
                        'columns' => '4',
                        'layout' => 'grid',
                    ],
                    'sort_order' => 0,
                ],
            ],

            // ==================== 分类页默认布局 ====================
            ThemeLayout::PAGE_TYPE_CATEGORY => [
                // 推荐产品
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'category-recommendations',
                    'widget_code' => 'recommended-products',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '继续探索 · Explore More',
                        'limit' => 8,
                        'columns' => '4',
                        'layout' => 'grid',
                    ],
                    'sort_order' => 0,
                ],
            ],

            // ==================== 购物车页默认布局 ====================
            ThemeLayout::PAGE_TYPE_CART => [
                // 交叉销售
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'cart-recommendations',
                    'widget_code' => 'cross-sell',
                    'widget_module' => 'Weline_Product',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '搭配成套 · Complete the Look',
                        'limit' => 4,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
            ],

            // ==================== 搜索页默认布局 ====================
            ThemeLayout::PAGE_TYPE_SEARCH => [
                // 热门搜索
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'search-recommendations',
                    'widget_code' => 'bestsellers',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '人气商品 · Popular Picks',
                        'limit' => 8,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
            ],

            ThemeLayout::PAGE_TYPE_BLOG => [
                // 博客详情由内容模板渲染，默认不注入 widgets
            ],

            ThemeLayout::PAGE_TYPE_BLOG_CATEGORY => [
                // 博客分类列表由内容模板渲染，默认不注入 widgets
            ],

            // ==================== CMS页面默认布局 ====================
            ThemeLayout::PAGE_TYPE_CMS => [
                // CMS 页面通常由内容管理，默认不添加 widgets
            ],

            // ==================== 结算页默认布局 ====================
            ThemeLayout::PAGE_TYPE_CHECKOUT => [
                // 结算页面通常固定，默认不添加 widgets
            ],

            // ==================== 账户中心默认布局 ====================
            ThemeLayout::PAGE_TYPE_ACCOUNT => [
                // 账户页面通常固定，默认不添加 widgets
            ],

            // ==================== 默认布局 ====================
            ThemeLayout::PAGE_TYPE_DEFAULT => [
                // 默认页面不添加 widgets，使用通用样式
            ],
        ];

        return $configs[$pageType] ?? [];
    }

    private function getThemeDefaultLayoutConfig(int $themeId, string $pageType): array
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return [];
        }

        /** @var ThemeResourceCatalog $resourceCatalog */
        $resourceCatalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
        $layoutResource = $resourceCatalog->getLayoutResource('frontend', $theme, $pageType, 'default');
        $decoded = $layoutResource['layout_info'] ?? null;
        if (!is_array($decoded)) {
            return [];
        }

        $widgets = $decoded['widgets'] ?? null;
        if (!is_array($widgets)) {
            return [];
        }

        $normalized = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (empty($widget['widget_code']) || empty($widget['widget_module']) || empty($widget['area'])) {
                continue;
            }
            $normalized[] = [
                'area' => (string)$widget['area'],
                'slot_id' => isset($widget['slot_id']) ? (string)$widget['slot_id'] : null,
                'widget_code' => (string)$widget['widget_code'],
                'widget_module' => (string)$widget['widget_module'],
                'widget_type' => isset($widget['widget_type']) ? (string)$widget['widget_type'] : '',
                'config' => is_array($widget['config'] ?? null) ? $widget['config'] : [],
                'sort_order' => (int)($widget['sort_order'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * 获取所有支持的页面类型及其默认配置概览
     *
     * @return array
     */
    public function getDefaultConfigSummary(): array
    {
        $summary = [];

        foreach (ThemeLayout::getPageTypes() as $pageType => $label) {
            $config = $this->getDefaultLayoutConfig($pageType, null);
            $summary[$pageType] = [
                'label' => $label,
                'widget_count' => count($config),
                'widgets' => array_map(function ($widget) {
                    return [
                        'code' => $widget['widget_code'],
                        'area' => $widget['area'],
                        'slot_id' => $widget['slot_id'] ?? null,
                    ];
                }, $config),
            ];
        }

        return $summary;
    }

    /**
     * Hero 标题取自 Website 基础信息站名；未配置时留空，由前台 SiteBrand 再解析。
     */
    private function resolveWebsiteBrandTitle(): string
    {
        try {
            /** @var \Weline\Theme\Helper\SiteBrand $siteBrand */
            $siteBrand = ObjectManager::getInstance(\Weline\Theme\Helper\SiteBrand::class);
            if ($siteBrand instanceof \Weline\Theme\Helper\SiteBrand) {
                return trim($siteBrand->resolveFrontendSiteName());
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
