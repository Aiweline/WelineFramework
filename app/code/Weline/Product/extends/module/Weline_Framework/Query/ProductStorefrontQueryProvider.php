<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\ProductQuoteRequestSubmitInterface;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryViewService;
use Weline\Product\Service\StorefrontListingShell;

/**
 * Server-side storefront catalogue boundary for cross-module consumers.
 *
 * Website scope is always taken from RequestContext. Callers cannot select a
 * website or shard through query parameters.
 */
class ProductStorefrontQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'product_storefront';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'searchPublishedOffers' => $this->searchPublishedOffers($params),
            'cardsByProductIds' => $this->cardsByProductIds($params),
            'bundleCards' => $this->bundleCards($params),
            'youMayLikeCards' => $this->youMayLikeCards($params),
            'recentlyViewedCards' => $this->recentlyViewedCards($params),
            'liveOffersByProductIds' => $this->liveOffersByProductIds($params),
            'filteredListingPage' => $this->filteredListingPage($params),
            'submitQuoteRequest' => $this->submitQuoteRequest($params),
            default => throw new \InvalidArgumentException((string)__(
                'Product Storefront 接口不支持操作：%{1}',
                [$operation],
            )),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Product Storefront',
            'description' => 'Server-side read/write boundary for published storefront offers and quote requests.',
            'module' => 'Weline_Product',
            'operations' => [
                [
                    'name' => 'searchPublishedOffers',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => false, 'max_length' => 200],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 48],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Search and normalize current-scope published Product offers',
                ],
                [
                    'name' => 'cardsByProductIds',
                    'frontend' => true,
                    'external' => false,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 24],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Batch storefront cards for explicit product IDs (targeted catalog; no live N+1); BinQuery hydrate',
                ],
                [
                    'name' => 'bundleCards',
                    'frontend' => true,
                    'external' => false,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'seed_product_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'companion_limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 3],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'PDP cross-sell bundle (seed + same-category companions); batch only',
                ],
                [
                    'name' => 'youMayLikeCards',
                    'frontend' => true,
                    'external' => false,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'exclude_product_id', 'type' => 'int', 'required' => false, 'min' => 0],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 8],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'PDP you-may-like cards (same-category companions batch)',
                ],
                [
                    'name' => 'recentlyViewedCards',
                    'frontend' => true,
                    'external' => false,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 6],
                        ['name' => 'exclude_product_id', 'type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Recently-viewed cards via cookie MRU → cardsByProductIds batch (no live N+1)',
                ],
                [
                    'name' => 'liveOffersByProductIds',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => '按当前范围批量读取实时商品首个已发布 Offer，以商品 ID 为键；每批最多 100 个商品',
                ],
                [
                    'name' => 'filteredListingPage',
                    'frontend' => true,
                    'external' => false,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        ['name' => 'surface', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'listing_path', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'category_path', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'price', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'sort', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1],
                        ['name' => 'filters', 'type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'PLP path-only: filtered grid HTML + Amazon refinement facet_counts via Filters',
                ],
                [
                    'name' => 'submitQuoteRequest',
                    'frontend' => true,
                    'external' => false,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'product_id', 'type' => 'int', 'required' => true],
                        ['name' => 'sku', 'type' => 'string', 'required' => true],
                        ['name' => 'idempotency_key', 'type' => 'string', 'required' => true],
                        ['name' => 'contact_name', 'type' => 'string', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit a Product-owned quote_only inquiry',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function filteredListingPage(array $params): array
    {
        /** @var StorefrontListingShell $shell */
        $shell = ObjectManager::getInstance(StorefrontListingShell::class);
        /** @var StorefrontCatalogViewService $catalog */
        $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);

        // Price buckets are in active display currency (SSR = website default USD
        // here). CLI / undressed Context still boots CNY → all SKUs land in 300-up
        // and price=0-99 becomes empty + attributes {}. Align only when no WLS
        // ScopeIdentity (shopper currency already applied by QueryBin otherwise).
        $this->alignListingCurrencyForPricing($params);

        $surface = strtolower(trim((string)($params['surface'] ?? 'products')));
        if ($surface !== 'category') {
            $surface = 'products';
        }
        $listingPath = $shell->normalizeListingPath((string)($params['listing_path'] ?? ($surface === 'category' ? '/category' : '/products')));
        $categoryPath = trim(str_replace('\\', '/', (string)($params['category_path'] ?? '')), '/');

        $query = [
            'price' => (string)($params['price'] ?? ''),
            'sort' => (string)($params['sort'] ?? ''),
            'page' => (string)($params['page'] ?? '1'),
        ];
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        foreach ($filters as $code => $value) {
            $code = strtolower(trim((string)$code));
            $code = preg_replace('/[^a-z0-9_\-]/', '', $code) ?? '';
            if ($code === '') {
                continue;
            }
            if (str_starts_with($code, 'af_')) {
                $code = substr($code, 3);
            }
            $raw = is_array($value) ? (string)reset($value) : (string)$value;
            $raw = trim($raw);
            if ($code === '' || $raw === '') {
                continue;
            }
            $query['af_' . $code] = $raw;
        }
        // Also accept flat af_* on params for soft URL mirrors.
        foreach ($params as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!str_starts_with($key, 'af_')) {
                continue;
            }
            $raw = is_array($value) ? (string)reset($value) : (string)$value;
            $raw = trim($raw);
            if ($raw !== '') {
                $query[$key] = $raw;
            }
        }

        // Soft listing always needs attribute projections on baseOffers so
        // price-only (and conjunction) refinement can count af_* dimensions.
        // Catalog projects facet-listing rows (specs+price, no PDP description fat).
        $includeDetails = true;
        if ($surface === 'category') {
            $offers = [];
            if ($categoryPath !== '') {
                /** @var StorefrontCategoryViewService $categories */
                $categories = ObjectManager::getInstance(StorefrontCategoryViewService::class);
                $page = $categories->resolvePage($categoryPath)
                    ?? $categories->synthesizePageFromPublicPath($categoryPath);
                $productIds = is_array($page['product_ids'] ?? null) ? $page['product_ids'] : [];
                if ($productIds !== []) {
                    // Facet-listing bag (no media). Never targeted-full reuse —
                    // full bags OOM QueryBin (~1GB) and leave PLP「加载中…」stuck.
                    $offers = $catalog->publishedFacetListingForProductIds($productIds, 120);
                }
            }
        } else {
            $offers = $catalog->publishedListingCandidates(1000, $includeDetails);
        }
        // Extra lean pass: keep only fields needed for refinement + grid cards.
        $offers = $this->slimOffersForSoftListing($offers);
        if (\function_exists('gc_collect_cycles')) {
            \gc_collect_cycles();
        }

        // Refinement FIRST while specs are present and before grid HTML pulls i18n.
        // attributes must json_encode as {} (object), never [] — front-end reads a map.
        $emptyFacetCounts = ['price' => [], 'attributes' => new \stdClass()];
        $facetCounts = $emptyFacetCounts;
        if (class_exists(\Weline\Filters\Service\StorefrontFilterPanelService::class)) {
            try {
                $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
                /** @var \Weline\Filters\Service\StorefrontFilterPanelService $panel */
                $panel = ObjectManager::getInstance(\Weline\Filters\Service\StorefrontFilterPanelService::class);
                $facetCounts = $panel->buildRefinementFacetCounts(
                    $offers,
                    $query,
                    $listingPath,
                    $websiteId,
                );
                if (!isset($facetCounts['attributes']) || $facetCounts['attributes'] === []) {
                    $facetCounts['attributes'] = new \stdClass();
                }
            } catch (\Throwable $facetError) {
                // Facet failure must not block the grid projection below — but must
                // be probe-visible (was silently returning empty attributes {}).
                $this->recordFacetRefinementFailure($facetError);
                $facetCounts = $emptyFacetCounts;
            }
        }
        if (\function_exists('gc_collect_cycles')) {
            \gc_collect_cycles();
        }

        // Facet-listing bags keep identity codes + empty images for the full
        // catalog (refinement needs specs, not card media). After filter+paginate
        // hydrate only the page bag (~12) to storefront names + real media, while
        // still releasing the full catalog before Phrase/grid (1GB ceiling).
        $projection = $shell->projectFiltered(
            $offers,
            $query,
            $listingPath,
            $surface,
            null,
            true,
            true,
            true,
        );
        // Drop catalog bags before response assembly / Phrase dictionary growth.
        unset($offers, $projection['offers']);
        if (\function_exists('gc_collect_cycles')) {
            \gc_collect_cycles();
        }

        $pageOffers = is_array($projection['page_offers'] ?? null) ? $projection['page_offers'] : [];
        $response = [
            'surface' => $surface,
            'listing_path' => $listingPath,
            'grid_html' => (string)($projection['grid_html'] ?? ''),
            'empty' => $pageOffers === [],
            'price' => (string)$projection['price'],
            'sort' => (string)$projection['sort'],
            'total' => (int)$projection['total'],
            'count' => (int)$projection['count'],
            'page' => (int)$projection['page'],
            'page_size' => (int)$projection['page_size'],
            'total_pages' => (int)$projection['total_pages'],
            'from' => (int)$projection['from'],
            'to' => (int)$projection['to'],
            'sort_options' => $projection['sort_options'],
            'page_options' => $projection['page_options'],
            'applied' => $projection['applied'],
            'facet_counts' => $facetCounts,
        ];
        unset($projection, $pageOffers, $facetCounts);

        return $response;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function bundleCards(array $params): array
    {
        $seedProductId = max(0, (int)($params['seed_product_id'] ?? 0));
        $companionLimit = max(1, min(3, (int)($params['companion_limit'] ?? 3)));
        if ($seedProductId <= 0) {
            return [];
        }

        return ObjectManager::getInstance(\Weline\Product\Service\StorefrontProductWidgetCatalog::class)
            ->bundleCards($seedProductId, $companionLimit);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function youMayLikeCards(array $params): array
    {
        $excludeProductId = max(0, (int)($params['exclude_product_id'] ?? 0));
        $limit = max(1, min(8, (int)($params['limit'] ?? 8)));

        return ObjectManager::getInstance(\Weline\Product\Service\StorefrontProductWidgetCatalog::class)
            ->youMayLikeCards($excludeProductId, $limit);
    }

    /**
     * Soft-depends on Weline_RecentlyViewed; empty when module unavailable.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function recentlyViewedCards(array $params): array
    {
        $limit = max(1, min(6, (int)($params['limit'] ?? 6)));
        $excludeProductId = max(0, (int)($params['exclude_product_id'] ?? 0));
        if (!\class_exists(\Weline\RecentlyViewed\Service\RecentlyViewedService::class)) {
            return [];
        }

        try {
            return ObjectManager::getInstance(\Weline\RecentlyViewed\Service\RecentlyViewedService::class)
                ->cards($limit, $excludeProductId);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function liveOffersByProductIds(array $params): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval', is_array($params['product_ids'] ?? null) ? $params['product_ids'] : [],
        ), static fn(int $id): bool => $id > 0)));
        $byId = [];
        foreach (array_chunk($ids, 100) as $batch) {
            foreach ($this->livePublishedOffers($batch) as $offer) {
                $id = (int)($offer['product_id'] ?? 0);
                if (in_array($id, $batch, true) && !isset($byId[$id])) {
                    $byId[$id] = $offer;
                }
            }
        }
        return $byId;
    }

    /** @return list<array<string, mixed>> */
    protected function livePublishedOffers(array $productIds): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->livePublishedOffersForProductIds($productIds);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function cardsByProductIds(array $params): array
    {
        $rawIds = $params['product_ids'] ?? [];
        if (!\is_array($rawIds)) {
            $rawIds = [];
        }
        $orderedIds = [];
        foreach ($rawIds as $rawId) {
            $productId = max(0, (int)$rawId);
            if ($productId <= 0 || isset($orderedIds[$productId])) {
                continue;
            }
            $orderedIds[$productId] = $productId;
        }
        $orderedIds = \array_values($orderedIds);
        $limit = max(1, min(24, (int)($params['limit'] ?? \count($orderedIds))));
        if ($orderedIds === []) {
            return [];
        }
        $orderedIds = \array_slice($orderedIds, 0, $limit);

        $offersByProductId = [];
        foreach ($this->targetedPublishedOffers($orderedIds) as $offer) {
            if (!\is_array($offer)) {
                continue;
            }
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($productId <= 0 || isset($offersByProductId[$productId])) {
                continue;
            }
            $offersByProductId[$productId] = $this->normalizeOfferSlug($offer);
        }

        $cards = [];
        foreach ($orderedIds as $productId) {
            $offer = $offersByProductId[$productId] ?? null;
            if (!\is_array($offer)) {
                continue;
            }
            $card = $this->renderCard($offer, \count($cards));
            if ((int)($card['id'] ?? 0) <= 0) {
                continue;
            }
            $cards[] = $card;
            if (\count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    protected function renderCard(array $offer, int $index): array
    {
        return ProductCardRenderer::fromStorefrontOffer($offer, $index);
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    protected function targetedPublishedOffers(array $productIds): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->publishedOffersForProductIds($productIds, max(\count($productIds), 1), false);
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeOfferSlug(array $offer): array
    {
        $slug = \strtolower(\trim((string)($offer['slug'] ?? '')));
        if ($slug === '') {
            $slug = \strtolower(\trim((string)($offer['source_slug'] ?? '')));
        }
        if ($slug !== '' && \preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) === 1) {
            $offer['slug'] = $slug;
        }

        return $offer;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function submitQuoteRequest(array $params): array
    {
        /** @var ProductQuoteRequestSubmitInterface $service */
        $service = ObjectManager::getInstance(ProductQuoteRequestSubmitInterface::class);

        return $service->submit($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function searchPublishedOffers(array $params): array
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(48, (int)($params['page_size'] ?? 20)));
        $needle = $this->normalize($keyword);

        $matches = array_values(array_filter(
            $this->publishedOffers(),
            function (array $offer) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                return str_contains($this->normalize(implode(' ', [
                    (string)($offer['name'] ?? ''),
                    (string)($offer['sku'] ?? ''),
                ])), $needle);
            },
        ));

        $total = count($matches);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $pageOffers = array_slice($matches, $offset, $pageSize);
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(array $offer): int => (int)($offer['product_id'] ?? 0),
            $pageOffers,
        ))));
        $media = $this->primaryMedia($productIds);

        $items = [];
        foreach ($pageOffers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            $sku = trim((string)($offer['sku'] ?? ''));
            $currency = trim((string)($offer['currency'] ?? ''));
            $price = ((int)($offer['unit_price_minor'] ?? 0)) / 100;
            $items[] = [
                'product_id' => $productId,
                'name' => (string)($offer['name'] ?? $sku),
                'sku' => $sku,
                'short_description' => $sku !== '' ? 'SKU: ' . $sku : '',
                'image' => trim((string)($offer['image'] ?? '')) !== ''
                    ? (string)$offer['image']
                    : (string)($media[$productId] ?? ''),
                'price' => $price,
                'currency' => $currency,
                'formatted_price' => trim($currency . ' ' . number_format((float)$price, 2)),
                'url' => 'products/',
                'source' => 'weline_product',
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'pages' => $pages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $pageSize, $total),
            ],
            'pagination_html' => '',
            'facets' => [],
            'applied_filters' => [],
            'clear_all_url' => '/search',
            'engine' => 'weline_product',
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function publishedOffers(): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->publishedOffers(200);
    }

    /** @param list<int> $productIds @return array<int, string> */
    protected function primaryMedia(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $websiteId = max(0, RequestContext::getWelineWebsiteId());
        $rows = ObjectManager::getInstance(MediaRepository::class)
            ->listByProductIds($websiteId, $productIds);
        $primary = [];
        foreach ($rows as $row) {
            $productId = (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0);
            $path = trim((string)($row[Media::schema_fields_PATH] ?? ''));
            if ($productId > 0 && $path !== '' && !isset($primary[$productId])) {
                $primary[$productId] = $path;
            }
        }

        return $primary;
    }

    private function normalize(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Align display currency with SSR price buckets when ScopeIdentity is absent
     * (CLI / undressed Context). Soft QueryBin already applied shopper currency
     * and typically carries ScopeIdentity — do not override that path.
     *
     * @param array<string, mixed> $params
     */
    private function alignListingCurrencyForPricing(array $params): void
    {
        $fromParam = strtoupper(trim((string)($params['currency'] ?? '')));
        if ($fromParam !== '' && preg_match('/^[A-Z]{3}$/', $fromParam) === 1) {
            RequestContext::setWelineUserCurrency($fromParam);

            return;
        }

        if (RequestContext::scopeIdentity() !== null) {
            return;
        }

        $websiteDefault = '';
        try {
            $websiteDefault = strtoupper(trim(State::resolveWebsiteDefaultCurrency()));
        } catch (\Throwable) {
            $websiteDefault = '';
        }
        if ($websiteDefault === '') {
            return;
        }

        $current = strtoupper(trim(RequestContext::getWelineUserCurrency()));
        if ($current !== $websiteDefault) {
            RequestContext::setWelineUserCurrency($websiteDefault);
        }
    }

    /**
     * Keep only fields required for refinement counts + soft grid cards.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    private function slimOffersForSoftListing(array $offers): array
    {
        $slim = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $specs = [];
            foreach (is_array($offer['specifications'] ?? null) ? $offer['specifications'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $code = strtolower(trim((string)($spec['code'] ?? $spec['attribute_code'] ?? '')));
                $value = trim((string)($spec['value'] ?? ''));
                if ($code === '' || $value === '') {
                    continue;
                }
                $specs[] = [
                    'code' => $code,
                    'label' => trim((string)($spec['label'] ?? '')),
                    'value' => $value,
                ];
            }
            $slim[] = [
                'product_id' => (int)($offer['product_id'] ?? 0),
                'offer_id' => (int)($offer['offer_id'] ?? 0),
                'name' => (string)($offer['name'] ?? ''),
                'sku' => (string)($offer['sku'] ?? ''),
                'slug' => (string)($offer['slug'] ?? ''),
                'url' => (string)($offer['url'] ?? ''),
                'image' => (string)($offer['image'] ?? ''),
                'images' => is_array($offer['images'] ?? null) ? $offer['images'] : [],
                'currency' => strtoupper(trim((string)($offer['currency'] ?? 'CNY'))) ?: 'CNY',
                'unit_price_minor' => max(0, (int)($offer['unit_price_minor'] ?? 0)),
                'catalog_price_minor' => max(0, (int)($offer['catalog_price_minor'] ?? $offer['unit_price_minor'] ?? 0)),
                'quote_only' => !empty($offer['quote_only']),
                'sellable' => !empty($offer['sellable']),
                'stock' => (int)($offer['stock'] ?? 0),
                'brand' => (string)($offer['brand'] ?? ''),
                'brand_code' => (string)($offer['brand_code'] ?? ''),
                'attribute_set' => (string)($offer['attribute_set'] ?? ''),
                'combination' => is_array($offer['combination'] ?? null) ? $offer['combination'] : [],
                'combination_key' => (string)($offer['combination_key'] ?? ''),
                'specifications' => $specs,
                'currency_unavailable' => !empty($offer['currency_unavailable']),
            ];
        }

        return $slim;
    }

    private function recordFacetRefinementFailure(\Throwable $error): void
    {
        $message = $error::class . ': ' . $error->getMessage();
        $requestId = (string)(RequestContext::getRequestId() ?? '');
        \error_log('[product.storefront.facet_refinement] ' . ($requestId !== '' ? $requestId . ' ' : '') . $message);
        try {
            RequestContext::set('product.storefront.facet_counts.error', [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
        } catch (\Throwable) {
        }
        try {
            RequestLifecycleTrace::recordPhase('product.storefront.facet_refinement', 0.0, [
                'status' => 'error',
                'error_class' => $error::class,
                'error' => $error->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }
}
