<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Url;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;

/**
 * Cached all-menu category nav tree derived from the storefront category index.
 */
final class StorefrontAllMenuCategoryTreeService
{
    private const CACHE_POOL = 'weline_product_storefront_category_tree';

    public function __construct(
        private readonly ProductCatalogQueryConsumer $catalog,
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly MenuTreeNormalizer $normalizer,
        private readonly Url $url,
        private readonly ?ProductCategoryAttributeService $categoryAttributes = null,
    ) {
    }

    public static function logicalCacheKey(int $websiteId, string $locale = ''): string
    {
        // v7: empty category description is ensured into EAV/Local per locale (attribute i18n).
        // Keep the locale explicit when State has advanced ahead of the frozen key.
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        return 'product.all_menu_category_tree.v7.' . max(0, $websiteId) . '.' . $locale;
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
        // Prefer live State locale (query/path override). Fall back to KeyBuilder
        // only when State is empty so PostResponse SWR still has a stable value.
        $locale = trim((string)State::getLangLocal());
        if ($locale === '') {
            $locale = trim((string)(KeyBuilder::storefrontDimensions(false)['lang'] ?? ''));
        }
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        $logicalKey = self::logicalCacheKey($websiteId, $locale);
        // The public tree carries URLs; only its raw routes belong in the shared cache.
        $requestKey = $logicalKey . '|' . KeyBuilder::environmentHash(
            ['resource' => 'product.category_menu.urls', 'locale' => $locale],
            ['area_route' => false],
        );

        return $this->hotCache->rememberForRequest(
            'product.category_menu.urls',
            $requestKey,
            function () use ($websiteId, $locale, $logicalKey): array {
                $tree = $this->hotCache->rememberPolicy(
                    StorefrontCatalogCacheCoordinator::categoryMenuPolicy(),
                    $logicalKey,
                    fn(): array => $this->build($websiteId, $locale),
                );

                return $this->materializeUrls($tree);
            },
        );
    }

    /**
     * Copy shared nodes into the current request without changing their public shape.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private function materializeUrls(array $tree): array
    {
        foreach ($tree as $index => $node) {
            $node['url'] = $this->url->getFrontendUrl((string)$node['url']);
            if ($node['children'] !== []) {
                $node['children'] = $this->materializeUrls($node['children']);
            }
            $tree[$index] = $node;
        }

        return $tree;
    }

    public function invalidate(int $websiteId): void
    {
        $websiteId = max(0, $websiteId);
        foreach (['zh_Hans_CN', 'en_US', trim((string)State::getLangLocal())] as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            $logicalKey = self::logicalCacheKey($websiteId, $locale);
            $this->hotCache->forgetPolicy(
                StorefrontCatalogCacheCoordinator::categoryMenuPolicy(),
                $logicalKey,
            );
        }
        // Legacy v3 key (pre-locale-in-key).
        $this->hotCache->purgeProcessCacheForLogicalKey('product.all_menu_category_tree.v3.' . $websiteId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(int $websiteId, string $locale): array
    {
        $locale = trim($locale);
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        $rows = $this->catalog->flatRows($websiteId, $locale);
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
            if (StorefrontCategoryPublicFilter::shouldHideFromCustomers([
                'path' => $path,
                'name' => \trim((string)($row['name'] ?? '')),
            ])) {
                continue;
            }
            $uuid = \trim((string)($row['uuid'] ?? $row['global_category_uuid'] ?? ''));
            $name = \trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = $this->displayNameFromPath($path);
            }
            $node = [
                'id' => 'category_' . ($uuid !== '' ? \preg_replace('/[^a-zA-Z0-9_-]+/', '_', $uuid) : (string)$categoryId),
                'tag' => MenuTreeNormalizer::TAG_CATEGORY,
                'name' => $name,
                'url' => $path !== '' ? 'category/' . $path : 'categories',
                'ref' => $uuid !== '' ? 'category:' . $uuid : 'category:' . $categoryId,
                'meta' => [
                    'category_id' => $categoryId,
                    'parent_id' => max(0, (int)($row['parent_id'] ?? $row['pid'] ?? 0)),
                    'path' => $path,
                ],
                'children' => [],
                '_parent_id' => max(0, (int)($row['parent_id'] ?? $row['pid'] ?? 0)),
            ];
            $image = \trim((string)($row['image'] ?? ''));
            $banner = \trim((string)($row['banner'] ?? ''));
            $summary = \trim((string)($row['summary'] ?? ''));
            $description = \trim((string)($row['description'] ?? ''));
            if ($description === '' && $name !== '') {
                $attributes = $this->categoryAttributes;
                if ($attributes instanceof ProductCategoryAttributeService) {
                    try {
                        $description = $attributes->ensureDescription($websiteId, $categoryId, $name, $locale);
                    } catch (\Throwable) {
                        $description = $attributes->composeDefaultDescription($name, $locale);
                    }
                }
            }
            if ($image !== '' && !\str_starts_with($image, 'data:image/')) {
                $node['image'] = $image;
            }
            if ($banner !== '' && !\str_starts_with($banner, 'data:image/')) {
                $node['banner'] = $banner;
            }
            if ($summary !== '') {
                $node['summary'] = $summary;
            }
            if ($description !== '') {
                $node['description'] = $description;
            }
            $nodes[$categoryId] = $node;
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
