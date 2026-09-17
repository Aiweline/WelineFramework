<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Service\SharedChromeService;

/**
 * Bake-time slot tree helpers extracted from SlotRendererService organization rules.
 */
final class ThemeLayoutSlotTreeBuilder
{
    public function __construct(
        private readonly SharedChromeService $sharedChrome,
    ) {
    }

    /**
     * Organize layoutData widgets by resolved slot_id (same rules as SlotRendererService).
     *
     * @param array<string, mixed> $layoutData
     * @return array<string, list<array<string, mixed>>>
     */
    public function organizeWidgetsBySlot(array $layoutData): array
    {
        $slotWidgets = [];

        foreach ($layoutData as $area => $areaData) {
            $widgets = \is_array($areaData) ? ($areaData['widgets'] ?? []) : [];
            if (!\is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                // Prefer widget slot_id, then meta.config.slot / meta.slot, else area.
                // Use ?: so empty string is treated as invalid (matches SlotRendererService).
                $slotId = (!empty($widget['slot_id']) ? $widget['slot_id'] : null)
                    ?? (!empty($widget['meta']['config']['slot']) ? $widget['meta']['config']['slot'] : null)
                    ?? (!empty($widget['meta']['slot']) ? $widget['meta']['slot'] : null)
                    ?? $area;

                $slotId = (string)$slotId;
                if (!isset($slotWidgets[$slotId])) {
                    $slotWidgets[$slotId] = [];
                }
                $slotWidgets[$slotId][] = $widget;
            }
        }

        foreach ($slotWidgets as &$widgets) {
            \usort($widgets, static function (array $a, array $b): int {
                return ((int)($a['sort_order'] ?? 0)) - ((int)($b['sort_order'] ?? 0));
            });
        }
        unset($widgets);

        return $slotWidgets;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    public function filterChromeNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $area = (string)($node['area'] ?? '');
            $slotId = (string)($node['slot_id'] ?? '');
            if (!$this->sharedChrome->isChromeTarget($area, $slotId)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $out[$uid] = $node;
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    public function filterContentNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $area = (string)($node['area'] ?? '');
            $slotId = (string)($node['slot_id'] ?? '');
            if ($this->sharedChrome->isChromeTarget($area, $slotId)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $out[$uid] = $node;
        }

        return $out;
    }

    /**
     * Convert flat nodes map to layoutData shape used by organizeWidgetsBySlot.
     *
     * @param array<string|int, mixed> $nodes
     * @return array<string, array{widgets: list<array<string, mixed>>}>
     */
    public function nodesToAreaLayout(array $nodes): array
    {
        $layout = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $area = \trim((string)($node['area'] ?? ''));
            if ($area === '') {
                $area = \trim((string)($node['slot_id'] ?? 'content'));
            }
            if ($area === '') {
                $area = 'content';
            }
            if (!isset($layout[$area])) {
                $layout[$area] = ['widgets' => []];
            }
            if (!isset($node['node_uid']) || $node['node_uid'] === '') {
                $node['node_uid'] = \is_string($key) ? $key : (string)$key;
            }
            $layout[$area]['widgets'][] = $node;
        }

        return $layout;
    }
}
