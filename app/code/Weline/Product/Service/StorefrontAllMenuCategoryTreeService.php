<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;

/**
 * Cached all-menu category nav tree derived from the storefront category index.
 */
final class StorefrontAllMenuCategoryTreeService
{
    private const CACHE_POOL = 'weline_product_storefront_category_tree';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly ProductCatalogQueryConsumer $catalog,
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly MenuTreeNormalizer $normalizer,
    ) {
    }

    public static function logicalCacheKey(int $websiteId): string
    {
        return 'product.all_menu_category_tree.' . max(0, $websiteId);
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function navTree(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);

        /** @var list<array<string, mixed>> $tree */
        $tree = $this->hotCache->remember(
            self::CACHE_POOL,
            self::logicalCacheKey($websiteId),
            self::FRESH_TTL_SECONDS,
            fn(): array => $this->build($websiteId),
            ['website' => true],
            self::STALE_TTL_SECONDS,
        );

        return $tree;
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
    private function build(int $websiteId): array
    {
        $rows = $this->catalog->flatRows($websiteId);
        if ($rows === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((int)($row['is_active'] ?? 1) === 0
                || strtolower(trim((string)($row['status'] ?? 'active'))) === 'inactive') {
                continue;
            }
            $categoryId = max(0, (int)($row['category_id'] ?? $row['id'] ?? 0));
            if ($categoryId <= 0) {
                continue;
            }
            $path = \trim((string)($row['path'] ?? ''), '/');
            if ($path !== '' && $path[0] === '/') {
                $path = \ltrim($path, '/');
            }
            $uuid = \trim((string)($row['uuid'] ?? $row['global_category_uuid'] ?? ''));
            $name = \trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = $this->displayNameFromPath($path);
            }
            $nodes[$categoryId] = [
                'id' => 'category_' . ($uuid !== '' ? \preg_replace('/[^a-zA-Z0-9_-]+/', '_', $uuid) : (string)$categoryId),
                'tag' => MenuTreeNormalizer::TAG_CATEGORY,
                'name' => $name,
                'url' => $path !== '' ? '/category/' . $path : '/categories',
                'ref' => $uuid !== '' ? 'category:' . $uuid : 'category:' . $categoryId,
                'meta' => [
                    'category_id' => $categoryId,
                    'parent_id' => max(0, (int)($row['parent_id'] ?? $row['pid'] ?? 0)),
                    'path' => $path,
                ],
                'children' => [],
                '_parent_id' => max(0, (int)($row['parent_id'] ?? $row['pid'] ?? 0)),
            ];
        }

        $roots = [];
        foreach ($nodes as $categoryId => &$node) {
            $parentId = (int)($node['_parent_id'] ?? 0);
            unset($node['_parent_id']);
            if ($parentId > 0 && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return $this->normalizer->normalize($roots);
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
