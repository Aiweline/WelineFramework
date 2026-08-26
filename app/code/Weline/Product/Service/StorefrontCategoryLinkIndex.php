<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\CategoryLink;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Website-scoped hot cache for category-product link rows.
 */
final class StorefrontCategoryLinkIndex
{
    private const CACHE_POOL = 'weline_product_storefront_category_tree';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly ProductShardProvisioner $provisioner,
    ) {
    }

    public static function logicalCacheKey(int $websiteId): string
    {
        return 'product.category_links.' . max(0, $websiteId);
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    public function listByCategoryIds(
        int $websiteId,
        array $categoryIds,
        array $storeIds = [0],
    ): array {
        return $this->filter(
            $this->forWebsite($websiteId),
            $this->positiveIds($categoryIds),
            [],
            $storeIds,
        );
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    public function listByProductIds(
        int $websiteId,
        array $productIds,
        array $storeIds = [0],
    ): array {
        return $this->filter(
            $this->forWebsite($websiteId),
            [],
            $this->positiveIds($productIds),
            $storeIds,
        );
    }

    public function invalidate(int $websiteId): void
    {
        $websiteId = max(0, $websiteId);
        $this->hotCache->purgeProcessCacheForLogicalKey(self::logicalCacheKey($websiteId));
        $this->hotCache->forget(
            self::CACHE_POOL,
            self::logicalCacheKey($websiteId),
            ['website' => true],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function forWebsite(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->hotCache->remember(
            self::CACHE_POOL,
            self::logicalCacheKey($websiteId),
            self::FRESH_TTL_SECONDS,
            fn(): array => $this->build($websiteId),
            ['website' => true],
            self::STALE_TTL_SECONDS,
        );

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(int $websiteId): array
    {
        $this->provisioner->assertReady($websiteId);
        /** @var CategoryLink $model */
        $model = ObjectManager::create(CategoryLink::class, [], false);
        $model = $model->forWebsite($websiteId);
        $raw = $model->clear()->select()->fetchArray();
        $rows = [];
        foreach ($raw as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rows[] = [
                'link_id' => (int)($row[CategoryLink::schema_fields_ID] ?? 0),
                'category_id' => (int)($row[CategoryLink::schema_fields_CATEGORY_ID] ?? 0),
                'product_id' => (int)($row[CategoryLink::schema_fields_PRODUCT_ID] ?? 0),
                'store_id' => (int)($row[CategoryLink::schema_fields_STORE_ID] ?? 0),
                'scope_state' => (string)($row[CategoryLink::schema_fields_SCOPE_STATE] ?? 'explicit'),
                'selected' => (int)($row[CategoryLink::schema_fields_SELECTED] ?? 0) === 1,
                'position' => (int)($row[CategoryLink::schema_fields_POSITION] ?? 0),
            ];
        }
        \usort(
            $rows,
            static fn(array $left, array $right): int => [
                $left['store_id'],
                $left['position'],
                $left['category_id'],
                $left['product_id'],
            ] <=> [
                $right['store_id'],
                $right['position'],
                $right['category_id'],
                $right['product_id'],
            ],
        );

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $categoryIds
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    private function filter(
        array $rows,
        array $categoryIds,
        array $productIds,
        array $storeIds,
    ): array {
        $storeIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $storeIds),
            static fn(int $id): bool => $id >= 0,
        )));
        if (($categoryIds === [] && $productIds === []) || $storeIds === []) {
            return [];
        }
        $storeLookup = \array_fill_keys($storeIds, true);
        $categoryLookup = $categoryIds !== [] ? \array_fill_keys($categoryIds, true) : [];
        $productLookup = $productIds !== [] ? \array_fill_keys($productIds, true) : [];

        $filtered = [];
        foreach ($rows as $row) {
            if (!isset($storeLookup[(int)($row['store_id'] ?? -1)])) {
                continue;
            }
            if ($categoryIds !== [] && !isset($categoryLookup[(int)($row['category_id'] ?? 0)])) {
                continue;
            }
            if ($productIds !== [] && !isset($productLookup[(int)($row['product_id'] ?? 0)])) {
                continue;
            }
            $filtered[] = $row;
        }

        return $filtered;
    }

    /** @param list<int> $ids @return list<int> */
    private function positiveIds(array $ids): array
    {
        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
    }
}
