<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Pure exclusivity rules for category-delete product selection.
 */
final class ProductCategoryExclusiveClassifier
{
    /**
     * @param list<int> $productIds
     * @param array<int, true> $subtreeSet
     * @param list<array<string, mixed>> $productLinks any-store links for the products
     * @return array<int, bool>
     */
    public static function classify(array $productIds, array $subtreeSet, array $productLinks): array
    {
        $map = [];
        foreach ($productIds as $productId) {
            $map[(int)$productId] = true;
        }
        if ($productIds === []) {
            return $map;
        }
        foreach ($productLinks as $link) {
            if (!self::isActiveMount($link)) {
                continue;
            }
            $productId = (int)($link['product_id'] ?? 0);
            $categoryId = (int)($link['category_id'] ?? 0);
            if ($productId <= 0 || $categoryId <= 0) {
                continue;
            }
            if (!isset($subtreeSet[$categoryId])) {
                $map[$productId] = false;
            }
        }

        return $map;
    }

    /**
     * @param list<int> $selectedProductIds
     * @param array<int, true> $mountedInSubtree
     * @return list<int>
     */
    public static function intersectSelected(array $selectedProductIds, array $mountedInSubtree): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $selectedProductIds),
            static fn(int $id): bool => $id > 0 && isset($mountedInSubtree[$id]),
        )));
    }

    /** @param array<string, mixed> $link */
    public static function isActiveMount(array $link): bool
    {
        if (empty($link['selected'])) {
            return false;
        }

        return strtolower((string)($link['scope_state'] ?? 'explicit')) !== 'cleared';
    }
}
