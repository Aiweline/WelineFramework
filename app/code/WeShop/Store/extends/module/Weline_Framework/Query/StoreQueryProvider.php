<?php
declare(strict_types=1);

namespace WeShop\Store\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Store\Model\Store;

/**
 * 店铺查询器
 *
 * 提供 getStoreById、getStoreList 等能力，供其他模块通过 w_query('store', ...) 调用。
 */
class StoreQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Store $storeModel
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
            default => throw new \InvalidArgumentException(
                (string)__('Store 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getStoreById(array $params): ?array
    {
        $storeId = (int)($params['store_id'] ?? 0);
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

    private function getStoreList(array $params): array
    {
        $status = $params['status'] ?? null;
        $store = clone $this->storeModel;
        $store->clear();
        if ($status !== null) {
            $store->where(Store::schema_fields_STATUS, (int)$status);
        }
        $store->order(Store::schema_fields_NAME, 'ASC');
        $items = $store->select()->fetch()->getItems();
        $list = [];
        foreach ($items as $s) {
            if (!$s->getId()) {
                continue;
            }
            $list[] = $this->storeToArray($s);
        }
        return $list;
    }

    private function storeToArray($store): array
    {
        return [
            'store_id' => (int)$store->getId(),
            'name' => $store->getData(Store::schema_fields_NAME),
            'code' => $store->getData(Store::schema_fields_CODE),
            'website_id' => (int)($store->getData(Store::schema_fields_WEBSITE_ID) ?? 0),
            'status' => (int)($store->getData(Store::schema_fields_STATUS) ?? 0),
            'description' => $store->getData(Store::schema_fields_DESCRIPTION),
            'address' => $store->getData(Store::schema_fields_ADDRESS),
            'meta_title' => $store->getData(Store::schema_fields_META_TITLE),
            'meta_description' => $store->getData(Store::schema_fields_META_DESCRIPTION),
            'meta_keywords' => $store->getData(Store::schema_fields_META_KEYWORDS),
            'local' => $store->getData(Store::schema_fields_LOCAL),
            'latitude' => $store->getData(Store::schema_fields_LATITUDE),
            'longitude' => $store->getData(Store::schema_fields_LONGITUDE),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'store',
            'name' => __('店铺查询'),
            'description' => __('提供店铺信息查询能力'),
            'module' => 'WeShop_Store',
            'operations' => [
                [
                    'name' => 'getStoreById',
                    'description' => __('根据 ID 获取店铺信息'),
                    'params' => [
                        ['name' => 'store_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'getStoreList',
                    'description' => __('获取店铺列表'),
                    'params' => [
                        ['name' => 'status', 'type' => 'int|null', 'required' => false, 'description' => __('按状态过滤，1=启用')],
                    ],
                ],
            ],
        ];
    }
}
