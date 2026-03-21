<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Service\WidgetConfigService;
use Weline\Widget\Service\WidgetPreviewService;

class ThemePlaceableRegistry implements ThemePlaceableRegistryInterface
{
    private const EXCLUSIVE_AREAS = ['header', 'footer'];
    private const SUB_SLOTS_MAP = [
        'logo' => 'header', 'search' => 'header', 'navigation' => 'header', 'user-area' => 'header',
        'account' => 'header', 'cart' => 'header', 'wishlist' => 'header', 'language' => 'header', 'currency' => 'header',
        'copyright' => 'footer', 'links' => 'footer', 'social' => 'footer', 'newsletter' => 'footer', 'payment' => 'footer',
    ];

    public function __construct(
        private readonly ThemeComponentCatalog $componentCatalog,
        private readonly ThemeComponentRenderer $componentRenderer,
        private readonly WidgetConfigService $widgetConfigService,
        private readonly WidgetPreviewService $widgetPreviewService,
    ) {
    }

    public function getAvailableList(?string $pageType = null, ?array $filterOptions = null, ?WelineTheme $theme = null, string $area = 'frontend'): array
    {
        $grouped = [];
        foreach ($this->componentCatalog->getDefinitions($area, $theme) as $definition) {
            if (!$this->matchesPageType($definition, $pageType, $filterOptions)) {
                continue;
            }

            $widget = $definition->toWidgetArray();
            if (!$this->matchesSlotFilter($widget, $filterOptions)) {
                continue;
            }

            $groupKey = $this->resolveGroupKey($definition);
            $grouped[$groupKey] ??= [
                'label' => $this->getGroupLabel($groupKey),
                'widgets' => [],
            ];
            $grouped[$groupKey]['widgets'][] = $widget;
        }

        foreach ($grouped as &$group) {
            usort($group['widgets'], fn(array $left, array $right): int => strcmp(
                (string)($left['name'] ?? $left['code'] ?? ''),
                (string)($right['name'] ?? $right['code'] ?? '')
            ));
        }

        ksort($grouped);
        return $grouped;
    }

    public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
    {
        return $this->componentCatalog->find($module, $type, $code, $area, $theme);
    }

    public function getParamDefinitions(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): array
    {
        $definition = $this->find($module, $type, $code, $theme, $area);
        if (!$definition) {
            return [];
        }

        if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
            return $definition->params ?: $this->convertSchemaToParams($definition->configSchema);
        }

        return $this->widgetConfigService->getParamDefinitions($module, $code, $area);
    }

    public function renderPreview(string $module, string $type, string $code, array $config = [], ?WelineTheme $theme = null, string $area = 'frontend'): string
    {
        $definition = $this->find($module, $type, $code, $theme, $area);
        if (!$definition) {
            return '<div class="widget-preview-placeholder">' . htmlspecialchars($code) . '</div>';
        }

        if ($definition->module !== 'Weline_Theme' || $definition->type !== 'theme_component') {
            return $this->widgetPreviewService->render($module, $code, $config, $area);
        }

        $html = $this->componentRenderer->render($definition, $config, $theme, [
            'area' => $area,
            'preview_mode' => true,
        ]);

        return $this->sanitizePreviewHtml($html);
    }

    private function resolveGroupKey(ThemeComponentDefinition $definition): string
    {
        if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
            return $definition->category ?: 'theme';
        }

        return $definition->type ?: 'other';
    }

    private function getGroupLabel(string $groupKey): string
    {
        $labels = [
            'header' => __('头部部件'),
            'footer' => __('底部部件'),
            'content' => __('内容部件'),
            'banner' => __('横幅部件'),
            'carousel' => __('轮播部件'),
            'container' => __('容器部件'),
            'basic' => __('基础部件'),
            'legacy' => __('主题旧部件'),
        ];

        return $labels[$groupKey] ?? ucfirst(str_replace(['-', '_'], ' ', $groupKey));
    }

    private function matchesPageType(ThemeComponentDefinition $definition, ?string $pageType, ?array $filterOptions): bool
    {
        if ($pageType === null) {
            return true;
        }

        $effectiveArea = $filterOptions['area'] ?? null;
        if ($effectiveArea === 'backend') {
            return true;
        }

        return in_array('*', $definition->pageLayouts, true)
            || in_array($pageType, $definition->pageLayouts, true)
            || in_array('default', $definition->pageLayouts, true);
    }

    private function matchesSlotFilter(array $widget, ?array $filterOptions): bool
    {
        if ($filterOptions === null) {
            return true;
        }

        $slotId = $filterOptions['slot_id'] ?? null;
        $area = $filterOptions['area'] ?? null;
        $showExclusiveOnly = (bool)($filterOptions['show_exclusive_only'] ?? false);
        if (!$slotId && !$area) {
            return true;
        }
        if ($area === 'backend') {
            return true;
        }

        $widgetExclusive = (bool)($widget['exclusive'] ?? false);
        $widgetSlot = $widget['slot'] ?? null;
        $widgetPositions = $widget['position'] ?? [];
        $widgetType = $widget['type'] ?? '';
        if (!is_array($widgetPositions)) {
            $widgetPositions = [$widgetPositions];
        }

        $isSubSlot = $slotId ? isset(self::SUB_SLOTS_MAP[$slotId]) : false;
        $isExclusiveArea = $area ? in_array($area, self::EXCLUSIVE_AREAS, true) : false;

        if ($isSubSlot) {
            return $widgetSlot === $slotId || in_array($slotId, $widgetPositions, true);
        }

        if ($isExclusiveArea && ($area === $slotId || $slotId === null)) {
            if ($showExclusiveOnly) {
                return $widgetExclusive;
            }
            return in_array($area, $widgetPositions, true) || in_array('*', $widgetPositions, true);
        }

        if ($area === 'content' || $slotId === 'content') {
            if ($widgetType === 'header' || $widgetType === 'footer') {
                return false;
            }
            return in_array('content', $widgetPositions, true) || in_array('*', $widgetPositions, true);
        }

        if ($area) {
            return in_array($area, $widgetPositions, true) || in_array('*', $widgetPositions, true);
        }

        return true;
    }

    private function convertSchemaToParams(array $schema): array
    {
        $params = [];
        foreach ($schema as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $params[$key] = [
                'name' => $field['label'] ?? $field['name'] ?? $key,
                'label' => $field['label'] ?? $field['name'] ?? $key,
                'description' => $field['description'] ?? '',
                'default' => $field['default'] ?? null,
                'type' => $field['type'] ?? 'text',
                'required' => (bool)($field['required'] ?? false),
                'options' => $field['options'] ?? [],
            ];
        }

        return $params;
    }

    private function sanitizePreviewHtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
            if (function_exists('mb_encode_numericentity')) {
                $wrapped = mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, 0xFFFF], 'UTF-8');
            }
            $document = new \DOMDocument('1.0', 'UTF-8');
            $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($document);
            foreach ($xpath->query('//script|//iframe') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//*[@*]') as $node) {
                if (!$node instanceof \DOMElement || !$node->hasAttributes()) {
                    continue;
                }
                $remove = [];
                foreach ($node->attributes as $attribute) {
                    if (stripos($attribute->name, 'on') === 0) {
                        $remove[] = $attribute->name;
                    }
                }
                foreach ($remove as $name) {
                    $node->removeAttribute($name);
                }
            }
            $body = $xpath->query('//body')->item(0);
            $result = $body ? $document->saveHTML($body) : $html;
            $result = preg_replace('#^<body>|</body>$#', '', (string)$result);
            return $result ?? $html;
        } catch (\Throwable $throwable) {
            return $html;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
