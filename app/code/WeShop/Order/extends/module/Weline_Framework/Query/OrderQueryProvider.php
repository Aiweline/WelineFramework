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
            default => throw new \InvalidArgumentException((string) __('不支持的订单提供者操作：%{1}', [$operation])),
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
                'message' => (string)__('请先登录后再继续。'),
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
            'message' => (string)__('订单加载成功。'),
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
                'message' => (string)__('请先登录后再继续。'),
                'data' => ['redirect_url' => $this->getUrl()->getUrl('customer/account/login')],
            ];
        }

        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('订单ID不能为空。'),
            ];
        }

        $checkResult = $this->orderService->canCancelOrder($orderId, $customerId);
        if (empty($checkResult['can_cancel'])) {
            return [
                'success' => false,
                'message' => (string)($checkResult['reason'] ?? __('该订单无法取消。')),
                'data' => $checkResult,
            ];
        }

        $this->orderService->cancelOrder($orderId, $customerId);

        return [
            'success' => true,
            'message' => !empty($checkResult['require_refund'])
                ? (string)__('订单已取消，退款将按支付方式规则处理。')
                : (string)__('订单已取消。'),
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
            'name' => __('订单查询'),
            'description' => __('提供订单创建、订单商品持久化和账户摘要查询。'),
            'module' => 'WeShop_Order',
            'operations' => [
                [
                    'name' => 'createOrder',
                    'description' => __('创建订单并返回订单摘要。'),
                    'params' => [['name' => 'order_data', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'addOrderItems',
                    'description' => __('批量保存订单商品。'),
                    'params' => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true],
                        ['name' => 'items', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getCustomerDashboardOrders',
                    'description' => __('返回账户中心最近订单和待支付订单。'),
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => true],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'dashboard',
                    'description' => __('返回当前前台客户的订单看板数据。'),
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
                    'summary' => '客户订单看板',
                ],
                [
                    'name' => 'unpaidSummary',
                    'description' => __('返回当前前台客户的待支付订单摘要。'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [
                        'page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => '客户待支付订单',
                ],
                [
                    'name' => 'cancel',
                    'description' => __('取消当前前台客户拥有的订单。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'order_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => '取消客户订单',
                ],
            ],
        ];
    }
}
