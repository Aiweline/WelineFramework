<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\CategoryLink;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCategoryLinkIndex;

final class CategoryLinkRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): CategoryLink)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): CategoryLink)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
        private readonly ?StorefrontCategoryLinkIndex $linkIndex = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function link(
        int $websiteId,
        int $categoryId,
        int $productId,
        int $storeId = 0,
        bool $selected = true,
        int $position = 0,
    ): CategoryLink {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($categoryId < 1 || $productId < 1 || $position < 0) {
            throw new \InvalidArgumentException('product_category_assignment_invalid');
        }
        $existing = $this->find($websiteId, $categoryId, $productId, $storeId);
        if ($existing !== null) {
            $existing
                ->setData(CategoryLink::schema_fields_SCOPE_STATE, 'explicit')
                ->setData(CategoryLink::schema_fields_SELECTED, $selected ? 1 : 0)
                ->setData(CategoryLink::schema_fields_POSITION, $position)
                ->save();
            $this->invalidateLinkCache($websiteId);
            return $this->find($websiteId, $categoryId, $productId, $storeId)
                ?? throw new \RuntimeException('product_category_assignment_readback_failed');
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            CategoryLink::schema_fields_CATEGORY_ID => $categoryId,
            CategoryLink::schema_fields_PRODUCT_ID => $productId,
            CategoryLink::schema_fields_STORE_ID => $storeId,
            CategoryLink::schema_fields_SCOPE_STATE => 'explicit',
            CategoryLink::schema_fields_SELECTED => $selected ? 1 : 0,
            CategoryLink::schema_fields_POSITION => $position,
        ])->save();
        $this->invalidateLinkCache($websiteId);
        return $this->find($websiteId, $categoryId, $productId, $storeId)
            ?? throw new \RuntimeException('product_category_assignment_readback_failed');
    }

    public function unlink(
        int $websiteId,
        int $categoryId,
        int $productId,
        int $storeId = 0,
    ): void {
        $this->assertWebsite($websiteId);
        $existing = $this->find($websiteId, $categoryId, $productId, $storeId);
        if ($existing !== null) {
            $existing->delete();
            $this->invalidateLinkCache($websiteId);
        }
    }

    public function find(
        int $websiteId,
        int $categoryId,
        int $productId,
        int $storeId = 0,
    ): ?CategoryLink {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(CategoryLink::schema_fields_STORE_ID, $storeId)
            ->where(CategoryLink::schema_fields_CATEGORY_ID, $categoryId)
            ->where(CategoryLink::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $storeIds
     * @return list<array<string,mixed>>
     */
    public function listByCategoryIds(
        int $websiteId,
        array $categoryIds,
        array $storeIds = [0],
    ): array {
        $index = $this->linkIndex;
        if ($index instanceof StorefrontCategoryLinkIndex) {
            return $index->listByCategoryIds($websiteId, $categoryIds, $storeIds);
        }

        return $this->listRows($websiteId, $categoryIds, [], $storeIds);
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string,mixed>>
     */
    public function listByProductIds(
        int $websiteId,
        array $productIds,
        array $storeIds = [0],
    ): array {
        $index = $this->linkIndex;
        if ($index instanceof StorefrontCategoryLinkIndex) {
            return $index->listByProductIds($websiteId, $productIds, $storeIds);
        }

        return $this->listRows($websiteId, [], $productIds, $storeIds);
    }

    /**
     * Complete one Product scope. Omitted/inherit rows are deleted so the Store
     * falls back to Website data again.
     *
     * @param list<int|array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function syncProductScope(
        int $websiteId,
        int $productId,
        int $storeId,
        array $rows,
    ): array {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($productId < 1) {
            throw new \InvalidArgumentException('product_category_product_invalid');
        }

        $desired = [];
        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : ['category_id' => $row];
            $categoryId = (int)($row['category_id'] ?? 0);
            $scopeState = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
            $selected = filter_var(
                $row['selected'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            $position = (int)($row['position'] ?? $index);
            if ($categoryId < 1 || $selected === null || $position < 0) {
                throw new \InvalidArgumentException('product_category_assignment_invalid');
            }
            if ($scopeState === 'inherit' || ($storeId === 0 && !$selected)) {
                continue;
            }
            if (!in_array($scopeState, ['explicit', 'cleared'], true)) {
                throw new \InvalidArgumentException('product_category_scope_state_invalid');
            }
            if (isset($desired[$categoryId])) {
                throw new \InvalidArgumentException('product_category_assignment_duplicate');
            }
            $desired[$categoryId] = [
                'selected' => $scopeState === 'cleared' ? false : $selected,
                'position' => $position,
            ];
        }

        $existing = $this->listByProductIds($websiteId, [$productId], [$storeId]);
        foreach ($existing as $row) {
            $categoryId = (int)($row['category_id'] ?? 0);
            if (!isset($desired[$categoryId])) {
                $this->unlink($websiteId, $categoryId, $productId, $storeId);
            }
        }
        foreach ($desired as $categoryId => $row) {
            $this->link(
                $websiteId,
                (int)$categoryId,
                $productId,
                $storeId,
                (bool)$row['selected'],
                (int)$row['position'],
            );
        }
        $this->invalidateLinkCache($websiteId);

        return $this->listByProductIds($websiteId, [$productId], [$storeId]);
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string,mixed>>
     */
    private function listRows(
        int $websiteId,
        array $categoryIds,
        array $productIds,
        array $storeIds,
    ): array {
        $this->assertWebsite($websiteId);
        $categoryIds = $this->positiveIds($categoryIds);
        $productIds = $this->positiveIds($productIds);
        $storeIds = array_values(array_unique(array_filter(
            array_map('intval', $storeIds),
            static fn(int $id): bool => $id >= 0,
        )));
        if (($categoryIds === [] && $productIds === []) || $storeIds === []) {
            return [];
        }
        $query = $this->newModel($websiteId)->clear();
        if ($categoryIds !== []) {
            $query->where(CategoryLink::schema_fields_CATEGORY_ID, $categoryIds, 'IN');
        }
        if ($productIds !== []) {
            $query->where(CategoryLink::schema_fields_PRODUCT_ID, $productIds, 'IN');
        }
        $raw = $query
            ->where(CategoryLink::schema_fields_STORE_ID, $storeIds, 'IN')
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $row) {
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
        usort(
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

    /** @param list<int> $ids @return list<int> */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var CategoryLink $model */
        $model = ObjectManager::create(CategoryLink::class, [], false);
        return $model->forWebsite($websiteId);
    }

    private function invalidateLinkCache(int $websiteId): void
    {
        $index = $this->linkIndex;
        if ($index instanceof StorefrontCategoryLinkIndex) {
            $index->invalidate($websiteId);
        }
    }
}
