<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Api\SearchFacetCapableFilterInterface;
use WeShop\Filters\Model\CategoryFilterConfig;
use WeShop\Filters\Model\FilterRegistry;
use WeShop\Filters\Provider\EavAttributeFilterProvider;
use WeShop\Filters\Service\FilterService;
use WeShop\Filters\Service\FilterUrlService;
use WeShop\Search\Api\SearchBrowseEngineInterface;
use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Search\Model\SearchHistory;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class SearchService
{
    private const SEARCH_ROUTE = '/search';
    private const BROWSE_RESULT_CACHE_TTL = 300;

    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $browseResultCache = [];
    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $fallbackFilterPayloadCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public function searchProducts(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20, string $scope = 'default'): array
    {
        $result = $this->browseProducts($keyword, $filters, $page, $pageSize, $scope, $this->normalizeCategoryIds($filters['category_id'] ?? []), false);

        if (trim($keyword) !== '') {
            $history = $this->getSearchHistoryModel();
            $history->recordSearch(trim($keyword), (int) ($result['total'] ?? 0), $this->getCurrentUserId());
        }

        return [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => (int) ($result['total'] ?? 0),
            'pagination' => (string) ($result['pagination_html'] ?? ''),
            'keyword' => trim($keyword),
            'engine' => (string) ($result['engine'] ?? 'mysql'),
        ];
    }

    public function browseProducts(
        string $keyword = '',
        array $filters = [],
        int $page = 1,
        int $pageSize = 20,
        string $scope = 'default',
        array $categoryIds = [],
        bool $includeFacets = true
    ): array {
        $keyword = trim($keyword);
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $primaryCategoryId = (int) ($categoryIds[0] ?? 0);
        $browseCacheKey = $this->buildBrowseResultCacheKey($keyword, $filters, $page, $pageSize, $scope, $categoryIds, $includeFacets);
        $cachedBrowseResult = $this->getBrowseResultCache($browseCacheKey);
        if (is_array($cachedBrowseResult)) {
            return $cachedBrowseResult;
        }

        $request = [
            'keyword' => $keyword,
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
            'scope' => $scope,
            'category_ids' => $categoryIds,
            'include_facets' => $includeFacets,
            'facet_definitions' => [],
        ];

        $engine = $this->traceStep(
            'search::create_browse_engine',
            fn () => $this->createBrowseEngine($scope),
            ['scope' => $scope]
        );
        $providers = [];
        if ($engine instanceof SearchBrowseEngineInterface) {
            $providers = $includeFacets ? $this->traceStep(
                'search::facet_providers',
                fn () => $this->getFacetProviders($primaryCategoryId),
                ['category_id' => $primaryCategoryId]
            ) : [];
            $facetDefinitions = $includeFacets ? $this->traceStep(
                'search::facet_definitions',
                fn () => $this->buildFacetDefinitions($providers, $primaryCategoryId, $filters, $categoryIds, $keyword),
                [
                    'category_id' => $primaryCategoryId,
                    'provider_count' => count($providers),
                ]
            ) : [];
            $request['facet_definitions'] = $facetDefinitions;
            $result = $this->traceStep(
                'search::engine_browse::' . $engine->getEngineType(),
                fn () => $engine->browseProducts($request),
                [
                    'scope' => $scope,
                    'include_facets' => $includeFacets,
                    'facet_definition_count' => count($facetDefinitions),
                ]
            );
            if (
                (empty($result['fallback']) || !$includeFacets)
                && !$this->shouldUseLocalBrowseFallback($result, $keyword)
            ) {
                $finalized = $this->traceStep(
                    'search::finalize_engine_result',
                    fn () => $this->finalizeBrowseResult($result, $providers, $filters),
                    [
                        'provider_count' => count($providers),
                        'filter_count' => count($filters),
                    ]
                );
                $this->rememberBrowseResultCache($browseCacheKey, $finalized);
                return $finalized;
            }
        }

        $fallbackResult = $this->traceStep(
            'search::fallback_browse_products',
            fn () => $this->fallbackBrowseProducts($request, $providers),
            [
                'provider_count' => count($providers),
                'category_id_count' => count($categoryIds),
                'include_facets' => $includeFacets,
            ]
        );
        $this->rememberBrowseResultCache($browseCacheKey, $fallbackResult);
        return $fallbackResult;
    }

    public function getSearchSuggestions(string $keyword, int $limit = 10, string $scope = 'default'): array
    {
        $keyword = trim($keyword);
        $limit = max(1, $limit);
        if ($keyword === '') {
            return [];
        }

        $engine = $this->createEngine($scope);
        if ($engine) {
            $engineSuggestions = $engine->getSuggestions($keyword, $limit);
            if (!empty($engineSuggestions)) {
                return array_slice($this->normalizeSuggestions($engineSuggestions), 0, $limit);
            }
        }

        $suggestions = [];
        foreach ([
            w_query('product', 'getProductSuggestions', ['keyword' => $keyword, 'limit' => min(5, $limit)]),
            w_query('catalog', 'getCategorySuggestions', ['keyword' => $keyword, 'limit' => min(3, max(1, $limit - count($suggestions)))]),
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $candidate) {
                $item = $this->normalizeSuggestion($candidate);
                $text = (string) ($item['text'] ?? '');
                if ($text === '' || $this->hasSuggestionText($suggestions, $text)) {
                    continue;
                }

                $suggestions[] = $item;
                if (count($suggestions) >= $limit) {
                    return $suggestions;
                }
            }
        }

        $history = $this->getSearchHistoryModel();
        $history->clear();
        $history->where(SearchHistory::schema_fields_KEYWORD, '%' . $keyword . '%', 'like')
            ->order(SearchHistory::schema_fields_SEARCH_COUNT, 'DESC')
            ->limit(min(3, max(1, $limit - count($suggestions))));

        foreach ($history->select()->fetchArray() as $item) {
            $text = (string) ($item[SearchHistory::schema_fields_KEYWORD] ?? '');
            if ($text === '' || $this->hasSuggestionText($suggestions, $text)) {
                continue;
            }

            $suggestions[] = [
                'text' => $text,
                'type' => 'history',
                'icon' => 'fa-history',
                'url' => $this->buildSearchUrl($text),
            ];
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    public function getPopularKeywords(int $limit = 10): array
    {
        return ObjectManager::getInstance(SearchHistory::class)->getPopularKeywords($limit);
    }

    protected function createEngine(string $scope): ?SearchEngineInterface
    {
        return SearchEngineFactory::create($scope);
    }

    protected function createBrowseEngine(string $scope): ?SearchBrowseEngineInterface
    {
        return SearchEngineFactory::createBrowse($scope);
    }

    protected function getSearchHistoryModel(): SearchHistory
    {
        return ObjectManager::getInstance(SearchHistory::class);
    }

    private function getCurrentUserId(): ?int
    {
        try {
            $customerContext = ObjectManager::getInstance(CustomerContextInterface::class);
            $userId = $customerContext->getUserId();
            return $userId !== null ? (int) $userId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, object>
     */
    private function getFacetProviders(int $categoryId): array
    {
        $providers = $this->traceStep(
            'search::base_facet_providers',
            fn () => $this->getBaseFacetProviders($categoryId),
            ['category_id' => $categoryId]
        );
        try {
            $metadata = $this->traceStep(
                'search::dynamic_filterable_metadata',
                fn () => $this->getDynamicFilterableAttributeMetadata('product'),
                ['entity' => 'product']
            );
        } catch (\Throwable) {
            $metadata = [];
        }

        $sortOrder = 200;
        foreach ($metadata as $attributeCode => $data) {
            $providerCode = 'attr_' . $attributeCode;
            if (isset($providers[$providerCode])) {
                continue;
            }

            $provider = EavAttributeFilterProvider::create($attributeCode, (string) ($data['attribute']['name'] ?? $attributeCode), $sortOrder++);
            if (!empty($data['attribute']['is_swatch'])) {
                $provider->setDisplayType('swatch');
            }
            $providers[$provider->getCode()] = $provider;
        }

        uasort($providers, static fn ($left, $right): int => $left->getSortOrder() <=> $right->getSortOrder());

        return array_values($providers);
    }

    /**
     * @return array<string, object>
     */
    protected function getBaseFacetProviders(int $categoryId): array
    {
        $registry = ObjectManager::getInstance(FilterRegistry::class);
        $providers = [];

        foreach ($registry->getAll() as $provider) {
            if ($provider instanceof SearchFacetCapableFilterInterface && $provider->isEnabled($categoryId)) {
                $providers[$provider->getCode()] = $provider;
            }
        }

        $configModel = ObjectManager::getInstance(CategoryFilterConfig::class);
        foreach ($configModel->getEnabledFilters($categoryId) as $config) {
            $filterCode = trim((string) ($config[CategoryFilterConfig::schema_fields_filter_code] ?? ''));
            if (!str_starts_with($filterCode, 'attr_')) {
                continue;
            }

            $provider = EavAttributeFilterProvider::create(substr($filterCode, 5), '', (int) ($config[CategoryFilterConfig::schema_fields_sort_order] ?? 200));
            $provider->setDisplayType((string) ($config[CategoryFilterConfig::schema_fields_display_type] ?? 'list'));
            $provider->setCollapsed((bool) ($config[CategoryFilterConfig::schema_fields_is_collapsed] ?? false));
            $providers[$provider->getCode()] = $provider;
        }

        return $providers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getDynamicFilterableAttributeMetadata(string $entityCode): array
    {
        return ObjectManager::getInstance(AttributeFilterService::class)->getFilterableAttributeMetadata($entityCode);
    }

    /**
     * @param array<int, object> $providers
     * @return array<string, array<string, mixed>>
     */
    private function buildFacetDefinitions(array $providers, int $categoryId, array $filters, array $categoryIds, string $keyword): array
    {
        $definitions = [];
        $seen = [];

        foreach ($providers as $provider) {
            if (!$provider instanceof SearchFacetCapableFilterInterface) {
                continue;
            }

            $definition = $provider->getSearchFacetDefinition($categoryId, [
                'filters' => $filters,
                'category_ids' => $categoryIds,
                'keyword' => $keyword,
            ]);
            if (!is_array($definition)) {
                continue;
            }

            $code = trim((string) ($definition['code'] ?? $provider->getCode()));
            if ($code === '') {
                continue;
            }

            $dedupeKey = ($definition['type'] ?? '') === 'eav'
                ? 'eav:' . trim((string) ($definition['attribute_code'] ?? $code))
                : 'code:' . $code;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $definition['code'] = $code;
            $definitions[$code] = $definition;
            $seen[$dedupeKey] = true;
        }

        return $definitions;
    }

    /**
     * @param array<int, object> $providers
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function finalizeBrowseResult(array $result, array $providers, array $filters): array
    {
        $providersByCode = [];
        foreach ($providers as $provider) {
            $providersByCode[$provider->getCode()] = $provider;
        }

        $urlService = ObjectManager::getInstance(FilterUrlService::class);
        $facets = [];
        foreach ((array) ($result['facets'] ?? []) as $code => $buckets) {
            $provider = $providersByCode[$code] ?? null;
            if (!$provider instanceof SearchFacetCapableFilterInterface) {
                continue;
            }

            $options = $provider->normalizeSearchFacetBuckets(is_array($buckets) ? $buckets : [], $filters);
            foreach ($options as &$option) {
                $option['toggle_url'] = $urlService->getToggleFilterUrl($code, (string) ($option['value'] ?? ''));
            }
            unset($option);

            if ($options === []) {
                continue;
            }

            $facets[] = [
                'code' => $code,
                'name' => $provider->getName(),
                'display_type' => $provider->getDisplayType(),
                'collapsed' => $provider->isCollapsed(),
                'icon' => $provider->getIcon(),
                'options' => $options,
            ];
        }

        return [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => (int) ($result['total'] ?? 0),
            'pagination' => is_array($result['pagination'] ?? null) ? $result['pagination'] : [],
            'pagination_html' => (string) ($result['pagination']['html'] ?? ''),
            'engine' => (string) ($result['engine'] ?? 'mysql'),
            'facets' => $facets,
            'applied_filters' => $this->buildAppliedFilters($providersByCode, $filters, $urlService),
            'clear_all_url' => $urlService->getClearAllUrl(),
        ];
    }

    /**
     * @param array<string, object> $providersByCode
     * @return array<int, array<string, string>>
     */
    private function buildAppliedFilters(array $providersByCode, array $filters, FilterUrlService $urlService): array
    {
        $applied = [];
        foreach ($filters as $code => $values) {
            if (in_array($code, ['order_by', 'order_dir'], true)) {
                continue;
            }

            $provider = $providersByCode[$code] ?? null;
            if (!$provider) {
                continue;
            }

            foreach ($this->normalizeFilterValues($values) as $value) {
                $applied[] = [
                    'filter_code' => $code,
                    'filter_name' => $provider->getName(),
                    'value' => (string) $value,
                    'label' => $provider->getValueLabel((string) $value),
                    'remove_url' => $urlService->getRemoveFilterUrl($code, (string) $value),
                ];
            }
        }

        return $applied;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<int, object> $providers
     * @return array<string, mixed>
     */
    private function fallbackBrowseProducts(array $request, array $providers): array
    {
        $categoryIds = $this->normalizeCategoryIds($request['category_ids'] ?? []);
        if ($categoryIds !== []) {
            $productIds = $this->traceStep(
                'search::fallback_category_product_ids',
                function () use ($categoryIds): array {
                    $productIds = [];
                    foreach ($categoryIds as $categoryId) {
                        $ids = w_query('product', 'getProductIdsByCategoryId', ['category_id' => $categoryId]);
                        if (is_array($ids)) {
                            $productIds = array_merge($productIds, array_map('intval', $ids));
                        }
                    }

                    return array_values(array_unique(array_filter($productIds)));
                },
                ['category_id_count' => count($categoryIds)]
            );

            $filterPayload = $this->traceStep(
                'search::fallback_filter_result',
                fn () => $this->getFallbackFilterPayload(
                    (int) ($categoryIds[0] ?? 0),
                    $productIds,
                    is_array($request['filters'] ?? null) ? $request['filters'] : [],
                    $providers
                ),
                [
                    'category_id' => (int) ($categoryIds[0] ?? 0),
                    'product_count' => count($productIds),
                ]
            );
            $filteredIds = array_values(array_map('intval', $filterPayload['product_ids'] ?? []));
            $page = (int) ($request['page'] ?? 1);
            $pageSize = (int) ($request['page_size'] ?? 20);
            $slice = array_slice($filteredIds, ($page - 1) * $pageSize, $pageSize);
            $items = $this->traceStep(
                'search::fallback_product_items',
                fn () => w_query('product', 'getProductByIds', ['product_ids' => $slice]),
                [
                    'filtered_count' => count($filteredIds),
                    'slice_count' => count($slice),
                ]
            );

            return [
                'items' => is_array($items) ? $items : [],
                'total' => count($filteredIds),
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'pages' => $pageSize > 0 ? (int) ceil(count($filteredIds) / $pageSize) : 0,
                    'total' => count($filteredIds),
                    'from' => count($filteredIds) > 0 ? (($page - 1) * $pageSize) + 1 : 0,
                    'to' => count($filteredIds) > 0 ? min($page * $pageSize, count($filteredIds)) : 0,
                ],
                'engine' => 'mysql',
                'facets' => is_array($filterPayload['filters'] ?? null) ? $filterPayload['filters'] : [],
                'applied_filters' => is_array($filterPayload['applied_filters'] ?? null) ? $filterPayload['applied_filters'] : [],
                'clear_all_url' => ObjectManager::getInstance(FilterUrlService::class)->getClearAllUrl((int) ($categoryIds[0] ?? 0)),
                'pagination_html' => '',
            ];
        }

        return $this->finalizeBrowseResult(
            $this->traceStep(
                'search::fallback_engine_browse',
                fn () => $this->fallbackEngineBrowseResult($request),
                ['include_facets' => (bool) ($request['include_facets'] ?? false)]
            ),
            $providers,
            is_array($request['filters'] ?? null) ? $request['filters'] : []
        );
    }

    /**
     * @param array<int, int> $productIds
     * @param array<string, mixed> $filters
     * @param array<int, object> $providers
     * @return array{product_ids: array<int, int>, filters: array<int, mixed>, applied_filters: array<int, mixed>}
     */
    private function getFallbackFilterPayload(int $categoryId, array $productIds, array $filters, array $providers): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $cacheKey = $this->buildFallbackFilterPayloadCacheKey($categoryId, $productIds, $filters);
        $cached = $this->getFallbackFilterPayloadCache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($filters === [] && $providers !== []) {
            $payload = $this->buildProviderFallbackFilterPayload($categoryId, $productIds, $providers);
        } else {
            $filterResult = ObjectManager::getInstance(FilterService::class)
                ->getFilterResult($categoryId, $productIds, $filters, false);
            $payload = [
                'product_ids' => array_values(array_map('intval', $filterResult->getProductIds())),
                'filters' => $filterResult->getFilters(),
                'applied_filters' => $filterResult->getAppliedFilters(),
            ];
        }

        $this->rememberFallbackFilterPayloadCache($cacheKey, $payload);

        return $payload;
    }

    /**
     * @param array<int, int> $productIds
     * @param array<int, object> $providers
     * @return array{product_ids: array<int, int>, filters: array<int, mixed>, applied_filters: array<int, mixed>}
     */
    private function buildProviderFallbackFilterPayload(int $categoryId, array $productIds, array $providers): array
    {
        $filtersData = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof SearchFacetCapableFilterInterface || !$provider instanceof FilterProviderInterface) {
                continue;
            }

            $options = $provider->getOptions($categoryId, $productIds, []);
            if ($options === []) {
                continue;
            }

            $filtersData[] = [
                'code' => $provider->getCode(),
                'name' => $provider->getName(),
                'display_type' => $provider->getDisplayType(),
                'collapsed' => $provider->isCollapsed(),
                'icon' => $provider->getIcon(),
                'options' => $options,
            ];
        }

        return [
            'product_ids' => $productIds,
            'filters' => $filtersData,
            'applied_filters' => [],
        ];
    }

    /**
     * @param array<int, int> $productIds
     * @param array<string, mixed> $filters
     */
    private function buildFallbackFilterPayloadCacheKey(int $categoryId, array $productIds, array $filters): string
    {
        return sha1((string)json_encode([
            'v' => 2,
            'environment' => KeyBuilder::environmentContext([
                'scope' => 'search-fallback-filter',
            ]),
            'category_id' => $categoryId,
            'product_ids' => $productIds,
            'filters' => $this->normalizeCacheValue($filters),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function getFallbackFilterPayloadCache(string $key): ?array
    {
        $now = microtime(true);
        if (isset(self::$fallbackFilterPayloadCache[$key])) {
            $cached = self::$fallbackFilterPayloadCache[$key];
            if (($cached['expires_at'] ?? 0.0) >= $now) {
                $this->setPerfHeader('X-WLS-Search-Fallback-Filter-Cache', 'local');
                return is_array($cached['data']) ? $cached['data'] : null;
            }
            unset(self::$fallbackFilterPayloadCache[$key]);
        }

        $runtimeCached = $this->runtimeCacheGet('search.fallback_filter.' . $key);
        if (is_array($runtimeCached)) {
            $this->setPerfHeader('X-WLS-Search-Fallback-Filter-Cache', 'shared');
            $this->rememberFallbackFilterPayloadLocal($key, $runtimeCached);
            return $runtimeCached;
        }

        $this->setPerfHeader('X-WLS-Search-Fallback-Filter-Cache', 'miss');
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rememberFallbackFilterPayloadCache(string $key, array $payload): void
    {
        $this->rememberFallbackFilterPayloadLocal($key, $payload);
        $this->runtimeCacheSet('search.fallback_filter.' . $key, $payload, $this->browseResultCacheTtl());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rememberFallbackFilterPayloadLocal(string $key, array $payload): void
    {
        if (count(self::$fallbackFilterPayloadCache) > 96) {
            self::$fallbackFilterPayloadCache = array_slice(self::$fallbackFilterPayloadCache, -48, null, true);
        }
        self::$fallbackFilterPayloadCache[$key] = [
            'expires_at' => microtime(true) + $this->browseResultCacheTtl(),
            'data' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function traceStep(string $name, callable $callback, array $meta = []): mixed
    {
        $start = microtime(true);
        $traceEnabled = RequestLifecycleTrace::isEnabled();
        if ($traceEnabled) {
            RequestLifecycleTrace::pushCurrentParent($name);
        }

        try {
            return $callback();
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $this->setPerfHeader('X-WLS-Search-Step-' . $this->normalizePerfHeaderName($name), sprintf('%.2f', $durationMs));
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan($name, $durationMs, 'search', null, $meta);
            }
        }
    }

    private function getBrowseResultCache(string $key): ?array
    {
        $now = microtime(true);
        if (isset(self::$browseResultCache[$key])) {
            $cached = self::$browseResultCache[$key];
            if (($cached['expires_at'] ?? 0.0) >= $now) {
                $this->setPerfHeader('X-WLS-Search-Browse-Cache', 'local');
                return is_array($cached['data']) ? $cached['data'] : null;
            }
            unset(self::$browseResultCache[$key]);
        }

        $runtimeCached = $this->runtimeCacheGet('search.browse.' . $key);
        if (is_array($runtimeCached)) {
            $this->setPerfHeader('X-WLS-Search-Browse-Cache', 'shared');
            if (count(self::$browseResultCache) > 96) {
                self::$browseResultCache = array_slice(self::$browseResultCache, -48, null, true);
            }
            $ttl = $this->browseResultCacheTtl();
            self::$browseResultCache[$key] = [
                'expires_at' => $now + $ttl,
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
        }

        $this->setPerfHeader('X-WLS-Search-Browse-Cache', 'miss');
        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function rememberBrowseResultCache(string $key, array $result): void
    {
        if (count(self::$browseResultCache) > 96) {
            self::$browseResultCache = array_slice(self::$browseResultCache, -48, null, true);
        }

        $ttl = $this->browseResultCacheTtl();
        self::$browseResultCache[$key] = [
            'expires_at' => microtime(true) + $ttl,
            'data' => $result,
        ];
        $this->runtimeCacheSet('search.browse.' . $key, $result, $ttl);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, int> $categoryIds
     */
    private function buildBrowseResultCacheKey(
        string $keyword,
        array $filters,
        int $page,
        int $pageSize,
        string $scope,
        array $categoryIds,
        bool $includeFacets
    ): string {
        $normalizedFilters = $this->normalizeCacheValue($filters);
        $uri = function_exists('w_env_request_uri') ? (string) w_env_request_uri() : '';
        $host = function_exists('w_env_http_host') ? (string) w_env_http_host() : '';

        return sha1((string) json_encode([
            'v' => 2,
            'environment' => KeyBuilder::environmentContext([
                'scope' => 'search-browse-result',
            ]),
            'keyword' => $keyword,
            'filters' => $normalizedFilters,
            'page' => $page,
            'page_size' => $pageSize,
            'scope' => $scope,
            'category_ids' => array_values($categoryIds),
            'include_facets' => $includeFacets,
            'host' => $host,
            'uri' => $uri,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function normalizeCacheValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeCacheValue($item);
        }
        ksort($normalized);

        return $normalized;
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('weshop_search_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set('weshop_search_runtime', $key, $value, max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private function setPerfHeader(string $name, string $value): void
    {
        try {
            ObjectManager::getInstance(Response::class)->setHeader($name, $value);
        } catch (\Throwable) {
        }
    }

    private function normalizePerfHeaderName(string $name): string
    {
        return substr((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), 0, 80);
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent() || !class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            self::$runtimeCache = new MemoryStateFacade(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'weshop_search_runtime',
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function browseResultCacheTtl(): int
    {
        return self::cachePolicy()->ttl('search.browse_result_ttl', self::BROWSE_RESULT_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function fallbackEngineBrowseResult(array $request): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => (string) ($request['keyword'] ?? ''),
            'filters' => is_array($request['filters'] ?? null) ? $request['filters'] : [],
            'page' => (int) ($request['page'] ?? 1),
            'page_size' => (int) ($request['page_size'] ?? 20),
        ]);

        if (is_array($result)) {
            return array_merge([
                'items' => [],
                'total' => 0,
                'pagination' => [],
                'engine' => 'mysql',
                'facets' => [],
            ], $result, ['engine' => 'mysql']);
        }

        return [
            'items' => [],
            'total' => 0,
            'pagination' => [],
            'engine' => 'mysql',
            'facets' => [],
        ];
    }

    private function shouldUseLocalBrowseFallback(array $result, string $keyword): bool
    {
        if (trim($keyword) === '') {
            return false;
        }

        $engine = strtolower((string) ($result['engine'] ?? ''));
        if ($engine === '' || $engine === 'mysql') {
            return false;
        }

        return (int) ($result['total'] ?? 0) === 0 && empty($result['items']);
    }

    private function normalizeSuggestions(array $suggestions): array
    {
        $normalized = [];
        foreach ($suggestions as $suggestion) {
            $item = $this->normalizeSuggestion($suggestion);
            if (($item['text'] ?? '') !== '') {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function normalizeSuggestion(mixed $suggestion): array
    {
        if (is_string($suggestion)) {
            return ['text' => $suggestion, 'type' => 'search', 'icon' => 'fa-search', 'url' => $this->buildSearchUrl($suggestion)];
        }
        if (!is_array($suggestion)) {
            return [];
        }

        $text = trim((string) ($suggestion['text'] ?? $suggestion['query'] ?? $suggestion['name'] ?? $suggestion['sku'] ?? ''));

        return $text === '' ? [] : [
            'text' => $text,
            'type' => (string) ($suggestion['type'] ?? 'search'),
            'icon' => (string) ($suggestion['icon'] ?? 'fa-search'),
            'url' => (string) ($suggestion['url'] ?? $this->buildSearchUrl($text)),
        ];
    }

    private function hasSuggestionText(array $suggestions, string $text): bool
    {
        foreach ($suggestions as $suggestion) {
            if ((string) ($suggestion['text'] ?? '') === $text) {
                return true;
            }
        }

        return false;
    }

    private function buildSearchUrl(string $keyword): string
    {
        return self::SEARCH_ROUTE . '?q=' . urlencode($keyword);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeCategoryIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = str_contains((string) $value, ',') ? explode(',', (string) $value) : [$value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFilterValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = str_contains((string) $value, ',') ? explode(',', (string) $value) : [$value];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
    }
}
