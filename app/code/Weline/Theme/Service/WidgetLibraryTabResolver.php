<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Mirrors Theme Editor JS getDashboardWidgetLibraryTab for server-side library filters.
 */
final class WidgetLibraryTabResolver
{
    /**
     * @param array<string,mixed> $widget
     */
    public static function resolve(array $widget): string
    {
        if (self::isDashboardContractWidget($widget)) {
            return 'general';
        }

        $moduleName = self::normalizeCode((string)($widget['module'] ?? ''));
        $widgetType = self::normalizeCode((string)($widget['type'] ?? ''));
        $widgetCode = self::normalizeCode((string)($widget['code'] ?? ''));
        $widgetCodeTail = self::normalizeCodeTail($widgetCode);
        $groupType = self::normalizeCode((string)($widget['group_type'] ?? $widget['groupType'] ?? ''));
        $groupLabel = mb_strtolower((string)($widget['group_label'] ?? $widget['groupLabel'] ?? ''));
        $supportCodes = self::collectSupportCodes($widget);

        $isThemeComponent = $moduleName === 'weline_theme'
            && ($widgetType === 'theme_component' || str_contains($widgetCode, '/') || str_starts_with($widgetCode, 'basic/'));

        $basicByCode = str_starts_with($widgetCode, 'basic/')
            || in_array($widgetCodeTail, self::dashboardBasicWidgetCodes(), true);
        $basicByProtocol = in_array('builder-component', $supportCodes, true)
            || (bool)array_filter($supportCodes, static fn(string $code): bool => str_starts_with($code, 'builder-'));
        $basicByGroup = $groupType === 'basic'
            || $groupType === 'base'
            || str_contains($groupLabel, '基础')
            || str_contains($groupLabel, 'base');

        return $isThemeComponent && ($basicByCode || $basicByGroup || $basicByProtocol) ? 'basic' : 'general';
    }

    /**
     * @param array<string,mixed> $widget
     */
    public static function isAiGenerated(array $widget): bool
    {
        if (!empty($widget['is_ai_generated'])) {
            return true;
        }
        $nested = is_array($widget['widget'] ?? null) ? $widget['widget'] : [];
        if (!empty($nested['is_ai_generated'])) {
            return true;
        }
        $meta = is_array($widget['meta'] ?? null) ? $widget['meta'] : [];
        if (!empty($meta['is_ai_generated'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $widget
     */
    private static function isDashboardContractWidget(array $widget): bool
    {
        foreach (self::collectSupportCodes($widget) as $code) {
            if ($code === 'dashboard' || str_starts_with($code, 'dashboard-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $widget
     * @return list<string>
     */
    private static function collectSupportCodes(array $widget): array
    {
        $codes = [];
        foreach (['supports', 'slots'] as $key) {
            $value = $widget[$key] ?? [];
            if (is_string($value)) {
                $value = preg_split('/[,\s]+/', $value) ?: [];
            }
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                $code = self::normalizeCode((string)$item);
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private static function dashboardBasicWidgetCodes(): array
    {
        return [
            'text',
            'image',
            'button',
            'spacer',
            'divider',
            'heading',
            'rich-text',
            'html',
        ];
    }

    private static function normalizeCode(string $value): string
    {
        return strtolower(trim(str_replace('\\', '/', $value)));
    }

    private static function normalizeCodeTail(string $value): string
    {
        $parts = array_values(array_filter(explode('/', $value), static fn(string $part): bool => $part !== ''));
        return $parts === [] ? '' : (string)end($parts);
    }
}
