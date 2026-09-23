<?php
declare(strict_types=1);

namespace Weline\Widget\Service;

/** Semantic registry delta; defaults and editorial metadata are not layout structure. */
final class DefaultInjectionStructureChanges
{
    public static function between(array $before, array $after): ?array
    {
        $old = self::structuralDeclarations($before);
        $new = self::structuralDeclarations($after);
        if ($old === $new) {
            return null;
        }
        $widget = $after !== [] ? $after : $before;
        return [
            'widget_identity' => [
                'area' => (string)($widget['area'] ?? 'frontend'),
                'module' => (string)($widget['module'] ?? ''),
                'type' => (string)($widget['type'] ?? ''),
                'code' => (string)($widget['code'] ?? ''),
            ],
            'before' => $old,
            'after' => $new,
        ];
    }

    private static function structuralDeclarations(array $widget): array
    {
        if (!empty($widget['disabled'])) {
            return [];
        }
        $items = $widget['default_injections'] ?? ($widget['config']['default_injections'] ?? []);
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (!is_array($items) || $items === []) {
            return [];
        }
        $items = array_is_list($items) ? $items : [$items];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slot = trim((string)($item['slot'] ?? ''));
            if ($slot === '') {
                continue;
            }
            $layoutTypes = $item['layout_type'] ?? $item['page_type'] ?? $item['page_layouts'] ?? '*';
            $layoutTypes = is_array($layoutTypes) ? $layoutTypes : [$layoutTypes];
            foreach ($layoutTypes as $layoutType) {
                if (!is_string($layoutType)) { continue; }
                $entry = [
                    'layout_type' => trim($layoutType) ?: '*',
                    'layout_option' => trim((string)($item['layout_option'] ?? 'default')) ?: 'default',
                    'slot' => $slot,
                    'area' => trim((string)($item['area'] ?? 'content')) ?: 'content',
                    'sort_order' => (int)($item['sort_order'] ?? 0),
                    'required' => in_array($item['required'] ?? false, [true, 1, '1', 'true'], true),
                    'default_view' => trim((string)($item['default_view'] ?? '')),
                ];
                $result[json_encode($entry, JSON_THROW_ON_ERROR)] = $entry;
            }
        }
        ksort($result);
        return array_values($result);
    }
}
