<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Service\ProductSearchProjectionService;

/**
 * Server-side public boundary for Search to read Product current projections.
 */
final class ProductSearchProjectionQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ProductSearchProjectionService $projections,
    ) {
    }

    public function getProviderName(): string
    {
        return 'product_search_projection';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'currentWatermark' => [
                'website_id' => $this->websiteId($params),
                'source_watermark' => $this->projections->currentWatermark(
                    $this->websiteId($params),
                ),
            ],
            'snapshotWebsite' => $this->projections->snapshotWebsite(
                $this->websiteId($params),
            ),
            'projectChange' => $this->projections->projectChange($params),
            default => throw new \InvalidArgumentException((string)__(
                'Product Search 投影接口不支持操作：%{1}',
                [$operation],
            )),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Product Search projection',
            'description' => 'Internal Product current-source boundary for Search index workers.',
            'module' => 'Weline_Product',
            'operations' => [
                [
                    'name' => 'currentWatermark',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Read Product Search source watermark',
                ],
                [
                    'name' => 'snapshotWebsite',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 10,
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Read exact published Store/Channel projection snapshot',
                ],
                [
                    'name' => 'projectChange',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                        ['name' => 'event_seq', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'target_type', 'type' => 'string', 'required' => true, 'max_length' => 32],
                        ['name' => 'target_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'store_id', 'type' => 'int', 'required' => false, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Resolve one Product projection event against current source',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function websiteId(array $params): int
    {
        if (!\array_key_exists('website_id', $params)) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 投影接口缺少 website_id',
            ));
        }
        $websiteId = (int)$params['website_id'];
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__(
                'website_id 不能为负数：%{1}',
                [$websiteId],
            ));
        }

        return $websiteId;
    }
}
