<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Storefront contract for default_injections planning helpers.
 *
 * Design (2026-09-21 user纠偏):
 * - Identity XOR (SAME module): the *same* widget must not be both layout-embedded
 *   and listed in `default_injections` JSON (layout OR injection — not both; delete JSON).
 * - CROSS module: FORBID layout/partial mutual widget calls; foreign widgets enter
 *   ONLY via owning-module JSON `default_injections` + empty slot.
 * - Overlay is best-effort; missing fill must not 500. Uninstall (`user_deleted@{versionId}`)
 *   still omits a planned injection.
 *
 * Runtime presence helpers (pageHasWidgetPresent / countWidgetPresent) avoid
 * stacking the same code; XOR at registry/static gate remains the source-of-truth
 * fix for dual-path (delete layout copy XOR `default_injections` JSON — do not 500).
 */
final class RequiredDefaultInjectionContract
{
    public static function userDeletedSource(int $versionId): string
    {
        return $versionId > 0 ? 'user_deleted@' . $versionId : 'user_deleted';
    }

    public static function isUninstallSource(string $source): bool
    {
        return $source === 'user_deleted' || str_starts_with($source, 'user_deleted@');
    }

    public static function matchesVersionUninstall(string $source, int $versionId): bool
    {
        return $versionId > 0 && $source === self::userDeletedSource($versionId);
    }

    /**
     * Required injection targets for a page type (no merge / no node payload).
     *
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string}>
     */
    public static function requiredTargets(array $declarations, string $pageType): array
    {
        $targets = [];
        foreach (self::requiredForPage($declarations, $pageType) as $item) {
            $targets[] = [
                'slot_id' => $item['slot_id'],
                'widget_module' => $item['widget_module'],
                'widget_code' => $item['widget_code'],
            ];
        }

        return $targets;
    }

    /**
     * Detect whether slot inner HTML already contains the widget markers.
     * Zero-tolerance: only data-widget-code / data-testid count — loose data-action /
     * class markers must not suppress required injection.
     */
    public static function slotInnerHasWidgetCode(string $innerHtml, string $module, string $code): bool
    {
        unset($module);
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }
        $innerNorm = strtolower($innerHtml);
        $testid = str_replace('_', '-', $code);

