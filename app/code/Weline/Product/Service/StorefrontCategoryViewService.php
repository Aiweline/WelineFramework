<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Repository\CategoryLinkRepository;

final class StorefrontCategoryViewService
{
    public function __construct(
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly StorefrontCategoryTreeIndex $tree,
        private readonly Url $url,
    ) {
    }

    /**
     * @return array{
     *     category: array<string, mixed>,
     *     children: list<array<string, mixed>>,
     *     siblings: list<array<string, mixed>>,
     *     tree: list<array<string, mixed>>,
     *     active_path_ids: list<int>,
     *     product_ids: list<int>,
     *     breadcrumbs: list<array{label:string,url:string}>
     * }|null
     */
    public function resolvePage(string $publicPath): ?array
    {
        $slugPath = $this->normalizePublicPath($publicPath);
        if ($slugPath === '') {
            return null;
        }

        $websiteId = (int)$this->currentScope()->websiteId;
        $category = $this->tree->findByPath($websiteId, $slugPath);
        if ($category === null) {
            return null;
        }

        $categoryId = (int)($category['id'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        $parentId = max(0, (int)($category['parent_id'] ?? 0));
        $children = $this->tree->childrenOf($websiteId, $categoryId);
        $siblings = $this->tree->siblingsOf($websiteId, $parentId);

        // A parent category represents the complete subtree. Resolve all of its
        // links in one cached batch so the controller and Filters widget share
        // the same product set instead of rebuilding it independently.
        $productIds = $this->collectCategoryProductIds($websiteId, $categoryId);

        return [
            'category' => $category,
            'children' => $children,
            'siblings' => $siblings,
            'tree' => $this->tree->nestedRoots($websiteId),
            'active_path_ids' => $this->tree->activePathIds($websiteId, $categoryId),
            'product_ids' => $productIds,
            'breadcrumbs' => $this->buildBreadcrumbs($websiteId, $category),
        ];
    }

    /**
     * @return array{
     *     category: array<string, mixed>,
     *     children: list<array<string, mixed>>,
     *     siblings: list<array<string, mixed>>,
     *     tree: list<array<string, mixed>>,
     *     active_path_ids: list<int>,
     *     product_ids: list<int>,
     *     breadcrumbs: list<array{label:string,url:string}>
     * }|null
     */
    public function synthesizePageFromPublicPath(string $publicPath): ?array
    {
        $slugPath = $this->normalizePublicPath($publicPath);
        if ($slugPath === '' || !preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#D', $slugPath)) {
            return null;
        }

        $websiteId = (int)$this->currentScope()->websiteId;
        $segments = explode('/', $slugPath);
        $breadcrumbs = [];
        $prefix = [];
        $paths = [];
        foreach ($segments as $segment) {
            $prefix[] = $segment;
            $partialPath = implode('/', $prefix);
            $paths[] = 'category/' . $partialPath;
            $breadcrumbs[] = [
                'label' => $this->displayNameFromPath($segment),
                'url' => '',
            ];
        }

        $urls = $this->url->getFrontendUrls($paths);
        foreach ($urls as $index => $url) {
            $breadcrumbs[$index]['url'] = $url;
        }

        return [
            'category' => [
                'id' => 0,
                'uuid' => '',
                'parent_id' => 0,
                'path' => $slugPath,
                'name' => $this->displayNameFromPath($slugPath),
                'url' => $urls[array_key_last($urls)],
                'synthetic' => true,
            ],
            'children' => [],
            'siblings' => [],
            'tree' => $this->tree->nestedRoots($websiteId),
            'active_path_ids' => [],
            'product_ids' => [],
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    /**
     * @return list<int>
     */
    private function collectCategoryProductIds(int $websiteId, int $categoryId): array
    {
        $categoryId = max(0, $categoryId);
        if ($categoryId <= 0) {
            return [];
        }

        $index = $this->tree->forWebsite($websiteId);
        $byParent = is_array($index['by_parent'] ?? null) ? $index['by_parent'] : [];
        $queue = [$categoryId];
        $seen = [$categoryId => true];
        $categoryIds = [];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            if ($parentId === null) {
                continue;
            }
            $categoryIds[] = (int)$parentId;
            foreach ($byParent[(int)$parentId] ?? [] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childId = (int)($child['id'] ?? 0);
                if ($childId <= 0 || isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $queue[] = $childId;
            }
        }

        $productIds = [];
        foreach ($this->categoryLinks->listByCategoryIds($websiteId, $categoryIds) as $link) {
            $productId = (int)($link['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        return array_values(array_unique($productIds));
    }

    /**
     * @param array<string, mixed> $category
     * @return list<array{label:string,url:string}>
     */
    private function buildBreadcrumbs(int $websiteId, array $category): array
    {
        $byId = $this->tree->forWebsite($websiteId)['by_id'];

        $chain = [];
        $current = $category;
        $guard = 0;
        while ($guard++ < 32) {
            $chain[] = $current;
            $parentId = (int)($current['parent_id'] ?? 0);
            if ($parentId <= 0 || !isset($byId[$parentId])) {
                break;
            }
            $current = $byId[$parentId];
        }

        $chain = array_reverse($chain);
        $breadcrumbs = [];
        foreach ($chain as $row) {
            if (StorefrontCategoryPublicFilter::shouldHideFromCustomers($row)) {
                continue;
            }
            $label = trim((string)($row['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $breadcrumbs[] = [
                'label' => $label,
                'url' => (string)($row['url'] ?? ''),
            ];
        }

        return $breadcrumbs;
    }

    private function displayNameFromPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return (string)__('分类');
        }
        $parts = explode('/', $path);
        $leaf = (string)end($parts);
        $leaf = str_replace(['-', '_'], ' ', $leaf);

        return $leaf !== '' ? $leaf : $path;
    }

    private function normalizePublicPath(string $publicPath): string
    {
        $publicPath = trim(str_replace('\\', '/', $publicPath), '/');
        if (str_starts_with(strtolower($publicPath), 'category/')) {
            $publicPath = substr($publicPath, strlen('category/'));
        }

        return trim($publicPath, '/');
    }

    private function currentScope(): ScopeIdentity
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity && !$scope->isGlobal() && $scope->websiteId !== null) {
            return $scope;
        }

        $websiteId = max(0, RequestContext::getWelineWebsiteId());
        $websiteCode = trim(RequestContext::getWelineWebsiteCode());

        return ScopeIdentity::website($websiteId, $websiteCode !== '' ? $websiteCode : 'default');
    }
}
