<?php

declare(strict_types=1);

namespace Weline\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Service\AbandonedCartSignalService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Read-only abandoned cart facts for Marketing (no abandon TTL / no outreach).
 */
final class CartSignalsQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'cart_signals';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        /** @var AbandonedCartSignalService $service */
        $service = ObjectManager::getInstance(AbandonedCartSignalService::class);

        return match ($operation) {
            'list_stale_carts' => $service->listStaleCarts($params),
            'get_stale_cart' => [
                'item' => $service->getStaleCart($params),
            ],
            default => throw new \InvalidArgumentException('Unsupported cart_signals operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'cart_signals',
            'name' => (string)\__('购物车遗弃信号'),
            'description' => (string)\__('只读非空未过期购物车事实，供营销挽回使用（无遗弃 TTL、无触达）。'),
            'module' => 'Weline_Cart',
            'operations' => [
                [
                    'name' => 'list_stale_carts',
                    'description' => (string)\__('列出未过期非空购物车供营销挽回（含无邮箱）'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Cart::cart_inspection',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'lookback_hours', 'type' => 'int', 'required' => false],
                        ['name' => 'updated_after', 'type' => 'string', 'required' => false],
                        ['name' => 'updated_before', 'type' => 'string', 'required' => false],
                        ['name' => 'created_after', 'type' => 'string', 'required' => false],
                        ['name' => 'created_before', 'type' => 'string', 'required' => false],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                        ['name' => 'exclude_empty', 'type' => 'bool', 'required' => false],
                        ['name' => 'exclude_expired', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'get_stale_cart',
                    'description' => (string)\__('发信前再验购物车是否仍未过期且可达'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Cart::cart_inspection',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'cart_key', 'type' => 'string', 'required' => false],
                        ['name' => 'cart_id', 'type' => 'int', 'required' => false],
                        ['name' => 'exclude_empty', 'type' => 'bool', 'required' => false],
                        ['name' => 'exclude_expired', 'type' => 'bool', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
