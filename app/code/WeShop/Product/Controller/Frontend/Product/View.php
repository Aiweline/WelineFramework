<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class View extends BaseController
{
    private const PRODUCT_LIST_ROUTE = 'weshop/product/list';
    private const CONTENT_TEMPLATE = 'WeShop_Product::templates/frontend/product/view.phtml';
    private const VIEW_PAYLOAD_CACHE_TTL = 120;

    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $viewPayloadCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    protected ?string $layoutType = 'product';

    public function __construct(
        private readonly StorefrontRecentlyViewedRecorder $storefrontRecentlyViewedRecorder,
        private readonly ProductViewPageDataService $productViewPageDataService
    ) {
    }

    public function index(): string
    {
        $productId = (int) ($this->request->getParam('id') ?? $this->request->getParam('product_id') ?? 0);

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Product ID is required.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->storefrontRecentlyViewedRecorder->recordProductView($productId);

        $cacheKey = $this->buildViewPayloadCacheKey($productId);
        $cachedView = $this->getViewPayloadCache($cacheKey);
        if (is_array($cachedView) && isset($cachedView['html'])) {
            $this->applyCachedViewPayload($cachedView);
            return (string)$cachedView['html'];
        }

        $pageData = $this->productViewPageDataService->build($productId);
        if (!$pageData) {
            $this->getMessageManager()->addError(__('Product is unavailable.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->assignPageData($pageData);
        $html = $this->fetch(self::CONTENT_TEMPLATE);
        $this->rememberViewPayloadCache($cacheKey, [
            'html' => $html,
            'assigns' => $pageData,
        ]);

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCachedViewPayload(array $payload): void
    {
        $assigns = is_array($payload['assigns'] ?? null) ? $payload['assigns'] : [];
        $this->assignPageData($assigns);
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function assignPageData(array $pageData): void
    {
        foreach ($pageData as $key => $value) {
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

        $runtimeCached = $this->runtimeCacheGet('product.view.' . $key);
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
        $this->runtimeCacheSet('product.view.' . $key, $payload, $ttl);
    }

    private function buildViewPayloadCacheKey(int $productId): string
    {
        $uri = function_exists('w_env_request_uri') ? (string)w_env_request_uri() : '';
        $host = function_exists('w_env_http_host') ? (string)w_env_http_host() : '';

        return sha1((string)json_encode([
            'v' => 4,
            'product_id' => $productId,
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
            return $cache->get('weshop_product_runtime', $key);
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
            $cache->set('weshop_product_runtime', $key, $value, max(1, $ttl));
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
                'consumer_code' => 'weshop_product_runtime',
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
        return self::cachePolicy()->ttl('page.product_view_ttl', self::VIEW_PAYLOAD_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }
}
