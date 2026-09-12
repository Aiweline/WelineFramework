<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Bulk-adjust website-level product category assignments from the catalog list.
 */
final class ProductCategoryBulkAssignService
{
    private const MODES = ['add', 'replace', 'remove'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
    ) {
    }

    /**
     * @param list<int> $categoryIds
     * @param list<array<string, mixed>> $items
     * @return array{success:bool,message:string,data:array<string,mixed>}
     */
    public function execute(
        int $websiteId,
        string $mode,
        array $categoryIds,
        array $items,
    ): array {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('product_admin_website_invalid');
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('product_category_bulk_mode_invalid');
        }
        if ($items === []) {
            throw new \InvalidArgumentException('product_admin_bulk_items_empty');
        }

        $categoryIds = $this->normalizeCategoryIds($websiteId, $categoryIds);
        if ($categoryIds === [] && $mode !== 'remove') {
            throw new \InvalidArgumentException('product_category_bulk_categories_empty');
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $failed++;
                continue;
            }
            $uuid = trim((string)($item['global_product_uuid'] ?? ''));
            if ($uuid === '') {
                $failed++;
                $results[] = [
                    'global_product_uuid' => '',
                    'success' => false,
                    'error_code' => 'product_admin_product_uuid_invalid',
                ];
                continue;
            }

            try {
                $product = $this->products->findByGlobalUuid($websiteId, $uuid)
                    ?? throw new \InvalidArgumentException('product_not_found');
                if ((string)$product->getData(Product::schema_fields_STATUS) === 'archived') {
                    throw new \InvalidArgumentException('product_archived_readonly');
                }
                $productId = (int)$product->getId();
                $existing = $this->categoryLinks->listByProductIds($websiteId, [$productId], [0]);
                $rows = $this->mergeAssignments($existing, $categoryIds, $mode);
                $this->categoryLinks->syncProductScope($websiteId, $productId, 0, $rows);
                $this->catalogCache->notifyCatalogChanged($websiteId, 'product_category_bulk_assign', [
                    'product_id' => $productId,
                    'mode' => $mode,
                    'index' => $index,
                ]);
                $succeeded++;
                $results[] = [
                    'global_product_uuid' => $uuid,
                    'product_id' => $productId,
                    'success' => true,
                ];
            } catch (\Throwable $throwable) {
                $failed++;
                $code = trim($throwable->getMessage());
                if (!preg_match('/^[a-z][a-z0-9_.-]{2,127}$/', $code)) {
                    $code = 'product_category_bulk_item_failed';
                }
                $results[] = [
                    'global_product_uuid' => $uuid,
                    'success' => false,
                    'error_code' => $code,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return ProductAdminResult::ok(
            [
                'mode' => $mode,
                'category_ids' => $categoryIds,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'items' => $results,
            ],
            (string)__(
                '批量分类调整完成：成功 %{1}，失败 %{2}',
                [(string)$succeeded, (string)$failed],
            ),
        )->toArray();
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    private function normalizeCategoryIds(int $websiteId, array $categoryIds): array
    {
        $normalized = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = max(0, (int)$categoryId);
            if ($categoryId < 1 || isset($normalized[$categoryId])) {
                continue;
            }
            if ($this->categories->findById($websiteId, $categoryId) === null) {
                throw new \InvalidArgumentException('product_category_not_found');
            }
            $normalized[$categoryId] = $categoryId;
        }

        return array_values($normalized);
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<int> $categoryIds
     * @return list<array<string, mixed>>
     */
    private function mergeAssignments(array $existing, array $categoryIds, string $mode): array
    {
        $existingMap = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = (int)($row['category_id'] ?? 0);
            $scopeState = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
            $selected = (bool)($row['selected'] ?? false);
            if ($categoryId < 1 || !$selected || $scopeState !== 'explicit') {
                continue;
            }
            $existingMap[$categoryId] = max(0, (int)($row['position'] ?? 0));
        }

        if ($mode === 'replace') {
            $rows = [];
            foreach ($categoryIds as $index => $categoryId) {
                $rows[] = [
                    'category_id' => $categoryId,
                    'scope_state' => 'explicit',
                    'selected' => true,
                    'position' => $index,
                ];
            }

            return $rows;
        }

        if ($mode === 'remove') {
            $removeLookup = array_fill_keys($categoryIds, true);
            $rows = [];
            $position = 0;
            foreach ($existingMap as $categoryId => $existingPosition) {
                if (isset($removeLookup[$categoryId])) {
                    continue;
                }
                $rows[] = [
                    'category_id' => $categoryId,
                    'scope_state' => 'explicit',
                    'selected' => true,
                    'position' => $position++,
                ];
                unset($existingPosition);
            }

            return $rows;
        }

        $rows = [];
        $position = 0;
        foreach ($existingMap as $categoryId => $existingPosition) {
            $rows[] = [
                'category_id' => $categoryId,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => $position++,
            ];
            unset($existingPosition);
        }
        foreach ($categoryIds as $categoryId) {
            if (isset($existingMap[$categoryId])) {
                continue;
            }
            $rows[] = [
                'category_id' => $categoryId,
                'scope_state' => 'explicit',
                'selected' => true,
                'position' => $position++,
            ];
        }

        return $rows;
    }
}
