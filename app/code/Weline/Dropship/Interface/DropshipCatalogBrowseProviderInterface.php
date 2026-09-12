<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

/**
 * Optional catalog browse capability for 选品：分类树/叶级列表。
 * Providers without categories still work via searchProducts alone.
 *
 * @phpstan-type DropshipCategoryNode array{
 *   id: string,
 *   name: string,
 *   parent_id?: string|null,
 *   level?: int,
 *   path?: string
 * }
 */
interface DropshipCatalogBrowseProviderInterface extends DropshipCatalogProviderInterface
{
    /**
     * Categories for 选品（树或扁平）。壳按 parent_id 渲染；空数组则空态。
     *
     * BrowseQuery keys (optional): country_code.
     *
     * @param array<string, mixed> $query
     * @return list<DropshipCategoryNode>
     */
    public function listCategories(array $query = []): array;
}
