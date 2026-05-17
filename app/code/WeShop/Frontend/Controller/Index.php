<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use WeShop\Catalog\Service\CategoryService;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class Index extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Frontend::templates/Index/index.phtml';
    private const VIEW_PAYLOAD_CACHE_TTL = 120;

    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $viewPayloadCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    protected ?string $layoutType = 'homepage';

    public function __construct(
        private readonly ProductService $productService,
        private readonly CategoryService $categoryService
    ) {
    }

    public function index(): string
    {
        $cacheKey = $this->buildViewPayloadCacheKey();
        $cachedView = $this->getViewPayloadCache($cacheKey);
        if (is_array($cachedView) && isset($cachedView['html'])) {
            $this->applyCachedViewPayload($cachedView);
            return (string)$cachedView['html'];
        }

        $categories = $this->categoryService->getCategoryTree(0);
        $formattedCategories = [];
        foreach ($categories as $category) {
            $formattedCategories[] = [
                'category_id' => $category['category_id'] ?? 0,
                'name' => $category['name'] ?? '',
                'url' => $this->getUrl('weshop/product/list', ['category_id' => $category['category_id'] ?? 0]),
                'image' => $category['image'] ?? '',
                'children' => $category['children'] ?? [],
            ];
        }

        $recommendedResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, 12);

        $recommendedProducts = [];
        foreach ($recommendedResult['items'] as $product) {
            $recommendedProducts[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'short_description' => $product['short_description'] ?? $product[Product::schema_fields_short_description] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
                'in_stock' => ($product['stock'] ?? $product[Product::schema_fields_stock] ?? 0) > 0,
            ];
        }

        $dealsResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => 'price',
            'order_dir' => 'ASC',
        ], 1, 8);

        $deals = [];
        foreach ($dealsResult['items'] as $product) {
            $deals[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
            ];
        }

        $bestsellersResult = $this->productService->getProducts([
            'status' => 1,
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, 8);

        $bestsellers = [];
        foreach ($bestsellersResult['items'] as $product) {
            $bestsellers[] = [
                'product_id' => $product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0,
                'name' => $product['name'] ?? $product[Product::schema_fields_name] ?? '',
                'price' => $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'original_price' => $product['original_price'] ?? $product['price'] ?? $product[Product::schema_fields_price] ?? 0,
                'special_price' => $product['special_price'] ?? null,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'image' => $product['image'] ?? $product[Product::schema_fields_image] ?? '',
                'sku' => $product['sku'] ?? $product[Product::schema_fields_sku] ?? '',
            ];
        }

        $banners = [
            [
                'title' => __('新品上市'),
                'subtitle' => __('发现最新潮流'),
                'image' => $this->getStaticUrl('assets/images/banner-1.jpg'),
                'link' => $this->getUrl('weshop/product/list'),
            ],
            [
                'title' => __('限时特惠'),
                'subtitle' => __('超值优惠等你来'),
                'image' => $this->getStaticUrl('assets/images/banner-2.jpg'),
                'link' => $this->getUrl('weshop/promotion'),
            ],
            [
                'title' => __('品质保证'),
                'subtitle' => __('正品保障，放心购物'),
                'image' => $this->getStaticUrl('assets/images/banner-3.jpg'),
                'link' => $this->getUrl('weshop'),
            ],
        ];

        $this->assign('categories', $formattedCategories);
        $this->assign('recommended_products', $recommendedProducts);
        $this->assign('deals', $deals);
        $this->assign('bestsellers', $bestsellers);
        $this->assign('banners', $banners);
        $this->assign('title', __('首页'));

        $assigns = [
            'categories' => $formattedCategories,
            'recommended_products' => $recommendedProducts,
            'deals' => $deals,
            'bestsellers' => $bestsellers,
            'banners' => $banners,
            'title' => __('首页'),
        ];
        $html = $this->fetch(self::CONTENT_TEMPLATE);
        $this->rememberViewPayloadCache($cacheKey, [
            'html' => $html,
            'assigns' => $assigns,
        ]);

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCachedViewPayload(array $payload): void
    {
        foreach ((array)($payload['assigns'] ?? []) as $key => $value) {
            $this->assign((string)$key, $value);
        }
    }

    private function getViewPayloadCache(string $key): ?array
    {
        $now = microtime(true);
        if (isset(self::$viewPayloadCache[$key])) {
            $cached = self::$viewPayloadCache[$key];
            if (($cached['expires_at'] ?? 0.0) >= $now) {
                return is_array($cached['data']) ? $cached['data'] : null;
            }
            unset(self::$viewPayloadCache[$key]);
        }

        $runtimeCached = $this->runtimeCacheGet('home.view.' . $key);
        if (is_array($runtimeCached)) {
            if (count(self::$viewPayloadCache) > 96) {
                self::$viewPayloadCache = array_slice(self::$viewPayloadCache, -48, null, true);
            }
            $ttl = $this->viewPayloadCacheTtl();
            self::$viewPayloadCache[$key] = [
                'expires_at' => $now + $ttl,
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rememberViewPayloadCache(string $key, array $payload): void
    {
        if (count(self::$viewPayloadCache) > 96) {
            self::$viewPayloadCache = array_slice(self::$viewPayloadCache, -48, null, true);
        }

        $ttl = $this->viewPayloadCacheTtl();
        self::$viewPayloadCache[$key] = [
            'expires_at' => microtime(true) + $ttl,
            'data' => $payload,
        ];
        $this->runtimeCacheSet('home.view.' . $key, $payload, $ttl);
    }

    private function buildViewPayloadCacheKey(): string
    {
        $uri = function_exists('w_env_request_uri') ? (string)w_env_request_uri() : '';
        $host = function_exists('w_env_http_host') ? (string)w_env_http_host() : '';

        return sha1((string)json_encode([
            'v' => 1,
            'lang' => State::getLang(),
            'lang_local' => State::getLangLocal(),
            'currency' => State::getCurrency(),
            'host' => $host,
            'uri' => $uri,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('weshop_frontend_runtime', $key);
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
            $cache->set('weshop_frontend_runtime', $key, $value, max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
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
                'consumer_code' => 'weshop_frontend_runtime',
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function viewPayloadCacheTtl(): int
    {
        return self::cachePolicy()->ttl('page.home_view_ttl', self::VIEW_PAYLOAD_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }
}
