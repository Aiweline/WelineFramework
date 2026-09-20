<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Storefront contract for required default_injections.
 *
 * Design (2026-09-20, corrected): 有部件必入声明槽 — subject is the widget.
 * When a required `default_injections` declaration exists and this theme layout
 * version did not record a human uninstall (`user_deleted@{versionId}`), the
 * widget MUST appear in its declared slot. The slot is destination only, not
 * the trigger. Missing published entities, param-only page-config, or stale
 * snapshots are not valid omission reasons.
 *
 * Published render merges/overlays required injections; this class does not write layouts.
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
