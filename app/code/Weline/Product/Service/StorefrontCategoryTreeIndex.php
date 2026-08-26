<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Repository\CategoryRepository;

/**
 * Cached storefront category tree indexed by id / parent_id / path for O(1) sibling lookups.
 */
final class StorefrontCategoryTreeIndex
{
    private const CACHE_POOL = 'weline_product_storefront_category_tree';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function logicalCacheKey(int $websiteId): string
    {
        return 'product.category_tree.' . max(0, $websiteId);
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    /**
     * @return array{
     *     by_id: array<int, array<string, mixed>>,
     *     by_parent: array<int, list<array<string, mixed>>>,
     *     by_path: array<string, int>
     * }
     */
    public function forWebsite(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        $logicalKey = self::logicalCacheKey($websiteId);

        /** @var array{by_id: array<int, array<string, mixed>>, by_parent: array<int, list<array<string, mixed>>>, by_path: array<string, int>} $index */
        $index = $this->hotCache->remember(
            self::CACHE_POOL,
            $logicalKey,
            self::FRESH_TTL_SECONDS,
            fn(): array => $this->build($websiteId),
            ['website' => true],
            self::STALE_TTL_SECONDS,
        );

        return $index;
    }

    /** @return list<array<string, mixed>> */
    public function childrenOf(int $websiteId, int $parentId): array
    {
        $index = $this->forWebsite($websiteId);
        $parentId = max(0, $parentId);
        $children = $index['by_parent'][$parentId] ?? [];

        return \is_array($children) ? \array_values($children) : [];
    }

    /** @return list<array<string, mixed>> */
    public function siblingsOf(int $websiteId, int $parentId): array
    {
        return $this->childrenOf($websiteId, $parentId);
    }

    /**
     * Nested forest for department nav (all categories under parent_id=0).
     *
     * @return list<array<string, mixed>>
     */
    public function nestedRoots(int $websiteId): array
    {
        $index = $this->forWebsite($websiteId);
        $byParent = $index['by_parent'];

        $walk = function (int $parentId) use (&$walk, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $id = (int)($row['id'] ?? 0);
                $node = $row;
                $node['children'] = $id > 0 ? $walk($id) : [];
                $nodes[] = $node;
            }

            return $nodes;
        };

        return $walk(0);
    }

    /**
     * Ancestor ids from root to current (inclusive).
     *
     * @return list<int>
     */
    public function activePathIds(int $websiteId, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $byId = $this->forWebsite($websiteId)['by_id'];
        $chain = [];
        $currentId = $categoryId;
        $guard = 0;
        while ($currentId > 0 && $guard++ < 32) {
            $row = $byId[$currentId] ?? null;
            if (!\is_array($row)) {
                break;
            }
            $chain[] = $currentId;
            $currentId = max(0, (int)($row['parent_id'] ?? 0));
        }

        return \array_reverse($chain);
    }

    /** @return array<string, mixed>|null */
    public function findByPath(int $websiteId, string $slugPath): ?array
    {
        $slugPath = \strtolower(\trim(\str_replace('\\', '/', $slugPath), '/'));
        if ($slugPath === '') {
            return null;
        }

        $index = $this->forWebsite($websiteId);
        $id = (int)($index['by_path'][$slugPath] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $row = $index['by_id'][$id] ?? null;

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $websiteId, int $categoryId): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }
        $index = $this->forWebsite($websiteId);
        $row = $index['by_id'][$categoryId] ?? null;

        return \is_array($row) ? $row : null;
    }

    public function invalidate(int $websiteId): void
    {
        $websiteId = max(0, $websiteId);
        $this->hotCache->purgeProcessCacheForLogicalKey(self::logicalCacheKey($websiteId));
    }

    /**
     * @return array{
     *     by_id: array<int, array<string, mixed>>,
     *     by_parent: array<int, list<array<string, mixed>>>,
     *     by_path: array<string, int>
     * }
     */
    private function build(int $websiteId): array
    {
        $byId = [];
        $byParent = [];
        $byPath = [];

        foreach ($this->categories->listAll($websiteId) as $row) {
            if (\strtolower(\trim((string)($row[Category::schema_fields_STATUS] ?? 'active'))) === 'inactive') {
                continue;
            }
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $presented = $this->present($row);
            $byId[$id] = $presented;
            $parentId = max(0, (int)($presented['parent_id'] ?? 0));
            $byParent[$parentId] ??= [];
            $byParent[$parentId][] = $presented;

            $path = \strtolower(\trim((string)($presented['path'] ?? ''), '/'));
            if ($path !== '') {
                $byPath[$path] = $id;
            }
        }

        return [
            'by_id' => $byId,
            'by_parent' => $byParent,
            'by_path' => $byPath,
        ];
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        $path = \trim(\str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/');
        if ($path !== '' && $path[0] === '/') {
            $path = \ltrim($path, '/');
        }

        return [
            'id' => (int)($row[Category::schema_fields_ID] ?? 0),
            'uuid' => \trim((string)($row[Category::schema_fields_GLOBAL_CATEGORY_UUID] ?? '')),
            'parent_id' => (int)($row[Category::schema_fields_PARENT_ID] ?? 0),
            'path' => $path,
            'name' => $this->displayNameFromPath($path),
            'url' => $path !== '' ? '/category/' . $path : '/categories',
        ];
    }

    private function displayNameFromPath(string $path): string
    {
        $path = \trim(\str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return (string)__('分类');
        }
        $parts = \explode('/', $path);
        $leaf = (string)\end($parts);
        $leaf = \str_replace(['-', '_'], ' ', $leaf);

        return $leaf !== '' ? $leaf : $path;
    }
}
