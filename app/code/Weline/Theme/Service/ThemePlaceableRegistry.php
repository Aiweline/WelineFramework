<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Interface\ThemePlaceableRegistryInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Api\WidgetConfigService;
use Weline\Widget\Api\WidgetPreviewService;

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
            $groupKey = $this->resolveGroupKey($definition);
            if (!$this->matchesLibraryFilter($widget, $groupKey, $filterOptions)) {
                continue;
            }
            if (!$this->matchesSlotFilter($widget, $filterOptions)) {
                continue;
            }

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
            'stats' => __('统计部件'),
            'chart' => __('图表部件'),
            'table' => __('表格部件'),
            'basic' => __('基础组件 / HTML 搭建'),
            'legacy' => __('主题旧部件'),
        ];

        return $labels[$groupKey] ?? ucfirst(str_replace(['-', '_'], ' ', $groupKey));
    }

    private function matchesPageType(ThemeComponentDefinition $definition, ?string $pageType, ?array $filterOptions): bool
    {
        if ($pageType === null) {
            return true;
        }

        return in_array('*', $definition->pageLayouts, true)
            || in_array($pageType, $definition->pageLayouts, true)
            || in_array('default', $definition->pageLayouts, true)
            || $this->matchesLayoutSupportCode($definition, $pageType);
    }

    private function matchesLayoutSupportCode(ThemeComponentDefinition $definition, string $pageType): bool
    {
        $layout = strtolower(trim($pageType));
        if ($layout === '') {
            return false;
        }

        $layoutCode = 'layout-' . $layout;
        $layoutPrefix = $layoutCode . '-';
        foreach ($this->collectWidgetSupportCodes($definition->toWidgetArray()) as $code) {
            if ($code === $layoutCode || str_starts_with($code, $layoutPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function matchesSlotFilter(array $widget, ?array $filterOptions): bool
    {
        if ($filterOptions === null) {
            return true;
        }

        $slotId = $filterOptions['slot_id'] ?? null;
        $area = $filterOptions['slot_area'] ?? ($filterOptions['area'] ?? null);
        $showExclusiveOnly = (bool)($filterOptions['show_exclusive_only'] ?? false);
        $acceptCodes = $this->normalizeCodeList($filterOptions['accept'] ?? $filterOptions['accept_codes'] ?? []);
        $rejectCodes = $this->normalizeCodeList($filterOptions['reject'] ?? $filterOptions['reject_codes'] ?? []);

        // backend/frontend 是组件注册目录，不是可摆放 slot 区域。没有指定 slot 时只按 page_layouts 过滤。
        if (($area === 'backend' || $area === 'frontend') && !$slotId) {
            $widgetCodes = $this->collectWidgetSupportCodes($widget);
            if ($rejectCodes !== [] && array_intersect($rejectCodes, $widgetCodes) !== []) {
                return false;
            }
            return $acceptCodes === [] && $rejectCodes === []
                ? true
                : $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
        }
        if ($area === 'backend' || $area === 'frontend') {
            $area = null;
        }

        if (!$slotId && !$area) {
            if ($rejectCodes !== [] && array_intersect($rejectCodes, $this->collectWidgetSupportCodes($widget)) !== []) {
                return false;
            }
            return $this->matchesSlotCodeProtocol($acceptCodes, $this->collectWidgetSupportCodes($widget));
        }

        $widgetCodes = $this->collectWidgetSupportCodes($widget);
        if ($rejectCodes !== [] && array_intersect($rejectCodes, $widgetCodes) !== []) {
            return false;
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
            if ($acceptCodes !== []) {
                return $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
            }
            return in_array((string)$slotId, $widgetCodes, true)
                || $widgetSlot === $slotId
                || in_array($slotId, $widgetPositions, true);
        }

        if ($isExclusiveArea && ($area === $slotId || $slotId === null)) {
            if ($showExclusiveOnly) {
                return $widgetExclusive && $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
            }
            return (in_array($area, $widgetPositions, true) || in_array('*', $widgetPositions, true))
                && $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
        }

        if ($area === 'content' || $slotId === 'content') {
            if ($widgetType === 'header' || $widgetType === 'footer') {
                return false;
            }
            return (in_array('content', $widgetPositions, true) || in_array('*', $widgetPositions, true))
                && $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
        }

        if ($area) {
            return (in_array($area, $widgetPositions, true) || in_array('*', $widgetPositions, true))
                && $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
        }

        return $this->matchesSlotCodeProtocol($acceptCodes, $widgetCodes);
    }

    private function matchesLibraryFilter(array $widget, string $groupKey, ?array $filterOptions): bool
    {
        if ($filterOptions === null) {
            return true;
        }

        $groupCodes = $this->normalizeCodeList([
            $groupKey,
            $widget['group_type'] ?? null,
            $widget['type'] ?? null,
        ]);
        $identityCodes = $this->collectWidgetIdentityCodes($widget);
        $supportCodes = $this->collectWidgetSupportCodes($widget);

        if (!$this->matchesAllowRejectFilter(
            $groupCodes,
            $this->readFilterCodes($filterOptions, ['widget_allow_groups', 'allow_groups', 'widget_group_allow']),
            $this->readFilterCodes($filterOptions, ['widget_reject_groups', 'reject_groups', 'widget_group_reject'])
        )) {
            return false;
        }

        if (!$this->matchesAllowRejectFilter(
            $identityCodes,
            $this->readFilterCodes($filterOptions, ['widget_allow_codes', 'widget_allow_widgets', 'allow_codes', 'allow_widgets']),
            $this->readFilterCodes($filterOptions, ['widget_reject_codes', 'widget_reject_widgets', 'reject_codes', 'reject_widgets'])
        )) {
            return false;
        }

        return $this->matchesAllowRejectFilter(
            $supportCodes,
            $this->readFilterCodes($filterOptions, ['widget_allow_supports', 'widget_allow_protocols', 'allow_supports', 'allow_protocols']),
            $this->readFilterCodes($filterOptions, ['widget_reject_supports', 'widget_reject_protocols', 'reject_supports', 'reject_protocols'])
        );
    }

    /**
     * @param list<string> $candidateCodes
     * @param list<string> $allowCodes
     * @param list<string> $rejectCodes
     */
    private function matchesAllowRejectFilter(array $candidateCodes, array $allowCodes, array $rejectCodes): bool
    {
        if ($rejectCodes !== [] && array_intersect($rejectCodes, $candidateCodes) !== []) {
            return false;
        }

        return $allowCodes === []
            || in_array('*', $allowCodes, true)
            || array_intersect($allowCodes, $candidateCodes) !== [];
    }

    /**
     * @param array<string,mixed> $filterOptions
     * @param list<string> $keys
     * @return list<string>
     */
    private function readFilterCodes(array $filterOptions, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $filterOptions)) {
                continue;
            }
            $values[] = $filterOptions[$key];
        }

        return $this->normalizeCodeList($values);
    }

    private function matchesSlotCodeProtocol(array $acceptCodes, array $widgetCodes): bool
    {
        if ($acceptCodes === [] || in_array('*', $acceptCodes, true)) {
            return true;
        }

        $expandedAccept = $this->expandAcceptCodeList($acceptCodes);

        return array_intersect($expandedAccept, $widgetCodes) !== [];
    }

    /**
     * 布局变体 accept 与通用 layout 码互通，例如：
     * layout-homepage-minimal-content 亦接受部件 supports 中的 layout-homepage-content。
     *
     * @return list<string>
     */
    private function expandAcceptCodeList(array $acceptCodes): array
    {
        $expanded = [];
        foreach ($acceptCodes as $code) {
            $code = strtolower(trim((string)$code));
            if ($code === '') {
                continue;
            }
            foreach ($this->expandAcceptAliases($code) as $alias) {
                $expanded[$alias] = $alias;
            }
        }

        return array_values($expanded);
    }

    /**
     * @return list<string>
     */
    private function expandAcceptAliases(string $acceptCode): array
    {
        $aliases = [$acceptCode];
        if (preg_match('#^layout-([^-]+)-([^-]+)-(.+)$#', $acceptCode, $matches) === 1) {
            $aliases[] = 'layout-' . $matches[1] . '-' . $matches[3];
        }

        return array_values(array_unique($aliases));
    }

    private function collectWidgetSupportCodes(array $widget): array
    {
        $codes = [
            $widget['code'] ?? null,
            $widget['type'] ?? null,
            $widget['slot'] ?? null,
        ];

        $supports = $widget['supports'] ?? [];
        if (!is_array($supports)) {
            $supports = [$supports];
        }
        foreach ($supports as $supportCode) {
            $codes[] = $supportCode;
        }

        $positions = $widget['position'] ?? [];
        if (!is_array($positions)) {
            $positions = [$positions];
        }
        foreach ($positions as $position) {
            $codes[] = $position;
        }

        $slots = $widget['slots'] ?? [];
        if (is_array($slots)) {
            foreach ($slots as $key => $slotConfig) {
                $codes[] = $key;
                if (is_array($slotConfig)) {
                    $codes[] = $slotConfig['id'] ?? null;
                    $codes[] = $slotConfig['code'] ?? null;
                }
            }
        }

        return $this->normalizeCodeList($codes);
    }

    private function collectWidgetIdentityCodes(array $widget): array
    {
        $code = (string)($widget['code'] ?? '');
        $module = (string)($widget['module'] ?? '');
        $codes = [
            $code,
            $widget['type'] ?? null,
            $widget['slot'] ?? null,
        ];

        $normalizedCode = strtolower(trim(str_replace('\\', '/', $code)));
        if ($normalizedCode !== '') {
            $parts = array_values(array_filter(explode('/', $normalizedCode), static fn(string $part): bool => $part !== ''));
            if ($parts !== []) {
                $codes[] = end($parts);
            }
        }

        if ($module !== '' && $code !== '') {
            $moduleCode = strtolower(trim((string)$module));
            $codes[] = $moduleCode . '::' . $normalizedCode;
            $codes[] = str_replace('_', '-', $moduleCode) . '/' . $normalizedCode;
        }

        return $this->normalizeCodeList($codes);
    }

    private function normalizeCodeList(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach ($item as $nested) {
                        $items[] = $nested;
                    }
                    continue;
                }
                $items[] = $item;
            }
        } else {
            $items = explode(',', (string)$value);
        }

        $codes = [];
        foreach ($items as $item) {
            $code = strtolower(trim((string)$item));
            if ($code === '') {
                continue;
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
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
                'i18n' => (bool)($field['i18n'] ?? $field['translate'] ?? $field['translatable'] ?? false),
                'translate' => (bool)($field['translate'] ?? $field['i18n'] ?? $field['translatable'] ?? false),
                'translatable' => (bool)($field['translatable'] ?? $field['i18n'] ?? $field['translate'] ?? false),
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
