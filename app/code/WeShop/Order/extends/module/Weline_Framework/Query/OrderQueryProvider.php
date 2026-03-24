<?php

declare(strict_types=1);

namespace WeShop\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\OrderService;

class OrderQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderItem $orderItemModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'order';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'createOrder' => $this->createOrder($params),
            'addOrderItems' => $this->addOrderItems($params),
            'getCustomerDashboardOrders' => $this->getCustomerDashboardOrders($params),
            default => throw new \InvalidArgumentException((string) __('Unsupported order provider operation: %{1}', [$operation])),
        };
    }

    private function createOrder(array $params): ?array
    {
        $orderData = $params['order_data'] ?? $params;
        if (!\is_array($orderData)) {
            return null;
        }

        $order = $this->orderService->createOrder($orderData);
        if (!$order->getId()) {
            return null;
        }

        return $this->orderToArray($order);
    }

    private function addOrderItems(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $items = $params['items'] ?? [];
        if ($orderId <= 0 || !\is_array($items) || $items === []) {
            return [];
        }

        $savedItems = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $orderItem = clone $this->orderItemModel;
            $orderItem->clearData()
                ->setData(OrderItem::schema_fields_ORDER_ID, $orderId)
                ->setData(OrderItem::schema_fields_PRODUCT_ID, (int) ($item['product_id'] ?? 0))
                ->setData(OrderItem::schema_fields_PRODUCT_NAME, (string) ($item['product_name'] ?? ''))
                ->setData(OrderItem::schema_fields_PRODUCT_SKU, (string) ($item['product_sku'] ?? ''))
                ->setData(OrderItem::schema_fields_QUANTITY, (int) ($item['quantity'] ?? 1))
                ->setData(OrderItem::schema_fields_PRICE, (float) ($item['price'] ?? 0))
                ->setData(OrderItem::schema_fields_TOTAL, (float) ($item['total'] ?? 0))
                ->setData(OrderItem::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->save();

            if ($orderItem->getId()) {
                $savedItems[] = [
                    'item_id' => (int) $orderItem->getId(),
                    'order_id' => $orderId,
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'product_name' => (string) ($item['product_name'] ?? ''),
                    'product_sku' => (string) ($item['product_sku'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0),
                    'total' => (float) ($item['total'] ?? 0),
                ];
            }
        }

        return $savedItems;
    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomerDashboardOrders(array $params): array
    {
        $customerId = (int) ($params['customer_id'] ?? 0);
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = max(1, (int) ($params['page_size'] ?? 3));

        if ($customerId <= 0) {
            return [
                'recent_orders' => [],
                'unpaid_orders' => [],
                'order_count' => 0,
                'unpaid_count' => 0,
            ];
        }

        $recentResult = $this->orderService->getCustomerOrders($customerId, $page, $pageSize);
        $recentOrders = \is_array($recentResult['items'] ?? null) ? $recentResult['items'] : [];
        $unpaidOrders = $this->orderService->getUnpaidOrders($customerId);

        return [
            'recent_orders' => $recentOrders,
            'unpaid_orders' => $unpaidOrders,
            'order_count' => (int) ($recentResult['total'] ?? \count($recentOrders)),
            'unpaid_count' => \count($unpaidOrders),
        ];
    }

    private function orderToArray(Order $order): array
    {
        return [
            'order_id' => (int) $order->getId(),
            'increment_id' => (string) $order->getData(Order::schema_fields_increment_id),
            'customer_id' => (int) ($order->getData(Order::schema_fields_customer_id) ?? 0),
            'status' => (string) ($order->getData(Order::schema_fields_status) ?? ''),
            'total' => (float) ($order->getData(Order::schema_fields_total) ?? 0),
            'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
            'updated_at' => (string) ($order->getData(Order::schema_fields_updated_at) ?? ''),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'order',
            'name' => __('Order Query'),
            'description' => __('Provides order creation, order item persistence, and account-summary queries.'),
            'module' => 'WeShop_Order',
            'operations' => [
                [
                    'name' => 'createOrder',
                    'description' => __('Create an order and return its summary.'),
                    'params' => [['name' => 'order_data', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'addOrderItems',
                    'description' => __('Persist order items in batch.'),
                    'params' => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true],
                        ['name' => 'items', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getCustomerDashboardOrders',
                    'description' => __('Return recent and unpaid orders for the customer account center.'),
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => true],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
