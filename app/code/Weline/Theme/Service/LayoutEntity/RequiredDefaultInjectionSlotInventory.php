<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * S1: build the slot destination inventory from Catalog declarations (not HTML probing).
 *
 * Root destinations = required injection targets that are not nested under a container
 * on this page. Nested destinations = container `slots` owned by containers that appear
 * as required widgets (or own a required target) on this pageType.
 */
final class RequiredDefaultInjectionSlotInventory
{
    public const MAX_NEST_DEPTH = 3;

    /**
     * @param list<array<string, mixed>> $declarations Catalog rows: module/type/code/default_injections/slots
     * @return array{
     *   slots: array<string, array{origin:string, parent_widget:?string, depth:int}>,
     *   edges: list<array{from_widget:string, to_slot:string}>
     * }
     */
    public static function build(array $declarations, string $pageType): array
    {
        $pageType = \trim($pageType);
        $slots = [];
        $edges = [];
        if ($pageType === '') {
            return ['slots' => $slots, 'edges' => $edges];
        }

        $required = RequiredDefaultInjectionContract::requiredInjections($declarations, $pageType);
        $requiredCodes = [];
        foreach ($required as $item) {
            $code = \trim((string)($item['widget_code'] ?? ''));
            if ($code !== '') {
                $requiredCodes[$code] = true;
            }
            $slotId = \trim((string)($item['slot_id'] ?? ''));
            if ($slotId === '') {
                continue;
            }
            $slots[$slotId] = [
                'origin' => 'injection_target',
                'parent_widget' => null,
                'depth' => 0,
            ];
        }

        /** @var array<string, string> $nestedOwner nested slot id → parent widget code */
        $nestedOwner = [];
        foreach ($declarations as $declaration) {
            if (!\is_array($declaration)) {
                continue;
            }
            $code = \trim((string)($declaration['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $rawSlots = $declaration['slots'] ?? [];
            if (!\is_array($rawSlots) || $rawSlots === []) {
                continue;
            }
            foreach ($rawSlots as $key => $rawSlot) {
                $nestedId = \is_string($key) ? \trim($key) : \trim((string)((\is_array($rawSlot) ? ($rawSlot['id'] ?? '') : '')));
                if ($nestedId === '') {
                    continue;
                }
                $nestedOwner[$nestedId] = $code;
                if (!isset($requiredCodes[$code])) {
                    // Only close nested slots for containers that are required on this page
                    // or that own a required injection target already in $slots.
                    $ownsRequiredTarget = false;
                    foreach ($required as $item) {
                        if (\trim((string)($item['slot_id'] ?? '')) === $nestedId) {
                            $ownsRequiredTarget = true;
                            break;
                        }
                    }
                    if (!$ownsRequiredTarget) {
                        continue;
                    }
                }
                $edges[] = ['from_widget' => $code, 'to_slot' => $nestedId];
                $slots[$nestedId] = [
                    'origin' => 'container_nested',
                    'parent_widget' => $code,
                    'depth' => 1,
                ];
            }
        }

        // Deepen: nested slot that is itself a container's injection target chain.
        for ($round = 0; $round < self::MAX_NEST_DEPTH; ++$round) {
            $changed = false;
            foreach ($slots as $slotId => $meta) {
                $parent = $meta['parent_widget'] ?? null;
                if (!\is_string($parent) || $parent === '') {
                    continue;
                }
                $parentDepth = 0;
                foreach ($required as $item) {
                    if (\trim((string)($item['widget_code'] ?? '')) !== $parent) {
                        continue;
                    }
                    $parentSlot = \trim((string)($item['slot_id'] ?? ''));
                    if ($parentSlot !== '' && isset($slots[$parentSlot])) {
                        $parentDepth = (int)($slots[$parentSlot]['depth'] ?? 0);
                    }
                    break;
                }
                $depth = \min(self::MAX_NEST_DEPTH, $parentDepth + 1);
                if ((int)($meta['depth'] ?? 0) !== $depth) {
                    $slots[$slotId]['depth'] = $depth;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        return ['slots' => $slots, 'edges' => $edges];
    }

    /**
     * @param array{slots?: array<string, array<string, mixed>>} $inventory
     */
    public static function depthOf(array $inventory, string $slotId): int
    {
        $slotId = \trim($slotId);
        $meta = $inventory['slots'][$slotId] ?? null;
        if (!\is_array($meta)) {
            return 0;
        }

        return (int)($meta['depth'] ?? 0);
    }

    /**
     * @param array{slots?: array<string, array<string, mixed>>} $inventory
     */
    public static function hasSlot(array $inventory, string $slotId): bool
    {
        $slotId = \trim($slotId);

        return $slotId !== '' && isset($inventory['slots'][$slotId]);
    }
}
