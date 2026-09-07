<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Service\NoopProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Store product selection overlay（不得跨 Website；表落在 Website 分片内）.
 */
final class StoreProductRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): StoreProduct)|null */
    private readonly mixed $modelFactory;

    private readonly ProductSearchProjectionMutationCoordinatorInterface $projectionMutations;

    /**
     * @param (\Closure(int): StoreProduct)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        ?ProductSearchProjectionMutationCoordinatorInterface $projectionMutations = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
        $this->projectionMutations = $projectionMutations
            ?? ($modelFactory !== null
                ? new NoopProductSearchProjectionMutationCoordinator()
                : ObjectManager::getInstance(ProductSearchProjectionMutationCoordinatorInterface::class));
    }

    public function select(int $websiteId, int $storeId, int $productId, bool $selected = true): StoreProduct
    {
        $connection = $this->newModel($websiteId)->getConnection();

        return $this->projectionMutations->execute(
            $connection,
            $websiteId,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_STORE_PRODUCT,
            $productId,
            $storeId,
            fn(): StoreProduct => $this->selectCurrent(
                $websiteId,
                $storeId,
                $productId,
                $selected,
            ),
        );
    }

    private function selectCurrent(
        int $websiteId,
        int $storeId,
        int $productId,
        bool $selected,
    ): StoreProduct {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_product');
        $existing = $this->find($websiteId, $storeId, $productId);
        if ($existing !== null) {
            $existing->setData(StoreProduct::schema_fields_SELECTED, $selected ? 1 : 0);
            $existing->setData('inheritance_mode', 'explicit');
            $existing->setData('version', (int)$existing->getData('version') + 1);
            $existing->save();
            return $existing;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            StoreProduct::schema_fields_STORE_ID => $storeId,
            StoreProduct::schema_fields_PRODUCT_ID => $productId,
            StoreProduct::schema_fields_SELECTED => $selected ? 1 : 0,
            'inheritance_mode' => 'explicit',
            'version' => 1,
        ])->save();
        $loaded = $this->find($websiteId, $storeId, $productId);
        if ($loaded === null) {
            throw new \RuntimeException(__('StoreProduct 写入后无法回读'));
        }
        return $loaded;
    }

    public function find(int $websiteId, int $storeId, int $productId): ?StoreProduct
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_product');
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(StoreProduct::schema_fields_STORE_ID, $storeId)
            ->where(StoreProduct::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function isSelected(int $websiteId, int $storeId, int $productId): bool
    {
        $row = $this->find($websiteId, $storeId, $productId);
        if ($row === null) {
            // No overlay → inherit Website catalog membership (selected by default)
            return true;
        }
        return (int)$row->getData(StoreProduct::schema_fields_SELECTED) === 1;
    }

    /**
     * Resolve Store product selection overlays in one read; missing rows inherit selected=true.
     *
     * @param list<int> $productIds
     * @return array<int, bool>
     */
    public function selectionMap(int $websiteId, int $storeId, array $productIds): array
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreOverlayId($storeId, 'store_product');
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $productId): bool => $productId > 0,
        )));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return [];
        }

        $selection = array_fill_keys($productIds, true);
        $rows = $this->newModel($websiteId)
            ->clear()
            ->where(StoreProduct::schema_fields_STORE_ID, $storeId)
            ->where(StoreProduct::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->select()
            ->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            $productId = (int)($row[StoreProduct::schema_fields_PRODUCT_ID] ?? 0);
            if (isset($selection[$productId])) {
                $selection[$productId] = (int)($row[StoreProduct::schema_fields_SELECTED] ?? 0) === 1;
            }
        }

        return $selection;
    }

    /** @param list<int> $productIds */
    public function purgeProductIds(int $websiteId, array $productIds): int
    {
        $this->assertWebsite($websiteId);
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return 0;
        }
        $rows = $this->newModel($websiteId)
            ->clear()
            ->where(StoreProduct::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->select()
            ->fetchArray();
        if ($rows === []) {
            return 0;
        }
        $this->newModel($websiteId)
            ->clear()
            ->where(StoreProduct::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->delete()
            ->fetch();
        return count($rows);
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var StoreProduct $model */
        $model = ObjectManager::create(StoreProduct::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
