<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;

/**
 * Checkout-group sibling / shipment-count read model.
 *
 * Terminology (must stay stable across backend + account):
 * - CheckoutGroup → 结账组 (G-…)
 * - Order → 子订单 when group size ≥ 2
 * - OrderShipment → 发货记录 (per child order; multi-row allowed)
 */
final class CheckoutGroupSiblingPresenter
{
    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    /**
     * Pure projection from already-loaded sibling rows + shipment counts.
     *
     * @param list<array<string, mixed>> $siblingRows
     * @param array<int, int> $shipmentCountsByOrderId
     * @return array{
     *     checkout_group_uuid: string,
     *     group_display: string,
     *     sibling_count: int,
     *     current_index: int,
     *     is_multi_order: bool,
     *     is_shipping_charge_owner: bool,
     *     shipment_count: int,
     *     shipment_count_in_group: int,
     *     child_badge: string,
     *     child_label: string,
     *     shipping_owner_label: string,
     *     group_order_count_label: string,
     *     group_shipment_count_label: string,
     *     siblings: list<array<string, mixed>>
     * }
     */
    public function presentFromRows(
        array $siblingRows,
        int|string $currentOrderIdOrUuid = 0,
        array $shipmentCountsByOrderId = [],
        string $checkoutGroupUuid = '',
    ): array {
        $normalized = [];
        foreach ($siblingRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $orderId = (int)($row[Order::schema_fields_ID] ?? $row['order_id'] ?? 0);
            $orderUuid = trim((string)($row[Order::schema_fields_ORDER_UUID] ?? $row['order_uuid'] ?? ''));
            $groupUuid = trim((string)($row[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? $row['checkout_group_uuid'] ?? ''));
            if ($checkoutGroupUuid === '' && $groupUuid !== '') {
                $checkoutGroupUuid = $groupUuid;
            }
            $shipmentCount = $shipmentCountsByOrderId[$orderId]
                ?? (int)($row['shipment_count'] ?? 0);
            $normalized[] = [
                'order_id' => $orderId,
                'order_uuid' => $orderUuid,
                'order_number' => trim((string)($row[Order::schema_fields_ORDER_NUMBER] ?? $row['order_number'] ?? $row['display_number'] ?? '')),
                'status' => trim((string)($row[Order::schema_fields_STATUS] ?? $row['status'] ?? '')),
                'fulfillment_status' => trim((string)($row[Order::schema_fields_FULFILLMENT_STATUS] ?? $row['fulfillment_status'] ?? '')),
                'is_shipping_charge_owner' => !empty($row[Order::schema_fields_IS_SHIPPING_CHARGE_OWNER] ?? $row['is_shipping_charge_owner'] ?? false),
                'shipment_count' => max(0, $shipmentCount),
            ];
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $idCmp = ($a['order_id'] <=> $b['order_id']);
                if ($idCmp !== 0) {
                    return $idCmp;
                }

                return strcmp((string)$a['order_uuid'], (string)$b['order_uuid']);
            }
        );

        $currentKey = is_int($currentOrderIdOrUuid) || ctype_digit((string)$currentOrderIdOrUuid)
            ? (string)(int)$currentOrderIdOrUuid
            : trim((string)$currentOrderIdOrUuid);

        $siblings = [];
        $currentIndex = 0;
        $currentShipmentCount = 0;
        $isOwner = false;
        $groupShipmentCount = 0;
        foreach ($normalized as $i => $row) {
            $index = $i + 1;
            $isCurrent = $this->isCurrentSibling($row, $currentKey);
            if ($isCurrent) {
                $currentIndex = $index;
                $currentShipmentCount = (int)$row['shipment_count'];
                $isOwner = (bool)$row['is_shipping_charge_owner'];
            }
            $groupShipmentCount += (int)$row['shipment_count'];
            $siblings[] = $row + [
                'index' => $index,
                'is_current' => $isCurrent,
                'shipment_badge' => $this->shipmentBadge((int)$row['shipment_count']),
            ];
        }

        $siblingCount = count($siblings);
        if ($currentIndex === 0 && $siblingCount > 0 && $currentKey !== '' && $currentKey !== '0') {
            // Current not in list: keep index 0 so UI does not claim "子订单 x/N".
            $currentIndex = 0;
        } elseif ($currentIndex === 0 && $siblingCount === 1) {
            $currentIndex = 1;
            $currentShipmentCount = (int)$siblings[0]['shipment_count'];
            $isOwner = (bool)$siblings[0]['is_shipping_charge_owner'];
            $siblings[0]['is_current'] = true;
        }

        $isMulti = $siblingCount >= 2;
        $groupDisplay = OrderListKeywordNormalizer::groupDisplayNumber($checkoutGroupUuid);

        return [
            'checkout_group_uuid' => $checkoutGroupUuid,
            'group_display' => $groupDisplay,
            'sibling_count' => $siblingCount,
            'current_index' => $currentIndex,
            'is_multi_order' => $isMulti,
            'is_shipping_charge_owner' => $isOwner,
            'shipment_count' => $currentShipmentCount,
            'shipment_count_in_group' => $groupShipmentCount,
            'child_badge' => $isMulti && $currentIndex > 0
                ? (string)__('子订单 %{1}/%{2}', [$currentIndex, $siblingCount])
                : '',
            'child_label' => $isMulti
                ? (string)__('子订单')
                : (string)__('订单'),
            'shipping_owner_label' => $isOwner
                ? (string)__('是')
                : (string)__('否'),
            'group_order_count_label' => $isMulti
                ? (string)__('结账组 · 含 %{1} 个子订单', [$siblingCount])
                : (string)__('结账组 · 1 笔订单'),
            'group_shipment_count_label' => $groupShipmentCount > 0
                ? (string)__('发货共 %{1} 笔', [$groupShipmentCount])
                : '',
            'siblings' => $siblings,
        ];
    }

