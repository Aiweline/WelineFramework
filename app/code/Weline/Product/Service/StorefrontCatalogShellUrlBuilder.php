<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Path-only storefront listing shells for product FPC impact / directed purge.
 *
 * Never includes /search (results are BinQuery + catalog namespace, not baked HTML).
 */
final class StorefrontCatalogShellUrlBuilder
{
    /** @var list<string> */
    public const FIXED_SHELL_PATHS = [
        '/',
        '/products',
        '/new-arrivals',
        '/best-sellers',
    ];

    public function __construct(
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly WebsiteCatalogInterface $websites,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Relative path-only shells (pipeline UrlMatrix expands locale×currency).
     *
     * @return list<string>
     */
    public function relativePathsForProduct(int $websiteId, int $productId): array
    {
        $paths = [];
        foreach (self::FIXED_SHELL_PATHS as $path) {
            $paths[$path] = $path;
        }
        foreach ($this->categoryShellPaths($websiteId, $productId) as $path) {
            $paths[$path] = $path;
        }

        return array_values($paths);
    }

    /**
     * Absolute listing shells for one website origin (cross-site matrix rows).
     *
     * @return list<string>
     */
    public function absolutePathsForWebsite(int $websiteId, int $productId): array
    {
        $base = $this->websiteBaseUrl($websiteId);
        if ($base === '') {
            return [];
        }
        $locs = [];
        foreach ($this->relativePathsForProduct($websiteId, $productId) as $path) {
            $loc = $path === '/' ? $base . '/' : $base . $path;
            $locs[$loc] = $loc;
        }

        return array_values($locs);
    }

    /**
     * Absolute home + listing shells for every website that carries the global product.
     *
     * @return list<string>
     */
    public function absoluteListingUrlsForGlobalProduct(string $globalUuid): array
    {
        $globalUuid = trim($globalUuid);
        if ($globalUuid === '') {
            return [];
        }
        /** @var ProductSitemapUrlService $sitemap */
        $sitemap = ObjectManager::getInstance(ProductSitemapUrlService::class);
        $locs = [];
        foreach ($sitemap->listWebsiteIdsForGlobalProduct($globalUuid) as $websiteId) {
            $product = $this->products->findByGlobalUuid($websiteId, $globalUuid);
            if ($product === null) {
                continue;
            }
            foreach ($this->absolutePathsForWebsite($websiteId, (int)$product->getId()) as $loc) {
                $locs[$loc] = $loc;
            }
        }

        return array_values($locs);
    }

    /**
     * Absolute fixed shells for every website with a public origin (B2B / script fallback).
     *
     * @return list<string>
     */
    public function absoluteFixedShellsForAllWebsites(): array
    {
        $locs = [];
        foreach ($this->websites->all() as $website) {
            $base = rtrim(trim((string)$website->url), '/');
            if ($base === '' || !$this->isHttpOrigin($base)) {
                continue;
            }
            foreach (self::FIXED_SHELL_PATHS as $path) {
                $loc = $path === '/' ? $base . '/' : $base . $path;
                $locs[$loc] = $loc;
            }
        }

        return array_values($locs);
    }

    /**
     * @return list<string>
     */
    private function categoryShellPaths(int $websiteId, int $productId): array
    {
        $websiteId = max(0, $websiteId);
        $productId = max(0, $productId);
        if ($productId < 1) {
            return [];
        }
        $categoryIds = [];
        try {
            foreach ($this->categoryLinks->listByProductIdsAnyStore($websiteId, [$productId]) as $row) {
                $categoryId = (int)($row['category_id'] ?? 0);
                if ($categoryId > 0) {
                    $categoryIds[$categoryId] = $categoryId;
                }
            }
        } catch (\Throwable) {
            return [];
        }
        if ($categoryIds === []) {
            return [];
        }

        $paths = [];
        try {
            /** @var Category $category */
            $category = clone ObjectManager::getInstance(Category::class);
            $rows = $category->forWebsite($websiteId)->clearData()->reset()
                ->where(Category::schema_fields_ID, array_values($categoryIds), 'IN')
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return [];
            }
            // Single-row find() may return associative row; multi select returns list.
            if ($rows !== [] && !array_is_list($rows)) {
                $rows = [$rows];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = trim(str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/');
                if ($path === '') {
                    continue;
                }
                $shell = '/category/' . $path;
                $paths[$shell] = $shell;
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values($paths);
    }

    private function websiteBaseUrl(int $websiteId): string
    {
        foreach ($this->websites->all() as $website) {
            if ($website->id !== $websiteId) {
                continue;
            }
            $base = rtrim(trim((string)$website->url), '/');
            if ($base === '' || !$this->isHttpOrigin($base)) {
                return '';
            }

            return $base;
        }

        return '';
    }

    private function isHttpOrigin(string $base): bool
    {
        return in_array(strtolower((string)parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string)parse_url($base, PHP_URL_HOST) !== '';
    }
}
