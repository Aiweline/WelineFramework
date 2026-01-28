<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeLayout;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 部件位置解析器
 * 根据部件的 position 属性判断可放置的区域
 */
class WidgetPositionResolver
{
    private WidgetRegistry $widgetRegistry;

    // 位置到区域的映射
    private const POSITION_TO_AREA_MAP = [
        'header' => [ThemeLayout::AREA_HEADER],
        'banner' => [ThemeLayout::AREA_BANNER],
        'sidebar' => [ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
        'left_sidebar' => [ThemeLayout::AREA_LEFT_SIDEBAR],
        'right_sidebar' => [ThemeLayout::AREA_RIGHT_SIDEBAR],
        'content' => [ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_BANNER],
        'footer' => [ThemeLayout::AREA_FOOTER],
        'all' => [
            ThemeLayout::AREA_HEADER,
            ThemeLayout::AREA_BANNER,
            ThemeLayout::AREA_LEFT_SIDEBAR,
            ThemeLayout::AREA_CONTENT,
            ThemeLayout::AREA_RIGHT_SIDEBAR,
            ThemeLayout::AREA_FOOTER,
        ],
    ];

    public function __construct(WidgetRegistry $widgetRegistry)
    {
        $this->widgetRegistry = $widgetRegistry;
    }

    /**
     * 获取部件允许放置的区域
     *
     * @param string $widgetModule 部件模块
     * @param string $widgetCode 部件代码
     * @return array 允许的区域列表
     */
    public function getAllowedAreas(string $widgetModule, string $widgetCode): array
    {
        $widget = $this->getWidget($widgetModule, $widgetCode);
        if (!$widget) {
            // 未找到部件，默认允许放在任何地方
            return array_keys(ThemeLayout::getAreas());
        }

        // 获取部件定义的位置
        $positions = $widget['position'] ?? [];
        if (empty($positions)) {
            // 未定义位置，根据类型推断
            return $this->inferAreasFromType($widget['type'] ?? 'content');
        }

        // 解析位置到区域
        $allowedAreas = [];
        foreach ($positions as $position) {
            if (isset(self::POSITION_TO_AREA_MAP[$position])) {
                $allowedAreas = array_merge($allowedAreas, self::POSITION_TO_AREA_MAP[$position]);
            }
        }

        return array_unique($allowedAreas);
    }

    /**
     * 检查部件是否可以放置在指定区域
     */
    public function canPlaceInArea(string $widgetModule, string $widgetCode, string $area): bool
    {
        // 如果传入的是自定义插槽ID（不在标准区域列表中），默认映射到内容区域
        if (!array_key_exists($area, ThemeLayout::getAreas())) {
            $area = ThemeLayout::AREA_CONTENT;
        }
        $allowedAreas = $this->getAllowedAreas($widgetModule, $widgetCode);
        return in_array($area, $allowedAreas, true);
    }

    /**
     * 检查两个部件是否兼容（可以放在同一个位置槽）
     */
    public function areCompatible(string $module1, string $code1, string $module2, string $code2): bool
    {
        $widget1 = $this->getWidget($module1, $code1);
        $widget2 = $this->getWidget($module2, $code2);

        // 默认排斥
        $compatible1 = $widget1['compatible'] ?? false;
        $compatible2 = $widget2['compatible'] ?? false;

        // 两个都必须是兼容的才能放在一起
        return $compatible1 && $compatible2;
    }

    /**
     * 根据部件类型推断允许的区域
     */
    private function inferAreasFromType(string $type): array
    {
        $typeToAreas = [
            'header' => [ThemeLayout::AREA_HEADER],
            'footer' => [ThemeLayout::AREA_FOOTER],
            'sidebar' => [ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
            'banner' => [ThemeLayout::AREA_BANNER, ThemeLayout::AREA_CONTENT],
            'carousel' => [ThemeLayout::AREA_BANNER, ThemeLayout::AREA_CONTENT],
            'slider' => [ThemeLayout::AREA_BANNER, ThemeLayout::AREA_CONTENT],
            'product' => [ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
            'category' => [ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
            'navigation' => [ThemeLayout::AREA_HEADER, ThemeLayout::AREA_LEFT_SIDEBAR],
            'search' => [ThemeLayout::AREA_HEADER, ThemeLayout::AREA_CONTENT],
            'breadcrumb' => [ThemeLayout::AREA_CONTENT],
            'pagination' => [ThemeLayout::AREA_CONTENT],
            'social' => [ThemeLayout::AREA_FOOTER, ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
            'newsletter' => [ThemeLayout::AREA_FOOTER, ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
            'testimonial' => [ThemeLayout::AREA_CONTENT],
            'faq' => [ThemeLayout::AREA_CONTENT],
            'video' => [ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_BANNER],
            'content' => [ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_LEFT_SIDEBAR, ThemeLayout::AREA_RIGHT_SIDEBAR],
        ];

        return $typeToAreas[$type] ?? [ThemeLayout::AREA_CONTENT];
    }

    /**
     * 获取部件信息
     */
    private function getWidget(string $module, string $code): ?array
    {
        $registry = $this->widgetRegistry->getRegistry();

        // 查找匹配的部件（注册表是嵌套结构：type -> code -> widget_data）
        foreach ($registry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widgetCode => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (isset($widget['module']) && isset($widget['code']) 
                    && $widget['module'] === $module && $widget['code'] === $code) {
                    return $widget;
                }
            }
        }

        return null;
    }

    /**
     * 获取区域内已有部件的兼容性状态
     * 用于判断新部件是否可以添加到该区域
     */
    public function getAreaCompatibilityStatus(array $existingWidgets): array
    {
        $hasIncompatible = false;
        $hasCompatible = false;

        foreach ($existingWidgets as $widget) {
            $widgetInfo = $this->getWidget($widget['widget_module'], $widget['widget_code']);
            $isCompatible = $widgetInfo['compatible'] ?? false;

            if ($isCompatible) {
                $hasCompatible = true;
            } else {
                $hasIncompatible = true;
            }
        }

        return [
            'can_add_compatible' => true, // 兼容部件总是可以添加
            'can_add_incompatible' => !$hasIncompatible, // 只有当没有排斥部件时才能添加排斥部件
            'has_incompatible' => $hasIncompatible,
            'has_compatible' => $hasCompatible,
        ];
    }

    /**
     * 获取所有区域的部件放置信息
     * 用于前端高亮可放置区域
     */
    public function getPlacementInfo(string $widgetModule, string $widgetCode): array
    {
        $allowedAreas = $this->getAllowedAreas($widgetModule, $widgetCode);
        $widget = $this->getWidget($widgetModule, $widgetCode);

        return [
            'allowed_areas' => $allowedAreas,
            'widget_type' => $widget['type'] ?? 'unknown',
            'is_compatible' => $widget['compatible'] ?? false,
            'widget_name' => $widget['name'] ?? $widgetCode,
        ];
    }
}
