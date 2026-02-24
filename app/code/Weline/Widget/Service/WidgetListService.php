<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

/**
 * 部件列表服务：分组、按 page_type/slot 过滤、params i18n
 */
class WidgetListService
{
    public const EXCLUSIVE_AREAS = ['header', 'footer'];

    public const SUB_SLOTS_MAP = [
        'logo' => 'header', 'search' => 'header', 'navigation' => 'header', 'user-area' => 'header',
        'account' => 'header', 'cart' => 'header', 'wishlist' => 'header', 'language' => 'header', 'currency' => 'header',
        'copyright' => 'footer', 'links' => 'footer', 'social' => 'footer', 'newsletter' => 'footer', 'payment' => 'footer',
    ];

    public function __construct(
        private readonly WidgetRegistry $widgetRegistry
    ) {
    }

    /**
     * 获取可用部件列表（分组、过滤、params 翻译）
     *
     * @param string|null $pageType 页面类型
     * @param array|null $filterOptions slot_id, area, show_exclusive_only 等
     * @return array 分组后的部件列表 [ type => [ 'label' => ..., 'widgets' => [...] ] ]
     */
    public function getAvailableList(?string $pageType = null, ?array $filterOptions = null): array
    {
        $widgets = $this->widgetRegistry->getRegistry();
        $grouped = [];

        foreach ($widgets as $type => $typeWidgets) {
            if (!is_array($typeWidgets)) {
                continue;
            }
            foreach ($typeWidgets as $widgetName => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (!isset($widget['type'])) {
                    $widget['type'] = $type;
                }
                if (!isset($widget['code'])) {
                    $widget['code'] = $widgetName;
                }
                $originalConfig = $widget['config'] ?? [];
                $pageLayouts = $widget['page_layouts'] ?? $originalConfig['page_layouts'] ?? ['*'];
                $widget['page_layouts'] = $pageLayouts;

                $effectivePageType = $this->effectivePageTypeForFilter($pageType, $filterOptions);
                if ($effectivePageType !== null && !$this->isWidgetAllowedForLayout($pageLayouts, $effectivePageType)) {
                    continue;
                }
                if (!isset($widget['position']) && isset($originalConfig['position'])) {
                    $widget['position'] = $originalConfig['position'];
                }
                if (!isset($widget['position']) || $widget['position'] === '' || $widget['position'] === []) {
                    $widget['position'] = $this->getDefaultPositionByType($type);
                }
                if (!isset($widget['compatible'])) {
                    $widget['compatible'] = $originalConfig['compatible'] ?? false;
                }
                if (!isset($widget['exclusive'])) {
                    $widget['exclusive'] = $originalConfig['exclusive'] ?? false;
                }
                if (!isset($widget['is_container'])) {
                    $widget['is_container'] = $originalConfig['is_container'] ?? false;
                }
                if (!isset($widget['slot'])) {
                    $widget['slot'] = $originalConfig['slot'] ?? null;
                }
                if (!isset($widget['slots'])) {
                    $widget['slots'] = $originalConfig['slots'] ?? [];
                }
                if (!empty($widget['params']) && is_array($widget['params'])) {
                    $translatedParams = [];
                    foreach ($widget['params'] as $paramKey => $paramConfig) {
                        if (!is_array($paramConfig)) {
                            $translatedParams[$paramKey] = $paramConfig;
                            continue;
                        }
                        if (!empty($paramConfig['label'])) {
                            $paramConfig['label'] = __($paramConfig['label']);
                        }
                        if (!empty($paramConfig['description'])) {
                            $paramConfig['description'] = __($paramConfig['description']);
                        }
                        if (!empty($paramConfig['placeholder'])) {
                            $paramConfig['placeholder'] = __($paramConfig['placeholder']);
                        }
                        if (!empty($paramConfig['options']) && is_array($paramConfig['options'])) {
                            $translatedOptions = [];
                            foreach ($paramConfig['options'] as $optionValue => $optionLabel) {
                                $translatedOptions[$optionValue] = __($optionLabel);
                            }
                            $paramConfig['options'] = $translatedOptions;
                        }
                        $translatedParams[$paramKey] = $paramConfig;
                    }
                    $widget['params'] = $translatedParams;
                }
                if ($filterOptions !== null && !$this->matchesSlotFilter($widget, $filterOptions)) {
                    continue;
                }
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [
                        'label' => $this->getTypeLabel($type),
                        'widgets' => [],
                    ];
                }
                $grouped[$type]['widgets'][] = $widget;
            }
        }
        return $grouped;
    }

    /**
     * 后台主题编辑场景下不按 page_type 过滤，返回全部部件；其它场景按传入的 pageType 过滤。
     */
    private function effectivePageTypeForFilter(?string $pageType, ?array $filterOptions): ?string
    {
        if ($pageType === null) {
            return null;
        }
        $area = $filterOptions['area'] ?? null;
        if ($area === 'backend') {
            return null;
        }
        return $pageType;
    }

    private function isWidgetAllowedForLayout(array $widgetLayouts, string $layoutName): bool
    {
        if (in_array('*', $widgetLayouts)) {
            return true;
        }
        if (in_array($layoutName, $widgetLayouts)) {
            return true;
        }
        if (in_array('default', $widgetLayouts)) {
            return true;
        }
        return false;
    }

    private function getDefaultPositionByType(string $type): array
    {
        $map = [
            'header' => ['header'], 'footer' => ['footer'],
            'sidebar' => ['sidebar', 'left_sidebar', 'right_sidebar'],
            'banner' => ['banner', 'content'], 'carousel' => ['banner', 'content'], 'slider' => ['banner', 'content'],
            'product' => ['content', 'sidebar'], 'category' => ['content', 'sidebar'],
            'navigation' => ['header'], 'search' => ['header'], 'breadcrumb' => ['content'],
            'pagination' => ['content'], 'social' => ['footer', 'sidebar'],
            'newsletter' => ['footer', 'sidebar', 'content'], 'testimonial' => ['content'],
            'faq' => ['content'], 'video' => ['content', 'banner'], 'content' => ['content', 'banner', 'sidebar'],
        ];
        return $map[$type] ?? ['content'];
    }

    private function getTypeLabel(string $type): string
    {
        $labels = [
            'header' => __('头部部件'), 'footer' => __('底部部件'), 'sidebar' => __('侧栏部件'),
            'content' => __('内容部件'), 'banner' => __('横幅部件'), 'carousel' => __('轮播部件'),
            'product' => __('产品部件'), 'category' => __('分类部件'), 'navigation' => __('导航部件'),
            'search' => __('搜索部件'), 'social' => __('社交部件'), 'newsletter' => __('订阅部件'),
            'testimonial' => __('评价部件'), 'faq' => __('FAQ部件'), 'breadcrumb' => __('面包屑部件'),
            'pagination' => __('分页部件'), 'video' => __('视频部件'), 'other' => __('其他部件'),
        ];
        return $labels[$type] ?? ucfirst($type);
    }

    private function matchesSlotFilter(array $widget, array $filterOptions): bool
    {
        $slotId = $filterOptions['slot_id'] ?? null;
        $area = $filterOptions['area'] ?? null;
        $showExclusiveOnly = $filterOptions['show_exclusive_only'] ?? false;
        if (!$slotId && !$area) {
            return true;
        }
        if ($area === 'backend') {
            return true;
        }
        $widgetExclusive = $widget['exclusive'] ?? false;
        $widgetSlot = $widget['slot'] ?? null;
        $widgetPositions = $widget['position'] ?? [];
        $widgetType = $widget['type'] ?? '';
        if (!is_array($widgetPositions)) {
            $widgetPositions = [$widgetPositions];
        }
        $isSubSlot = isset(self::SUB_SLOTS_MAP[$slotId]);
        $isExclusiveArea = in_array($area, self::EXCLUSIVE_AREAS);

        if ($isSubSlot) {
            if ($widgetSlot === $slotId || in_array($slotId, $widgetPositions)) {
                return true;
            }
            return false;
        }
        if ($isExclusiveArea && ($area === $slotId || $slotId === null)) {
            if ($showExclusiveOnly) {
                return $widgetExclusive === true;
            }
            if (in_array($area, $widgetPositions) || in_array('*', $widgetPositions)) {
                return true;
            }
            return false;
        }
        if ($area === 'content' || $slotId === 'content') {
            if ($widgetType === 'header' || $widgetType === 'footer') {
                return false;
            }
            if (in_array('content', $widgetPositions) || in_array('*', $widgetPositions)) {
                return true;
            }
            return false;
        }
        if ($area && (in_array($area, $widgetPositions) || in_array('*', $widgetPositions))) {
            return true;
        }
        return false;
    }
}
