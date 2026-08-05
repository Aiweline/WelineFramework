<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * DEC-015：组运费 100% 计入首张需配送 Order；owner 内行按最大余数法分摊。
 */
final class ShippingAllocationService
{
    public const ERROR_BLOCKED_COMBO = 'checkout_shipping_combo_blocked';

    /**
     * @param list<array{
     *   split_key:string,
     *   requires_shipping:bool,
     *   items:list<array{line_uuid:string,row_total_minor:int,qty_minor:int,requires_shipping?:bool}>
     * }> $orders
     * @return array{
     *   owner_index:int|null,
     *   order_shipping_minor:list<int>,
     *   owner_item_shipping_minor:array<string,int>,
     *   group_shipping_minor:int
     * }
     */
    public function allocate(array $orders, int $groupShippingMinor): array
    {
        $groupShippingMinor = max(0, $groupShippingMinor);
        $ownerIndex = null;
        foreach ($orders as $i => $order) {
            if ((bool)($order['requires_shipping'] ?? false)) {
                $ownerIndex = $i;
                break;
            }
        }

        $orderShipping = array_fill(0, count($orders), 0);
        $itemShipping = [];
        if ($ownerIndex === null) {
            return [
                'owner_index' => null,
                'order_shipping_minor' => $orderShipping,
                'owner_item_shipping_minor' => $itemShipping,
                'group_shipping_minor' => 0,
            ];
        }

        $orderShipping[$ownerIndex] = $groupShippingMinor;
        $ownerItems = $orders[$ownerIndex]['items'] ?? [];
        $shippable = [];
        foreach ($ownerItems as $item) {
            if ((bool)($item['requires_shipping'] ?? true)) {
                $shippable[] = $item;
            }
        }
        $itemShipping = $this->largestRemainder($shippable, $groupShippingMinor);

        return [
            'owner_index' => $ownerIndex,
            'order_shipping_minor' => $orderShipping,
            'owner_item_shipping_minor' => $itemShipping,
            'group_shipping_minor' => $groupShippingMinor,
        ];
    }

    /**
     * Compatibility matrix：多法务主体不可共收运费 → blocked（P2 简化为 explicit flag）.
     *
     * @param list<array<string, mixed>> $orders
     */
    public function assertCompatible(array $orders): void
    {
        $entities = [];
        foreach ($orders as $order) {
            if (!(bool)($order['requires_shipping'] ?? false)) {
                continue;
            }
            $entity = trim((string)($order['legal_entity'] ?? 'default'));
            $entities[$entity] = true;
        }
        if (count($entities) > 1) {
            throw new CheckoutV2ConflictException(
                self::ERROR_BLOCKED_COMBO,
                __('多个不能共同收取运费的法务主体，当前阶段禁止结账'),
                ['legal_entities' => array_keys($entities)],
            );
        }
    }

    /**
     * @param list<array{line_uuid:string,row_total_minor:int,qty_minor:int}> $items
     * @return array<string, int> line_uuid => shipping_minor
     */
    private function largestRemainder(array $items, int $totalMinor): array
    {
        if ($items === [] || $totalMinor <= 0) {
            $out = [];
            foreach ($items as $item) {
                $out[(string)$item['line_uuid']] = 0;
            }
            return $out;
        }

        // Weight：未税需配送行金额；零金额按数量；破同余用稳定 UUID
        $weights = [];
        $sum = 0;
        foreach ($items as $item) {
            $uuid = (string)$item['line_uuid'];
            $w = (int)$item['row_total_minor'];
            if ($w <= 0) {
                $w = max(1, (int)$item['qty_minor']);
            }
            $weights[$uuid] = $w;
            $sum += $w;
        }

        $floors = [];
        $fracs = [];
        $assigned = 0;
        foreach ($weights as $uuid => $w) {
            $exact = ($w / $sum) * $totalMinor;
            $floor = (int)floor($exact);
            $floors[$uuid] = $floor;
            $fracs[$uuid] = $exact - $floor;
            $assigned += $floor;
        }
        $remain = $totalMinor - $assigned;
        $order = array_keys($fracs);
        usort($order, static function (string $a, string $b) use ($fracs): int {
            $cmp = $fracs[$b] <=> $fracs[$a];
            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });
        foreach ($order as $uuid) {
            if ($remain <= 0) {
                break;
            }
            $floors[$uuid]++;
            $remain--;
        }
        return $floors;
    }
}
