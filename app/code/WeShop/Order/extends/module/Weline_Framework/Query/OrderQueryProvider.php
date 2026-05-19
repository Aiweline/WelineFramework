<?php

declare(strict_types=1);

namespace WeShop\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\OrderService;

class OrderQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderItem $orderItemModel,
        private readonly ?CustomerContextInterface $customerContext = null,
        private readonly ?Url $url = null
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
            'dashboard' => $this->getFrontendDashboardOrders($params),
            'unpaidSummary' => $this->getFrontendDashboardOrders($params),
            'cancel' => $this->cancel($params),
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

        return $this->orderService->addOrderItems($orderId, $items);
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

    /**
     * @return array<string, mixed>
     */
    private function getFrontendDashboardOrders(array $params): array
    {
        $customerId = $this->getFrontendCustomerId();
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please log in to continue.'),
                'data' => [
                    'recent_orders' => [],
                    'unpaid_orders' => [],
                    'order_count' => 0,
                    'unpaid_count' => 0,
                    'redirect_url' => $this->getUrl()->getUrl('customer/account/login'),
                ],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Orders loaded.'),
            'data' => $this->getCustomerDashboardOrders([
                'customer_id' => $customerId,
                'page' => max(1, (int) ($params['page'] ?? 1)),
                'page_size' => min(20, max(1, (int) ($params['page_size'] ?? 3))),
            ]),
        ];
    }

    private function cancel(array $params): array
    {
        $customerId = $this->getFrontendCustomerId();
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please log in to continue.'),
                'data' => ['redirect_url' => $this->getUrl()->getUrl('customer/account/login')],
            ];
        }

        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Order ID is required.'),
            ];
        }

        $checkResult = $this->orderService->canCancelOrder($orderId, $customerId);
        if (empty($checkResult['can_cancel'])) {
            return [
                'success' => false,
                'message' => (string)($checkResult['reason'] ?? __('This order cannot be cancelled.')),
                'data' => $checkResult,
            ];
        }

        $this->orderService->cancelOrder($orderId, $customerId);

        return [
            'success' => true,
            'message' => !empty($checkResult['require_refund'])
                ? (string)__('Order cancelled. Refund processing will follow your payment method rules.')
                : (string)__('Order cancelled.'),
            'data' => ['order_id' => $orderId],
        ];
    }

    private function getFrontendCustomerId(): int
    {
        $context = $this->customerContext ?? ObjectManager::getInstance(CustomerContextInterface::class);

        return (int)($context->getUserId() ?? 0);
    }

    private function getUrl(): Url
    {
        return $this->url ?? ObjectManager::getInstance(Url::class);
    }

    private function orderToArray(Order $order): array
    {
        return [
            'order_id' => (int) $order->getId(),
            'increment_id' => (string) $order->getData(Order::schema_fields_increment_id),
            'customer_id' => (int) ($order->getData(Order::schema_fields_customer_id) ?? 0),
            'status' => (string) ($order->getData(Order::schema_fields_status) ?? ''),
            'payment_status' => (string) ($order->getData(Order::schema_fields_payment_status) ?? ''),
            'fulfillment_status' => (string) ($order->getData(Order::schema_fields_fulfillment_status) ?? ''),
            'shipping_method' => (string) ($order->getData(Order::schema_fields_shipping_method) ?? ''),
            'payment_method' => (string) ($order->getData(Order::schema_fields_payment_method) ?? ''),
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
                [
                    'name' => 'dashboard',
                    'description' => __('Return current frontend customer order dashboard data.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 5,
                    'params' => [
                        'page' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000],
                        'page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Customer order dashboard',
                ],
                [
                    'name' => 'unpaidSummary',
                    'description' => __('Return current frontend customer unpaid orders summary.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [
                        'page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Customer unpaid orders',
                ],
                [
                    'name' => 'cancel',
                    'description' => __('Cancel an order owned by the current frontend customer.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'order_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cancel customer order',
                ],
            ],
        ];
    }
}