        return str_contains($innerNorm, 'data-widget-code="' . $code . '"')
            || str_contains($innerNorm, "data-widget-code='" . $code . "'")
            || str_contains($innerNorm, 'data-testid="' . $code . '"')
            || str_contains($innerNorm, "data-testid='" . $code . "'")
            || str_contains($innerNorm, 'data-testid="' . $testid . '"')
            || str_contains($innerNorm, "data-testid='" . $testid . "'");
    }

    /**
     * Page-level presence (整页 once): same explicit markers as slotInnerHasWidgetCode,
     * plus data-w-component="{code}" for templates that fetch the widget shell beside
     * an empty declared slot. Prevents required overlay from stacking a second copy.
     */
    public static function pageHasWidgetPresent(string $pageHtml, string $module, string $code): bool
    {
        $pageHtml = self::stripIgnoredPresenceRegions($pageHtml);
        if (self::slotInnerHasWidgetCode($pageHtml, $module, $code)) {
            return true;
        }
        $code = strtolower(trim($code));
        if ($code === '' || $pageHtml === '') {
            return false;
        }
        $norm = strtolower($pageHtml);

        return str_contains($norm, 'data-w-component="' . $code . '"')
            || str_contains($norm, "data-w-component='" . $code . "'")
            || (bool)preg_match(
                '/\bdata-w-component=(["\'])([^"\']*\s)?' . preg_quote($code, '/') . '(\s[^"\']*)?\1/',
                $norm,
            );
    }

    /**
     * Count explicit presence markers (style/script + ignore regions stripped).
     * Counts one marker family only (widget-code → testid → component).
     */
    public static function countWidgetPresent(string $pageHtml, string $module, string $code): int
    {
        unset($module);
        $code = strtolower(trim($code));
        if ($code === '' || $pageHtml === '') {
            return 0;
        }
        $plain = self::stripIgnoredPresenceRegions($pageHtml);
        $plain = preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $plain) ?? $plain;
        $plain = strtolower($plain);
        $testid = str_replace('_', '-', $code);

        $byWidgetCode = substr_count($plain, 'data-widget-code="' . $code . '"')
            + substr_count($plain, "data-widget-code='" . $code . "'");
        if ($byWidgetCode > 0) {
            return $byWidgetCode;
        }

        // hyphenated $code === $testid must not double-count one data-testid.
        $testIdNeedles = array_values(array_unique([
            'data-testid="' . $code . '"',
            "data-testid='" . $code . "'",
            'data-testid="' . $testid . '"',
            "data-testid='" . $testid . "'",
        ]));
        $byTestId = 0;
        foreach ($testIdNeedles as $needle) {
            $byTestId += substr_count($plain, $needle);
        }
        if ($byTestId > 0) {
            return $byTestId;
        }

        return substr_count($plain, 'data-w-component="' . $code . '"')
            + substr_count($plain, "data-w-component='" . $code . "'");
    }

    private static function stripIgnoredPresenceRegions(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace(
            '/<([a-z0-9]+)([^>]*\bdata-purchase-failsafe\b[^>]*)>.*?<\/\1>/is',
            '',
            $html,
        ) ?? $html;
        $html = preg_replace(
            '/<([a-z0-9]+)([^>]*\bdata-required-injection-ignore\b[^>]*)>.*?<\/\1>/is',
            '',
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * Full required injection items (including render node) for a page type.
     *
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    public static function requiredInjections(array $declarations, string $pageType): array
    {
        return self::requiredForPage($declarations, $pageType);
    }

    /**
     * @param list<array<string, mixed>> $omissions
     */
    public static function isUninstalled(array $omissions, string $slotId, string $module, string $code): bool
    {
        return self::isOmitted($omissions, $slotId, $module, $code);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param list<array<string, mixed>> $declarations
     * @param list<array<string, mixed>> $omissions
     * @return array<string, list<array<string, mixed>>>
     */
    public static function merge(array $slots, string $pageType, array $declarations, array $omissions): array
    {
        $pageType = trim($pageType);
        if ($pageType === '') {
            return $slots;
        }

        foreach (self::requiredForPage($declarations, $pageType) as $item) {
            $slotId = $item['slot_id'];
            if (self::slotHasWidget($slots, $slotId, $item['widget_module'], $item['widget_code'])) {
                continue;
            }
            if (self::isOmitted($omissions, $slotId, $item['widget_module'], $item['widget_code'])) {
                continue;
            }
            $slots[$slotId][] = $item['node'];
            usort(
                $slots[$slotId],
                static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)),
            );
        }

        return $slots;
    }

    /**
     * @param list<array<string, mixed>> $declarations
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    private static function requiredForPage(array $declarations, string $pageType): array
    {
        $items = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            $module = trim((string)($declaration['module'] ?? ''));
            $type = trim((string)($declaration['type'] ?? ''));
            $code = trim((string)($declaration['code'] ?? ''));
            if ($module === '' || $type === '' || $code === '') {
                continue;
            }
            $injections = $declaration['default_injections'] ?? [];
            if (!is_array($injections)) {
                continue;
            }
            $isList = $injections === [] || array_keys($injections) === range(0, count($injections) - 1);
            foreach ($isList ? $injections : [$injections] as $injection) {
                if (!is_array($injection) || !self::isRequired($injection['required'] ?? false)) {
                    continue;
                }
                $layoutType = trim((string)($injection['layout_type'] ?? ''));
                if ($layoutType !== '' && $layoutType !== '*' && $layoutType !== $pageType) {
                    continue;
                }
                $option = trim((string)($injection['layout_option'] ?? 'default'));
                if ($option !== '' && $option !== 'default' && $option !== '*') {
                    continue;
                }
                $slotId = trim((string)($injection['slot'] ?? ''));
                if ($slotId === '') {
                    continue;
                }
                $area = trim((string)($injection['area'] ?? ''));
                if ($area === '') {
                    $area = 'content';
                }
                $sort = (int)($injection['sort_order'] ?? 0);
                $items[] = [
                    'slot_id' => $slotId,
                    'widget_module' => $module,
                    'widget_code' => $code,
                    'node' => [
                        'node_uid' => substr(hash('sha256', $pageType . '|' . $slotId . '|' . $module . '|' . $code), 0, 32),
                        'widget_module' => $module,
                        'widget_code' => $code,
                        'widget_type' => $type,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'area' => $area,
                        'slot_id' => $slotId,
                        'config' => [],
                    ],
                ];
            }
        }

        return $items;
    }

    private static function isRequired(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     */
    private static function slotHasWidget(array $slots, string $slotId, string $module, string $code): bool
    {
        foreach ($slots[$slotId] ?? [] as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (trim((string)($widget['widget_code'] ?? '')) !== $code) {
                continue;
            }
            $existingModule = trim((string)($widget['widget_module'] ?? ''));
            // Empty module on a seeded ghost must not suppress a required module.
            if ($existingModule === $module) {
                return true;
            }
            if ($existingModule === '' && $module === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $omissions
     */
    private static function isOmitted(array $omissions, string $slotId, string $module, string $code): bool
    {
        foreach ($omissions as $omission) {
            if (!is_array($omission)) {
                continue;
            }
            $omittedSlot = trim((string)($omission['slot_id'] ?? ''));
            if ($omittedSlot !== '' && $omittedSlot !== $slotId) {
                continue;
            }
            if (trim((string)($omission['widget_code'] ?? '')) !== $code) {
                continue;
            }
            $omittedModule = trim((string)($omission['widget_module'] ?? ''));
            if ($omittedModule === '' || $omittedModule === $module) {
                return true;
            }
        }

        return false;
    }
}
