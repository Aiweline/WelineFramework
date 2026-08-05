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
            $existing->setData(StoreProduct::schema_fields_SELECTED, $selected ? 1 : 0)->save();
            return $existing;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            StoreProduct::schema_fields_STORE_ID => $storeId,
            StoreProduct::schema_fields_PRODUCT_ID => $productId,
            StoreProduct::schema_fields_SELECTED => $selected ? 1 : 0,
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
