<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Order\Service\CustomerPurchaseFactsService;
use Weline\Order\Service\UnpaidOrderSignalService;

/**
 * Read-only unpaid order facts for Marketing (no abandon TTL / no outreach).
 */
final class OrderSignalsQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'order_signals';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        /** @var UnpaidOrderSignalService $service */
        $service = ObjectManager::getInstance(UnpaidOrderSignalService::class);

        return match ($operation) {
            'list_unpaid_orders' => $service->listUnpaid($params),
            'get_unpaid_order' => [
                'item' => $service->getUnpaid($params),
            ],
            'get_customer_purchase_summary' => [
                'item' => ObjectManager::getInstance(CustomerPurchaseFactsService::class)
                    ->summarize((int)($params['customer_id'] ?? 0)),
            ],
            default => throw new \InvalidArgumentException('Unsupported order_signals operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'order_signals',
            'name' => (string)__('未付订单信号'),
            'description' => (string)__('只读未付订单事实，供营销挽回使用（无遗弃 TTL、无触达）。'),
            'module' => 'Weline_Order',
            'operations' => [
                [
                    'name' => 'list_unpaid_orders',
                    'description' => (string)__('列出未付零售订单事实供营销挽回'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Order::order_list',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'lookback_hours', 'type' => 'int', 'required' => false],
                        ['name' => 'created_after', 'type' => 'string', 'required' => false],
                        ['name' => 'created_before', 'type' => 'string', 'required' => false],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false],
                        ['name' => 'store_id', 'type' => 'int', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'get_unpaid_order',
                    'description' => (string)__('发信前再验订单是否仍未付'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Order::order_view',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'order_uuid', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'get_customer_purchase_summary',
                    'description' => (string)__('客户已付订单计数与最近活跃时间'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Order::order_list',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
