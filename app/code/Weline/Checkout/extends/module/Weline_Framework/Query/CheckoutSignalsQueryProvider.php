<?php

declare(strict_types=1);

namespace Weline\Checkout\Extends\Module\Weline_Framework\Query;

use Weline\Checkout\Service\AbandonedCheckoutSignalService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Read-only abandoned checkout facts for Marketing (no abandon TTL / no outreach).
 */
final class CheckoutSignalsQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'checkout_signals';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        /** @var AbandonedCheckoutSignalService $service */
        $service = ObjectManager::getInstance(AbandonedCheckoutSignalService::class);

        return match ($operation) {
            'list_stale_quotes' => $service->listStaleQuotes($params),
            'get_stale_quote' => [
                'item' => $service->getStaleQuote($params),
            ],
            'has_quoted_session' => $service->hasQuotedSession($params),
            default => throw new \InvalidArgumentException('Unsupported checkout_signals operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'checkout_signals',
            'name' => (string)\__('结账遗弃信号'),
            'description' => (string)\__('只读 quoted 结账会话事实，供营销挽回使用（无遗弃 TTL、无触达）。'),
            'module' => 'Weline_Checkout',
            'operations' => [
                [
                    'name' => 'list_stale_quotes',
                    'description' => (string)\__('列出未过期 quoted 结账会话供营销挽回'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Checkout::checkout_sessions',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'lookback_hours', 'type' => 'int', 'required' => false],
                        ['name' => 'created_after', 'type' => 'string', 'required' => false],
                        ['name' => 'created_before', 'type' => 'string', 'required' => false],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'get_stale_quote',
                    'description' => (string)\__('发信前再验结账会话是否仍为 quoted 且可达'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Checkout::checkout_sessions',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'quote_token', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'has_quoted_session',
                    'description' => (string)\__('按 customer_id 或 cart_key 判断是否存在未过期 quoted 结账会话（供购物车挽回去重）'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Checkout::checkout_sessions',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => false],
                        ['name' => 'cart_key', 'type' => 'string', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
