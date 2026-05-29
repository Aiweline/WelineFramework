<?php

declare(strict_types=1);

namespace WeShop\Order\Extends\Module\Weline_Framework\Query;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Server\Service\MemoryStateFacade;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\AccountOrdersListApiService;
use WeShop\Order\Service\OrderService;

class OrderQueryProvider implements QueryProviderInterface
{
    private const FRONTEND_SUMMARY_CACHE_TTL = 60;

    /** @var array<int, array{expires_at:float, data:array<string, mixed>}> */
    private static array $frontendUnpaidSummaryCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

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
            'unpaidSummary' => $this->getFrontendUnpaidSummary(),
            'cancel' => $this->cancel($params),
            'accountListFragment' => $this->getFrontendAccountListFragment($params),
            'getOrdersInfo' => $this->getOrdersInfo($params),
            'getCustomersOrderStats' => $this->getCustomersOrderStats($params),
            default => throw new \InvalidArgumentException((string) __('订单查询器不支持的操作：%{1}', [$operation])),
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

        $customerId = (int)($order->getData(Order::schema_fields_customer_id) ?? 0);
        if ($customerId > 0) {
            self::resetFrontendSummaryCache($customerId);
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

        $result = $this->orderService->addOrderItems($orderId, $items);
        self::resetFrontendSummaryCache();

        return $result;
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
                'message' => (string)__('请先登录。'),
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
            'message' => (string)__('订单已加载。'),
            'data' => $this->getCustomerDashboardOrders([
                'customer_id' => $customerId,
                'page' => max(1, (int) ($params['page'] ?? 1)),
                'page_size' => min(20, max(1, (int) ($params['page_size'] ?? 3))),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFrontendUnpaidSummary(): array
    {
        $profileStart = \microtime(true);
        $customerId = $this->getFrontendCustomerId();
        $this->recordProviderProfile('order.unpaid_summary.customer_context', $profileStart, [
            'customer_id' => $customerId,
        ]);
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please login.'),
                'data' => [
                    'recent_orders' => [],
                    'unpaid_orders' => [],
                    'order_count' => 0,
                    'unpaid_count' => 0,
                    'redirect_url' => '/customer/account/login',
                ],
            ];
        }

        $now = \microtime(true);
        $profileStart = \microtime(true);
        $cached = self::$frontendUnpaidSummaryCache[$customerId] ?? null;
        if (\is_array($cached) && ($cached['expires_at'] ?? 0.0) >= $now) {
            $this->recordProviderProfile('order.unpaid_summary.local_cache', $profileStart, [
                'status' => 'hit',
            ]);
            return $cached['data'];
        }
        $this->recordProviderProfile('order.unpaid_summary.local_cache', $profileStart, [
            'status' => 'miss',
        ]);

        $sharedCacheKey = 'order.unpaid_summary.' . $customerId;
        $profileStart = \microtime(true);
        $sharedSummary = $this->runtimeCacheGet($sharedCacheKey);
        if (\is_array($sharedSummary)) {
            $this->recordProviderProfile('order.unpaid_summary.runtime_cache_get', $profileStart, [
                'status' => 'hit',
            ]);
            self::$frontendUnpaidSummaryCache[$customerId] = [
                'expires_at' => $now + self::FRONTEND_SUMMARY_CACHE_TTL,
                'data' => $sharedSummary,
            ];
            return $sharedSummary;
        }
        $this->recordProviderProfile('order.unpaid_summary.runtime_cache_get', $profileStart, [
            'status' => 'miss',
        ]);

        $profileStart = \microtime(true);
        $unpaidCount = $this->orderService->getUnpaidOrderCount($customerId);
        $this->recordProviderProfile('order.unpaid_summary.order_count', $profileStart);
        $summary = [
            'success' => true,
            'message' => (string)__('Orders loaded.'),
            'data' => [
                'recent_orders' => [],
                'unpaid_orders' => [],
                'order_count' => 0,
                'unpaid_count' => $unpaidCount,
            ],
        ];

        self::$frontendUnpaidSummaryCache[$customerId] = [
            'expires_at' => $now + self::FRONTEND_SUMMARY_CACHE_TTL,
            'data' => $summary,
        ];
        $profileStart = \microtime(true);
        $this->runtimeCacheSet($sharedCacheKey, $summary, self::FRONTEND_SUMMARY_CACHE_TTL);
        $this->recordProviderProfile('order.unpaid_summary.runtime_cache_set', $profileStart);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function getFrontendAccountListFragment(array $params): array
    {
        $customerId = $this->getFrontendCustomerId();
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string) __('请先登录。'),
                'redirect_url' => $this->getUrl()->getUrl('customer/account/login'),
            ];
        }

