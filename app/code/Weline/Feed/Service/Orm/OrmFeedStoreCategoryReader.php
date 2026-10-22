<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Orm;

use Weline\Feed\Api\FeedStoreCategoryReaderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Category\LocalDescription;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;

/**
 * Reads the catalog category tree. Feed does not write it and does not seed it.
 */
final class OrmFeedStoreCategoryReader implements FeedStoreCategoryReaderInterface
{
    public function categories(int $websiteId, string $locale = ''): array
    {
        $repository = ObjectManager::getInstance(CategoryRepository::class);
        if (!$repository instanceof CategoryRepository) {
            throw new \RuntimeException('feed_store_category_repository_missing');
        }
        $rows = $repository->listAll($websiteId);
        $names = $this->names($rows, $locale);
        $byId = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = [
                'parent_id' => (int)($row[Category::schema_fields_PARENT_ID] ?? 0),
                'name' => $names[$id] ?? ('#' . $id),
            ];
        }
        $categories = [];
        foreach ($byId as $id => $row) {
            $labels = [];
            $cursor = $id;
            $guard = 0;
            while ($cursor > 0 && isset($byId[$cursor]) && $guard < 32) {
                array_unshift($labels, $byId[$cursor]['name']);
                $parent = $byId[$cursor]['parent_id'];
                if ($parent === $cursor) {
                    break;
                }
                $cursor = $parent;
                $guard++;
            }
            $categories[] = [
                'category_id' => $id,
                'name' => $row['name'],
                'path' => implode(' / ', $labels),
                'depth' => max(0, count($labels) - 1),
            ];
        }

        return $categories;
    }

    public function assignments(int $websiteId, array $productIds, int $storeId = 0): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }
        $links = ObjectManager::getInstance(CategoryLinkRepository::class);
        if (!$links instanceof CategoryLinkRepository) {
            throw new \RuntimeException('feed_store_category_link_repository_missing');
        }
        $storeIds = $storeId > 0 ? [$storeId, 0] : [0];
        $rows = $links->listByProductIds($websiteId, $productIds, $storeIds);
        $preferred = [];
        foreach ($rows as $row) {
            if (($row['selected'] ?? false) !== true) {
                continue;
            }
            $productId = (int)($row['product_id'] ?? 0);
            if ($storeId > 0 && (int)($row['store_id'] ?? 0) === $storeId) {
                $preferred[$productId] = true;
            }
        }
        $categories = [];
        foreach ($this->categories($websiteId) as $category) {
            $categories[$category['category_id']] = $category;
        }
        $assignments = [];
        foreach ($rows as $row) {
            if (($row['selected'] ?? false) !== true) {
                continue;
            }
            $productId = (int)($row['product_id'] ?? 0);
            $categoryId = (int)($row['category_id'] ?? 0);
            $rowStore = (int)($row['store_id'] ?? 0);
            if ($productId <= 0 || $categoryId <= 0 || !isset($categories[$categoryId])) {
                continue;
            }
            if ($storeId > 0 && isset($preferred[$productId]) && $rowStore !== $storeId) {
                continue;
            }
            $assignments[] = [
                'product_id' => $productId,
                'category_id' => $categoryId,
                'primary' => false,
                'depth' => $categories[$categoryId]['depth'],
                'name' => $categories[$categoryId]['name'],
            ];
        }

        return $assignments;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function names(array $rows, string $locale): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $model = ObjectManager::getInstance(LocalDescription::class);
        if (!$model instanceof LocalDescription) {
            throw new \RuntimeException('feed_store_category_name_model_missing');
        }
        $fetched = $model->clear()
            ->where(LocalDescription::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetchArray();
        $locale = trim($locale);
        $exact = [];
        $fallback = [];
        foreach ($fetched as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[LocalDescription::schema_fields_ID] ?? 0);
            $name = trim((string)($row[LocalDescription::schema_fields_NAME] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $rowLocale = trim((string)($row[LocalDescription::schema_fields_LOCALE_CODE] ?? ''));
            if ($locale !== '' && $rowLocale === $locale) {
                $exact[$id] = $name;
                continue;
            }
            if (!isset($fallback[$id])) {
                $fallback[$id] = $name;
            }
        }

        return $exact + $fallback;
    }
}
