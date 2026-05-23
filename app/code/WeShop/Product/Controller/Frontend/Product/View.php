<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
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
    private static string $lastRuntimeCacheGetStatus = 'none';
    /** @var list<array{name:string, duration_ms:float, meta:array<string, mixed>}> */
    private array $productProfileSteps = [];

    protected ?string $layoutType = 'product';

    public function __construct(
        private readonly StorefrontRecentlyViewedRecorder $storefrontRecentlyViewedRecorder,
        private readonly ProductViewPageDataService $productViewPageDataService
    ) {
    }

    public function index(): string
    {
        $this->productProfileSteps = [];
        RequestContext::set('product.view.profile', []);

        $productId = (int) ($this->request->getParam('id') ?? $this->request->getParam('product_id') ?? 0);

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Product ID is required.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->traceProductStep(
            'product::record_recently_viewed',
            fn () => $this->recordRecentlyViewed($productId),
            ['product_id' => $productId]
        );

        $cacheKey = $this->buildViewPayloadCacheKey($productId);
        $cachedView = $this->getViewPayloadCache($cacheKey);
        if (is_array($cachedView) && isset($cachedView['html'])) {
            $this->applyCachedViewPayload($cachedView);
            return (string)$cachedView['html'];
        }

        $this->cooperativeControllerYield();
        $pageData = $this->traceProductStep(
            'product::build_page_data',
            fn (): ?array => $this->productViewPageDataService->build($productId),
            ['product_id' => $productId]
        );
        $this->cooperativeControllerYield();
        if (!$pageData) {
            $this->getMessageManager()->addError(__('Product is unavailable.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->assignPageData($pageData);
        $this->cooperativeControllerYield();
        $html = $this->traceProductStep(
            'product::render_content_template',
            fn (): string => $this->fetch(self::CONTENT_TEMPLATE),
            ['product_id' => $productId]
        );
        $this->traceProductStep(
            'product::payload_cache_store',
            function () use ($cacheKey, $html, $pageData) {
                $this->rememberViewPayloadCache($cacheKey, [
                    'html' => $html,
                    'assigns' => $pageData,
                ]);
                return null;
            },
            ['product_id' => $productId]
        );

        return $html;
    }

    private function recordRecentlyViewed(int $productId): void
    {
        $this->storefrontRecentlyViewedRecorder->recordProductView($productId);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function traceProductStep(string $name, callable $callback, array $meta = []): mixed
    {
        $start = microtime(true);
        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $this->cooperativeControllerYield();
        if ($traceEnabled) {
            RequestLifecycleTrace::pushCurrentParent($name);
        }

        try {
            return $callback();
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $this->recordProductProfileStep($name, $durationMs, $meta);
            $this->setPerfHeader('X-WLS-Product-Step-' . $this->normalizePerfHeaderName($name), sprintf('%.2f', $durationMs));
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan(
                    $name,
                    $durationMs,
                    'controller',
                    'controller_chain::action_execute',
                    $meta
                );
            }
            $this->cooperativeControllerYield();
        }
    }

    private function cooperativeControllerYield(): void
    {
        if (!Runtime::isPersistent()
            || !\Weline\Framework\Runtime\SchedulerSystem::isSchedulerActive()
            || !\Fiber::getCurrent()) {
            return;
        }

        static $fiberYieldAt = null;
        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }
        if (!$fiberYieldAt instanceof \WeakMap) {
            $fiberYieldAt = new \WeakMap();
        }

        $now = microtime(true);
        $lastYieldAt = (float)($fiberYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            $fiberYieldAt[$fiber] = $now;
            return;
        }
        if (($now - $lastYieldAt) < 0.01) {
            return;
        }

        $fiberYieldAt[$fiber] = $now;
        \Weline\Framework\Runtime\SchedulerSystem::yield();
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordProductProfileStep(string $name, float $durationMs, array $meta = []): void
    {
        $this->productProfileSteps[] = [
            'name' => $name,
            'duration_ms' => round($durationMs, 2),
            'meta' => $meta,
        ];

        if (count($this->productProfileSteps) > 40) {
            $this->productProfileSteps = array_slice($this->productProfileSteps, -40);
        }

        RequestContext::set('product.view.profile', $this->productProfileSteps);
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
                $this->setPerfHeader('X-WLS-Product-View-Cache', 'local');
                $this->recordProductProfileStep('product::runtime_cache_get', 0.0, ['status' => 'local']);
                return is_array($cached['data']) ? $cached['data'] : null;
            }
            unset(self::$viewPayloadCache[$key]);
        }

        $runtimeStart = microtime(true);
        $runtimeCached = $this->runtimeCacheGet('product.view.' . $key);
        $runtimeDurationMs = (microtime(true) - $runtimeStart) * 1000;
        $this->setPerfHeader('X-WLS-Product-View-Cache-Get-Ms', sprintf('%.2f', $runtimeDurationMs));
        $this->recordProductProfileStep('product::runtime_cache_get', $runtimeDurationMs, [
            'status' => self::$lastRuntimeCacheGetStatus,
        ]);
        if (is_array($runtimeCached)) {
            $this->setPerfHeader('X-WLS-Product-View-Cache', 'shared');
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

        $this->setPerfHeader('X-WLS-Product-View-Cache', 'miss:' . self::$lastRuntimeCacheGetStatus);
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
        $this->recordProductProfileStep('product::runtime_cache_store', 0.0, [
            'html_bytes' => strlen((string)($payload['html'] ?? '')),
        ]);
    }

    private function buildViewPayloadCacheKey(int $productId): string
    {
        return KeyBuilder::environmentHash([
            'scope' => 'product-view-payload-v6',
            'product_id' => $productId,
            'lang' => Cookie::getLang(),
        ]);
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            self::$lastRuntimeCacheGetStatus = 'unavailable';
            return null;
        }

        try {
            $value = $cache->get('weshop_product_runtime', $key);
            self::$lastRuntimeCacheGetStatus = $value === null ? 'empty' : 'value';
            return $value;
        } catch (\Throwable $throwable) {
            self::$lastRuntimeCacheGetStatus = 'error:' . $throwable::class;
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
            $stored = $cache->set('weshop_product_runtime', $key, $value, max(1, $ttl));
            $this->setPerfHeader('X-WLS-Product-View-Cache-Store', $stored ? 'ok' : 'fail');
        } catch (\Throwable $throwable) {
            $this->setPerfHeader('X-WLS-Product-View-Cache-Store', 'error:' . $throwable::class);
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private function setPerfHeader(string $name, string $value): void
    {
        try {
            $this->request->getResponse()->setHeader($name, $value);
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