    /**
     * @param Order|array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function presentForOrder(Order|array $order): array
    {
        $data = $order instanceof Order ? $order->getData() : $order;
        if (!\is_array($data)) {
            $data = [];
        }
        $groupUuid = trim((string)($data[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? ''));
        $orderId = (int)($data[Order::schema_fields_ID] ?? 0);
        $orderUuid = trim((string)($data[Order::schema_fields_ORDER_UUID] ?? ''));
        $current = $orderId > 0 ? $orderId : $orderUuid;

        if ($groupUuid === '') {
            return $this->presentFromRows(
                $data !== [] ? [$data] : [],
                $current,
                [],
                ''
            );
        }

        $rows = $this->loadSiblingRows($groupUuid);
        if ($rows === [] && $data !== []) {
            $rows = [$data];
        }
        $counts = $this->countShipmentsByOrderIds(array_map(
            static fn (array $row): int => (int)($row[Order::schema_fields_ID] ?? 0),
            $rows
        ));

        return $this->presentFromRows($rows, $current, $counts, $groupUuid);
    }

    /**
     * Batch present for backend order list rows (keyed by order_id).
     *
     * @param list<Order|array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    public function presentBatch(array $orders): array
    {
        $byGroup = [];
        $orderMeta = [];
        foreach ($orders as $order) {
            $data = $order instanceof Order ? $order->getData() : $order;
            if (!\is_array($data)) {
                continue;
            }
            $orderId = (int)($data[Order::schema_fields_ID] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $groupUuid = trim((string)($data[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? ''));
            $orderMeta[$orderId] = [
                'group_uuid' => $groupUuid,
                'data' => $data,
            ];
            if ($groupUuid !== '') {
                $byGroup[$groupUuid][$orderId] = true;
            }
        }

        $groupContexts = [];
        foreach (array_keys($byGroup) as $groupUuid) {
            $rows = $this->loadSiblingRows($groupUuid);
            $ids = array_map(
                static fn (array $row): int => (int)($row[Order::schema_fields_ID] ?? 0),
                $rows
            );
            $counts = $this->countShipmentsByOrderIds($ids);
            foreach ($ids as $id) {
                if ($id <= 0) {
                    continue;
                }
                $groupContexts[$id] = $this->presentFromRows($rows, $id, $counts, $groupUuid);
            }
        }

        $out = [];
        foreach ($orderMeta as $orderId => $meta) {
            if (isset($groupContexts[$orderId])) {
                $out[$orderId] = $groupContexts[$orderId];
                continue;
            }
            $out[$orderId] = $this->presentFromRows(
                [$meta['data']],
                $orderId,
                $this->countShipmentsByOrderIds([$orderId]),
                (string)$meta['group_uuid']
            );
        }

        return $out;
    }

    public function shipmentBadge(int $count): string
    {
        $count = max(0, $count);
        if ($count <= 0) {
            return '';
        }

        return (string)__('发货×%{1}', [$count]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadSiblingRows(string $checkoutGroupUuid): array
    {
        $checkoutGroupUuid = trim($checkoutGroupUuid);
        if ($checkoutGroupUuid === '') {
            return [];
        }

        try {
            // Clone: never clear() the shared Order singleton (would wipe controller-assigned $order).
            /** @var Order $model */
            $model = clone $this->resolve(Order::class);
            $rows = $model->clear()->reset()
                ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $checkoutGroupUuid)
                ->order(Order::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();

            return \is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, int>
     */
    private function countShipmentsByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int)$id, $orderIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($orderIds === []) {
            return [];
        }

        $counts = array_fill_keys($orderIds, 0);
        try {
            // Clone: shared OrderShipment singleton must not be cleared under live request models.
            /** @var OrderShipment $model */
            $model = clone $this->resolve(OrderShipment::class);
            $rows = $model->clear()->reset()
                ->where(OrderShipment::schema_fields_ORDER_ID, $orderIds, 'IN')
                ->select()
                ->fetchArray();
            if (!\is_array($rows)) {
                return $counts;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $oid = (int)($row[OrderShipment::schema_fields_ORDER_ID] ?? 0);
                if ($oid > 0 && isset($counts[$oid])) {
                    ++$counts[$oid];
                }
            }
        } catch (\Throwable) {
            // fail-soft: counts stay zero
        }

        return $counts;
    }

    /** @param array{order_id:int,order_uuid:string} $row */
    private function isCurrentSibling(array $row, string $currentKey): bool
    {
        if ($currentKey === '' || $currentKey === '0') {
            return false;
        }
        if (ctype_digit($currentKey) && (int)$currentKey === (int)$row['order_id']) {
            return true;
        }

        return $currentKey !== '' && $currentKey === (string)$row['order_uuid'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function resolve(string $class): object
    {
        if ($this->objectManager !== null) {
            return $this->objectManager->getInstance($class);
        }

        return ObjectManager::getInstance($class);
    }
}
