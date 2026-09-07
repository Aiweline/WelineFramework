<?php
declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
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
            'list' => $this->regionService->getAllActiveList(
                trim((string)($params['country_code'] ?? '')) !== ''
                    ? strtoupper(trim((string)$params['country_code']))
                    : null,
                in_array(($catalog = strtolower(trim((string)($params['catalog'] ?? 'installed')))), ['installed', 'global'], true)
                    ? $catalog
                    : 'installed'
            ),
            'children' => $this->regionService->getChildrenList(
                isset($params['parent_region_id']) && $params['parent_region_id'] !== ''
                    ? (int)$params['parent_region_id']
                    : null,
                trim((string)($params['country_code'] ?? '')) !== ''
                    ? trim((string)$params['country_code'])
                    : null,
                isset($params['limit']) && $params['limit'] !== '' ? (int)$params['limit'] : 500
            ),
            'suggest' => $this->regionService->suggest(
                trim((string)($params['q'] ?? $params['query'] ?? '')),
                trim((string)($params['country_code'] ?? '')) !== ''
                    ? strtoupper(trim((string)$params['country_code']))
                    : null,
                isset($params['limit']) && $params['limit'] !== '' ? (int)$params['limit'] : 8
            ),
            'format_suggestion' => $this->regionService->formatSuggestion(
                (int)($params['id'] ?? $params['region_id'] ?? 0)
            ),
            'country_profile' => $this->regionService->getAddressCountryProfile(
                trim((string)($params['country_code'] ?? '')) !== ''
                    ? strtoupper(trim((string)$params['country_code']))
                    : null
            ),
            'postal_countries' => $this->regionService->postalCountries(
                trim((string)($params['postal_code'] ?? $params['postal'] ?? ''))
            ),
            'postal_lookup' => $this->regionService->postalLookup(
                strtoupper(trim((string)($params['country_code'] ?? ''))),
                trim((string)($params['postal_code'] ?? $params['postal'] ?? '')),
                isset($params['limit']) && $params['limit'] !== '' ? (int)$params['limit'] : 20
            ),
            'has_streets' => [
                'has_streets' => $this->regionService->hasStreets((int)($params['parent_region_id'] ?? 0)),
            ],
            'streets' => $this->regionService->streetsByParent(
                (int)($params['parent_region_id'] ?? 0),
                isset($params['limit']) && $params['limit'] !== '' ? (int)$params['limit'] : 200
            ),
            'embargo_evaluate' => ObjectManager::getInstance(\Weline\Shipping\Service\EmbargoService::class)
                ->evaluateAddress([
                    'country_code' => strtoupper(trim((string)($params['country_code'] ?? ''))),
                    'province_code' => trim((string)($params['province_code'] ?? '')),
                    'province_region_id' => (int)($params['province_region_id'] ?? 0),
                    'city_code' => trim((string)($params['city_code'] ?? '')),
                    'city_region_id' => (int)($params['city_region_id'] ?? 0),
                    'district_code' => trim((string)($params['district_code'] ?? '')),
                    'district_region_id' => (int)($params['district_region_id'] ?? 0),
                    'street_code' => trim((string)($params['street_code'] ?? '')),
                    'street_id' => (int)($params['street_id'] ?? 0),
                ]),
            'embargo_countries' => array_keys(
                ObjectManager::getInstance(\Weline\Shipping\Service\EmbargoService::class)->embargoedCountryCodes()
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
                    'params' => [
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                        'catalog' => ['type' => 'string', 'enum' => ['installed', 'global']],
                    ],
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
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 2000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List shipping region children',
                ],
                [
                    'name' => 'suggest',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 15,
                    'params' => [
                        'q' => ['type' => 'string', 'max_length' => 120],
                        'query' => ['type' => 'string', 'max_length' => 120],
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Local address autocomplete from shipping regions',
                ],
                [
                    'name' => 'format_suggestion',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 30,
                    'params' => [
                        'id' => ['type' => 'int', 'min' => 1],
                        'region_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'object'],
                    'summary' => 'Format a selected region suggestion into address fields',
                ],
                [
                    'name' => 'country_profile',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'params' => [
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                    ],
                    'returns' => ['type' => 'object'],
                    'summary' => 'Per-country address field depth profile',
                ],
                [
                    'name' => 'postal_countries',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [
                        'postal_code' => ['type' => 'string', 'max_length' => 32, 'required' => true],
                        'postal' => ['type' => 'string', 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Countries that match a postal code (for multi-country disambiguation)',
                ],
                [
                    'name' => 'postal_lookup',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [
                        'country_code' => ['type' => 'string', 'max_length' => 8, 'required' => true],
                        'postal_code' => ['type' => 'string', 'max_length' => 32, 'required' => true],
                        'postal' => ['type' => 'string', 'max_length' => 32],
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Postal reverse lookup candidates (requires country_code)',
                ],
                [
                    'name' => 'has_streets',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'params' => [
                        'parent_region_id' => ['type' => 'int', 'min' => 1, 'required' => true],
                    ],
                    'returns' => ['type' => 'object'],
                    'summary' => 'Whether parent region has street cascade data',
                ],
                [
                    'name' => 'streets',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [
                        'parent_region_id' => ['type' => 'int', 'min' => 1, 'required' => true],
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 500],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List streets under parent region',
                ],
                [
                    'name' => 'embargo_evaluate',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                        'province_code' => ['type' => 'string', 'max_length' => 96],
                        'province_region_id' => ['type' => 'int', 'min' => 0],
                        'city_code' => ['type' => 'string', 'max_length' => 96],
                        'city_region_id' => ['type' => 'int', 'min' => 0],
                        'district_code' => ['type' => 'string', 'max_length' => 96],
                        'district_region_id' => ['type' => 'int', 'min' => 0],
                        'street_code' => ['type' => 'string', 'max_length' => 96],
                        'street_id' => ['type' => 'int', 'min' => 0],
                    ],
                    'returns' => ['type' => 'object'],
                    'summary' => 'Evaluate shipping embargo for current address scope',
                ],
                [
                    'name' => 'embargo_countries',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 15,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Country codes blocked by shipping embargo union',
                ],
            ],
        ];
    }
}
