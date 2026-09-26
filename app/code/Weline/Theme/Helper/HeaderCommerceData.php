<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Search\Service\SearchProviderRegistry;
use Weline\Theme\Service\AllMenu\AllMenuTreeRegistry;
use Weline\Theme\Service\Storefront\StorefrontRenderContextBag;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * 页头商务数据：优先 Query 真实数据；仅在无数据/不可用时回落主题演示默认值。
 * Demo 热搜按当前 website code 分流，禁止 DaoCharms 等非汉服站回落马面裙/明制汉服。
 */
final class HeaderCommerceData
{
    /**
     * @return list<string>
     */
    public static function defaultHotWords(?string $websiteCode = null): array
    {
        $code = \strtolower(\trim($websiteCode ?? self::resolveWebsiteCode()));
        if ($code === 'daocharms') {
            return self::daocharmsDefaultHotWords();
        }

        return self::hanfuDefaultHotWords();
    }

    /**
     * Default-website / Hanfu storefront demo keywords (Chinese sources).
     *
     * @return list<string>
     */
    public static function hanfuDefaultHotWords(): array
    {
        return ['马面裙', '明制汉服', '宋制汉服', '齐胸襦裙', '披帛'];
    }

    /**
     * DaoCharms ritual-pendant storefront demo keywords (Chinese sources).
     *
     * @return list<string>
     */
    public static function daocharmsDefaultHotWords(): array
    {
        return ['黑曜石', '阴阳', '八卦', '平安扣', '玉石'];
    }

    /**
     * 横向分类条 / 分类部件：优先万能分类 product space 店面树。
     * 是否前置「全部商品」由 all-menu 默认部件经 AllMenuTreeRegistry 发布（默认开启，可关）。
     *
     * @param array{include_all_products?:bool,all_products_label?:string,all_products_url?:string} $options
     * @return array{
     *   items:list<array<string,mixed>>,
     *   source:string,
     *   is_demo:bool
     * }
     */
    public static function resolveCategoryNavItems(array $options = []): array
    {
        $include = array_key_exists('include_all_products', $options)
            ? (bool)$options['include_all_products']
            : AllMenuTreeRegistry::allProductsEnabled();
        $label = trim((string)($options['all_products_label'] ?? AllMenuTreeRegistry::allProductsLabel()));
        $url = trim((string)($options['all_products_url'] ?? AllMenuTreeRegistry::allProductsUrl()));
        $label = $label !== '' ? $label : '全部商品';
        $url = $url !== '' && $url !== '#' ? $url : '/products';

        // Absolute nav URLs are origin-sensitive: shared HotCache key MUST embed
        // request origin (v2) so loopback warmup Host cannot leak into Nginx.
        // Underlying tree still shares origin-free relative routes via Product.
        $origin = self::requestOriginSegment();
        $requestKey = ($include ? 'all1' : 'all0')
            . '|' . $label
            . '|' . $url
            . '|' . $origin;
        $sharedKey = \sprintf(
            'theme.header.category_nav.v2.%s.%s.%s.%s',
            self::storefrontLocaleSegment(),
            \preg_replace('/[^a-z0-9.:_-]+/i', '-', $origin) ?: 'unknown',
            $include ? 'all1' : 'all0',
            \substr(\sha1($label . '|' . $url), 0, 12),
        );

        // N2: prime category_nav.v2 into one shared_read_batch *before* remember
        // so the projection itself is not a lone residual shared_read.
        try {
            /** @var \Weline\Theme\Service\StorefrontHeaderNavFragmentCache $fragCache */
            $fragCache = ObjectManager::getInstance(
                \Weline\Theme\Service\StorefrontHeaderNavFragmentCache::class
            );
            $fragCache->prefetchCategoryNavFragments([], true, [$sharedKey], false);
        } catch (\Throwable) {
            // Prefetch is an optimization boundary.
        }

        $resolved = self::rememberRequestMemo(
            'theme.header.category_nav',
            $requestKey,
            static fn(): array => self::resolveCategoryNavItemsUncached($include, $label, $url),
            StorefrontThemeCacheCoordinator::headerNavigationPolicy(),
            $sharedKey,
        );

        if (!\is_array($resolved)) {
            return [
                'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
                'source' => 'error',
                'is_demo' => false,
            ];
        }

        // N2: after items known, MGET horizontal/sidebar/mega (+ sharedKey again).
        $items = \is_array($resolved['items'] ?? null) ? $resolved['items'] : [];
        if ($items !== []) {
            try {
                /** @var \Weline\Theme\Service\StorefrontHeaderNavFragmentCache $fragCache */
                $fragCache = ObjectManager::getInstance(
                    \Weline\Theme\Service\StorefrontHeaderNavFragmentCache::class
                );
                $fragCache->prefetchCategoryNavFragments(
                    $items,
                    true,
                    [$sharedKey],
                    true,
                );
            } catch (\Throwable) {
                // Prefetch is an optimization boundary.
            }
        }

        return $resolved;
    }

