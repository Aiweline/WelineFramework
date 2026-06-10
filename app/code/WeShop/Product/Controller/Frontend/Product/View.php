<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductLayoutService;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class View extends BaseController
{
    private const PRODUCT_LIST_ROUTE = 'weshop/product/list';
    private const CONTENT_TEMPLATE = 'WeShop_Product::templates/frontend/product/view.phtml';
    private const VIEW_PAYLOAD_CACHE_TTL = 600;

    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $viewPayloadCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;
    private static string $lastRuntimeCacheGetStatus = 'none';
    /** @var list<array{name:string, duration_ms:float, meta:array<string, mixed>}> */
    private array $productProfileSteps = [];
    private ?StorefrontRecentlyViewedRecorder $storefrontRecentlyViewedRecorder = null;
    private ?ProductLayoutService $productLayoutService = null;
    private ?ProductViewPageDataService $productViewPageDataService = null;

    protected ?string $layoutType = 'product';

    public function __construct(
        ?StorefrontRecentlyViewedRecorder $storefrontRecentlyViewedRecorder = null,
        ?ProductViewPageDataService $productViewPageDataService = null,
        ?ProductLayoutService $productLayoutService = null
    ) {
        $this->storefrontRecentlyViewedRecorder = $storefrontRecentlyViewedRecorder;
        $this->productViewPageDataService = $productViewPageDataService;
        $this->productLayoutService = $productLayoutService;
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

        $layoutContext = $this->resolveProductLayoutContext($productId);
        $layoutOption = is_array($layoutContext) ? trim((string)($layoutContext['layout_code'] ?? '')) : null;
        if ($layoutOption !== null) {
            $this->layoutType = 'product.' . $layoutOption;
        }
        $this->applyThemeLayoutTargetContext($productId, $layoutContext);

        $isLayoutPreviewRequest = $this->isLayoutPreviewRequest();
        $layoutRuntimeIdentity = $this->productLayoutService()->buildLayoutRuntimeCacheIdentity($layoutContext, 'product');
        $cacheKey = $this->buildViewPayloadCacheKey($productId, $layoutOption ?? 'default', $layoutRuntimeIdentity);
        if (!$isLayoutPreviewRequest) {
            $cachedView = $this->getViewPayloadCache($cacheKey);
            if (is_array($cachedView) && isset($cachedView['html'])) {
                $this->recordRecentlyViewedIfNeeded($productId);
                $this->dispatchProductViewedIfNeeded($productId, is_array($cachedView['assigns'] ?? null) ? $cachedView['assigns'] : []);
                $this->applyCachedViewPayload($cachedView);
                return (string)$cachedView['html'];
            }
        }

        $this->cooperativeControllerYield();
        $pageData = $this->traceProductStep(
            'product::build_page_data',
            fn (): ?array => $this->productViewPageDataService()->build($productId),
            ['product_id' => $productId]
        );
        $this->cooperativeControllerYield();
        if (!$pageData) {
            $this->getMessageManager()->addError(__('Product is unavailable.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->recordRecentlyViewedIfNeeded($productId);
        $this->dispatchProductViewedIfNeeded($productId, $pageData);
        $this->assignPageData($pageData);
        $this->cooperativeControllerYield();
        $html = $this->traceProductStep(
            'product::render_content_template',
            fn (): string => $this->fetch(self::CONTENT_TEMPLATE),
            ['product_id' => $productId]
        );
        if (!$isLayoutPreviewRequest) {
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
        }

        return $html;
    }

    private function recordRecentlyViewed(int $productId): void
    {
        $this->storefrontRecentlyViewedRecorder()->recordProductView($productId);
    }

    private function recordRecentlyViewedIfNeeded(int $productId): void
    {
        if (!$this->shouldRecordRecentlyViewed()) {
            return;
        }

        $this->traceProductStep(
            'product::record_recently_viewed',
            fn () => $this->recordRecentlyViewed($productId),
            ['product_id' => $productId]
        );
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function dispatchProductViewedIfNeeded(int $productId, array $pageData = []): void
    {
        if (!$this->shouldRecordRecentlyViewed()) {
            return;
        }

        $this->traceProductStep(
            'product::dispatch_product_viewed',
            function () use ($productId, $pageData): void {
                $eventData = [
                    'product_id' => $productId,
                    'product' => $pageData['product'] ?? null,
                    'idempotency_key' => '',
                ];
                ObjectManager::getInstance(EventsManager::class)->dispatch('WeShop_Product::product_viewed', $eventData);
            },
            ['product_id' => $productId]
        );
    }

    private function shouldRecordRecentlyViewed(): bool
    {
        if ($this->isLayoutPreviewRequest()) {
            return false;
        }

        return (string)($_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] ?? '') !== '1'
            && (string)($_SERVER['HTTP_X_WLS_DYNAMIC_WARMUP'] ?? '') !== '1'
            && (string)($_SERVER['HTTP_X_WLS_DYNAMIC_BENCHMARK'] ?? '') !== '1'
            && (string)($_SERVER['WLS_INTERNAL_WARMUP'] ?? '') !== '1'
            && (string)($_SERVER['HTTP_WELINE_INTERNAL_WARMUP'] ?? '') !== '1';
    }

    private function storefrontRecentlyViewedRecorder(): StorefrontRecentlyViewedRecorder
    {
        if ($this->storefrontRecentlyViewedRecorder instanceof StorefrontRecentlyViewedRecorder) {
            return $this->storefrontRecentlyViewedRecorder;
        }

        return $this->storefrontRecentlyViewedRecorder = ObjectManager::getInstance(StorefrontRecentlyViewedRecorder::class);
    }

    private function productViewPageDataService(): ProductViewPageDataService
    {
        if ($this->productViewPageDataService instanceof ProductViewPageDataService) {
            return $this->productViewPageDataService;
        }

        return $this->productViewPageDataService = ObjectManager::getInstance(ProductViewPageDataService::class);
    }

    private function productLayoutService(): ProductLayoutService
    {
        if ($this->productLayoutService instanceof ProductLayoutService) {
            return $this->productLayoutService;
        }

        return $this->productLayoutService = ObjectManager::getInstance(ProductLayoutService::class);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveProductLayoutContext(int $productId): ?array
    {
        $previewOption = $this->resolvePreviewLayoutOption('product');
        if ($previewOption !== null) {
            $sourceType = (string)($this->request->getParam('theme_layout_source_target_type') ?? $this->request->getGet('theme_layout_source_target_type', 'product'));
            $sourceId = (int)($this->request->getParam('theme_layout_source_target_id') ?? $this->request->getGet('theme_layout_source_target_id', $productId));
            if (!in_array($sourceType, ['product', 'category_product_default'], true) || $sourceId <= 0) {
                $sourceType = 'product';
                $sourceId = $productId;
            }

            return [
                'layout_code' => $previewOption,
                'entity_type' => $sourceType,
                'entity_id' => $sourceId,
                'layout_type' => 'product',
                'source' => 'preview',
            ];
        }

        try {
            return $this->productLayoutService()->resolveProductLayoutContext(
                $productId,
                'product',
                $this->resolveCurrentCategoryId()
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveCurrentCategoryId(): ?int
    {
        foreach (['category_id', 'current_category_id', 'cid'] as $key) {
            $value = (int)($this->request->getParam($key) ?? $this->request->getGet($key, 0));
            if ($value > 0) {
                return $value;
            }
        }

        $category = $this->request->getData('category');
        if (is_array($category)) {
            $value = (int)($category['category_id'] ?? $category['id'] ?? 0);
            return $value > 0 ? $value : null;
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $layoutContext
     */
    private function applyThemeLayoutTargetContext(int $productId, ?array $layoutContext): void
    {
        $chain = [];
        $sourceType = (string)($layoutContext['entity_type'] ?? '');
        $sourceId = (int)($layoutContext['entity_id'] ?? 0);
        if ($sourceType !== '' && $sourceId > 0) {
            $chain[] = [
                'target_type' => $sourceType,
                'target_id' => $sourceId,
            ];
            $this->request->setData('theme_layout_source_target_type', $sourceType);
            $this->request->setData('theme_layout_source_target_id', $sourceId);
        }

        $chain[] = [
            'target_type' => 'product',
            'target_id' => $productId,
        ];

        $this->request->setData('theme_layout_target_type', 'product');
        $this->request->setData('theme_layout_target_id', $productId);
        $this->request->setData('theme_layout_target_chain', $chain);
        if (is_array($layoutContext)) {
            $this->request->setData('theme_layout_context', $layoutContext);
        }
    }

    private function isLayoutPreviewRequest(): bool
    {
        foreach (['layout_preview', 'preview_layout'] as $key) {
            $value = (string)($this->request->getParam($key) ?? $this->request->getGet($key, ''));
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    private function resolvePreviewLayoutOption(string $layoutType): ?string
    {
        if (!$this->isLayoutPreviewRequest()) {
            return null;
        }

        $layoutOption = (string)($this->request->getParam('layout_option') ?? $this->request->getGet('layout_option', ''));
        $layoutOption = $this->productLayoutService()->normalizeLayoutOptionCode($layoutOption);
        if ($layoutOption === '') {
            return null;
        }

        return $layoutOption;
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
        RequestContext::set('weshop.product.view.page_data', $pageData);
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

    private function buildViewPayloadCacheKey(int $productId, string $layoutOption, string $layoutRuntimeIdentity): string
    {
        $host = $this->normalizeViewPayloadCacheHost(\function_exists('w_env_http_host') ? (string)\w_env_http_host() : '');
        $environment = KeyBuilder::environmentContext([
            'scope' => 'product-view-payload',
        ]);

        return \sha1((string)\json_encode([
            'v' => 20,
            'product_id' => $productId,
            'layout_option' => $layoutOption,
            'layout_runtime_identity' => $layoutRuntimeIdentity,
            'environment' => $environment,
            'host' => $host,
            'view_fingerprint' => $this->viewPayloadTemplateFingerprint(),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function viewPayloadTemplateFingerprint(): string
    {
        $paths = [
            BP . '/generated/hooks.php',
            BP . '/app/code/Weline/Theme/view/theme/frontend/layouts/product/default.phtml',
            BP . '/app/code/WeShop/Product/Helper/HanfuDemoOptionImageProvider.php',
            BP . '/app/code/WeShop/Product/Service/ConfigurableProductService.php',
            BP . '/app/code/WeShop/Product/Service/ProductViewPageDataService.php',
            BP . '/app/code/WeShop/Product/view/templates/frontend/product/view.phtml',
            BP . '/app/design/WeShop/default/frontend/pages/product/view.phtml',
        ];

        foreach (\glob(BP . '/app/code/Weline/Theme/view/theme/frontend/layouts/product/*.phtml') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach ($this->viewPayloadHookFingerprintPatterns() as $pattern) {
            foreach (\glob($pattern) ?: [] as $path) {
                $paths[] = $path;
            }
        }

        $paths = \array_values(\array_unique($paths));
        \sort($paths, \SORT_STRING);

        $fingerprint = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $fingerprint[] = basename($path) . ':' . (string)filemtime($path) . ':' . (string)filesize($path);
        }

        return implode('|', $fingerprint);
    }

    /**
     * @return list<string>
     */
    private function viewPayloadHookFingerprintPatterns(): array
    {
        return [
            BP . '/app/code/WeShop/Product/i18n/*.csv',
            BP . '/app/code/WeShop/Product/view/hooks/WeShop_Product/frontend/layouts/product/*.phtml',
            BP . '/app/code/WeShop/QA/i18n/*.csv',
            BP . '/app/code/WeShop/Review/i18n/*.csv',
            BP . '/app/code/WeShop/Review/view/hooks/WeShop_Review/frontend/layouts/product-reviews/*.phtml',
            BP . '/app/code/WeShop/Review/view/hooks/Weline_Theme/frontend/layouts/base/*.phtml',
        ];
    }

    private function normalizeViewPayloadCacheHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }

        if (\str_contains($host, '://')) {
            try {
                $parsedHost = \parse_url($host, \PHP_URL_HOST);
                $host = \is_string($parsedHost) ? \strtolower(\trim($parsedHost)) : $host;
            } catch (\Throwable) {
                return '';
            }
        }

        $host = \trim($host, " \t\n\r\0\x0B/");
        if ($host === '') {
            return '';
        }

        if ($host[0] === '[') {
            $end = \strpos($host, ']');
            if ($end === false) {
                return '';
            }

            $ip = \substr($host, 1, $end - 1);
            return \filter_var($ip, \FILTER_VALIDATE_IP) ? '' : $host;
        }

        $colonCount = \substr_count($host, ':');
        if ($colonCount === 1) {
            $host = \preg_replace('/:\d+$/', '', $host) ?? $host;
        } elseif ($colonCount > 1) {
            return '';
        }

        $host = \trim($host, " \t\n\r\0\x0B[]/");
        if ($host === '' || $host === 'localhost' || \filter_var($host, \FILTER_VALIDATE_IP)) {
            return '';
        }

        return $host;
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
