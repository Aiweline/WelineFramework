<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\StateManager;

/**
 * Warm the critical runtime classes that the *first* storefront request would
 * otherwise load lazily.
 *
 * Why this exists
 * ---------------
 * Worker bootstrap already warms registries (compiled container, object metadata,
 * routes, hooks, event descriptors) but never touches the classes whose static
 * initialisation sits on the hot path of a cold request. Measured on this repo
 * (cold process, opcache enabled, 70 script misses on first touch):
 *
 *   Url::registerStateResets()             3.880 ms cold  /  0.001–0.003 ms warm  (~1293x)
 *   StateManager::registerFrameworkResets() 4.721 ms cold  /  0.003–0.006 ms warm  ( ~787x)
 *   Env::getInstance()                     3.298 ms cold
 *
 * Those costs land inside the request pipeline (`parse`, `run_after`) instead of
 * during worker start. This provider moves them to PHASE_BOOTSTRAP, which runs in
 * `WlsRuntime::preloadWorkerRegistries()` before the worker reports READY — so the
 * first real request no longer pays them.
 *
 * Scope
 * -----
 * Worker processes only (WLS). Control-plane roles and FPM are excluded: FPM has no
 * long-lived process to warm, so preloading there would only duplicate the work the
 * request is doing anyway.
 *
 * Always on for serving workers — no configuration flag. Everything is wrapped in
 * try/catch: a warm failure must never block worker READY.
 */
final class CriticalRuntimeClassPreloadProvider implements WorkerPreloadProviderInterface
{
    /** Control-plane / non-serving roles never serve storefront requests. */
    private const EXCLUDED_ROLES = ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'];

    /**
     * Events dispatched on every storefront request. Resolving their observers
     * here moves the first-lookup cost (registry scan + observer list build) to
     * worker start-up instead of the first request.
     *
     * Keep this list to genuinely hot framework events; the per-event observer
     * cache is unbounded per process, so a long tail of cold events would trade
     * start-up time for memory that never gets read.
     *
     * @var list<string>
     */
    private const HOT_EVENTS = [
        'Weline_Framework_Runtime::worker_bootstrap_after',
        'Weline_Framework::App::run_before',
        'Weline_Framework::App::run_after',
        'Weline_Framework::App::url_parsed_after',
        'Weline_Framework_Url::seo_decode',
        'Weline_Framework_Url::detect_website',
        'Weline_Framework_Router::before_start',
        // applyParsedUrl() dispatches these before the cache identity freezes;
        // their observers install the theme application context. First dispatch
        // measured 8–11 ms per Worker (observer + dependency graph load), and it
        // sits inside process_url_parse_ms on every storefront request.
        'Weline_Framework::App::storefront_scope_ready_gate',
        'Weline_Framework::App::application_context_ready',
        'Weline_Framework_Router::process_uri_before',
        'Weline_Framework_Router::route_before',
        'Weline_Framework_Router::route_after',
        'Weline_Framework_Controller::fetch_file_before',
        'Weline_Framework_Controller::fetch_file_after',
        'Weline_Framework_FrontendController::init_before',
        'Weline_Framework_FrontendController::init_after',
        'Weline_Framework_View::fetch_file',
        'Weline_Framework_Template::after_render',
        'Weline_Framework_Query::before_execute',
        'Weline_Framework_Query::after_execute',
        // Dispatched by RequestPipeline::execute before URL parsing, on every
        // request; the maintenance gate runs first (sort=-999).
        'Weline_Framework::App::pre_route_gate',
        // Dispatched once per Template::fetch. The first dispatch measured
        // 45.04 ms cold (theme/preview resolution) against 0.12 ms afterwards,
        // and a storefront fetches dozens of templates per request.
        'Weline_Framework_View::resolve_theme_cache_suffix',
        // Every full-page-cache hit decorates the stored response through this.
        'Weline_Framework_Fpc::cache_hit_response',
        // FPM final-response decoration: the last observer chain of the request.
        'Weline_Framework_Http::response_ready',
    ];

    /**
     * Query providers touched by storefront request resolution (IP, website,
     * locale). Instantiating a provider pulls in its model/helper graph, which
     * measured 18–37 ms cold and is otherwise paid on the first request.
     *
     * @var list<string>
     */
    private const HOT_QUERY_PROVIDERS = ['websites'];

