<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Context;
use Weline\Product\Repository\CategoryLinkRepository;

/**
 * Builds Google-style product breadcrumb trails for multi-category PDPs.
 *
 * One trail per assigned category (ancestor chain), plus a primary trail for
 * the visible UI crumb. JSON-LD may emit multiple BreadcrumbList nodes.
 */
final class ProductStorefrontBreadcrumbBuilder
{
    public const MAX_TRAILS = 5;

    private const CONTEXT_BUNDLE_KEY = 'product.storefront.breadcrumb_bundle';

    public function __construct(
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly StorefrontCategoryTreeIndex $tree,
    ) {
    }

    /**
     * Carry primary/trails to slot widgets that render outside Detail assign() context.
     *
     * @param array{primary?: list<array{name:string,url:string}>, trails?: list<list<array{name:string,url:string}>>, primary_category_id?: int} $bundle
     */
    public static function remember(array $bundle): void
    {
        if (Context::hasCurrent()) {
            Context::current()->set(self::CONTEXT_BUNDLE_KEY, $bundle);
        }
    }

    /**
     * @return array{primary: list<array{name:string,url:string}>, trails: list<list<array{name:string,url:string}>>, primary_category_id: int}|null
     */
    public static function remembered(): ?array
    {
        $bundle = Context::getCurrent()?->get(self::CONTEXT_BUNDLE_KEY);
        if (!is_array($bundle) || !isset($bundle['primary']) || !is_array($bundle['primary'])) {
            return null;
        }

        return [
            'primary' => array_values(array_filter($bundle['primary'], 'is_array')),
            'trails' => isset($bundle['trails']) && is_array($bundle['trails'])
                ? array_values(array_filter($bundle['trails'], 'is_array'))
                : [],
            'primary_category_id' => (int)($bundle['primary_category_id'] ?? 0),
        ];
    }


    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>>|null $linkRows Optional fixture rows (tests / offline).
     * @param array{by_id?: array<int, array<string, mixed>>, by_parent?: mixed, by_path?: mixed}|null $treeIndex
     * @return array{
     *     primary: list<array{name:string,url:string}>,
     *     trails: list<list<array{name:string,url:string}>>,
     *     primary_category_id: int
     * }
     */
    public function build(
        int $websiteId,
        int $productId,
        array $product,
        string $productName,
        string $canonicalUrl,
        int $preferredCategoryId = 0,
        string $referer = '',
        ?array $linkRows = null,
        ?array $treeIndex = null,
    ): array {
        $siteRoot = $this->siteRoot($canonicalUrl);
        $home = [
            'name' => \function_exists('__') ? (string)__('首页') : '首页',
            'url' => $siteRoot !== '' ? $siteRoot : '/',
        ];
        $leafName = $this->cleanLeafName($productName !== ''
            ? $productName
            : (string)($product['name'] ?? $product['meta_name'] ?? ''));
        $leaf = [
            'name' => $leafName,
            'url' => $canonicalUrl !== '' ? $canonicalUrl : '#',
        ];

        $assignments = $this->resolveAssignments($websiteId, $productId, $product, $preferredCategoryId, $linkRows);
        $byId = is_array($treeIndex['by_id'] ?? null)
            ? $treeIndex['by_id']
            : $this->tree->forWebsite($websiteId)['by_id'];
        $trails = [];
        $trailCategoryIds = [];

        foreach ($assignments as $assignment) {
            $categoryId = (int)($assignment['category_id'] ?? 0);
            if ($categoryId <= 0 || !isset($byId[$categoryId])) {
                continue;
            }
            $chain = $this->ancestorChain($byId, $categoryId);
            if ($chain === []) {
                continue;
            }
            $trail = [$home];
            foreach ($chain as $row) {
                if (StorefrontCategoryPublicFilter::shouldHideFromCustomers($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? ''));
                $url = trim((string)($row['url'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $trail[] = [
                    'name' => $name,
                    'url' => $url !== '' ? $url : ($siteRoot !== '' ? $siteRoot : '/'),
                ];
            }
            if ($leaf['name'] !== '') {
                $trail[] = $leaf;
            }
            if (count($trail) < 2) {
                continue;
            }
            $trails[] = $trail;
            $trailCategoryIds[] = $categoryId;
            if (count($trails) >= self::MAX_TRAILS) {
                break;
            }
        }

        if ($trails === []) {
            $fallback = [$home];
            if ($leaf['name'] !== '') {
                $fallback[] = $leaf;
            }
            $trails = count($fallback) >= 2 ? [$fallback] : [];
            $trailCategoryIds = [0];
        }

        $primaryIndex = $this->pickPrimaryIndex(
            $trailCategoryIds,
            $preferredCategoryId,
            $referer,
            $byId,
        );
        $primary = $trails[$primaryIndex] ?? $trails[0];

        return [
            'primary' => $primary,
            'trails' => $trails,
            'primary_category_id' => (int)($trailCategoryIds[$primaryIndex] ?? 0),
        ];
    }

    /**
     * Theme breadcrumb partial uses text/url (last crumb has empty url).
     *
     * @param list<array{name:string,url:string}> $trail
     * @return list<array{text:string,url:string}>
     */
    public function toVisibleItems(array $trail): array
    {
        $items = [];
        $total = count($trail);
        foreach ($trail as $index => $crumb) {
            $name = trim((string)($crumb['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $isLast = $index === $total - 1;
            $items[] = [
                'text' => $name,
                'url' => $isLast ? '' : trim((string)($crumb['url'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>>|null $linkRows
     * @return list<array{category_id:int,position:int}>
     */
    private function resolveAssignments(
        int $websiteId,
        int $productId,
        array $product,
        int $preferredCategoryId,
        ?array $linkRows = null,
    ): array {
        $rows = [];
        $sourceRows = $linkRows;
        if ($sourceRows === null && $productId > 0) {
            try {
                $sourceRows = $this->categoryLinks->listByProductIds($websiteId, [$productId], [0]);
            } catch (\Throwable) {
                $sourceRows = [];
            }
        }
        foreach (is_array($sourceRows) ? $sourceRows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = (int)($row['category_id'] ?? 0);
            $selected = (int)($row['selected'] ?? 1);
            if ($categoryId <= 0 || $selected !== 1) {
                continue;
            }
            $rows[$categoryId] = [
                'category_id' => $categoryId,
                'position' => (int)($row['position'] ?? 0),
            ];
        }

        foreach (['category_id', 'primary_category_id', 'main_category_id'] as $key) {
            $id = (int)($product[$key] ?? 0);
            if ($id > 0 && !isset($rows[$id])) {
                $rows[$id] = ['category_id' => $id, 'position' => 1000 + $id];
            }
        }
        if ($preferredCategoryId > 0 && !isset($rows[$preferredCategoryId])) {
            $rows[$preferredCategoryId] = [
                'category_id' => $preferredCategoryId,
                'position' => -1,
            ];
        }

        $list = array_values($rows);
        usort($list, static function (array $a, array $b): int {
            $pos = ((int)$a['position']) <=> ((int)$b['position']);
            return $pos !== 0 ? $pos : ((int)$a['category_id']) <=> ((int)$b['category_id']);
        });

        return $list;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     * @return list<array<string, mixed>>
     */
    private function ancestorChain(array $byId, int $categoryId): array
    {
        $chain = [];
        $current = $byId[$categoryId] ?? null;
        $guard = 0;
        while (is_array($current) && $guard++ < 32) {
            $chain[] = $current;
            $parentId = (int)($current['parent_id'] ?? 0);
            if ($parentId <= 0 || !isset($byId[$parentId])) {
                break;
            }
            $current = $byId[$parentId];
        }

        return array_reverse($chain);
    }

    /**
     * @param list<int> $trailCategoryIds
     * @param array<int, array<string, mixed>> $byId
     */
    private function pickPrimaryIndex(
        array $trailCategoryIds,
        int $preferredCategoryId,
        string $referer,
        array $byId,
    ): int {
        if ($preferredCategoryId > 0) {
            $idx = array_search($preferredCategoryId, $trailCategoryIds, true);
            if ($idx !== false) {
                return (int)$idx;
            }
        }

        $fromReferer = $this->categoryIdFromReferer($referer, $byId);
        if ($fromReferer > 0) {
            $idx = array_search($fromReferer, $trailCategoryIds, true);
            if ($idx !== false) {
                return (int)$idx;
            }
            // Prefer a trail whose leaf category is an ancestor/descendant of referer category.
            foreach ($trailCategoryIds as $i => $categoryId) {
                if ($this->isRelatedCategory($byId, $categoryId, $fromReferer)) {
                    return (int)$i;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     */
    private function categoryIdFromReferer(string $referer, array $byId): int
    {
        $referer = trim($referer);
        if ($referer === '') {
            return 0;
        }
        $path = (string)(parse_url($referer, PHP_URL_PATH) ?? '');
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || !preg_match('#(?:^|/)category/(.+)$#i', $path, $m)) {
            return 0;
        }
        $categoryPath = trim((string)$m[1], '/');
        foreach ($byId as $id => $row) {
            $rowPath = trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/');
            if ($rowPath !== '' && strcasecmp($rowPath, $categoryPath) === 0) {
                return (int)$id;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     */
    private function isRelatedCategory(array $byId, int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $chainA = array_map(
            static fn(array $row): int => (int)($row['id'] ?? $row['category_id'] ?? 0),
            $this->ancestorChain($byId, $a),
        );
        $chainB = array_map(
            static fn(array $row): int => (int)($row['id'] ?? $row['category_id'] ?? 0),
            $this->ancestorChain($byId, $b),
        );

        return in_array($a, $chainB, true) || in_array($b, $chainA, true);
    }

    private function siteRoot(string $canonicalUrl): string
    {
        if ($canonicalUrl !== '' && preg_match('#^(https?://[^/]+)#i', $canonicalUrl, $m)) {
            return $m[1] . '/';
        }

        return '';
    }

    private function cleanLeafName(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ' | ')) {
            $name = trim((string)explode(' | ', $name, 2)[0]);
        }

        return $name;
    }
}
