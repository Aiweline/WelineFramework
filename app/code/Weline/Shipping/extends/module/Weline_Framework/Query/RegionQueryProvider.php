<?php
declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Shipping\Service\RegionService;

class RegionQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly RegionService $regionService
    ) {
    }

    public function getProviderName(): string
    {
        return 'region';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'list' => $this->regionService->getAllActiveList(),
            'children' => $this->regionService->getChildrenList(
                isset($params['parent_region_id']) && $params['parent_region_id'] !== ''
                    ? (int)$params['parent_region_id']
                    : null,
                trim((string)($params['country_code'] ?? '')) !== ''
                    ? trim((string)$params['country_code'])
                    : null
            ),
            default => throw new \InvalidArgumentException('Region query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'region',
            'name' => 'Frontend region worker API',
            'description' => 'Shipping region lookup operations for address widgets.',
            'module' => 'Weline_Shipping',
            'operations' => [
                [
                    'name' => 'list',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List active shipping regions',
                ],
                [
                    'name' => 'children',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [
                        'parent_region_id' => ['type' => 'int', 'min' => 0],
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List shipping region children',
                ],
            ],
        ];
    }
}
