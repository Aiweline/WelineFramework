<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 主题布局服务
 * 管理主题的部件布局配置
 */
class ThemeLayoutService
{
    private ThemeLayout $themeLayout;
    private WelineTheme $welineTheme;
    private WidgetRegistry $widgetRegistry;

    public function __construct(
        ThemeLayout $themeLayout,
        WelineTheme $welineTheme,
        WidgetRegistry $widgetRegistry
    ) {
        $this->themeLayout = $themeLayout;
        $this->welineTheme = $welineTheme;
        $this->widgetRegistry = $widgetRegistry;
    }

    /**
     * 获取主题布局配置
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @return array 按区域分组的部件配置
     */
    public function getLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT): array
    {
        // 按区域分组
        $groupedLayout = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $areaLabel) {
            $groupedLayout[$areaCode] = [
                'label' => $areaLabel,
                'widgets' => [],
            ];
        }

        try {
            $layouts = $this->themeLayout->reset()
                ->where(ThemeLayout::fields_THEME_ID, $themeId)
                ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::fields_IS_ACTIVE, 1)
                ->order(ThemeLayout::fields_AREA)
                ->order(ThemeLayout::fields_SORT_ORDER)
                ->select()
                ->fetch();

            // 确保 layouts 是数组
            if (!is_array($layouts)) {
                return $groupedLayout;
            }

            foreach ($layouts as $layout) {
                // 确保 layout 是数组
                if (!is_array($layout)) {
                    continue;
                }
                
                $area = $layout[ThemeLayout::fields_AREA] ?? '';
                if (isset($groupedLayout[$area])) {
                    $config = $layout[ThemeLayout::fields_CONFIG] ?? '{}';
                    $groupedLayout[$area]['widgets'][] = [
                        'layout_id' => $layout[ThemeLayout::fields_ID] ?? 0,
                        'widget_code' => $layout[ThemeLayout::fields_WIDGET_CODE] ?? '',
                        'widget_module' => $layout[ThemeLayout::fields_WIDGET_MODULE] ?? '',
                        'widget_type' => $layout[ThemeLayout::fields_WIDGET_TYPE] ?? '',
                        'config' => is_string($config) ? json_decode($config, true) : $config,
                        'sort_order' => $layout[ThemeLayout::fields_SORT_ORDER] ?? 0,
                    ];
                }
            }
        } catch (\Exception $e) {
            // 表可能不存在，返回空布局
        }

        return $groupedLayout;
    }

    /**
     * 获取完整布局数据（包含部件元信息）
     */
    public function getFullLayout(int $themeId, string $pageType = ThemeLayout::PAGE_TYPE_DEFAULT): array
    {
        $layout = $this->getLayout($themeId, $pageType);
        $widgetRegistry = $this->widgetRegistry->getRegistry();

        // 为每个部件添加元信息
        foreach ($layout as $area => &$areaData) {
            foreach ($areaData['widgets'] as &$widget) {
                $widgetKey = $widget['widget_module'] . '/' . $widget['widget_type'] . '/' . $widget['widget_code'];
                if (isset($widgetRegistry[$widgetKey])) {
                    $widget['meta'] = $widgetRegistry[$widgetKey];
                } else {
                    // 尝试其他匹配方式
                    foreach ($widgetRegistry as $key => $meta) {
                        if ($meta['code'] === $widget['widget_code'] && $meta['module'] === $widget['widget_module']) {
                            $widget['meta'] = $meta;
                            break;
                        }
                    }
                }
            }
        }

        return $layout;
    }

    /**
     * 保存单个部件配置
     */
    public function saveWidget(array $data): int
    {
        $layoutId = $data['layout_id'] ?? 0;

        if ($layoutId) {
            // 更新现有部件
            $this->themeLayout->reset()->load($layoutId);
        } else {
            // 新建部件
            $this->themeLayout->reset();
        }

        $this->themeLayout
            ->setThemeId((int)$data['theme_id'])
            ->setPageType($data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT)
            ->setArea($data['area'])
            ->setWidgetCode($data['widget_code'])
            ->setWidgetModule($data['widget_module'])
            ->setWidgetType($data['widget_type'] ?? '')
            ->setWidgetConfig($data['config'] ?? [])
            ->setSortOrder((int)($data['sort_order'] ?? 0))
            ->setIsActive((bool)($data['is_active'] ?? true))
            ->save();

        return $this->themeLayout->getLayoutId();
    }

    /**
     * 批量保存布局
     */
    public function saveLayout(int $themeId, string $pageType, array $layoutData): bool
    {
        // 先删除该页面的所有布局
        $this->themeLayout->reset()
            ->where(ThemeLayout::fields_THEME_ID, $themeId)
            ->where(ThemeLayout::fields_PAGE_TYPE, $pageType)
            ->delete()
            ->fetch();

        // 保存新布局
        foreach ($layoutData as $area => $widgets) {
            foreach ($widgets as $index => $widget) {
                $this->saveWidget([
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                    'area' => $area,
                    'widget_code' => $widget['widget_code'],
                    'widget_module' => $widget['widget_module'],
                    'widget_type' => $widget['widget_type'] ?? '',
                    'config' => $widget['config'] ?? [],
                    'sort_order' => $index,
                    'is_active' => $widget['is_active'] ?? true,
                ]);
            }
        }

        return true;
    }

    /**
     * 更新部件配置
     */
    public function updateWidgetConfig(int $layoutId, array $config): bool
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return false;
        }

        $this->themeLayout->setWidgetConfig($config)->save();
        return true;
    }

    /**
     * 删除部件
     */
    public function deleteWidget(int $layoutId): bool
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return false;
        }

        $this->themeLayout->delete();
        return true;
    }

    /**
     * 根据布局ID获取部件数据
     */
    public function getWidgetByLayoutId(int $layoutId): ?array
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return null;
        }

        $widgetModule = $this->themeLayout->getWidgetModule();
        $widgetCode = $this->themeLayout->getWidgetCode();
        $config = $this->themeLayout->getWidgetConfig();

        // 解析 JSON 配置
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }

        return [
            'layout_id' => $layoutId,
            'widget_module' => $widgetModule,
            'widget_code' => $widgetCode,
            'config' => $config,
            'area' => $this->themeLayout->getArea(),
            'sort_order' => $this->themeLayout->getSortOrder(),
        ];
    }

    /**
     * 更新部件排序
     */
    public function updateSortOrder(array $sortData): bool
    {
        foreach ($sortData as $layoutId => $sortOrder) {
            $this->themeLayout->reset()->load($layoutId);
            if ($this->themeLayout->getLayoutId()) {
                $this->themeLayout->setSortOrder((int)$sortOrder)->save();
            }
        }
        return true;
    }

    /**
     * 移动部件到新区域
     */
    public function moveWidget(int $layoutId, string $newArea, int $newSortOrder): bool
    {
        $this->themeLayout->reset()->load($layoutId);
        if (!$this->themeLayout->getLayoutId()) {
            return false;
        }

        $this->themeLayout
            ->setArea($newArea)
            ->setSortOrder($newSortOrder)
            ->save();

        return true;
    }

    /**
     * 复制布局到另一个页面类型
     */
    public function copyLayout(int $themeId, string $fromPageType, string $toPageType): bool
    {
        $sourceLayout = $this->getLayout($themeId, $fromPageType);

        // 转换格式
        $layoutData = [];
        foreach ($sourceLayout as $area => $areaData) {
            $layoutData[$area] = $areaData['widgets'];
        }

        return $this->saveLayout($themeId, $toPageType, $layoutData);
    }

    /**
     * 获取可用的部件列表（按类型分组）
     */
    public function getAvailableWidgets(): array
    {
        $widgets = $this->widgetRegistry->getRegistry();

        // WidgetRegistry 返回的结构是 $result[$type][$name] = $config
        // 需要遍历两层：类型 -> 部件名称
        $grouped = [];
        
        foreach ($widgets as $type => $typeWidgets) {
            // $type 是类型（如 'header', 'footer'）
            // $typeWidgets 是该类型下的所有部件数组
            if (!is_array($typeWidgets)) {
                continue;
            }
            
            if (!isset($grouped[$type])) {
                $grouped[$type] = [
                    'label' => $this->getTypeLabel($type),
                    'widgets' => [],
                ];
            }
            
            // 遍历该类型下的所有部件
            foreach ($typeWidgets as $widgetName => $widget) {
                if (is_array($widget)) {
                    // 确保部件数据包含 type 字段
                    if (!isset($widget['type'])) {
                        $widget['type'] = $type;
                    }
                    // 确保部件数据包含 code 字段
                    if (!isset($widget['code'])) {
                        $widget['code'] = $widgetName;
                    }
                    
                    // 从原始配置中提取 position 和 compatible 字段
                    // WidgetScanner 将原始配置存储在 'config' 子数组中
                    $originalConfig = $widget['config'] ?? [];
                    
                    // 确保 position 字段存在（从原始配置中提取）
                    if (!isset($widget['position']) && isset($originalConfig['position'])) {
                        $widget['position'] = $originalConfig['position'];
                    }
                    // 如果仍然没有 position，根据 type 设置默认值
                    if (!isset($widget['position']) || empty($widget['position'])) {
                        $widget['position'] = $this->getDefaultPositionByType($type);
                    }
                    
                    // 确保 compatible 字段存在
                    if (!isset($widget['compatible'])) {
                        $widget['compatible'] = $originalConfig['compatible'] ?? false;
                    }
                    
                    $grouped[$type]['widgets'][] = $widget;
                }
            }
        }

        return $grouped;
    }
    
    /**
     * 根据部件类型获取默认允许的位置
     */
    private function getDefaultPositionByType(string $type): array
    {
        $typePositionMap = [
            'header' => ['header'],
            'footer' => ['footer'],
            'sidebar' => ['sidebar', 'left_sidebar', 'right_sidebar'],
            'banner' => ['banner', 'content'],
            'carousel' => ['banner', 'content'],
            'slider' => ['banner', 'content'],
            'product' => ['content', 'sidebar'],
            'category' => ['content', 'sidebar'],
            'navigation' => ['header'],
            'search' => ['header'],
            'breadcrumb' => ['content'],
            'pagination' => ['content'],
            'social' => ['footer', 'sidebar'],
            'newsletter' => ['footer', 'sidebar', 'content'],
            'testimonial' => ['content'],
            'faq' => ['content'],
            'video' => ['content', 'banner'],
            'content' => ['content', 'banner', 'sidebar'],
        ];
        
        return $typePositionMap[$type] ?? ['content']; // 默认允许在内容区
    }

    /**
     * 获取类型标签
     */
    private function getTypeLabel(string $type): string
    {
        $labels = [
            'header' => __('头部部件'),
            'footer' => __('底部部件'),
            'sidebar' => __('侧栏部件'),
            'content' => __('内容部件'),
            'banner' => __('横幅部件'),
            'carousel' => __('轮播部件'),
            'product' => __('产品部件'),
            'category' => __('分类部件'),
            'navigation' => __('导航部件'),
            'search' => __('搜索部件'),
            'social' => __('社交部件'),
            'newsletter' => __('订阅部件'),
            'testimonial' => __('评价部件'),
            'faq' => __('FAQ部件'),
            'breadcrumb' => __('面包屑部件'),
            'pagination' => __('分页部件'),
            'video' => __('视频部件'),
            'other' => __('其他部件'),
        ];

        return $labels[$type] ?? ucfirst($type);
    }
}