    /**
     * Default Website scope used to prime the FPC serve gate.
     *
     * These are part of the framework's own default-scope contract; importing a
     * module model (Website::ID_DEFAULT / CODE_DEFAULT) from here would make the
     * framework build-time dependent on Weline_Websites.
     */
    private const DEFAULT_WEBSITE_ID = 0;
    private const DEFAULT_WEBSITE_CODE = 'default';


    public function code(): string
    {
        return 'critical_runtime_classes';
    }

    /**
     * Providers whose process-memory output this warm-up reads.
     */
    public function dependsOn(): array
    {
        return [
            'compiled_registry_warm',
            'object_metadata',
            'route_registry',
        ];
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    /**
     * Run after the registry providers (5–40) so their artifacts are already in
     * memory and this step only pays for the remaining class loads.
     */
    public function priority(): int
    {
        return 50;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        if ($context->runtimeMode() !== RuntimeInterface::MODE_WLS) {
            return false;
        }
        $role = \strtolower(\trim($context->role()));

        return !\in_array($role, self::EXCLUDED_ROLES, true);
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $detail = [];
        $items = 0;

        // 1) Url: parser state caches are reset once per request; load the class and
        //    register its resets so the first request finds them already wired.
        if ($this->step('url', static function (): void {
            Url::registerStateResets();
        }, $detail)) {
            $items++;
        }

        // 2) StateManager: the per-request baseline runs before the controller chain.
        if ($this->step('state_manager', static function (): void {
            StateManager::registerFrameworkResets();
        }, $detail)) {
            $items++;
        }

        // 3) Env: config + directory resolution is used by parse/apply and every
        //    runtime scope lookup.
        if ($this->step('env', static function (): void {
            Env::getInstance()->getConfig();
        }, $detail)) {
            $items++;
        }

        // 4) ObjectManager's own runtime metadata is a prerequisite of the two above
        //    in some code paths; touching it here keeps the ordering deterministic.
        if ($this->step('object_manager', static function (): void {
            ObjectManager::getInstance(Env::class);
        }, $detail)) {
            $items++;
        }

        // 5) RequestContext: created for every request; warm its class + PHP-side
        //    state container so the first request does not pay the autoload.
        if ($this->step('request_context', static function (): void {
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
                \Weline\Framework\Runtime\RequestContext::isInitialized();
            }
        }, $detail)) {
            $items++;
        }

        // 6) Taglibs: the template compiler resolves tag definitions on first render.
        //    Measured cold 49.5 ms vs 0.09 ms warm (~550x); every storefront page
        //    goes through the compiler.
        // taglibs/widgets 预热已下沉为各模块自有 preload provider
        // (`worker_preload_provider.Weline_Taglib_Catalogue` / `..._Widget_Catalogue`)，
        // 运行时只负责调度。

        // 8) Cache pools: the first w_cache() call constructs CacheManager, which
        //    merges cache switch state from the database (measured 61–82 ms cold:
        //    CacheManager ctor → Env::getConfig('cache') → mergeCacheStatusFromDb →
        //    cacheStatusProvider()->all() reading ~57 rows). Every later call in
        //    this process is a static hit (~0.002 ms), so paying it here keeps it
        //    off the first request.
        if ($this->step('cache_manager', static function (): void {
            if (\function_exists('w_cache')) {
                w_cache('event');
            }
        }, $detail)) {
            $items++;
        }

        // 9) Events manager + observer resolution: constructed on every request
        //    path (router, controller, template hooks). Cold construction pulls in
        //    Scanner (23 ms) and the event cache pool; first observer lookup costs
        //    another ~2.9 ms before its per-event cache warms.
        //
        //    Observers are resolved from the event registry, never from a
        //    hardcoded class list: the framework owns which of *its own* events
        //    are hot, the owning module owns which class observes them.
        if ($this->step('events', static function (): void {
            if (!\class_exists(\Weline\Framework\Event\EventsManager::class)) {
                return;
            }
            /** @var \Weline\Framework\Event\EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            foreach (self::HOT_EVENTS as $eventName) {
                self::warmEventObservers($eventsManager, $eventName);
            }
        }, $detail)) {
            $items++;
        }

        // 10) Query providers: w_query() instantiates the named provider on first
        //     use (Websites provider alone measured 18–37 ms cold, pulling in its
        //     model and helper graph). Storefront IP/website/locale lookups hit
        //     this on the first request, so instantiate the hot providers here.
        if ($this->step('query_providers', static function (): void {
            if (!\class_exists(\Weline\Framework\Service\Query\QueryProviderRegistry::class)) {
                return;
            }
            $registry = ObjectManager::getInstance(\Weline\Framework\Service\Query\QueryProviderRegistry::class);
            foreach (self::HOT_QUERY_PROVIDERS as $providerName) {
                $registry->getProvider($providerName);
            }
        }, $detail)) {
            $items++;
        }

        // 11) FPC gate data: every storefront request runs the full-page-cache
        //     serve gate before it can reuse a cached page. Three cold loads sat
        //     on that gate's first call (measured 5.6 ms per Worker):
        //       - the store adapter registry (ObjectManager provider resolution)
        //       - the FPC policy snapshot (~149 KB declaration map, re-read from
        //         Shared because L1 rejects entries that large)
        //       - the CDN dev-mode config flag (system-config row resolution)
        //     Priming them here keeps the request path to plain array/memory
        //     reads. Fail-open: FPC missing data must not block Worker READY.
        if ($this->step('fpc_gate_data', static function (): void {
            if (!\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class)) {
                return;
            }
            /** @var \Weline\Framework\Router\FullPageCacheCoordinator $fpc */
            $fpc = ObjectManager::getInstance(\Weline\Framework\Router\FullPageCacheCoordinator::class);

            // Prime the policy snapshot + path policy for the storefront root;
            // the snapshot is the expensive part and is process-static afterwards.
            $identity = \Weline\Framework\Runtime\ScopeIdentity::channel(
                self::DEFAULT_WEBSITE_ID,
                self::DEFAULT_WEBSITE_CODE,
                self::DEFAULT_WEBSITE_CODE,
                self::DEFAULT_WEBSITE_CODE,
                \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL,
            );
            $fpc->warmServeGateData($identity);
        }, $detail)) {
            $items++;
        }

        // 12) DevTool 面板会话探测：`DevToolMemoryLimitBootstrap::applyIfDevToolSessionActive()`
        //     在每个请求的 bootstrapRequestCycle 里都会 resolve 这个 provider，
        //     首次解析要装配 DeveloperWorkspace 的模型/配置图（冷启实测 130–560 ms）。
        //     这里只把对象装配好，不触发真正的会话判定。
        if ($this->step('developer_access_provider', static function (): void {
            $interface = \Weline\Framework\Runtime\DeveloperAccessProviderInterface::class;
            if (!\interface_exists($interface)) {
                return;
            }
            ObjectManager::getInstance($interface);
        }, $detail)) {
            $items++;
        }

        // 13) 店面分类树预热已下沉为 Product 模块自有 preload provider
        //     (`worker_preload_provider.Weline_Product_CategoryTree`)，运行时只负责调度。

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        $failed = \array_keys(\array_filter($detail, static fn($ok): bool => $ok === false));
        if ($failed !== [] && $items === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $context->phase(),
                'all_steps_failed:' . \implode(',', $failed),
                $durationMs,
                $memoryDelta
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            $durationMs,
            $memoryDelta,
            $detail
        );
    }

