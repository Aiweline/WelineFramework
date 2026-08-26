<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\ProductDownloadEntitlementInterface;
use Weline\Product\Service\ProductCurrentCustomerResolver;

final class ProductDownloadQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ProductDownloadEntitlementInterface $entitlements,
        private readonly ProductCurrentCustomerResolver $customers,
    ) {
    }

    public function getProviderName(): string
    {
        return 'product_download';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'mine' => [
                'success' => true,
                'items' => $this->entitlements->listMine(
                    $this->customers->requireCustomerId(),
                    max(0, RequestContext::getWelineWebsiteId()),
                    max(1, min(200, (int)($params['limit'] ?? 100))),
                ),
            ],
            default => throw new \InvalidArgumentException((string)__(
                'Product Download 接口不支持操作：%{1}',
                [$operation],
            )),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Product',
            'summary' => 'Customer-owned downloadable product entitlements',
            'operations' => [[
                'name' => 'mine',
                'frontend' => true,
                'auth' => 'customer',
                'mode' => 'read',
                'graph' => false,
                'cost' => 2,
                'cache_ttl' => 0,
                'params' => [
                    'limit' => [
                        'type' => 'int',
                        'required' => false,
                        'min' => 1,
                        'max' => 200,
                    ],
                ],
                'returns' => ['type' => 'array'],
                'summary' => 'List current customer downloadable entitlements',
            ]],
        ];
    }
}
