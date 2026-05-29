<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

/**
 * 默认布局种子生成器
 * 
 * 当某 theme+pageType 没有任何 widget 时，写入一份默认 draft 布局配置
 * 复用 SlotRendererService 的 draft→published 自动发布机制
 */
class DefaultLayoutSeeder
{
    private ThemeLayoutService $layoutService;
    private WelineTheme $welineTheme;

    public function __construct(
        ThemeLayoutService $layoutService,
        WelineTheme $welineTheme
    ) {
        $this->layoutService = $layoutService;
        $this->welineTheme = $welineTheme;
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

        // 写入默认配置
        foreach ($defaultConfig as $widgetData) {
            $this->layoutService->saveWidget(array_merge($widgetData, [
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'status' => ThemeLayout::STATUS_DRAFT,
                'is_active' => true,
            ]));
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
            if ($this->seedDefaultLayout($themeId, $pageType, $forceReseed)) {
                $seededPageTypes[] = $pageType;
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
        // 检查草稿和已发布状态
        $draftLayout = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);
        $publishedLayout = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED);

        foreach ($draftLayout as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                return true;
            }
        }

        foreach ($publishedLayout as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 清除指定主题和页面类型的布局配置
     */
    private function clearLayout(int $themeId, string $pageType): void
    {
        // 使用 saveLayout 空数据来清除
        $this->layoutService->saveLayout($themeId, $pageType, [], ThemeLayout::STATUS_DRAFT);
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
                        'title' => '欢迎来到我们的商店',
                        'subtitle' => '发现最新产品和优惠',
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
                        'title' => '特色产品',
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
                        'title' => '新品上市',
                        'limit' => 8,
                        'columns' => 4,
                    ],
                    'sort_order' => 1,
                ],
            ],

            // ==================== 产品详情页默认布局 ====================
            ThemeLayout::PAGE_TYPE_PRODUCT => [
                // 相关产品推荐
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-related-products',
                    'widget_code' => 'related-products',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '相关产品',
                        'limit' => 4,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
                // 最近浏览
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'product-recently-viewed',
                    'widget_code' => 'recently-viewed',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '最近浏览',
                        'limit' => 4,
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
                        'title' => '热销产品',
                        'limit' => 4,
                        'columns' => 4,
                        'layout' => 'carousel',
                    ],
                    'sort_order' => 2,
                ],
            ],

            // ==================== 产品列表页默认布局 ====================
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST => [
                // 推荐产品区域 - 特色产品
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'list-recommendations',
                    'widget_code' => 'featured-products',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '推荐产品',
                        'limit' => 4,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
            ],

            // ==================== 分类页默认布局 ====================
            ThemeLayout::PAGE_TYPE_CATEGORY => [
                // 相关分类
                [
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'category-recommendations',
                    'widget_code' => 'category-grid',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'category',
                    'config' => [
                        'title' => '相关分类',
                        'limit' => 6,
                        'columns' => 3,
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
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'product',
                    'config' => [
                        'title' => '您可能还需要',
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
                        'title' => '热门产品',
                        'limit' => 8,
                        'columns' => 4,
                    ],
                    'sort_order' => 0,
                ],
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

        $themePath = rtrim((string)$theme->getPath(), '/\\');
        if ($themePath === '') {
            return [];
        }

        $layoutJson = $themePath . DS . 'frontend' . DS . 'layouts' . DS . $pageType . DS . 'default.layout.json';
        if (!is_file($layoutJson)) {
            return [];
        }

        $raw = file_get_contents($layoutJson);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
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
}
