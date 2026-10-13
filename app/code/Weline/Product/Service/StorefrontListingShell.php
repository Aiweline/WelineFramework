<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Event\EventsManager;

/**
 * Shared storefront PLP projection for /products and /category/**.
 *
 * Path-only FPC: SSR always uses the neutral default (page 1, default sort,
 * no price/af_*). Interactive filters/sort/page refresh via BinQuery and must
 * reuse the same price/sort → storefront_offers_filter → paginate pipeline.
 */
final class StorefrontListingShell
{
    public function __construct(
        private readonly StorefrontCategoryListingFilter $listingFilter,
        private readonly StorefrontListingPager $listingPager,
        private readonly EventsManager $events,
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    /**
     * Neutral SSR listing — ignores visitor filter/sort/page query identity.
     *
     * @param list<array<string, mixed>> $offers
     * @return array{
     *   offers:list<array<string,mixed>>,
     *   page_offers:list<array<string,mixed>>,
     *   price:string,
     *   sort:string,
     *   total:int,
     *   count:int,
     *   page:int,
     *   page_size:int,
     *   total_pages:int,
     *   sort_options:list<array<string,mixed>>,
     *   page_options:list<array<string,mixed>>,
     *   from:int,
     *   to:int,
     *   grid_html:string
     * }
     */
    public function projectNeutral(
        array $offers,
        string $listingPath,
        string $surface = 'products',
        ?int $pageSizeOverride = null,
        bool $hydrateMedia = true,
        bool $withGridHtml = false,
    ): array {
        return $this->project(
            $offers,
            [],
            $listingPath,
            $surface,
            $pageSizeOverride,
            $hydrateMedia,
            $withGridHtml,
        );
    }

    /**
     * Interactive / BinQuery listing — applies price/sort/page/af_* from $query.
     *
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $query
     * @return array{
     *   offers:list<array<string,mixed>>,
     *   page_offers:list<array<string,mixed>>,
     *   price:string,
     *   sort:string,
     *   total:int,
     *   count:int,
     *   page:int,
     *   page_size:int,
     *   total_pages:int,
     *   sort_options:list<array<string,mixed>>,
     *   page_options:list<array<string,mixed>>,
     *   from:int,
     *   to:int,
     *   grid_html:string,
     *   applied:array<string,mixed>
     * }
     */
    public function projectFiltered(
        array &$offers,
        array $query,
        string $listingPath,
        string $surface = 'products',
        ?int $pageSizeOverride = null,
        bool $hydrateMedia = true,
        bool $withGridHtml = true,
        ?bool $releaseCallerCatalog = null,
    ): array {
        return $this->project(
            $offers,
            $this->normalizeQuery($query),
            $listingPath,
            $surface,
            $pageSizeOverride,
            $hydrateMedia,
            $withGridHtml,
            // Soft QueryBin: may hydrate the page bag while still releasing the
            // full catalog before Phrase/grid (worker ~1GB ceiling).
            releaseCallerCatalog: $releaseCallerCatalog ?? !$hydrateMedia,
        );
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function project(
        array &$offers,
        array $query,
        string $listingPath,
        string $surface,
        ?int $pageSizeOverride,
        bool $hydrateMedia,
        bool $withGridHtml,
        bool $releaseCallerCatalog = false,
    ): array {
        $listingPath = $this->normalizeListingPath($listingPath);
        $priceBucket = $this->listingFilter->normalizePriceBucket((string)($query['price'] ?? ''));
        $sort = $this->listingFilter->normalizeSort((string)($query['sort'] ?? ''));
        $filteredOffers = $this->listingFilter->apply($offers, $priceBucket, $sort);

        $filterEvent = [
            'offers' => $filteredOffers,
            'query' => $query,
            'surface' => $surface,
        ];
        $this->events->dispatch('Weline_Product::storefront_offers_filter', $filterEvent);
        $filteredOffers = is_array($filterEvent['offers'] ?? null) ? $filterEvent['offers'] : $filteredOffers;

        $page = $this->listingFilter->normalizePage($query['page'] ?? 1);
        $paged = $this->listingFilter->paginate($filteredOffers, $page, $pageSizeOverride);
        $pageOffers = $paged['items'];
        if ($hydrateMedia) {
            // Soft facet bags need names+media; SSR summary bags already have
            // display names and only need media — presentation hydrate covers both.
            $pageOffers = $this->catalog->hydrateListingCardPresentation($pageOffers);
        }

        $sortOptions = $this->buildSortOptions($listingPath, $sort);
        $pageOptions = [];
        if ((int)$paged['total_pages'] > 1) {
            $pageOptions = $this->buildPathOnlyPageOptions(
                $listingPath,
                (int)$paged['page'],
                (int)$paged['total_pages'],
            );
        }

        $count = (int)$paged['total'];
        $pageNum = (int)$paged['page'];
        $pageSize = (int)$paged['page_size'];
        $from = $count > 0 ? (($pageNum - 1) * $pageSize) + 1 : 0;
        $to = $count > 0 ? min($pageNum * $pageSize, $count) : 0;

        $appliedAttrs = [];
        foreach ($query as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!str_starts_with($key, 'af_')) {
                continue;
            }
            $raw = is_array($value) ? (string)reset($value) : (string)$value;
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $appliedAttrs[substr($key, 3)] = $raw;
        }

        // Soft refresh: drop full catalog (caller + locals) BEFORE card HTML so
        // Phrase dictionary load has headroom under the ~1GB QueryBin ceiling.
        // Grid only needs the current pageOffers (~page_size rows).
        $retainCatalogOffers = $hydrateMedia && !$releaseCallerCatalog;
        $catalogTotal = count($offers);
        $totalPages = (int)$paged['total_pages'];
        if (!$retainCatalogOffers) {
            $offers = [];
            unset($filteredOffers, $paged);
            if (\function_exists('gc_collect_cycles')) {
                \gc_collect_cycles();
            }
        }

        $gridHtml = '';
        if ($withGridHtml) {
            $gridHtml = ProductCardRenderer::projectFromOffers($pageOffers, [
                'show_price' => true,
                'show_add_to_cart' => true,
                'show_wishlist' => true,
                'show_compare' => true,
                'show_quickview' => true,
                'show_labels' => true,
                'show_sku' => true,
                'density' => 'standard',
                'class' => 'wpc-listing-card',
                'wishlist_pixel' => true,
            ]);
        }

        return [
            'offers' => $retainCatalogOffers ? $offers : [],
            'page_offers' => $pageOffers,
            'price' => $priceBucket,
            'sort' => $sort,
            'total' => $catalogTotal,
            'count' => $count,
            'page' => $pageNum,
            'page_size' => $pageSize,
            'total_pages' => $totalPages,
            'sort_options' => $sortOptions,
            'page_options' => $pageOptions,
            'from' => $from,
            'to' => $to,
            'grid_html' => $gridHtml,
            'applied' => [
                'price' => $priceBucket,
                'sort' => $sort,
                'page' => $pageNum,
                'attributes' => $appliedAttrs,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function normalizeQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            $key = strtolower(trim((string)$key));
            if ($key === '') {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            if ($key === 'price' || $key === 'sort' || $key === 'page' || str_starts_with($key, 'af_')) {
                $out[$key] = $text;
            }
        }

        return $out;
    }

    public function normalizeListingPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/products';
        }
        $parsed = parse_url($path, PHP_URL_PATH);
        if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
        }
        $path = '/' . trim(str_replace('\\', '/', $path), '/');

        return $path === '/' ? '/products' : $path;
    }

