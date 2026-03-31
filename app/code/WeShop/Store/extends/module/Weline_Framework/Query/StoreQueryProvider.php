<?php

declare(strict_types=1);

namespace WeShop\Store\Extends\Module\Weline_Framework\Query;

use WeShop\Store\Model\Store;
use WeShop\Store\Service\StoreContextService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class StoreQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Store $storeModel,
        private readonly StoreContextService $storeContextService
    ) {
    }

    public function getProviderName(): string
    {
        return 'store';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getStoreById' => $this->getStoreById($params),
            'getStoreList' => $this->getStoreList($params),
            'getCurrentStore' => $this->getCurrentStore($params),
            default => throw new \InvalidArgumentException(
                (string) __('Store query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function getStoreById(array $params): ?array
    {
        $storeId = (int) ($params['store_id'] ?? 0);
        if ($storeId <= 0) {
            return null;
        }

        $store = clone $this->storeModel;
        $store->load($storeId);
        if (!$store->getId()) {
            return null;
        }

        return $this->storeToArray($store);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function getCurrentStore(array $params): ?array
    {
        $store = $this->storeContextService->getCurrentStore(
            isset($params['website_id']) ? (int) $params['website_id'] : null,
            isset($params['locale']) ? (string) $params['locale'] : null,
            isset($params['currency']) ? (string) $params['currency'] : null
        );

        return $store === null ? null : $this->storeToArray($store);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function getStoreList(array $params): array
    {
        $status = $params['status'] ?? null;
        $store = clone $this->storeModel;
        $store->clear();
        if ($status !== null) {
            $store->where(Store::schema_fields_STATUS, (int) $status);
        }
        $store->order(Store::schema_fields_NAME, 'ASC');

        $items = $store->select()->fetch()->getItems();
        $list = [];
        foreach ($items as $item) {
            if (!$item->getId()) {
                continue;
            }
            $list[] = $this->storeToArray($item);
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeToArray(mixed $store): array
    {
        if (is_array($store)) {
            return [
                'store_id' => (int) ($store[Store::schema_fields_ID] ?? $store['store_id'] ?? 0),
                'name' => $store[Store::schema_fields_NAME] ?? $store['name'] ?? '',
                'code' => $store[Store::schema_fields_CODE] ?? $store['code'] ?? '',
                'website_id' => (int) ($store[Store::schema_fields_WEBSITE_ID] ?? $store['website_id'] ?? 0),
                'status' => (int) ($store[Store::schema_fields_STATUS] ?? $store['status'] ?? 0),
                'description' => $store[Store::schema_fields_DESCRIPTION] ?? $store['description'] ?? '',
                'address' => $store[Store::schema_fields_ADDRESS] ?? $store['address'] ?? '',
                'meta_title' => $store[Store::schema_fields_META_TITLE] ?? $store['meta_title'] ?? '',
                'meta_description' => $store[Store::schema_fields_META_DESCRIPTION] ?? $store['meta_description'] ?? '',
                'meta_keywords' => $store[Store::schema_fields_META_KEYWORDS] ?? $store['meta_keywords'] ?? '',
                'local' => $store[Store::schema_fields_LOCAL] ?? $store['local'] ?? '',
                'currency' => $store[Store::schema_fields_CURRENCY] ?? $store['currency'] ?? '',
                'latitude' => $store[Store::schema_fields_LATITUDE] ?? $store['latitude'] ?? '',
                'longitude' => $store[Store::schema_fields_LONGITUDE] ?? $store['longitude'] ?? '',
                'sort_order' => (int) ($store[Store::schema_fields_SORT_ORDER] ?? $store['sort_order'] ?? 0),
            ];
        }

        return [
            'store_id' => (int) $store->getId(),
            'name' => $store->getData(Store::schema_fields_NAME),
            'code' => $store->getData(Store::schema_fields_CODE),
            'website_id' => (int) ($store->getData(Store::schema_fields_WEBSITE_ID) ?? 0),
            'status' => (int) ($store->getData(Store::schema_fields_STATUS) ?? 0),
            'description' => $store->getData(Store::schema_fields_DESCRIPTION),
            'address' => $store->getData(Store::schema_fields_ADDRESS),
            'meta_title' => $store->getData(Store::schema_fields_META_TITLE),
            'meta_description' => $store->getData(Store::schema_fields_META_DESCRIPTION),
            'meta_keywords' => $store->getData(Store::schema_fields_META_KEYWORDS),
            'local' => $store->getData(Store::schema_fields_LOCAL),
            'currency' => $store->getData(Store::schema_fields_CURRENCY),
            'latitude' => $store->getData(Store::schema_fields_LATITUDE),
            'longitude' => $store->getData(Store::schema_fields_LONGITUDE),
            'sort_order' => (int) ($store->getData(Store::schema_fields_SORT_ORDER) ?? 0),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'store',
            'name' => __('Store Query'),
            'description' => __('Provides store lookup for storefront and API consumers.'),
            'module' => 'WeShop_Store',
            'operations' => [
                [
                    'name' => 'getStoreById',
                    'description' => __('Load a store by id.'),
                    'params' => [
                        ['name' => 'store_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getStoreList',
                    'description' => __('List stores, optionally filtered by status.'),
                    'params' => [
                        ['name' => 'status', 'type' => 'int|null', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getCurrentStore',
                    'description' => __('Resolve the best storefront store for the current website, locale, and currency context.'),
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int|null', 'required' => false],
                        ['name' => 'locale', 'type' => 'string|null', 'required' => false],
                        ['name' => 'currency', 'type' => 'string|null', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