        return ObjectManager::getInstance(AccountOrdersListApiService::class)
            ->buildPayload($customerId, $params);
    }

    private function cancel(array $params): array
    {
        $customerId = $this->getFrontendCustomerId();
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请先登录。'),
                'data' => ['redirect_url' => $this->getUrl()->getUrl('customer/account/login')],
            ];
        }

        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('缺少订单 ID。'),
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
        self::resetFrontendSummaryCache($customerId);

        return [
            'success' => true,
            'message' => !empty($checkResult['require_refund'])
                ? (string)__('订单已取消。退款将按您的支付方式规则处理。')
                : (string)__('订单已取消。'),
            'data' => ['order_id' => $orderId],
        ];
    }

    public static function resetFrontendSummaryCache(?int $customerId = null): void
    {
        if ($customerId !== null && $customerId > 0) {
            unset(self::$frontendUnpaidSummaryCache[$customerId]);
            self::runtimeCacheDelete('order.unpaid_summary.' . $customerId);
            return;
        }

        self::$frontendUnpaidSummaryCache = [];
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('weshop_order_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set('weshop_order_runtime', $key, $value, max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCacheDelete(string $key): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->delete('weshop_order_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent() || !class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            self::$runtimeCache = new MemoryStateFacade(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'weshop_order_runtime',
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    private function getFrontendCustomerId(): int
    {
        $context = $this->customerContext ?? ObjectManager::getInstance(CustomerContextInterface::class);

        return (int)($context->getUserId() ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOrdersInfo(array $params): array
    {
        $ids = $params['order_ids'] ?? [];
        if (!\is_array($ids) || $ids === []) {
            return [];
        }

        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $rows = ObjectManager::getInstance(Order::class)
            ->clear()
            ->where(Order::schema_fields_ID, $ids, 'in')
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $orderId = (int) ($row[Order::schema_fields_ID] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $result[] = [
                'order_id' => $orderId,
                'increment_id' => (string) ($row[Order::schema_fields_increment_id] ?? ''),
                'customer_id' => (int) ($row[Order::schema_fields_customer_id] ?? 0),
                'status' => (string) ($row[Order::schema_fields_status] ?? ''),
                'payment_status' => (string) ($row[Order::schema_fields_payment_status] ?? ''),
                'fulfillment_status' => (string) ($row[Order::schema_fields_fulfillment_status] ?? ''),
                'currency_code' => $this->normalizeCurrencyCode((string) ($row[Order::schema_fields_currency_code] ?? '')),
                'total' => (float) ($row[Order::schema_fields_total] ?? 0),
                'subtotal' => (float) ($row[Order::schema_fields_subtotal] ?? 0),
                'discount_amount' => (float) ($row[Order::schema_fields_discount_amount] ?? 0),
                'created_at' => (string) ($row[Order::schema_fields_created_at] ?? ''),
                'updated_at' => (string) ($row[Order::schema_fields_updated_at] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCustomersOrderStats(array $params): array
    {
        $ids = $params['customer_ids'] ?? [];
        if (!\is_array($ids) || $ids === []) {
            return [];
        }

        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $stats = [];
        foreach ($ids as $customerId) {
            $stats[$customerId] = [
                'customer_id' => $customerId,
                'order_count' => 0,
                'paid_order_count' => 0,
                'total_amount' => 0.0,
                'paid_amount' => 0.0,
                'last_order_at' => '',
                'currency_code' => '',
                'currency_codes' => [],
            ];
        }

        $rows = ObjectManager::getInstance(Order::class)
            ->clear()
            ->where(Order::schema_fields_customer_id, $ids, 'in')
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $customerId = (int) ($row[Order::schema_fields_customer_id] ?? 0);
            if (!isset($stats[$customerId])) {
                continue;
            }

            $amount = (float) ($row[Order::schema_fields_total] ?? 0);
            $currencyCode = $this->normalizeCurrencyCode((string) ($row[Order::schema_fields_currency_code] ?? ''));
            $paymentStatus = strtolower((string) ($row[Order::schema_fields_payment_status] ?? ''));
            $createdAt = (string) ($row[Order::schema_fields_created_at] ?? '');

            ++$stats[$customerId]['order_count'];
            $stats[$customerId]['total_amount'] = round((float) $stats[$customerId]['total_amount'] + $amount, 2);
            $stats[$customerId]['currency_codes'][$currencyCode] = true;
            if ($paymentStatus === 'paid') {
                ++$stats[$customerId]['paid_order_count'];
                $stats[$customerId]['paid_amount'] = round((float) $stats[$customerId]['paid_amount'] + $amount, 2);
            }
            if ($createdAt !== '' && ($stats[$customerId]['last_order_at'] === '' || strcmp($createdAt, (string) $stats[$customerId]['last_order_at']) > 0)) {
                $stats[$customerId]['last_order_at'] = $createdAt;
            }
        }

        foreach ($stats as &$row) {
            $codes = array_keys(array_filter($row['currency_codes'] ?? []));
            sort($codes);
            $row['currency_code'] = count($codes) === 1 ? $codes[0] : ($codes === [] ? '' : 'MIXED');
            $row['currency_codes'] = $codes;
        }
        unset($row);

        return array_values($stats);
    }

    private function getUrl(): Url
    {
        return $this->url ?? ObjectManager::getInstance(Url::class);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordProviderProfile(string $name, float $startedAt, array $meta = []): void
    {
        $profile = RequestContext::get('query_bin.provider_profile');
        if (!\is_array($profile)) {
            $profile = [];
        }

        $step = [
            'name' => $name,
            'duration_ms' => \round((\microtime(true) - $startedAt) * 1000, 2),
        ];
        if ($meta !== []) {
            $step['meta'] = $meta;
        }
        $profile[] = $step;
        RequestContext::set('query_bin.provider_profile', $profile);
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
            'currency_code' => $this->normalizeCurrencyCode((string) ($order->getData(Order::schema_fields_currency_code) ?? '')),
            'shipping_method' => (string) ($order->getData(Order::schema_fields_shipping_method) ?? ''),
            'payment_method' => (string) ($order->getData(Order::schema_fields_payment_method) ?? ''),
            'total' => (float) ($order->getData(Order::schema_fields_total) ?? 0),
            'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
            'updated_at' => (string) ($order->getData(Order::schema_fields_updated_at) ?? ''),
        ];
    }

    private function normalizeCurrencyCode(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'order',
            'name' => __('订单查询'),
            'description' => __('提供订单创建、订单行持久化与账户摘要查询。'),
            'module' => 'WeShop_Order',
            'operations' => [
                [
                    'name' => 'createOrder',
                    'description' => __('创建订单并返回摘要。'),
                    'params' => [['name' => 'order_data', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'addOrderItems',
                    'description' => __('批量持久化订单商品行。'),
                    'params' => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true],
                        ['name' => 'items', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getCustomerDashboardOrders',
                    'description' => __('返回客户账户中心的最近订单与待付款订单。'),
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => true],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getOrdersInfo',
                    'description' => __('批量返回订单基础信息，供跨模块报表聚合使用。'),
                    'params' => [
                        ['name' => 'order_ids', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getCustomersOrderStats',
                    'description' => __('批量返回客户订单数、成交金额和最近下单时间。'),
                    'params' => [
                        ['name' => 'customer_ids', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'dashboard',
                    'description' => __('返回当前前台客户订单仪表盘数据。'),
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
                    'description' => __('返回当前前台客户待付款订单摘要。'),
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
                    'description' => __('取消当前前台客户拥有的订单。'),
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
                [
                    'name' => 'accountListFragment',
                    'description' => __('返回当前前台客户账户订单列表 JSON（供 Weline.Api 客户端渲染，勿返回 HTML）。'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'order_status' => ['type' => 'string', 'required' => false],
                        'order_page' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000],
                        'order_page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 50],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Account orders list HTML fragment',
                ],
            ],
        ];
    }
}