    /**
     * @return list<array{code:string,label:string,url:string,selected:bool}>
     */
    private function buildSortOptions(string $listingPath, string $selectedSort): array
    {
        $options = [];
        foreach ([
            StorefrontCategoryListingFilter::SORT_DEFAULT => '默认排序',
            StorefrontCategoryListingFilter::SORT_PRICE_ASC => '价格从低到高',
            StorefrontCategoryListingFilter::SORT_PRICE_DESC => '价格从高到低',
            StorefrontCategoryListingFilter::SORT_NAME_ASC => '名称 A-Z',
        ] as $code => $label) {
            $options[] = [
                'code' => $code,
                'label' => $label,
                'url' => $listingPath,
                'selected' => $selectedSort === $code,
            ];
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPathOnlyPageOptions(string $listingPath, int $currentPage, int $totalPages): array
    {
        $options = $this->listingPager->buildPageOptions($listingPath, $currentPage, $totalPages, []);
        foreach ($options as &$option) {
            if (!is_array($option)) {
                continue;
            }
            // Path-only: never emit query-bearing hrefs for formal navigation.
            $option['url'] = $listingPath;
        }
        unset($option);

        return $options;
    }

    public function queryHasAttributeFilters(array $query): bool
    {
        foreach ($this->normalizeQuery($query) as $key => $value) {
            if (str_starts_with((string)$key, 'af_') && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }
}
