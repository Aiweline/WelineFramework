<?php

declare(strict_types=1);

namespace WeShop\RMA\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Model\Rma;

class RmaPageDataService
{
    /** @var array<int, Order|null> */
    protected array $orderCache = [];

    public function __construct(
        private readonly RmaService $rmaService,
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $customerId, int $orderId = 0, string $orderIncrementId = ''): array
    {
        $rmaList = $this->normalizeRmas($this->rmaService->getCustomerRmas($customerId));
        $selectedOrder = $this->loadOwnedOrder($customerId, $orderId, $orderIncrementId);
        $orderOptions = $this->buildOrderOptions($customerId);

        return [
            'order' => $selectedOrder,
            'order_options' => $orderOptions,
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
     * @return array<int,array<string,mixed>>
     */
    protected function buildOrderOptions(int $customerId): array
    {
        $orders = (array) ($this->orderService->getCustomerOrders($customerId, 1, 50)['items'] ?? []);
        $options = [];
        foreach ($orders as $orderData) {
            if (!is_array($orderData)) {
                continue;
            }

            $orderId = (int) ($orderData[Order::schema_fields_ID] ?? $orderData['order_id'] ?? $orderData['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $ownerId = (int) ($orderData[Order::schema_fields_customer_id] ?? $orderData['customer_id'] ?? 0);
            if ($ownerId !== $customerId) {
                continue;
            }

            $items = $this->normalizeOrderItems($this->orderService->getOrderItems($orderId));
            $eligibility = $this->getReturnEligibility($orderData, count($items));
            $options[] = [
                'order_id' => $orderId,
                'increment_id' => (string) ($orderData[Order::schema_fields_increment_id] ?? ''),
                'status' => (string) ($orderData[Order::schema_fields_status] ?? ''),
                'payment_status' => (string) ($orderData[Order::schema_fields_payment_status] ?? ''),
                'total' => (float) ($orderData[Order::schema_fields_total] ?? 0),
                'created_at' => (string) ($orderData[Order::schema_fields_created_at] ?? ''),
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'],
                'items' => $items,
                'item_count' => count($items),
            ];
        }

        return $options;
    }

    /**
     * @return array{eligible:bool,reason:string}
     */
    protected function getReturnEligibility(array $orderData, int $itemCount): array
    {
        if ($itemCount <= 0) {
            return ['eligible' => false, 'reason' => (string) __('订单没有可售后商品')];
        }

        $status = (string) ($orderData[Order::schema_fields_status] ?? '');
        $paymentStatus = (string) ($orderData[Order::schema_fields_payment_status] ?? '');
        if (in_array($status, [OrderService::STATUS_CANCELLED, OrderService::STATUS_REFUNDED], true)) {
            return ['eligible' => false, 'reason' => (string) __('订单已取消或已退款')];
        }

        if ($paymentStatus !== OrderService::PAYMENT_STATUS_PAID
            && !in_array($status, [OrderService::STATUS_PAID, OrderService::STATUS_FULFILLED, OrderService::STATUS_COMPLETED], true)
        ) {
            return ['eligible' => false, 'reason' => (string) __('订单尚未完成支付')];
        }

        return ['eligible' => true, 'reason' => ''];
    }

    /**
     * @param array<int,array<string,mixed>> $rmas
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeRmas(array $rmas): array
    {
        $mapped = [];
        foreach ($rmas as $rma) {
            if (!is_array($rma)) {
                continue;
            }

            $orderId = (int) ($rma[Rma::schema_fields_ORDER_ID] ?? 0);
            $order = $this->getOrderById($orderId);
            $allOrderItems = $orderId > 0 ? $this->orderService->getOrderItems($orderId) : [];
            $orderItems = $this->normalizeOrderItems($allOrderItems, 3);
            $status = (string) ($rma[Rma::schema_fields_STATUS] ?? RmaService::STATUS_PENDING);
            $mapped[] = [
                'rma_id' => (int) ($rma[Rma::schema_fields_ID] ?? 0),
                'order_id' => $orderId,
                'order_increment_id' => $order ? (string) ($order->getData(Order::schema_fields_increment_id) ?? '') : '',
                'reason' => (string) ($rma[Rma::schema_fields_REASON] ?? ''),
                'description' => (string) ($rma[Rma::schema_fields_DESCRIPTION] ?? ''),
                'status' => $status,
                'status_label' => $this->getStatusLabel($status),
                'created_at' => (string) ($rma[Rma::schema_fields_CREATED_AT] ?? ''),
                'order_total' => $order ? (float) ($order->getData(Order::schema_fields_total) ?? 0) : 0.0,
                'order_created_at' => $order ? (string) ($order->getData(Order::schema_fields_created_at) ?? '') : '',
                'items' => $orderItems,
                'item_count' => count($allOrderItems),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function loadOwnedOrder(int $customerId, int $orderId, string $orderIncrementId): ?array
    {
        $order = null;
        if ($orderIncrementId !== '') {
            $order = $this->getOrderByIncrementId($orderIncrementId);
        } elseif ($orderId > 0) {
            $order = $this->getOrderById($orderId);
        }

        if (!$order && $orderId > 0) {
            $order = $this->getOrderById($orderId);
        }

        if (!$order) {
            return null;
        }

        $ownerId = (int) ($order->getData(Order::schema_fields_customer_id) ?? 0);
        if ($ownerId !== $customerId) {
            return null;
        }

        $orderStatus = (string) ($order->getData(Order::schema_fields_status) ?? '');
        $orderItems = $this->normalizeOrderItems($this->orderService->getOrderItems((int) ($order->getId() ?? 0)));

        return [
            'order_id' => (int) ($order->getId() ?? 0),
            'increment_id' => (string) ($order->getData(Order::schema_fields_increment_id) ?? ''),
            'status' => $orderStatus,
            'status_label' => $orderStatus,
            'total' => (float) ($order->getData(Order::schema_fields_total) ?? 0),
            'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
            'items' => $orderItems,
            'item_count' => count($orderItems),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeOrderItems(array $items, int $limit = 0): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mapped[] = [
                'item_id' => (int) ($item['item_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_name' => (string) ($item['product_name'] ?? ''),
                'product_sku' => (string) ($item['product_sku'] ?? ''),
                'product_image' => (string) ($item['product_image'] ?? $item['image'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price' => (float) ($item['price'] ?? 0),
                'total' => (float) ($item['total'] ?? 0),
            ];

            if ($limit > 0 && count($mapped) >= $limit) {
                break;
            }
        }

        return $mapped;
    }

    protected function getStatusLabel(string $status): string
    {
        return match ($status) {
            RmaService::STATUS_PENDING => (string) __('待处理'),
            RmaService::STATUS_APPROVED => (string) __('已同意'),
            RmaService::STATUS_REJECTED => (string) __('已拒绝'),
            default => $status,
        };
    }

    protected function getOrderById(int $orderId): ?Order
    {
        if ($orderId <= 0) {
            return null;
        }

        if (!array_key_exists($orderId, $this->orderCache)) {
            $this->orderCache[$orderId] = $this->orderService->getOrder($orderId);
        }

        return $this->orderCache[$orderId];
    }

    protected function getOrderByIncrementId(string $orderIncrementId): ?Order
    {
        if ($orderIncrementId === '') {
            return null;
        }

        $order = $this->orderService->getOrderByIncrementId($orderIncrementId);
        if ($order && $order->getId()) {
            $this->orderCache[(int) ($order->getId() ?? 0)] = $order;
        }

        return $order;
    }
}
