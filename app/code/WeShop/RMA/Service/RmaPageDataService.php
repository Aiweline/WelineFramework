<?php

declare(strict_types=1);

namespace WeShop\RMA\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Model\Rma;

class RmaPageDataService
{
    public function __construct(
        private readonly RmaService $rmaService,
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $customerId, int $orderId = 0): array
    {
        $rmaList = $this->normalizeRmas($this->rmaService->getCustomerRmas($customerId));
        $selectedOrder = $this->loadOwnedOrder($customerId, $orderId);

        return [
            'order' => $selectedOrder,
            'rma_list' => $rmaList,
            'rma_count' => count($rmaList),
            'request_types' => [
                ['code' => 'return', 'label' => (string) __('Return')],
                ['code' => 'exchange', 'label' => (string) __('Exchange')],
            ],
            'reason_options' => [
                (string) __('Wrong size'),
                (string) __('Damaged in transit'),
                (string) __('Defective item'),
                (string) __('Item not as described'),
                (string) __('Changed my mind'),
                (string) __('Other'),
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rmas
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeRmas(array $rmas): array
    {
        $mapped = [];
        foreach ($rmas as $rma) {
            $mapped[] = [
                'rma_id' => (int) ($rma[Rma::schema_fields_ID] ?? 0),
                'order_id' => (int) ($rma[Rma::schema_fields_ORDER_ID] ?? 0),
                'reason' => (string) ($rma[Rma::schema_fields_REASON] ?? ''),
                'description' => (string) ($rma[Rma::schema_fields_DESCRIPTION] ?? ''),
                'status' => (string) ($rma[Rma::schema_fields_STATUS] ?? RmaService::STATUS_PENDING),
                'created_at' => (string) ($rma[Rma::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function loadOwnedOrder(int $customerId, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            return null;
        }

        $ownerId = (int) ($order->getData(Order::schema_fields_customer_id) ?? 0);
        if ($ownerId !== $customerId) {
            return null;
        }

        return [
            'order_id' => (int) ($order->getId() ?? 0),
            'increment_id' => (string) ($order->getData(Order::schema_fields_increment_id) ?? ''),
            'status' => (string) ($order->getData(Order::schema_fields_status) ?? ''),
            'total' => (float) ($order->getData(Order::schema_fields_total) ?? 0),
            'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
        ];
    }
}