    private static function storefrontLocaleSegment(): string
    {
        try {
            $locale = \trim(\str_replace('-', '_', (string)\Weline\Framework\App\State::getLangLocal()));
        } catch (\Throwable) {
            $locale = '';
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * Browser-visible origin for request-local memos that embed absolute hrefs.
     * Prefer website_url host; fall back to HTTP_HOST + scheme.
     */
    private static function requestOriginSegment(): string
    {
        $websiteUrl = '';
        try {
            $websiteUrl = \trim((string)\Weline\Framework\Env\WelineEnv::get('website_url', ''));
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl === '') {
            try {
                $websiteUrl = \trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_URL', ''));
            } catch (\Throwable) {
                $websiteUrl = '';
            }
        }
        if ($websiteUrl !== '' && \str_contains($websiteUrl, '://')) {
            $parts = \parse_url($websiteUrl);
            if (\is_array($parts)) {
                $scheme = \strtolower(\trim((string)($parts['scheme'] ?? '')));
                $host = \strtolower(\trim((string)($parts['host'] ?? '')));
                $port = isset($parts['port']) ? (int)$parts['port'] : 0;
                if ($scheme !== '' && $host !== '') {
                    $default = ($scheme === 'https') ? 443 : 80;
                    $authority = $host . ($port > 0 && $port !== $default ? ':' . $port : '');

                    return $scheme . ':' . $authority;
                }
            }
        }

        $scheme = 'http';
        try {
            $scheme = \strtolower(\trim((string)\Weline\Framework\Env\WelineEnv::get('request.scheme', 'http'))) ?: 'http';
        } catch (\Throwable) {
            $scheme = 'http';
        }
        $host = '';
        try {
            $host = \strtolower(\trim((string)\Weline\Framework\Env\WelineEnv::get('server.http_host', '')));
        } catch (\Throwable) {
            $host = '';
        }
        if ($host === '') {
            $host = \strtolower(\trim((string)(
                \Weline\Framework\Env\WelineEnv::server('HTTP_HOST', '')
                ?: ($_SERVER['HTTP_HOST'] ?? '')
            )));
        }

        return ($scheme !== '' ? $scheme : 'http') . ':' . ($host !== '' ? $host : 'unknown');
    }

    /**
     * @return array{
     *   items:list<array<string,mixed>>,
     *   source:string,
     *   is_demo:bool
     * }
     */
    private static function resolveCategoryNavItemsUncached(
        bool $include,
        string $label,
        string $url,
    ): array {
        try {
            if (!\class_exists(\Weline\Product\Service\StorefrontAllMenuCategoryTreeService::class)) {
                return [
                    'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
                    'source' => $include ? 'products_catalog' : 'unavailable',
                    'is_demo' => false,
                ];
            }
            /** @var \Weline\Product\Service\StorefrontAllMenuCategoryTreeService $service */
            $service = ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontAllMenuCategoryTreeService::class
            );
            $tree = $service->navTree(self::resolveWebsiteId());
            if ($tree === []) {
                return [
                    'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
                    'source' => $include ? 'products_catalog' : 'catalog_empty',
                    'is_demo' => false,
                ];
            }
            $normalizer = new \Weline\Theme\Service\AllMenu\MenuTreeNormalizer();
            $items = $normalizer->toNavItems($tree);
            if ($items === []) {
                return [
                    'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
                    'source' => $include ? 'products_catalog' : 'catalog_empty',
                    'is_demo' => false,
                ];
            }

            return [
                'items' => self::maybePrependAllProductsItem($items, $include, $label, $url),
                'source' => 'catalog',
                'is_demo' => false,
            ];
        } catch (\Throwable) {
            return [
                'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
                'source' => $include ? 'products_catalog' : 'error',
                'is_demo' => false,
            ];
        }
    }

    /**
     * Storefront category names already resolved from category locale rows.
     * Callers must not run these labels through WidgetI18n / __().
     *
     * @return array<string, string> path code (women, sets, …) => display name
     */
    public static function categoryDisplayNamesByCode(): array
    {
        $names = [];
        $walk = static function (array $items) use (&$walk, &$names): void {
            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $url = (string)($item['url'] ?? '');
                $path = \parse_url($url, \PHP_URL_PATH);
                $path = \is_string($path) && $path !== '' ? $path : $url;
                // Use ~ delimiter: # inside [^/?#] would end a #-delimited pattern early
                // and yield "Unknown modifier ']'".
                if (\preg_match('~(?:^|/)category/([^/?#]+)/?$~', $path, $matches) === 1) {
                    $code = \rawurldecode((string)$matches[1]);
                    $text = \trim((string)($item['text'] ?? $item['name'] ?? ''));
                    if ($code !== '' && $text !== '') {
                        $names[$code] = $text;
                    }
                }
                $children = $item['children'] ?? [];
                if (\is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };
        $resolved = self::resolveCategoryNavItems(['include_all_products' => false]);
        $items = $resolved['items'] ?? [];
        if (\is_array($items)) {
            $walk($items);
        }

        return $names;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function prependProductsCatalogItem(
        array $items,
        string $label = '全部商品',
        string $url = '/products',
        string $description = '浏览全部已发布商品',
    ): array {
        return self::maybePrependAllProductsItem($items, true, $label, $url, $description);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function maybePrependAllProductsItem(
        array $items,
        bool $include,
        string $label = '全部商品',
        string $url = '/products',
        string $description = '浏览全部已发布商品',
    ): array {
        if (!$include) {
            return $items;
        }

        $label = trim($label) !== '' ? trim($label) : '全部商品';
        $url = trim($url);
        if ($url === '' || $url === '#') {
            $url = '/products';
        }
        $description = trim($description) !== '' ? trim($description) : '浏览全部已发布商品';

        $firstText = trim((string)($items[0]['text'] ?? $items[0]['name'] ?? ''));
        $firstUrl = trim((string)($items[0]['url'] ?? ''));
        if ($firstText === $label || preg_match('#(^|/)products/?$#', $firstUrl) === 1) {
            return $items;
        }

        array_unshift($items, [
            'text' => $label,
            'url' => $url,
            'description' => $description,
            'children' => [],
        ]);

        return $items;
    }

    private static function resolveWebsiteId(): int
    {
        // WS1: bag website_id is authoritative when Installer has frozen the request.
        $fromBag = StorefrontRenderContextBag::websiteId();
        if ($fromBag !== null) {
            return $fromBag;
        }
        try {
            if (\class_exists(\Weline\Websites\Service\WebsiteAclGrantService::class)) {
                /** @var \Weline\Websites\Service\WebsiteAclGrantService $grants */
                $grants = ObjectManager::getInstance(
                    \Weline\Websites\Service\WebsiteAclGrantService::class
                );
                $id = (int)$grants->currentWebsiteId();
                if ($id >= 0) {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private static function resolveWebsiteCode(): string
    {
        $fromBag = StorefrontRenderContextBag::websiteCode();
        if ($fromBag !== null && $fromBag !== '') {
            return $fromBag;
        }
        try {
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
                $code = \strtolower(\trim(
                    (string)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode()
                ));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable) {
        }

        return 'default';
    }

    /**
     * @return array{
     *   words:list<string>,
     *   source:string,
     *   is_demo:bool
     * }
     */
    public static function resolveHotWords(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        return self::rememberRequestMemo(
            'theme.header.hot_words',
            (string)$limit,
            static function () use ($limit): array {
                try {
                    if (\function_exists('w_query')) {
                        $result = \w_query('search', 'hotWords', ['limit' => $limit], 'frontend');
                        if (\is_array($result) && ($result['success'] ?? false)) {
                            $words = $result['words'] ?? ($result['data']['words'] ?? []);
                            $normalized = self::normalizeHotWords($words, $limit);
                            if ($normalized !== []) {
                                return [
                                    'words' => $normalized,
                                    'source' => (string)($result['source'] ?? $result['data']['source'] ?? 'search'),
                                    'is_demo' => false,
                                ];
                            }
                        }
                    }
                } catch (\Throwable) {
                    // fall through to demo defaults
                }

                return [
                    'words' => \array_slice(self::defaultHotWords(), 0, $limit),
                    'source' => 'theme_demo',
                    'is_demo' => true,
                ];
            },
        );
    }

    /**
     * @return array{
     *   available:bool,
     *   is_demo:bool,
     *   is_empty:bool,
     *   cart_count:int,
     *   subtotal:float,
     *   subtotal_formatted:string,
     *   currency:string,
     *   items:list<array<string,mixed>>
     * }
     */
    public static function resolveCartSummary(bool $allowDemoFallback = true, int $itemLimit = 5): array
    {
        $itemLimit = max(1, min(20, $itemLimit));

        return self::rememberRequestMemo(
            'theme.header.cart_summary',
            ($allowDemoFallback ? 'demo' : 'live') . '|' . $itemLimit,
            static fn(): array => self::resolveCartSummaryUncached($allowDemoFallback, $itemLimit),
        );
    }

    private static function resolveCartSummaryUncached(bool $allowDemoFallback, int $itemLimit): array
    {
        $itemLimit = max(1, min(20, $itemLimit));
        $queried = false;
        try {
            if (\function_exists('w_query')) {
                $result = \w_query('cart', 'summary', [], 'frontend');
                if (\is_array($result) && ($result['success'] ?? false)) {
                    $queried = true;
                    $data = \is_array($result['data'] ?? null) ? $result['data'] : $result;
                    $items = \is_array($data['items'] ?? null) ? $data['items'] : [];
                    $items = \array_values(\array_filter($items, static fn ($item): bool => \is_array($item)));
                    $items = \array_slice($items, 0, $itemLimit);
                    $count = (int)($data['cart_count'] ?? $data['item_count'] ?? 0);
                    $subtotal = (float)($data['subtotal'] ?? $data['grand_total'] ?? 0);
                    $currency = (string)($data['currency'] ?? 'CNY');
                    $isEmpty = $count <= 0 || ($data['is_empty'] ?? false) || $items === [];

                    // 主题预览且真实购物车为空：回落演示数据，便于观察完整 chrome
                    if ($isEmpty && $allowDemoFallback) {
                        return self::demoCartSummary();
                    }

                    // WO-BUILD-HOME-ZERO：空车禁预格式化 $0.00 / ¥0.00（真小计由 JS 水合）
                    $formatted = $isEmpty
                        ? ''
                        : self::formatMoney($subtotal, $currency);

                    return [
                        'available' => true,
                        'is_demo' => false,
                        'is_empty' => $isEmpty,
                        'cart_count' => $count,
                        'subtotal' => $isEmpty ? 0.0 : $subtotal,
                        'subtotal_formatted' => $formatted,
                        'currency' => $currency,
                        'items' => $items,
                    ];
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        if ($allowDemoFallback) {
            return self::demoCartSummary();
        }

        return [
            'available' => $queried,
            'is_demo' => false,
            'is_empty' => true,
            'cart_count' => 0,
            'subtotal' => 0.0,
            // WO-BUILD-HOME-ZERO：空车共享摘要不得带 $0.00 价签噪声
            'subtotal_formatted' => '',
            'currency' => 'CNY',
            'items' => [],
        ];
    }

    /**
     * @return array{
     *   available:bool,
     *   is_demo:bool,
     *   is_empty:bool,
     *   cart_count:int,
     *   subtotal:float,
     *   subtotal_formatted:string,
     *   currency:string,
     *   items:list<array<string,mixed>>
     * }
     */
    public static function demoCartSummary(): array
    {
        $placeholder = '';
        try {
            // Preview-only sample line; live empty carts must not use this path.
            $products = ThemeDemoCatalog::products(1, 3);
            $product = $products[0] ?? null;
            if (\is_array($product)) {
                $placeholder = (string)($product['image'] ?? '');
                return [
                    'available' => true,
                    'is_demo' => true,
                    'is_empty' => false,
                    'cart_count' => 3,
                    'subtotal' => 299.0,
                    'subtotal_formatted' => ThemeDemoCatalog::formatPrice(299.0),
                    'currency' => 'CNY',
                    'items' => [[
                        'name' => (string)($product['name'] ?? __('示例商品名称')),
                        'image' => $placeholder,
                        'price' => 99.0,
                        'qty' => 1,
                        'quantity' => 1,
                        'row_total' => 99.0,
                        'url' => (string)($product['url'] ?? '/cart'),
                    ]],
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        return [
            'available' => true,
            'is_demo' => true,
            'is_empty' => false,
            'cart_count' => 3,
            'subtotal' => 299.0,
            'subtotal_formatted' => ThemeDemoCatalog::formatPrice(299.0),
            'currency' => 'CNY',
            'items' => [[
                'name' => (string)__('示例商品名称'),
                'image' => '',
                'price' => 99.0,
                'qty' => 1,
                'quantity' => 1,
                'row_total' => 99.0,
                'url' => '/cart',
            ]],
        ];
    }

    public static function formatMoney(float $amount, string $currency = 'CNY'): string
    {
        if (class_exists(\Weline\Currency\Helper\CurrencySymbol::class)) {
            return \Weline\Currency\Helper\CurrencySymbol::formatAmount($amount, $currency);
        }

        $currency = strtoupper(trim($currency));
        $symbol = match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            default => '¥',
        };

        return $symbol . number_format($amount, 2);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function normalizeHotWords(mixed $raw, int $limit): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $word = \is_string($item)
                ? trim($item)
                : trim((string)((\is_array($item) ? ($item['word'] ?? $item['title'] ?? $item['q'] ?? '') : '')));
            if ($word === '') {
                continue;
            }
            $key = \mb_strtolower($word);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $word;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{code:string,label:string,children:list<array{code:string,label:string,params:array<string,int|string|float|bool>}>}>
     */
    public static function resolveSearchTypes(): array
    {
        $websiteCode = trim((string)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode());
        $websiteId = max(0, (int)(\Weline\Framework\Runtime\RequestContext::websiteId() ?? 0));
        @file_put_contents(
            BP . 'dev/tmp/blog-search-scope.log',
            date('c') . " resolveSearchTypes code={$websiteCode} id={$websiteId}\n",
            FILE_APPEND
        );
        return self::rememberRequestMemo(
            'theme.header.search_types',
            'default',
            static function () use ($websiteCode, $websiteId): array {
                @file_put_contents(
                    BP . 'dev/tmp/blog-search-scope.log',
                    date('c') . " builder RUN code={$websiteCode} id={$websiteId}\n",
                    FILE_APPEND
                );
                try {
                    /** @var SearchProviderRegistry $registry */
                    $registry = ObjectManager::getInstance(SearchProviderRegistry::class);
                    // The header is a storefront surface. Restrict provider
                    // discovery to frontend types so backend-only providers do
                    // not build their scopes during every cold page render.
                    // Keep withScopes=true: type-dropdown flies out category
                    // children (禁拆壳). Scope projection must reuse catalog
                    // tree read model (ProductSearchCategoryScopeService).
                    $types = $registry->listTypes(true, 'frontend');
                    if ($types !== []) {
                        return $types;
                    }
                } catch (\Throwable) {
                    // fall through
                }

                return [
                    [
                        'code' => 'all',
                        'label' => (string)__('全部'),
                        'children' => [],
                    ],
                ];
            },
            StorefrontThemeCacheCoordinator::headerSearchTypesPolicy(),
            // v4: website code in logical key — prevent cross-brand blog taxonomy bleed via shared header cache.
            'theme.header.search_types.v4.' . ($websiteCode !== '' ? $websiteCode : 'w' . $websiteId),
        );
    }

    /** 在搜索菜单渲染前一次预取整棵类型树的动态文案。 */
    public static function prefetchSearchTypeLabels(array $types): void
    {
        $sources = ['全部', '全部%{1}'];
        $collect = static function (array $nodes, bool $topLevel) use (&$collect, &$sources): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $label = trim((string)($node['label'] ?? ''));
                $sources[] = $label !== '' ? $label : ($topLevel ? (string)($node['code'] ?? '') : '');
                if (is_array($node['children'] ?? null)) {
                    $collect($node['children'], false);
                }
            }
        };
        $collect($types, true);
        WidgetI18n::prefetchLabels($sources);
    }

    private static function rememberRequestMemo(
        string $resource,
        string $logicalKey,
        callable $builder,
        mixed $policy = null,
        ?string $sharedLogicalKey = null,
    ): mixed
    {
        try {
            /** @var StorefrontScopeHotCache $cache */
            $cache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        } catch (\Throwable) {
            return $builder();
        }

        return $cache->rememberForRequest(
            $resource,
            $logicalKey,
            function () use ($cache, $policy, $sharedLogicalKey, $resource, $logicalKey, $builder): mixed {
                if ($policy !== null) {
                    try {
                        return $cache->rememberPolicy(
                            $policy,
                            $sharedLogicalKey ?? $logicalKey,
                            static fn(): mixed => RequestLifecycleTrace::measurePhase($resource, $builder),
                        );
                    } catch (\Throwable) {
                        // Shared metadata is an optimization boundary; preserve the
                        // request result if the optional cache service is unavailable.
                    }
                }

                return RequestLifecycleTrace::measurePhase($resource, $builder);
            },
        );
    }
}
