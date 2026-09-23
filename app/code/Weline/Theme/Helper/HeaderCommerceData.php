<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Search\Service\SearchProviderRegistry;
use Weline\Theme\Service\AllMenu\AllMenuTreeRegistry;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * 页头商务数据：优先 Query 真实数据；仅在无数据/不可用时回落主题演示默认值。
 */
final class HeaderCommerceData
{
    /**
     * @return list<string>
     */
    public static function defaultHotWords(): array
    {
        return ['马面裙', '明制汉服', '宋制汉服', '齐胸襦裙', '披帛'];
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

        $requestKey = ($include ? 'all1' : 'all0')
            . '|' . $label
            . '|' . $url;
        $sharedKey = 'theme.header.category_nav.v1.'
            . self::storefrontLocaleSegment()
            . '.'
            . ($include ? 'all1' : 'all0')
            . '.'
            . \substr(\sha1($label . '|' . $url), 0, 12);

        $resolved = self::rememberRequestMemo(
            'theme.header.category_nav',
            $requestKey,
            static fn(): array => self::resolveCategoryNavItemsUncached($include, $label, $url),
            StorefrontThemeCacheCoordinator::headerNavigationPolicy(),
            $sharedKey,
        );

        return \is_array($resolved) ? $resolved : [
            'items' => self::maybePrependAllProductsItem([], $include, $label, $url),
            'source' => 'error',
            'is_demo' => false,
        ];
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

    private static function storefrontLocaleSegment(): string
    {
        try {
            $locale = \trim(\str_replace('-', '_', (string)\Weline\Framework\App\State::getLangLocal()));
        } catch (\Throwable) {
            $locale = '';
        }
        $requestUri = (string)(\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        $pathLocale = WidgetI18n::localeFromRequestUri($requestUri);
        if ($pathLocale !== null) {
            $locale = $pathLocale;
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
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
        return self::rememberRequestMemo(
            'theme.header.search_types',
            'default',
            static function (): array {
                try {
                    /** @var SearchProviderRegistry $registry */
                    $registry = ObjectManager::getInstance(SearchProviderRegistry::class);
                    // The header is a storefront surface. Restrict provider
                    // discovery to frontend types so backend-only providers do
                    // not build their scopes during every cold page render.
                    $types = $registry->listTypes(area: 'frontend');
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
            'theme.header.search_types.v1',
        );
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
