<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryListingFilter;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Product\Service\StorefrontCategoryViewService;

/**
 * Builds storefront filter panel data: department roots, price buckets, EAV attributes.
 *
 * @phpstan-type FacetOption array{label:string,value:string,count:int,url:string,selected:bool}
 * @phpstan-type FacetGroup array{code:string,name:string,kind:string,options:list<FacetOption>}
 */
final class StorefrontFilterPanelService
{
    public function __construct(
        private readonly AttributeFilterService $attributes,
        private readonly StorefrontAttributeListingFilter $attributeListing,
        private readonly StorefrontCategoryListingFilter $listingFilter,
        private readonly StorefrontCategoryTreeIndex $tree,
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCategoryViewService $categories,
        private readonly CategoryLinkRepository $categoryLinks,
    ) {
    }

    /**
     * Resolve unfiltered listing offers when Theme widget render cleared page assigns.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveListingOffers(string $requestPath): array
    {
        $requestPath = strtolower(trim(str_replace('\\', '/', $requestPath), '/'));
        try {
            if (preg_match('#(?:^|/)category/(.+)$#', $requestPath, $matches) === 1) {
                $publicPath = trim((string)$matches[1], '/');
                $page = $this->categories->resolvePage($publicPath)
                    ?? $this->categories->synthesizePageFromPublicPath($publicPath);
                if (!is_array($page)) {
                    return [];
                }
                $productIds = is_array($page['product_ids'] ?? null) ? $page['product_ids'] : [];
                if ($productIds === []) {
                    $categoryId = (int)(($page['category']['id'] ?? 0));
                    $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
                    $productIds = $this->collectDescendantProductIds($websiteId, $categoryId);
                }
                if ($productIds === []) {
                    return [];
                }

                return $this->catalog->publishedOffersForProductIds($productIds, 120);
            }

            return $this->catalog->publishedOffers();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    private function collectDescendantProductIds(int $websiteId, int $rootCategoryId): array
    {
        if ($rootCategoryId <= 0) {
            return [];
        }

        $queue = [$rootCategoryId];
        $seen = [$rootCategoryId => true];
        $categoryIds = [$rootCategoryId];
        while ($queue !== []) {
            $parentId = array_shift($queue);
            foreach ($this->tree->childrenOf($websiteId, (int)$parentId) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childId = (int)($child['id'] ?? 0);
                if ($childId <= 0 || isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $categoryIds[] = $childId;
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
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $query
     * @return array{
     *     listing_url:string,
     *     clear_url:string,
     *     departments:list<array{name:string,url:string,current:bool,depth:int}>,
     *     price:list<FacetOption>,
     *     attributes:list<FacetGroup>,
     *     selected_attributes:array<string,string>,
     *     active_price:string,
     *     has_active:bool
     * }
     */
    public function buildPanel(
        array $offers,
        string $listingUrl,
        array $query = [],
        int $websiteId = 0,
        bool $rootCurrent = false,
    ): array {
        $listingUrl = '/' . ltrim(trim($listingUrl), '/');
        if ($listingUrl === '/') {
            $listingUrl = '/categories';
        }

        $activePrice = $this->listingFilter->normalizePriceBucket((string)($query['price'] ?? ''));
        $activeSort = $this->listingFilter->normalizeSort((string)($query['sort'] ?? ''));
        $selectedAttrs = $this->attributeListing->normalizeFromRequest($query);

        $baseExtra = [];
        if ($activeSort !== StorefrontCategoryListingFilter::SORT_DEFAULT) {
            $baseExtra['sort'] = $activeSort;
        }

        $productIds = [];
        foreach ($offers as $offer) {
            $id = (int)($offer['product_id'] ?? $offer['id'] ?? 0);
            if ($id > 0) {
                $productIds[] = $id;
            }
        }
        $productIds = array_values(array_unique($productIds));

        $departments = $this->buildDepartmentNav(max(0, $websiteId), $listingUrl);

        $priceOptions = [];
        foreach ($this->listingFilter->priceBucketsWithCounts($offers) as $bucket) {
            if ((int)$bucket['count'] <= 0) {
                continue;
            }
            $code = (string)$bucket['code'];
            $selected = $activePrice === $code;
            $params = $baseExtra;
            foreach ($selectedAttrs as $attrCode => $attrValue) {
                $params[StorefrontAttributeListingFilter::QUERY_PREFIX . $attrCode] = $attrValue;
            }
            if (!$selected) {
                $params['price'] = $code;
            }
            $priceOptions[] = [
                'label' => (string)$bucket['label'],
                'value' => $code,
                'count' => (int)$bucket['count'],
                'url' => $this->attributeListing->buildListingUrl($listingUrl, [], $params),
                'selected' => $selected,
            ];
        }

        $attributeGroups = [];
        $codeNames = [];
        try {
            foreach ($this->attributes->getFilterableAttributeMetadata('product') as $code => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $attr = is_array($row['attribute'] ?? null) ? $row['attribute'] : $row;
                $code = strtolower(trim((string)($attr['code'] ?? $code)));
                if ($code === '' || str_starts_with($code, 'source_')) {
                    continue;
                }
                $name = trim((string)($attr['name'] ?? $code));
                $codeNames[$code] = $name !== '' ? $name : $code;
            }
        } catch (\Throwable) {
            $codeNames = [];
        }

        $facetData = [];
        if ($productIds !== []) {
            try {
                $facetData = $this->attributes->getFilterableAttributes('product', $productIds);
            } catch (\Throwable) {
                $facetData = [];
            }
        }

        $countsByCode = [];
        foreach ($facetData as $code => $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string)$code));
            $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
            $nonEmpty = [];
            foreach ($counts as $value => $count) {
                $count = (int)$count;
                if ($count > 0 && trim((string)$value) !== '') {
                    $nonEmpty[(string)$value] = $count;
                }
            }
            if ($nonEmpty !== []) {
                $countsByCode[$code] = $nonEmpty;
                $attr = is_array($row['attribute'] ?? null) ? $row['attribute'] : [];
                $name = trim((string)($attr['name'] ?? ($codeNames[$code] ?? $code)));
                $codeNames[$code] = $name !== '' ? $name : $code;
            }
        }

        // Product 店面快照常把可筛属性投影到 offer（brand/specifications），而经典 EAV 值表可能为空。
        foreach ($this->attributeListing->countOfferAttributeValues($offers, $codeNames !== [] ? $codeNames : [
            'brand' => '品牌',
            'color' => '颜色',
            'material' => '材质',
            'size' => '尺码',
            'style_type' => '类型',
            'available_colors' => '可选颜色',
            'available_sizes' => '可选尺码',
        ]) as $code => $derived) {
            if (isset($countsByCode[$code])) {
                continue;
            }
            $countsByCode[$code] = $derived['counts'];
            $codeNames[$code] = (string)$derived['name'];
        }

        foreach ($countsByCode as $code => $counts) {
            $options = [];
            foreach ($counts as $value => $count) {
                $value = (string)$value;
                $selected = isset($selectedAttrs[$code])
                    && strtolower($selectedAttrs[$code]) === strtolower($value);
                $nextSelected = $selectedAttrs;
                if ($selected) {
                    unset($nextSelected[$code]);
                } else {
                    $nextSelected[$code] = $value;
                }
                $params = $baseExtra;
                if ($activePrice !== '') {
                    $params['price'] = $activePrice;
                }
                $options[] = [
                    'label' => $value,
                    'value' => $value,
                    'count' => (int)$count,
                    'url' => $this->attributeListing->buildListingUrl($listingUrl, $nextSelected, $params),
                    'selected' => $selected,
                ];
            }
            if ($options === []) {
                continue;
            }
            usort(
                $options,
                static fn(array $a, array $b): int => strcasecmp((string)$a['label'], (string)$b['label']),
            );
            $attributeGroups[] = [
                'code' => (string)$code,
                'name' => (string)($codeNames[$code] ?? $code),
                'kind' => 'attribute',
                'options' => $options,
            ];
        }

        $clearParams = $baseExtra;
        $clearUrl = $this->attributeListing->buildListingUrl($listingUrl, [], $clearParams);

        return [
            'listing_url' => $listingUrl,
            'clear_url' => $clearUrl,
            'departments' => $departments,
            'price' => $priceOptions,
            'attributes' => $attributeGroups,
            'selected_attributes' => $selectedAttrs,
            'active_price' => $activePrice,
            'root_current' => $rootCurrent,
            'has_active' => $activePrice !== '' || $selectedAttrs !== [],
        ];
    }


    /**
     * Amazon-style department nav: expand along the active path and list children under the current node.
     *
     * @return list<array{name:string,url:string,current:bool,depth:int}>
     */
    private function buildDepartmentNav(int $websiteId, string $listingUrl): array
    {
        $websiteId = max(0, $websiteId);
        $current = $this->resolveCategoryFromListingUrl($websiteId, $listingUrl);
        $currentId = is_array($current) ? (int)($current['id'] ?? 0) : 0;
        $pathSet = [];
        if ($currentId > 0) {
            foreach ($this->tree->activePathIds($websiteId, $currentId) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $pathSet[$id] = true;
                }
            }
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $websiteId, $pathSet, $currentId): void {
            foreach ($this->tree->childrenOf($websiteId, $parentId) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $row = $this->presentDepartmentRow($child, $depth, $currentId);
                if ($row === null) {
                    continue;
                }
                $out[] = $row;
                $childId = (int)($child['id'] ?? 0);
                if ($childId > 0 && isset($pathSet[$childId])) {
                    $walk($childId, $depth + 1);
                }
            }
        };

        try {
            $walk(0, 0);
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCategoryFromListingUrl(int $websiteId, string $listingUrl): ?array
    {
        $path = strtolower(trim(str_replace('\\', '/', (string)(parse_url($listingUrl, PHP_URL_PATH) ?: $listingUrl)), '/'));
        if ($path === '' || !str_starts_with($path, 'category/')) {
            return null;
        }
        $slug = trim(substr($path, strlen('category/')), '/');
        if ($slug === '') {
            return null;
        }
        try {
            return $this->tree->findByPath($websiteId, $slug);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array{name:string,url:string,current:bool,depth:int}|null
     */
    private function presentDepartmentRow(array $node, int $depth, int $currentId): ?array
    {
        $name = trim((string)($node['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $url = trim((string)($node['url'] ?? ''));
        if ($url === '') {
            $path = trim(str_replace('\\', '/', (string)($node['path'] ?? '')), '/');
            if ($path === '') {
                return null;
            }
            $url = '/category/' . $path;
        } else {
            $pathOnly = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
            $url = '/' . ltrim($pathOnly, '/');
        }

        return [
            'name' => $name,
            'url' => $url,
            'current' => $currentId > 0 && (int)($node['id'] ?? 0) === $currentId,
            'depth' => max(0, $depth),
        ];
    }

    public static function createDefault(): self
    {
        return ObjectManager::getInstance(self::class);
    }
}
