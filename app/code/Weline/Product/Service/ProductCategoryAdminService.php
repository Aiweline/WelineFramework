<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Backend tree editor for website-scoped product categories.
 */
final class ProductCategoryAdminService
{
    private const DEFAULT_LOCALE = '';

    public const DELETE_PRODUCT_LIST_LIMIT = 200;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ProductCategoryAttributeService $categoryAttributes,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly StorefrontCategoryTreeIndex $categoryTree,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
        private readonly ?ProductRepository $products = null,
        private readonly ?ProductPhysicalDeleteService $physicalDelete = null,
        private readonly mixed $transactionRunner = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(int $websiteId, string $locale = self::DEFAULT_LOCALE): array
    {
        $this->assertWebsite($websiteId);
        $rows = $this->enrichedRows($websiteId, $locale);
        $byParent = [];
        foreach ($rows as $row) {
            $parentId = max(0, (int)($row['parent_id'] ?? 0));
            $byParent[$parentId] ??= [];
            $byParent[$parentId][] = $row;
        }

        $walk = function (int $parentId) use (&$walk, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                $id = (int)($row['category_id'] ?? 0);
                $node = $row;
                $node['nodes'] = $id > 0 ? $walk($id) : [];
                $nodes[] = $node;
            }

            return $nodes;
        };