    public function invalidationKeys(): array
    {
        // Pure class warm-up: nothing generated is cached here, only code identity.
        return ['code'];
    }

    /**
     * Resolve one hot event's observer list and build each observer object.
     *
     * Registering an event only resolves its list; the observer object itself
     * (and its constructor dependency graph) is still built on the first
     * dispatch — measured 8–45 ms per observer on a cold Worker. Building them
     * here moves that cost into worker boot instead of the first request.
     *
     * Fail-open per observer: a module that installs an unbuildable observer
     * must not stop the rest of the warm-up or block Worker READY.
     */
    private static function warmEventObservers(
        \Weline\Framework\Event\EventsManager $eventsManager,
        string $eventName
    ): void {
        $observers = $eventsManager->getEventObservers($eventName);
        if (!\is_array($observers)) {
            return;
        }

        foreach ($observers as $observer) {
            if (!\is_array($observer)) {
                continue;
            }
            $instance = \ltrim(\trim((string)($observer['instance'] ?? '')), '\\');
            if ($instance === '' || !\class_exists($instance)) {
                continue;
            }
            try {
                ObjectManager::getInstance($instance);
            } catch (\Throwable) {
                // The dispatch path keeps its own fail-open handling.
            }
        }
    }

    /**
     * @param callable(): void $loader
     * @param array<string, bool> $detail
     */
    private function step(string $name, callable $loader, array &$detail): bool
    {
        try {
            $loader();
            $detail[$name] = true;

            return true;
        } catch (\Throwable) {
            // A warm-up step must never break worker READY; the request path can
            // still load the class lazily as before.
            $detail[$name] = false;

            return false;
        }
    }
}
