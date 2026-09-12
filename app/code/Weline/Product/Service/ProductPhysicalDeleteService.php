<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\ProductSupplier;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\ProductSupplierRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;

/**
 * Cascade physical delete for catalog products (aligned with Hanfu purge order).
 */
final class ProductPhysicalDeleteService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly PriceRepository $prices,
        private readonly StoreOfferRepository $storeOffers,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly StoreProductRepository $storeProducts,
        private readonly MediaRepository $media,
        private readonly AttributeValueRepository $attributeValues,
        private readonly ProductSupplierRepository $productSuppliers,
        private readonly mixed $inventory = null,
    ) {
    }

    /**
     * @param list<int> $productIds
     * @return array{deleted:int,product_ids:list<int>}
     */
    public function deleteByIds(int $websiteId, array $productIds): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('product_physical_delete_website_invalid');
        }
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return ['deleted' => 0, 'product_ids' => []];
        }

        $existing = $this->products->listByIds($websiteId, $productIds);
        $productIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row[\Weline\Product\Model\Shard\Product::schema_fields_ID] ?? 0),
            $existing,
        ), static fn(int $id): bool => $id > 0));
        sort($productIds, SORT_NUMERIC);
        if ($productIds === []) {
            return ['deleted' => 0, 'product_ids' => []];
        }

        $offerRows = $this->offers->listByProductIds($websiteId, $productIds);
        $offerIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row[Offer::schema_fields_ID] ?? 0),
            $offerRows,
        ), static fn(int $id): bool => $id > 0)));
        sort($offerIds, SORT_NUMERIC);

        $this->prices->purgeOfferIds($websiteId, $offerIds);
        $this->storeOffers->purgeOfferIds($websiteId, $offerIds);
        $this->purgeInventory($websiteId, $offerIds);
        foreach ($offerIds as $offerId) {
            $this->attributeValues->purgeEntity($websiteId, 'offer', $offerId);
        }
        $this->offers->deleteByProductIds($websiteId, $productIds);
        $this->categoryLinks->purgeProductIds($websiteId, $productIds);
        $this->storeProducts->purgeProductIds($websiteId, $productIds);
        $this->purgeMedia($websiteId, $productIds);
        foreach ($productIds as $productId) {
            $this->attributeValues->purgeEntity($websiteId, 'product', $productId);
            $this->purgeProductSupplierLinks($websiteId, $productId);
        }
        $this->products->deleteByIds($websiteId, $productIds);

        return ['deleted' => count($productIds), 'product_ids' => $productIds];
    }

    /** @param list<int> $offerIds */
    private function purgeInventory(int $websiteId, array $offerIds): void
    {
        if ($offerIds === []) {
            return;
        }
        $inventory = $this->inventory;
        if ($inventory === null) {
            if (!$this->inventoryModuleEnabled()) {
                return;
            }
            if (!interface_exists(\Weline\Inventory\Api\InventoryCatalogMaintenanceInterface::class)) {
                throw new \RuntimeException('product_physical_delete_inventory_interface_missing');
            }
            $inventory = ObjectManager::getInstance(
                \Weline\Inventory\Api\InventoryCatalogMaintenanceInterface::class,
            );
        }
        if (!$inventory instanceof \Weline\Inventory\Api\InventoryCatalogMaintenanceInterface) {
            throw new \RuntimeException('product_physical_delete_inventory_unavailable');
        }
        $inventory->purgeCatalogOffers($websiteId, $offerIds);
    }

    private function inventoryModuleEnabled(): bool
    {
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        $module = $modules['Weline_Inventory'] ?? null;
        if (!is_array($module)) {
            return false;
        }

        return !empty($module['status']);
    }

    /** @param list<int> $productIds */
    private function purgeMedia(int $websiteId, array $productIds): void
    {
        foreach ($this->media->listByProductIds($websiteId, $productIds, null) as $row) {
            $mediaId = (int)($row[Media::schema_fields_ID] ?? $row['media_id'] ?? 0);
            if ($mediaId > 0) {
                $this->media->remove($websiteId, $mediaId);
            }
        }
    }

    private function purgeProductSupplierLinks(int $websiteId, int $productId): void
    {
        foreach ($this->productSuppliers->listByProduct($websiteId, $productId) as $row) {
            $linkId = (int)($row[ProductSupplier::schema_fields_ID] ?? 0);
            if ($linkId <= 0) {
                continue;
            }
            $link = $this->productSuppliers->findById($websiteId, $linkId);
            $link?->delete();
        }
    }
}
