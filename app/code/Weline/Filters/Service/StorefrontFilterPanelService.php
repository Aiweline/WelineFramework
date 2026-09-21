<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCategoryListingFilter;
use Weline\Product\Service\StorefrontCategoryTreeIndex;
use Weline\Product\Service\StorefrontCategoryViewService;
use Weline\Product\Service\StorefrontEavLabelResolver;

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
        private readonly StorefrontFacetPresentationPolicy $facetPresentation,
        private readonly StorefrontEavLabelResolver $eavLabels,
        private readonly StorefrontFacetTranslator $facetTranslator,
        private readonly Url $url,
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    /**
     * True for category/product listing routes — not PDP or unrelated chrome pages.
     */
    public static function isListingLikePath(string $requestPath): bool
    {
        $requestPath = strtolower(trim(str_replace('\\', '/', $requestPath), '/'));

        // Match listing segments even when a locale/currency prefix is present.
        // Keep `product/{slug}` (PDP) out: require `products` / `categories` / `category/…`.
        return preg_match('#(?:^|/)(?:categories|products)(?:/|$)#', $requestPath) === 1
            || preg_match('#(?:^|/)category(?:/|$)#', $requestPath) === 1;
    }

    /**
     * Resolve unfiltered listing offers when Theme widget render cleared page assigns.
     * Only listing-like paths may rebuild catalog projections; PDP/other pages return [].
     *
     * @return list<array<string, mixed>>
     */
    public function resolveListingOffers(string $requestPath): array
    {
        $contextOffers = StorefrontPageContext::listingOffers();
        if ($contextOffers !== null) {
            return $contextOffers;
        }

        $requestPath = strtolower(trim(str_replace('\\', '/', $requestPath), '/'));
        if (!self::isListingLikePath($requestPath)) {
            return [];
        }

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

                return $this->catalog->publishedOffersForProductIds($productIds, 120, false);
            }

            // Root listing: prefer candidates (no media) + request memo / HotCache single-flight.
            return $this->catalog->publishedListingCandidates(120, false);
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
        try {
            $treeIndex = $this->tree->forWebsite($websiteId);
            $byParent = is_array($treeIndex['by_parent'] ?? null) ? $treeIndex['by_parent'] : [];
        } catch (\Throwable) {
            return [];
        }
        while ($queue !== []) {
            $parentId = array_shift($queue);
            foreach ($byParent[(int)$parentId] ?? [] as $child) {
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
        $query = $this->normalizePanelQuery($query);
        $logicalKey = $this->buildPanelLogicalKey($offers, $listingUrl, $query, $websiteId, $rootCurrent);

        $panel = $this->hotCache->rememberPolicy(
            StorefrontCatalogCacheCoordinator::filterPanelPolicy(),
            $logicalKey,
            fn(): array => $this->hotCache->rememberForRequest(
                'storefront.filters.panel',
                $logicalKey,
                fn(): array => \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                    'storefront.filters.panel',
                    fn(): array => $this->buildPanelData($offers, $listingUrl, $query, $websiteId, $rootCurrent),
                    ['website_id' => $websiteId, 'offers' => count($offers)],
                ),
            ),
        );

        if (!is_array($panel)) {
            return [];
        }

        // Cache may have been built before label/locale fixes. Always re-bind
        // facet chrome to the current request locale before Phrase prefetch.
        $panel = $this->localizePanelLabels($panel);

        // Dynamic facet labels are localized via EAV / facetTranslator below.
        // Prefetching every option into Phrase worker L1 permanently parks
        // unique (often missing) strings across PDP/list crawls — skip it.

        return $panel;
    }

    /**
     * @param array<string, mixed> $panel
     * @return array<string, mixed>
     */
    private function localizePanelLabels(array $panel): array
    {
        if (isset($panel['attributes']) && is_array($panel['attributes'])) {
            foreach ($panel['attributes'] as $index => $group) {
                if (!is_array($group)) {
                    continue;
                }
                $code = (string)($group['code'] ?? '');
                $localizedName = $code !== '' ? trim($this->eavLabels->attributeLabel($code)) : '';
                $name = $localizedName !== '' ? $localizedName : (string)($group['name'] ?? '');
                $panel['attributes'][$index]['name'] = $this->facetTranslator->translate($name);
                if (!isset($group['options']) || !is_array($group['options'])) {
                    continue;
                }
                foreach ($group['options'] as $optionIndex => $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $value = (string)($option['value'] ?? '');
                    $existing = (string)($option['label'] ?? '');
                    $resolved = ($code !== '' && $value !== '')
                        ? trim($this->eavLabels->resolve($code, $value))
                        : '';
                    // resolve() falls back to the raw value token on misses; keep the
                    // already-built panel label in that case so cache rebind does not
                    // erase humanized option chrome.
                    $valueToken = StorefrontEavLabelResolver::displayOptionToken($value);
                    if ($resolved !== '' && strcasecmp($resolved, $valueToken) !== 0) {
                        $label = $resolved;
                    } elseif ($existing !== '') {
                        $label = $existing;
                    } else {
                        $label = $resolved !== '' ? $resolved : $value;
                    }
                    $panel['attributes'][$index]['options'][$optionIndex]['label'] = $this->facetTranslator->translate($label);
                }
            }
        }

        if (isset($panel['price']) && is_array($panel['price'])) {
            foreach ($panel['price'] as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }
                $label = (string)($option['label'] ?? '');
                if ($label !== '') {
                    $panel['price'][$index]['label'] = $this->facetTranslator->translate($label);
                }
            }
        }

        return $panel;
    }

    /**
     * Keep diagnostics and transport-only query values out of the panel cache key.
     * The panel varies only by active price/sort and selected af_* facets.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizePanelQuery(array $query): array
    {
        $normalized = [];
        foreach ($query as $rawKey => $rawValue) {
            $key = strtolower(trim((string)$rawKey));
            if ($key !== 'price' && $key !== 'sort' && !str_starts_with($key, 'af_')) {
                continue;
            }
            if (is_array($rawValue)) {
                $rawValue = reset($rawValue);
            }
            $value = trim((string)$rawValue);
            if ($value === '') {
                continue;
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * Keep the panel cache stable when card-only fields (media, URLs, labels)
     * change. Facets depend on the product/variant identity and the effective
     * price bucket; the cache policy's catalog/price generations handle their
     * cross-request invalidation.
     *
     * @param list<array<string, mixed>> $offers
     */
    private function buildPanelLogicalKey(
        array $offers,
        string $listingUrl,
        array $query,
        int $websiteId,
        bool $rootCurrent,
    ): string {
        $identities = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $productId = (int)($offer['product_id'] ?? $offer['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $combination = is_array($offer['combination'] ?? null) ? $offer['combination'] : [];
            $combination = $this->normalizePanelCombination($combination);
            $identities[] = [
                $productId,
                (int)($offer['unit_price_minor'] ?? 0),
                !empty($offer['quote_only']),
                $combination,
            ];
        }
        usort($identities, static fn(array $left, array $right): int => strcmp(
            serialize($left),
            serialize($right),
        ));

        return hash('sha256', serialize([
            $identities,
            '/' . ltrim(trim($listingUrl), '/'),
            $query,
            max(0, $websiteId),
            $rootCurrent,
        ]));
    }

    /**
     * @param array<string, mixed> $combination
     * @return array<string, list<string>>
     */
    private function normalizePanelCombination(array $combination): array
    {
        $normalized = [];
        foreach ($combination as $code => $value) {
            $code = strtolower(trim((string)$code));
            if ($code === '') {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            $values = array_values(array_filter(
                array_map(static fn(mixed $item): string => trim((string)$item), $values),
                static fn(string $item): bool => $item !== '',
            ));
            if ($values === []) {
                continue;
            }
            sort($values, SORT_STRING);
            $normalized[$code] = $values;
        }
        ksort($normalized);

        return $normalized;
    }

    private function buildPanelData(
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

        $departments = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase('storefront.filters.departments',
            fn(): array => $this->buildDepartmentNav(max(0, $websiteId), $listingUrl),
        );

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
            foreach (\Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase('storefront.filters.metadata',
                fn(): array => $this->attributes->getFilterableAttributeMetadata('product', [], null, false),
            ) as $code => $row) {
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

        $fallbackCodeNames = [
            'hanfu_chao_dai' => '朝代',
            'hanfu_xing_zhi' => '形制',
            'hanfu_han_fu_zhi_shi' => '汉服形制',
            'style_type' => '类型',
            'hanfu_shi_yong_xing_bie' => '适用性别',
            'color' => '颜色',
            'available_colors' => '可选颜色',
            'size' => '尺码',
            'available_sizes' => '可选尺码',
            'hanfu_zhi_wu' => '面料',
            'material' => '材质',
            'hanfu_zhi_wu_ming_cheng' => '面料名称',
            'hanfu_zhu_zhi_wu_cheng_fen' => '主面料成分',
            'hanfu_shi_yong_chang_he' => '适用场合',
            'hanfu_shi_yong_ji_jie' => '适用季节',
            'hanfu_shi_he_ji_jie' => '适合季节',
            'hanfu_shang_shi_nian_fen_ji_jie' => '上市季节',
            'hanfu_feng_ge' => '风格',
            'hanfu_zao_xing_feng_ge' => '造型风格',
            'hanfu_gong_yi' => '工艺',
            'hanfu_zhi_wu_gong_yi' => '面料工艺',
            'hanfu_tu_an' => '图案',
            'brand' => '品牌',
            'hanfu_pin_pai' => '品牌',
        ];
        $candidateCodeNames = $this->facetPresentation->candidateCodeNames(
            array_replace($fallbackCodeNames, $codeNames),
        );

        $countsByCode = [];
        // Product attributes live on the Website shard. Prefer the catalog
        if ($productIds !== []) {
            try {
                $catalogCounts = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                    'storefront.filters.catalog_counts',
                    fn(): array => $this->catalog->facetCountsForProductIds(
                        $productIds,
                        $candidateCodeNames,
                        $offers,
                    ),
                    ['products' => count($productIds), 'codes' => count($candidateCodeNames)],
                );
                foreach ($catalogCounts as $code => $counts) {
                    $code = strtolower(trim((string)$code));
                    if ($code === '' || !is_array($counts) || $counts === []) {
                        continue;
                    }
                    $normalizedCounts = [];
                    foreach ($counts as $value => $count) {
                        $value = trim((string)$value);
                        if ($value === '' || (int)$count <= 0) {
                            continue;
                        }
                        $label = trim($this->eavLabels->resolve($code, $value));
                        $label = $label !== '' ? $label : $value;
                        $normalizedCounts[$label] = ($normalizedCounts[$label] ?? 0) + (int)$count;
                    }
                    if ($normalizedCounts !== []) {
                        $countsByCode[$code] = $normalizedCounts;
                    }
                }
            } catch (\Throwable) {
                $countsByCode = [];
            }
        }

        // The catalog read model is authoritative on current installations.
        // Only hit the legacy EAV tables when it is unavailable/empty; doing
        // both on every panel made a cold category request pay for the same
        // attribute rows twice.
        if ($countsByCode === [] && $productIds !== []) {
            try {
                $facetData = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                    'storefront.filters.eav_counts',
                    fn(): array => $this->attributes->getFilterableAttributes('product', $productIds, [], false),
                );
                foreach ($facetData as $code => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $code = strtolower(trim((string)$code));
                    if (!isset($candidateCodeNames[$code])) {
                        continue;
                    }
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
                        $candidateCodeNames[$code] = $name !== '' ? $name : $candidateCodeNames[$code];
                    }
                }
            } catch (\Throwable) {
                // Keep offer projection fallback below.
            }
        }

        // Product 店面快照常把可筛属性投影到 offer（brand/specifications），而经典 EAV 值表可能为空。
        foreach (\Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase('storefront.filters.offer_counts',
            fn(): array => $this->attributeListing->countOfferAttributeValues($offers, $candidateCodeNames),
        ) as $code => $derived) {
            if (isset($countsByCode[$code])) {
                continue;
            }
            $countsByCode[$code] = $derived['counts'];
            $candidateCodeNames[$code] = (string)$derived['name'];
        }

        $curatedCounts = $this->facetPresentation->curateFacetCounts(
            $countsByCode,
            $candidateCodeNames,
            $selectedAttrs,
        );
        $attributeGroups = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase('storefront.filters.options',
            function () use ($curatedCounts, $selectedAttrs, $baseExtra, $activePrice, $listingUrl): array {
                $attributeGroups = [];
                foreach ($curatedCounts as $code => $facet) {
                    $counts = $facet['counts'];
                    $options = [];
                    $localizedName = trim($this->eavLabels->attributeLabel((string)$code));
                    $groupName = $localizedName !== '' ? $localizedName : (string)$facet['name'];
                    $groupName = $this->facetTranslator->translate($groupName);
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
                        $localizedLabel = trim($this->eavLabels->resolve((string)$code, $value));
                        $label = $localizedLabel !== '' ? $localizedLabel : $value;
                        $label = $this->facetTranslator->translate($label);
                        $options[] = [
                            'label' => $label,
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
                        'name' => $groupName,
                        'kind' => 'attribute',
                        'options' => $options,
                    ];
                }
                return $attributeGroups;
            },
        );

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
        try {
            $treeIndex = $this->tree->forWebsite($websiteId);
            $byId = is_array($treeIndex['by_id'] ?? null) ? $treeIndex['by_id'] : [];
            $byParent = is_array($treeIndex['by_parent'] ?? null) ? $treeIndex['by_parent'] : [];
        } catch (\Throwable) {
            return [];
        }

        $current = $this->resolveCategoryFromListingUrl($websiteId, $listingUrl, $treeIndex);
        $currentId = is_array($current) ? (int)($current['id'] ?? 0) : 0;
        $pathSet = [];
        if ($currentId > 0) {
            $cursor = $currentId;
            $guard = 0;
            while ($cursor > 0 && $guard++ < 32) {
                $pathSet[$cursor] = true;
                $row = $byId[$cursor] ?? null;
                if (!is_array($row)) {
                    break;
                }
                $cursor = max(0, (int)($row['parent_id'] ?? 0));
            }
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $pathSet, $currentId): void {
            foreach ($byParent[$parentId] ?? [] as $child) {
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
    /**
     * @param array{by_id?: array<int, array<string, mixed>>, by_path?: array<string, int>}|null $treeIndex
     */
    private function resolveCategoryFromListingUrl(int $websiteId, string $listingUrl, ?array $treeIndex = null): ?array
    {
        $path = strtolower(trim(str_replace('\\', '/', (string)(parse_url($listingUrl, PHP_URL_PATH) ?: $listingUrl)), '/'));
        $path = strtolower($this->peelLocalePrefix($path));
        if ($path === '' || !str_starts_with($path, 'category/')) {
            return null;
        }
        $slug = trim(substr($path, strlen('category/')), '/');
        if ($slug === '') {
            return null;
        }
        try {
            if ($treeIndex !== null) {
                $id = (int)($treeIndex['by_path'][$slug] ?? 0);
                $row = $id > 0 ? ($treeIndex['by_id'][$id] ?? null) : null;
                return is_array($row) ? $row : null;
            }
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
            $url = $this->localizeStorefrontPath('category/' . $path);
        } else {
            $pathOnly = parse_url($url, PHP_URL_PATH);
            $url = is_string($pathOnly) && $pathOnly !== ''
                ? $pathOnly
                : '/' . ltrim($url, '/');
        }

        return [
            'name' => $name,
            'url' => $url,
            'current' => $currentId > 0 && (int)($node['id'] ?? 0) === $currentId,
            'depth' => max(0, $depth),
        ];
    }

    /**
     * Rebuild a storefront path with the current currency/language prefix via Url.
     */
    private function localizeStorefrontPath(string $path): string
    {
        $route = $this->peelLocalePrefix(trim(str_replace('\\', '/', $path), '/'));
        if ($route === '') {
            $route = 'categories';
        }

        try {
            $built = (string)$this->url->getFrontendUrl($route);
            $pathOnly = parse_url($built, PHP_URL_PATH);
            if (is_string($pathOnly) && $pathOnly !== '') {
                return $pathOnly;
            }
        } catch (\Throwable) {
            // CLI / unit without request context.
        }

        return '/' . $route;
    }

    private function peelLocalePrefix(string $path): string
    {
        $segments = $path === '' ? [] : explode('/', $path);
        while ($segments !== []) {
            $first = (string)$segments[0];
            if (\Weline\Framework\App\State::isAllowedCurrencyCode($first)
                || \Weline\Framework\App\State::isAllowedLanguageCode($first)
            ) {
                array_shift($segments);
                continue;
            }
            break;
        }

        return implode('/', $segments);
    }

    public static function createDefault(): self
    {
        return ObjectManager::getInstance(self::class);
    }
}