        return $walk(0);
    }

    /**
     * Find an existing sibling category by localized display name (EAV name attribute).
     */
    public function findSiblingIdByLocalizedName(
        int $websiteId,
        int $parentId,
        string $name,
        string $locale = self::DEFAULT_LOCALE,
    ): int {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $this->assertWebsite($websiteId);

        $siblingIds = [];
        foreach ($this->categories->listSiblings($websiteId, $parentId) as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId > 0) {
                $siblingIds[] = $categoryId;
            }
        }
        if ($siblingIds === []) {
            return 0;
        }

        foreach ([$locale, ''] as $candidateLocale) {
            $names = $this->categoryAttributes->readNameMap($websiteId, $siblingIds, $candidateLocale);
            foreach ($siblingIds as $categoryId) {
                if (trim($names[$categoryId] ?? '') === $name) {
                    return $categoryId;
                }
            }
            if ($locale === '') {
                break;
            }
        }

        return 0;
    }

    /**
     * Remove duplicate sibling categories that share the same localized name, keeping the richest subtree.
     *
     * @return list<int> removed category ids
     */
    public function dedupeSiblingsByLocalizedName(
        int $websiteId,
        int $parentId,
        string $name,
        string $locale = self::DEFAULT_LOCALE,
    ): array {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $this->assertWebsite($websiteId);

        $siblingIds = [];
        foreach ($this->categories->listSiblings($websiteId, $parentId) as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId > 0) {
                $siblingIds[] = $categoryId;
            }
        }
        if ($siblingIds === []) {
            return [];
        }

        $matches = [];
        foreach ([$locale, ''] as $candidateLocale) {
            $names = $this->categoryAttributes->readNameMap($websiteId, $siblingIds, $candidateLocale);
            foreach ($siblingIds as $categoryId) {
                if (trim($names[$categoryId] ?? '') === $name) {
                    $matches[] = $categoryId;
                }
            }
            if ($matches !== []) {
                break;
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) <= 1) {
            return [];
        }

        usort(
            $matches,
            fn(int $left, int $right): int => $this->countDescendants($websiteId, $right)
                <=> $this->countDescendants($websiteId, $left)
                ?: $left <=> $right,
        );
        $keeperId = $matches[0];
        $removed = [];
        foreach (array_slice($matches, 1) as $duplicateId) {
            foreach ($this->categories->listSiblings($websiteId, $duplicateId) as $childRow) {
                $childId = (int)($childRow[Category::schema_fields_ID] ?? 0);
                if ($childId <= 0) {
                    continue;
                }
                $childName = trim((string)($this->categoryAttributes->readNameMap(
                    $websiteId,
                    [$childId],
                    $locale,
                )[$childId] ?? ''));
                if ($childName === '') {
                    $childName = $this->displayNameFromPath((string)($childRow[Category::schema_fields_PATH] ?? ''));
                }
                $this->save(
                    $websiteId,
                    $childId,
                    $keeperId,
                    $childName,
                    (string)($childRow[Category::schema_fields_STATUS] ?? 'active'),
                    $this->leafSlug((string)($childRow[Category::schema_fields_PATH] ?? '')),
                    $locale,
                );
            }
            $this->delete($websiteId, $duplicateId);
            $removed[] = $duplicateId;
        }

        return $removed;
    }

    /** @return array<string, mixed>|null */
    public function view(int $websiteId, int $categoryId, string $locale = self::DEFAULT_LOCALE): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }
        foreach ($this->enrichedRows($websiteId, $locale) as $row) {
            if ((int)($row['category_id'] ?? 0) === $categoryId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{category_id:int}
     */
    public function save(
        int $websiteId,
        int $categoryId,
        int $parentId,
        string $name,
        string $status,
        string $code = '',
        string $locale = self::DEFAULT_LOCALE,
        ?string $googleTaxonomyId = null,
        ?string $image = null,
        ?string $banner = null,
        ?string $summary = null,
        ?string $description = null,
    ): array {
        $this->assertWebsite($websiteId);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('分类名称不能为空'));
        }
        $slug = $this->resolveCode($code, $name);
        if ($categoryId > 0 && $categoryId === $parentId) {
            throw new \InvalidArgumentException((string)__('不能选择自己作为父分类'));
        }
        if ($categoryId > 0 && $this->isDescendant($websiteId, $categoryId, $parentId)) {
            throw new \InvalidArgumentException((string)__('不能将分类移动到其子孙节点下'));
        }
        $normalizedStatus = strtolower(trim($status)) === 'inactive' ? 'inactive' : 'active';

        if ($categoryId > 0) {
            $existing = $this->categories->findById($websiteId, $categoryId)
                ?? throw new \InvalidArgumentException((string)__('分类不存在'));
            $oldParentId = max(0, (int)($existing->getData(Category::schema_fields_PARENT_ID) ?? 0));
            $path = $this->buildUniquePath($websiteId, $parentId, $slug, $categoryId);
            $this->categories->updateStructure($websiteId, $categoryId, [
                Category::schema_fields_PARENT_ID => $parentId > 0 ? $parentId : null,
                Category::schema_fields_PATH => $path,
                Category::schema_fields_STATUS => $normalizedStatus,
            ]);
            if ($oldParentId !== $parentId) {
                $this->categories->updateFields($websiteId, $categoryId, [
                    Category::schema_fields_POSITION => $this->categories->nextSiblingPosition($websiteId, $parentId),
                ]);
            }
            $this->rebuildDescendantPaths($websiteId, $categoryId);
        } else {
            $path = $this->buildUniquePath($websiteId, $parentId, $slug);
            $created = $this->categories->create($websiteId, [
                Category::schema_fields_PARENT_ID => $parentId > 0 ? $parentId : null,
                Category::schema_fields_PATH => $path,
                Category::schema_fields_STATUS => $normalizedStatus,
                Category::schema_fields_POSITION => $this->categories->nextSiblingPosition($websiteId, $parentId),
            ]);
            $categoryId = (int)$created->getId();
        }

        $this->categoryAttributes->writeName($websiteId, $categoryId, $name, $locale);
        $this->categoryAttributes->writeCode($websiteId, $categoryId, $slug, $locale);
        if ($googleTaxonomyId !== null) {
            $this->categoryAttributes->writeGoogleTaxonomyId(
                $websiteId,
                $categoryId,
                trim($googleTaxonomyId),
                $locale,
            );
        }
        if ($image !== null) {
            $this->categoryAttributes->writeImage($websiteId, $categoryId, $image, $locale);
        }
        if ($banner !== null) {
            $this->categoryAttributes->writeBanner($websiteId, $categoryId, $banner, $locale);
        }
        if ($summary !== null) {
            $this->categoryAttributes->writeSummary($websiteId, $categoryId, $summary, $locale);
        }
        if ($description !== null) {
            $this->categoryAttributes->writeDescription($websiteId, $categoryId, $description, $locale);
        }
        $this->invalidate($websiteId, $categoryId);

        return [
            'category_id' => $categoryId,
            'code' => $slug,
            'path' => $path,
            'google_taxonomy_id' => $googleTaxonomyId !== null ? trim($googleTaxonomyId) : null,
            'image' => $image !== null ? trim($image) : null,
            'banner' => $banner !== null ? trim($banner) : null,
            'summary' => $summary !== null ? trim($summary) : null,
            'description' => $description !== null ? trim($description) : null,
        ];
    }

    /**
     * @return list<int>
     */
    public function collectSubtreeCategoryIds(int $websiteId, int $categoryId): array
    {
        $this->assertWebsite($websiteId);
        if ($categoryId <= 0) {
            return [];
        }
        $childrenByParent = [];
        foreach ($this->categories->listAll($websiteId) as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            $parentId = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $childrenByParent[$parentId] ??= [];
            $childrenByParent[$parentId][] = $id;
        }
        $ids = [];
        $stack = [$categoryId];
        while ($stack !== []) {
            $current = (int)array_pop($stack);
            if ($current <= 0 || isset($ids[$current])) {
                continue;
            }
            $ids[$current] = true;
            foreach ($childrenByParent[$current] ?? [] as $childId) {
                $stack[] = (int)$childId;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @return array{products:list<array{product_id:int,name:string,sku:string,exclusive:bool}>,truncated:bool,total:int}
     */
    public function listProductsForDelete(int $websiteId, int $categoryId): array
    {
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $this->assertWebsite($websiteId);
        if ($this->categories->findById($websiteId, $categoryId) === null) {
            throw new \InvalidArgumentException((string)__('分类不存在'));
        }

        $subtreeIds = $this->collectSubtreeCategoryIds($websiteId, $categoryId);
        $subtreeSet = array_fill_keys($subtreeIds, true);
        $links = $this->categoryLinks->listByCategoryIdsAnyStore($websiteId, $subtreeIds);
        $productIds = [];
        foreach ($links as $link) {
            if (!ProductCategoryExclusiveClassifier::isActiveMount($link)) {
                continue;
            }
            $productId = (int)($link['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }
        $productIds = array_map('intval', array_keys($productIds));
        sort($productIds, SORT_NUMERIC);
        $total = count($productIds);
        $truncated = $total > self::DELETE_PRODUCT_LIST_LIMIT;
        if ($truncated) {
            $productIds = array_slice($productIds, 0, self::DELETE_PRODUCT_LIST_LIMIT);
        }

        $exclusiveMap = $this->classifyExclusiveProductIds($websiteId, $productIds, $subtreeSet);
        $meta = $this->productDisplayMeta($websiteId, $productIds);
        $products = [];
        foreach ($productIds as $productId) {
            $products[] = [
                'product_id' => $productId,
                'name' => (string)($meta[$productId]['name'] ?? ('#' . $productId)),
                'sku' => (string)($meta[$productId]['sku'] ?? ''),
                'exclusive' => (bool)($exclusiveMap[$productId] ?? false),
            ];
        }

        return [
            'products' => $products,
            'truncated' => $truncated,
            'total' => $total,
        ];
    }

    /**
     * @param list<int> $selectedProductIds
     */
    public function delete(int $websiteId, int $categoryId, array $selectedProductIds = []): void
    {
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $this->assertWebsite($websiteId);
        if ($this->categories->findById($websiteId, $categoryId) === null) {
            throw new \InvalidArgumentException((string)__('分类不存在'));
        }

        $subtreeIds = $this->collectSubtreeCategoryIds($websiteId, $categoryId);
        $subtreeSet = array_fill_keys($subtreeIds, true);
        $mountedProductIds = $this->mountedProductIdsInSubtree($websiteId, $subtreeIds);
        $selected = ProductCategoryExclusiveClassifier::intersectSelected($selectedProductIds, $mountedProductIds);
        $exclusiveMap = $this->classifyExclusiveProductIds($websiteId, $selected, $subtreeSet);
        $purgeIds = [];
        foreach ($selected as $productId) {
            if (!empty($exclusiveMap[$productId])) {
                $purgeIds[] = $productId;
            }
        }

        $this->withWebsiteTransaction($websiteId, function () use ($websiteId, $categoryId, $purgeIds): void {
            if ($purgeIds !== []) {
                $physical = $this->physicalDelete
                    ?? ObjectManager::getInstance(ProductPhysicalDeleteService::class);
                $physical->deleteByIds($websiteId, $purgeIds);
            }
            $this->deleteRecursive($websiteId, $categoryId);
        });
        $this->invalidate($websiteId, $categoryId);
    }

    /**
     * @param callable():void $work
     */
    private function withWebsiteTransaction(int $websiteId, callable $work): void
    {
        if (is_callable($this->transactionRunner)) {
            ($this->transactionRunner)($websiteId, $work);
            return;
        }
        /** @var Category $model */
        $model = ObjectManager::create(Category::class, [], false)->forWebsite($websiteId);
        $query = $model->getQuery();
        $started = false;
        try {
            $query->beginTransaction();
            $started = true;
            $work();
            $query->commit();
        } catch (\Throwable $exception) {
            if ($started) {
                try {
                    $query->rollBack();
                } catch (\Throwable) {
                    // keep original failure
                }
            }
            throw $exception;
        }
    }

    /**
     * @return array{id:int,pid:int,level:int,position:int}
     */
    public function reorder(
        int $websiteId,
        int $categoryId,
        int $parentId,
        int $level,
        int $position,
    ): array {
        $this->assertWebsite($websiteId);
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        if ($categoryId === $parentId) {
            throw new \InvalidArgumentException((string)__('不能将自己作为父分类'));
        }
        if ($this->isDescendant($websiteId, $categoryId, $parentId)) {
            throw new \InvalidArgumentException((string)__('不能将分类移动到其子孙节点下'));
        }

        $category = $this->categories->findById($websiteId, $categoryId)
            ?? throw new \InvalidArgumentException((string)__('分类不存在'));
        $oldParentId = max(0, (int)($category->getData(Category::schema_fields_PARENT_ID) ?? 0));
        $position = max(1, $position);
        $level = max(1, $level);

        if ($oldParentId !== $parentId) {
            $this->compactSiblingPositions($websiteId, $oldParentId, $categoryId);
            $siblings = $this->categories->listSiblings($websiteId, $parentId, $categoryId);
            $targetIndex = max(0, min($position - 1, count($siblings)));
            $updates = [];
            foreach ($siblings as $index => $sibling) {
                $siblingId = (int)($sibling[Category::schema_fields_ID] ?? 0);
                $updates[$siblingId] = $index >= $targetIndex ? $index + 2 : $index + 1;
            }
            $this->categories->batchUpdatePosition($websiteId, $updates);
            $finalPosition = $targetIndex + 1;

            $slug = $this->leafSlug((string)($category->getData(Category::schema_fields_PATH) ?? ''));
            if ($slug === '') {
                $slug = 'category-' . $categoryId;
            }
            $path = $this->buildUniquePath($websiteId, $parentId, $slug, $categoryId);
            $this->categories->updateStructure($websiteId, $categoryId, [
                Category::schema_fields_PARENT_ID => $parentId > 0 ? $parentId : null,
                Category::schema_fields_PATH => $path,
                Category::schema_fields_POSITION => $finalPosition,
            ]);
            $this->rebuildDescendantPaths($websiteId, $categoryId);
        } else {
            $siblings = $this->categories->listSiblings($websiteId, $parentId, $categoryId);
            $targetIndex = max(0, min($position - 1, count($siblings)));
            $updates = [];
            foreach ($siblings as $index => $sibling) {
                $siblingId = (int)($sibling[Category::schema_fields_ID] ?? 0);
                $updates[$siblingId] = $index >= $targetIndex ? $index + 2 : $index + 1;
            }
            $this->categories->batchUpdatePosition($websiteId, $updates);
            $finalPosition = $targetIndex + 1;
            $this->categories->updateFields($websiteId, $categoryId, [
                Category::schema_fields_POSITION => $finalPosition,
            ]);
        }

        $this->invalidate($websiteId, $categoryId);

        return [
            'id' => $categoryId,
            'pid' => $parentId,
            'level' => $level,
            'position' => $finalPosition,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function enrichedRows(int $websiteId, string $locale): array
    {
        $rows = $this->categories->listAll($websiteId);
        $ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row[Category::schema_fields_ID] ?? 0),
            $rows,
        )));
        $names = $this->categoryAttributes->readNameMap($websiteId, $ids, $locale);
        $googleIds = $this->categoryAttributes->readGoogleTaxonomyIdMap($websiteId, $ids, $locale);
        $images = $this->categoryAttributes->readImageMap($websiteId, $ids, $locale);
        $banners = $this->categoryAttributes->readBannerMap($websiteId, $ids, $locale);
        $summaries = $this->categoryAttributes->readSummaryMap($websiteId, $ids, $locale);
        $descriptions = $this->categoryAttributes->readDescriptionMap($websiteId, $ids, $locale);
        $sourcePlatforms = $this->categoryAttributes->readSourcePlatformMap($websiteId, $ids, $locale);

        $presented = [];
        foreach ($rows as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $path = trim(str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/');
            $code = $this->leafSlug($path);
            $presented[] = [
                'category_id' => $categoryId,
                'id' => $categoryId,
                'parent_id' => max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0)),
                'pid' => max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0)),
                'path' => $path !== '' ? '/' . ltrim($path, '/') : '',
                'code' => $code,
                'position' => (int)($row[Category::schema_fields_POSITION] ?? 0),
                'status' => (string)($row[Category::schema_fields_STATUS] ?? 'active'),
                'is_active' => strtolower((string)($row[Category::schema_fields_STATUS] ?? 'active')) !== 'inactive' ? 1 : 0,
                'name' => $names[$categoryId] ?? $this->displayNameFromPath($path),
                'google_taxonomy_id' => (string)($googleIds[$categoryId] ?? ''),
                'image' => (string)($images[$categoryId] ?? ''),
                'banner' => (string)($banners[$categoryId] ?? ''),
                'summary' => (string)($summaries[$categoryId] ?? ''),
                'description' => (string)($descriptions[$categoryId] ?? ''),
                'source_platform' => (string)($sourcePlatforms[$categoryId] ?? ''),
                'level' => $this->depthFor($rows, $categoryId),
            ];
        }

        return $presented;
    }

    /** @param list<array<string, mixed>> $rows */
    private function depthFor(array $rows, int $categoryId): int
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $byId[$id] = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            }
        }
        $depth = 1;
        $cursor = $categoryId;
        $guard = 0;
        while (($byId[$cursor] ?? 0) > 0 && $guard < 32) {
            $depth++;
            $cursor = (int)$byId[$cursor];
            $guard++;
        }

        return $depth;
    }

    private function deleteRecursive(int $websiteId, int $categoryId): void
    {
        foreach ($this->categories->listAll($websiteId) as $row) {
            $childId = (int)($row[Category::schema_fields_ID] ?? 0);
            $parentId = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            if ($parentId === $categoryId && $childId > 0) {
                $this->deleteRecursive($websiteId, $childId);
            }
        }
        $this->deleteCategoryLinks($websiteId, $categoryId);
        $this->categoryAttributes->purge($websiteId, $categoryId);
        $this->categories->deleteById($websiteId, $categoryId);
    }

    private function deleteCategoryLinks(int $websiteId, int $categoryId): void
    {
        foreach ($this->categoryLinks->listByCategoryIdsAnyStore($websiteId, [$categoryId]) as $link) {
            $productId = (int)($link['product_id'] ?? 0);
            $storeId = (int)($link['store_id'] ?? 0);
            if ($productId > 0) {
                $this->categoryLinks->unlink($websiteId, $categoryId, $productId, $storeId);
            }
        }
    }

    /**
     * @param list<int> $subtreeIds
     * @return array<int, true>
     */
    private function mountedProductIdsInSubtree(int $websiteId, array $subtreeIds): array
    {
        $mounted = [];
        foreach ($this->categoryLinks->listByCategoryIdsAnyStore($websiteId, $subtreeIds) as $link) {
            if (!ProductCategoryExclusiveClassifier::isActiveMount($link)) {
                continue;
            }
            $productId = (int)($link['product_id'] ?? 0);
            if ($productId > 0) {
                $mounted[$productId] = true;
            }
        }

        return $mounted;
    }

    /**
     * @param list<int> $productIds
     * @param array<int, true> $subtreeSet
     * @return array<int, bool>
     */
    private function classifyExclusiveProductIds(int $websiteId, array $productIds, array $subtreeSet): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductCategoryExclusiveClassifier::classify(
            $productIds,
            $subtreeSet,
            $this->categoryLinks->listByProductIdsAnyStore($websiteId, $productIds),
        );
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array{name:string,sku:string}>
     */
    private function productDisplayMeta(int $websiteId, array $productIds): array
    {
        $meta = [];
        if ($productIds === []) {
            return $meta;
        }
        $products = $this->products
            ?? ObjectManager::getInstance(ProductRepository::class);
        foreach ($products->listByIds($websiteId, $productIds) as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $sku = trim((string)($row[Product::schema_fields_SKU] ?? ''));
            $code = trim((string)($row[Product::schema_fields_PRODUCT_CODE] ?? ''));
            $meta[$productId] = [
                'sku' => $sku !== '' ? $sku : $code,
                'name' => $sku !== '' ? $sku : ($code !== '' ? $code : ('#' . $productId)),
            ];
        }

        return $meta;
    }

    private function compactSiblingPositions(int $websiteId, int $parentId, int $excludeId): void
    {
        $siblings = $this->categories->listSiblings($websiteId, $parentId, $excludeId);
        $updates = [];
        foreach (array_values($siblings) as $index => $sibling) {
            $updates[(int)($sibling[Category::schema_fields_ID] ?? 0)] = $index + 1;
        }
        $this->categories->batchUpdatePosition($websiteId, $updates);
    }

    private function rebuildDescendantPaths(int $websiteId, int $categoryId): void
    {
        $current = $this->categories->findById($websiteId, $categoryId);
        if ($current === null) {
            return;
        }
        foreach ($this->categories->listAll($websiteId) as $row) {
            $childId = (int)($row[Category::schema_fields_ID] ?? 0);
            $parentId = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            if ($parentId !== $categoryId || $childId <= 0) {
                continue;
            }
            $slug = $this->leafSlug((string)($row[Category::schema_fields_PATH] ?? ''));
            if ($slug === '') {
                $slug = 'category-' . $childId;
            }
            $childPath = $this->buildUniquePath($websiteId, $categoryId, $slug, $childId);
            $this->categories->updateStructure($websiteId, $childId, [
                Category::schema_fields_PATH => $childPath,
            ]);
            $this->rebuildDescendantPaths($websiteId, $childId);
        }
    }

    private function buildUniquePath(
        int $websiteId,
        int $parentId,
        string $slug,
        int $excludeId = 0,
    ): string {
        $slug = $this->slugify($slug);
        $parentPath = '';
        if ($parentId > 0) {
            $parent = $this->categories->findById($websiteId, $parentId);
            $parentPath = trim(str_replace('\\', '/', (string)($parent?->getData(Category::schema_fields_PATH) ?? '')), '/');
        }
        $candidate = ($parentPath !== '' ? '/' . $parentPath : '') . '/' . $slug;
        $suffix = 2;
        while ($this->pathTaken($websiteId, $candidate, $excludeId)) {
            $candidate = ($parentPath !== '' ? '/' . $parentPath : '') . '/' . $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function pathTaken(int $websiteId, string $path, int $excludeId = 0): bool
    {
        $needle = strtolower(trim(str_replace('\\', '/', $path), '/'));
        foreach ($this->categories->listAll($websiteId) as $row) {
            $rowId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($rowId === $excludeId) {
                continue;
            }
            $stored = strtolower(trim(str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/'));
            if ($stored === ltrim($needle, '/')) {
                return true;
            }
        }

        return false;
    }

    private function isDescendant(int $websiteId, int $ancestorId, int $candidateParentId): bool
    {
        if ($candidateParentId <= 0) {
            return false;
        }
        $cursor = $candidateParentId;
        $guard = 0;
        while ($cursor > 0 && $guard < 64) {
            if ($cursor === $ancestorId) {
                return true;
            }
            $row = $this->categories->findById($websiteId, $cursor);
            if ($row === null) {
                return false;
            }
            $cursor = max(0, (int)($row->getData(Category::schema_fields_PARENT_ID) ?? 0));
            $guard++;
        }

        return false;
    }

    private function resolveCode(string $code, string $name): string
    {
        $code = trim($code);
        if ($code === '') {
            $code = $name;
        }
        $slug = $this->slugify($code);
        if ($slug === 'category' && trim($name) !== '') {
            $slug = $this->slugify($name);
        }
        if ($slug === '') {
            throw new \InvalidArgumentException((string)__('分类 Code 不能为空'));
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException((string)__('分类 Code 仅允许小写字母、数字和连字符'));
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'category';
    }

    private function leafSlug(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '' : (string)array_slice(explode('/', $path), -1)[0];
    }

    private function countDescendants(int $websiteId, int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }
        $count = 0;
        foreach ($this->categories->listAll($websiteId) as $row) {
            if (max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0)) === $categoryId) {
                $count++;
            }
        }

        return $count;
    }

    private function displayNameFromPath(string $path): string
    {
        $slug = $this->leafSlug($path);
        if ($slug === '') {
            return (string)__('分类');
        }

        return str_replace(['-', '_'], ' ', $slug);
    }

    private function invalidate(int $websiteId, int $categoryId): void
    {
        $this->categoryTree->invalidate($websiteId);
        $this->catalogCache->notifyCategoryChanged($websiteId, 'category_admin_changed', $categoryId);
    }

    private function assertWebsite(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能小于 0'));
        }
    }
}
