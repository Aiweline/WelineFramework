<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * S2: build a topological injection plan from inventory + required declarations.
 * Does not render HTML. Whole-slot once is enforced at execute time.
 */
final class RequiredDefaultInjectionPlanner
{
    /**
     * @param list<array<string, mixed>> $declarations
     * @param list<array<string, mixed>> $omissions version uninstall rows
     * @param array{slots?: array<string, array<string, mixed>>, edges?: list<array<string, string>>} $inventory
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,depth:int,node:array<string,mixed>}>
     */
    public static function plan(array $declarations, string $pageType, array $omissions, array $inventory): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return [];
        }

        $items = [];
        foreach (RequiredDefaultInjectionContract::requiredInjections($declarations, $pageType) as $item) {
            $slotId = \trim((string)($item['slot_id'] ?? ''));
            $module = \trim((string)($item['widget_module'] ?? ''));
            $code = \trim((string)($item['widget_code'] ?? ''));
            if ($slotId === '' || $module === '' || $code === '') {
                continue;
            }
            if (RequiredDefaultInjectionContract::isUninstalled($omissions, $slotId, $module, $code)) {
                continue;
            }
            // Inventory always includes required injection targets (declaration destinations).
            // Nested slots from required containers are closed in SlotInventory::build.
            $items[] = [
                'slot_id' => $slotId,
                'widget_module' => $module,
                'widget_code' => $code,
                'depth' => RequiredDefaultInjectionSlotInventory::depthOf($inventory, $slotId),
                'node' => \is_array($item['node'] ?? null) ? $item['node'] : [],
            ];
        }

        \usort(
            $items,
            static function (array $a, array $b): int {
                $byDepth = ((int)($a['depth'] ?? 0)) <=> ((int)($b['depth'] ?? 0));
                if ($byDepth !== 0) {
                    return $byDepth;
                }
                $sa = (int)($a['node']['sort_order'] ?? 0);
                $sb = (int)($b['node']['sort_order'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }

                return \strcmp((string)$a['widget_code'], (string)$b['widget_code']);
            },
        );

        return $items;
    }
}
