<?php
declare(strict_types=1);

/**
 * Weline Framework - WLS 运行时
 * 
 * Weline Server 常驻内存模式的运行时实现
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\App;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser as PhraseParser;
use Weline\Framework\Router\Core as Router;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadManager;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Service\Query\QueryProviderRegistry;
use Weline\I18n\Parser as I18nParser;
use Weline\Server\Log\LogConfig;
use Weline\Server\Service\DynamicWarmup\HotPathDiscoveryService;
use Weline\Server\Service\MemoryStateFacade;
/**
 * WLS 运行时
 * 
 * 特点：
 * - 进程启动时初始化框架（只执行一次）
 * - 每个请求调用 handle()
 * - 请求结束调用 reset() 清理状态
 * - 常驻内存，高性能
 */
class WlsRuntime implements RuntimeInterface
{
    private const DYNAMIC_WARMUP_COORDINATOR_NS = 'wls_dynamic_warmup';

    /**
     * 是否已初始化
     */
    private bool $bootstrapped = false;

    private static ?MemoryStateFacade $dynamicWarmupCoordinator = null;
    private static bool $dynamicWarmupCoordinatorResolved = false;
    
    /**
     * 事件管理器（进程级缓存）
     */
    private ?EventsManager $eventManager = null;
    
    /**
     * 路由器（进程级缓存）
     */
    private ?Router $router = null;

    private ?WorkerPreloadManager $preloadManager = null;

    private array $preloadPhasesCompleted = [];

    private bool $readyGateWorkerBootstrapWarmupCompleted = false;

    private bool $readyGateWorkerRegistryWarmupCompleted = false;

    private bool $readyGateDynamicFirstRenderWarmupCompleted = false;

    /**
     * 请求计数
     */
    private int $requestCount = 0;

    /**
     * Pending response state must stay request-scoped. Store it per fiber so
     * one request cannot overwrite another request's cookies/headers/status
     * during scheduler yields.
     *
     * @var \WeakMap<\Fiber, array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}>|null
     */
    private ?\WeakMap $fiberPendingResponseStates = null;

    /**
     * Fallback pending response state for non-fiber callers such as unit tests.
     *
     * @var array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private array $mainPendingResponseState = [
        'cookies' => [],
        'headers' => [],
        'status_code' => null,
        'explicit' => false,
        'sse_started' => false,
    ];
    
    /**
     * 超全局变量模拟器
     */
    /**
     * 待发送的 Cookie（在 StateManager 重置前从 HeaderCollector 提取）
     * Worker 在构建 HTTP 响应时读取这些 Cookie 并添加 Set-Cookie 头
     */
    /**
     * 待发送的响应头（在 StateManager 重置前从 HeaderCollector 提取）
     */
    /**
     * Pending HTTP status captured before request state is reset.
     */
    /**
     * Whether the pending HTTP status was explicitly overridden by application code.
     */
    /** WLS 运行时性能配置缓存 */
    private ?array $performanceConfig = null;
    
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        
        // 定义 WLS 模式常量
        if (!\defined('WLS_MODE')) {
            \define('WLS_MODE', true);
        }
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        
        // 定义框架核心常量（原本在 bootstrap.php 中定义）
        if (!\defined('VENDOR_PATH')) {
            \define('VENDOR_PATH', BP . 'vendor' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('VAR_PATH')) {
            \define('VAR_PATH', BP . 'var' . DIRECTORY_SEPARATOR);
        }
        
        // 初始化框架核心
        App::init();
        SchedulerSystem::yield();
        
        // 注册框架核心重置回调
        StateManager::registerFrameworkResets();
        FiberOutputBuffer::install();
        SchedulerSystem::yield();

        // 预加载常用对象（进程级缓存）
        // Router 在 WLS 模式下是进程级单例。
        // 请求级状态由 Router::__init() 在每个请求开始时重置，
        // 无需在此注册额外回调（__init 通过 RequestContext ID 检测新请求）。
        
        $this->bootstrapped = true;
    }

    private function getWorkerPreloadManager(): WorkerPreloadManager
    {
        if ($this->preloadManager === null) {
            $this->preloadManager = WorkerPreloadManager::createDefault();
        }

        return $this->preloadManager;
    }

    private function runWorkerPreloadPhase(string $phase): void
    {
        if (isset($this->preloadPhasesCompleted[$phase])) {
            return;
        }

        $this->getWorkerPreloadManager()->runPhase(
            $phase,
            WorkerPreloadContext::fromGlobals($phase, RuntimeInterface::MODE_WLS)
        );
        $this->preloadPhasesCompleted[$phase] = true;
    }

    private function shouldRunWorkerBootstrapWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_bootstrap_warmup', null);
        }

        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            return true;
        }

        $flag = \strtolower(\trim((string)$rawFlag));
        return \in_array($flag, ['1', 'true', 'yes', 'on', 'sync', 'async', 'deferred'], true);
    }

    public function runReadyGateWorkerBootstrapWarmup(): void
    {
        if ($this->readyGateWorkerBootstrapWarmupCompleted) {
            return;
        }
        if (!$this->shouldRunReadyGateWorkerBootstrapWarmup()) {
            $this->readyGateWorkerBootstrapWarmupCompleted = true;
            return;
        }

        $startedAt = \microtime(true);
        $backendResult = $this->newBackendFirstRenderWarmupResult();
        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);

        $this->logReadyGateWarmupStep('route_assert_begin', $workerId, $startedAt);
        $this->assertGeneratedRouteFilesReady();
        $this->logReadyGateWarmupStep('route_assert_done', $workerId, $startedAt);
        if ($this->shouldRunReadyGateWorkerRegistryWarmup()) {
            $this->eventManager ??= ObjectManager::getInstance(EventsManager::class);
            $this->router ??= ObjectManager::getInstance(Router::class);
            $this->logReadyGateWarmupStep('registry_begin', $workerId, $startedAt);
            $this->preloadWorkerRegistries(true);
            $this->readyGateWorkerRegistryWarmupCompleted = true;
            $this->logReadyGateWarmupStep('registry_done', $workerId, $startedAt);
            SchedulerSystem::yield();
        } else {
            $this->logReadyGateWarmupStep('registry_skipped', $workerId, $startedAt);
        }

        if ($this->shouldRunReadyGateWorkerBootstrapObserverWarmup()) {
            $this->eventManager ??= ObjectManager::getInstance(EventsManager::class);
            $this->router ??= ObjectManager::getInstance(Router::class);
            $this->logReadyGateWarmupStep('observer_begin', $workerId, $startedAt);
            $this->dispatchWorkerBootstrapWarmup();
            $this->logReadyGateWarmupStep('observer_done', $workerId, $startedAt);
            SchedulerSystem::yield();
        } else {
            $this->logReadyGateWarmupStep('observer_skipped', $workerId, $startedAt);
        }

        $this->logReadyGateWarmupStep('backend_begin', $workerId, $startedAt);
        $backendResult = $this->runReadyGateBackendFirstRenderWarmup();
        $this->logReadyGateWarmupStep(
            'backend_done warmed=' . (int)($backendResult['warmed'] ?? 0)
            . ' failed=' . (int)($backendResult['failed'] ?? 0)
            . ' elapsed_ms=' . (float)($backendResult['elapsed_ms'] ?? 0.0),
            $workerId,
            $startedAt
        );
        if ((int)($backendResult['failed'] ?? 0) > 0) {
            $message = 'READY gate backend first-render warmup failed worker=' . $workerId
                . ' failed=' . (int)($backendResult['failed'] ?? 0)
                . ' errors=' . \json_encode(
                    \array_slice(\is_array($backendResult['errors'] ?? null) ? $backendResult['errors'] : [], 0, 6),
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                );
            if (!$this->shouldFailOpenReadyGateBackendWarmup()) {
                throw new \RuntimeException($message);
            }
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WlsRuntime] ' . $message);
            }
        }

        $dynamicResult = $this->newDynamicFirstRenderWarmupResult();
        $this->logReadyGateWarmupStep('dynamic_begin', $workerId, $startedAt);
        if ($this->shouldRunReadyGateDynamicFirstRenderWarmup()) {
            $dynamicResult = $this->runReadyGateDynamicFirstRenderWarmup();
            $this->readyGateDynamicFirstRenderWarmupCompleted = true;
            $this->logReadyGateWarmupStep(
                'dynamic_done warmed=' . (int)($dynamicResult['warmed'] ?? 0)
                . ' failed=' . (int)($dynamicResult['failed'] ?? 0)
                . ' elapsed_ms=' . (float)($dynamicResult['elapsed_ms'] ?? 0.0),
                $workerId,
                $startedAt
            );
            if ((int)($dynamicResult['failed'] ?? 0) > 0) {
                $message = 'READY gate dynamic first-render warmup failed worker=' . $workerId
                    . ' failed=' . (int)($dynamicResult['failed'] ?? 0)
                    . ' errors=' . \json_encode(
                        \array_slice(\is_array($dynamicResult['errors'] ?? null) ? $dynamicResult['errors'] : [], 0, 8),
                        \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                    );
                if (!$this->shouldFailOpenReadyGateDynamicWarmup()) {
                    throw new \RuntimeException($message);
                }
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[WlsRuntime] ' . $message);
                }
            }
        } else {
            $this->logReadyGateWarmupStep('dynamic_skipped', $workerId, $startedAt);
        }

        $this->readyGateWorkerBootstrapWarmupCompleted = true;
        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] ready-gate bootstrap warmup done worker=' . $workerId
                . ' backend_warmed=' . (int)($backendResult['warmed'] ?? 0)
                . ' backend_failed=' . (int)($backendResult['failed'] ?? 0)
                . ' dynamic_warmed=' . (int)($dynamicResult['warmed'] ?? 0)
                . ' dynamic_failed=' . (int)($dynamicResult['failed'] ?? 0)
                . ' hosts=' . \json_encode($backendResult['hosts'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                . ' paths=' . \json_encode($backendResult['paths'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                . ' elapsed_ms=' . \round((\microtime(true) - $startedAt) * 1000, 2));
        }
    }

    private function shouldRunReadyGateWorkerBootstrapWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_READY_GATE_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.ready_gate_bootstrap_warmup', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync', 'ready_gate'], true);
    }

    private function shouldRunReadyGateWorkerBootstrapObserverWarmup(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_READY_GATE_OBSERVER_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.ready_gate_observer_warmup', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync'], true);
    }

    private function shouldRunReadyGateWorkerRegistryWarmup(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_READY_GATE_REGISTRY_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.ready_gate_registry_warmup', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync'], true);
    }

    private function logReadyGateWarmupStep(string $step, int $workerId, float $startedAt): void
    {
        if (!\function_exists('w_log_info')) {
            return;
        }

        \w_log_info('[WlsRuntime] ready-gate ' . $step
            . ' worker=' . $workerId
            . ' elapsed_ms=' . \round((\microtime(true) - $startedAt) * 1000, 2));
    }

    public function runDeferredWorkerBootstrapWarmup(): void
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        $roleCanRunGeneralDeferred = $this->roleCanRunDeferredWorkerBootstrap($role);

        $runRegistryWarmup = $roleCanRunGeneralDeferred && $this->shouldRunWorkerBootstrapWarmup();
        $runObserverWarmup = $roleCanRunGeneralDeferred && $this->shouldRunDeferredWorkerBootstrapObserverWarmup();
        $runUrlMetadataWarmup = $roleCanRunGeneralDeferred && $this->shouldRunDeferredWorkerBootstrapUrlMetadataWarmup();
        $runBackendFirstRenderWarmup = $this->shouldRunDeferredBackendFirstRenderWarmup();
        $runFpcBuildAheadWarmup = $roleCanRunGeneralDeferred && $this->shouldRunDeferredFpcBuildAheadWarmup();
        $runFpcProcessPullWarmup = $roleCanRunGeneralDeferred && $this->shouldRunDeferredFpcProcessPullWarmup();
        $runDynamicFirstRenderWarmup = $roleCanRunGeneralDeferred && $this->shouldRunDeferredDynamicFirstRenderWarmup();
        if ($this->readyGateWorkerRegistryWarmupCompleted) {
            $runRegistryWarmup = false;
            $runUrlMetadataWarmup = false;
        }
        if (!$runRegistryWarmup
            && !$runObserverWarmup
            && !$runUrlMetadataWarmup
            && !$runBackendFirstRenderWarmup
            && !$runFpcBuildAheadWarmup
            && !$runFpcProcessPullWarmup
            && !$runDynamicFirstRenderWarmup
        ) {
            return;
        }

        $this->eventManager ??= ObjectManager::getInstance(EventsManager::class);
        $this->router ??= ObjectManager::getInstance(Router::class);
        if ($runRegistryWarmup) {
            $this->preloadWorkerRegistries();
        }
        if ($runObserverWarmup) {
            $this->dispatchWorkerBootstrapWarmup();
        }
        if ($runUrlMetadataWarmup) {
            $this->warmupStep(static function (): void {
                Url::preloadWorkerRoutingMetadata();
            }, 'url metadata');
        }
        if ($runBackendFirstRenderWarmup) {
            $this->runDeferredBackendFirstRenderWarmup();
        }
        if ($runFpcBuildAheadWarmup) {
            $this->runDeferredFpcBuildAheadWarmup();
        }
        if ($runFpcProcessPullWarmup) {
            $this->runDeferredFpcProcessPullWarmup($runFpcBuildAheadWarmup);
        }
        if ($runDynamicFirstRenderWarmup) {
            $this->runDeferredDynamicFirstRenderWarmup();
        }
    }

    /**
     * @return array{enabled: bool, warmed: int, failed: int, paths: list<string>, hosts: list<string>, errors: list<string>, samples: list<array<string, mixed>>, elapsed_ms: float}
     */
    private function newDynamicFirstRenderWarmupResult(): array
    {
        return [
            'enabled' => false,
            'warmed' => 0,
            'failed' => 0,
            'paths' => [],
            'hosts' => [],
            'errors' => [],
            'samples' => [],
            'elapsed_ms' => 0.0,
        ];
    }

    /**
     * @return array{enabled: bool, warmed: int, failed: int, paths: list<string>, hosts: list<string>, errors: list<string>, samples: list<array<string, mixed>>, elapsed_ms: float}
     */
    private function runReadyGateDynamicFirstRenderWarmup(): array
    {
        if (!$this->shouldRunReadyGateDynamicFirstRenderWarmup()) {
            return $this->newDynamicFirstRenderWarmupResult();
        }

        return $this->runDynamicFirstRenderWarmupInternal(
            $this->readyGateDynamicWarmupMaxPaths(),
            $this->newDynamicFirstRenderWarmupResult(),
            $this->resolveReadyGateDynamicWarmupPaths()
        );
    }

    private function shouldRunReadyGateDynamicFirstRenderWarmup(): bool
    {
        if (!$this->canRunDynamicFirstRenderWarmupForCurrentRole()) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_READY_GATE_ENABLED');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_ready_gate_enabled', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync', 'ready_gate'], true);
    }

    private function shouldFailOpenReadyGateDynamicWarmup(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_READY_GATE_FAIL_OPEN');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_ready_gate_fail_open', '1');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'fail_open'], true);
    }

    private function readyGateDynamicWarmupMaxPaths(): int
    {
        $raw = \getenv('WLS_WORKER_DYNAMIC_READY_GATE_MAX_PATHS');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.dynamic_ready_gate_max_paths', null);
        }
        if ($raw === null || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.dynamic_ready_gate_max', 32);
        }

        return \max(1, \min(128, (int)$raw));
    }

    /**
     * @return list<string>
     */
    private function resolveReadyGateDynamicWarmupPaths(): array
    {
        $maxPaths = $this->readyGateDynamicWarmupMaxPaths();
        $configured = Env::get('wls.worker.dynamic_ready_gate_paths', null);
        if ($configured !== null && (!\is_string($configured) || \trim($configured) !== '')) {
            return $this->shardReadyGateWarmupPaths(
                \array_slice($this->normalizeDynamicWarmupPathList($configured), 0, $maxPaths)
            );
        }

        if (!$this->shouldUseReadyGateDynamicDiscovery()) {
            return $this->shardReadyGateWarmupPaths(
                \array_slice($this->readyGateDynamicCriticalWarmupPaths(), 0, $maxPaths)
            );
        }

        try {
            $discovery = ObjectManager::getInstance(HotPathDiscoveryService::class);
            $paths = $discovery instanceof HotPathDiscoveryService
                ? $discovery->discover(\max($maxPaths * 4, 16))
                : [];
        } catch (\Throwable) {
            $paths = [];
        }

        $readyGatePaths = [];
        foreach ($paths as $path) {
            if (!$this->isReadyGateDynamicControllerCachePath($path)) {
                continue;
            }
            $readyGatePaths[$path] = $path;
            if (\count($readyGatePaths) >= $maxPaths) {
                break;
            }
        }

        if ($readyGatePaths === []) {
            $readyGatePaths = \array_combine(
                $this->readyGateDynamicCriticalWarmupPaths(),
                $this->readyGateDynamicCriticalWarmupPaths()
            ) ?: [];
        }

        return $this->shardReadyGateWarmupPaths(\array_slice(\array_values($readyGatePaths), 0, $maxPaths));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function shardReadyGateWarmupPaths(array $paths): array
    {
        if ($paths === [] || !$this->shouldShardReadyGateWarmupPaths()) {
            return $paths;
        }

        $workerId = $this->currentWorkerId();
        $workerCount = $this->currentWorkerCount();
        if ($workerId <= 0 || $workerCount <= 1) {
            return $paths;
        }

        $workerIndex = ($workerId - 1) % $workerCount;
        $shard = [];
        foreach (\array_values($paths) as $index => $path) {
            if (($index % $workerCount) === $workerIndex) {
                $shard[] = $path;
            }
        }

        return $shard;
    }

    private function shouldShardReadyGateWarmupPaths(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_READY_GATE_SHARD_PATHS');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.ready_gate_shard_paths', '1');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'shard'], true);
    }

    private function currentWorkerId(): int
    {
        return \max(0, (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0));
    }

    private function currentWorkerCount(): int
    {
        $raw = $_SERVER['WLS_WORKER_COUNT'] ?? $_ENV['WLS_WORKER_COUNT'] ?? \getenv('WLS_WORKER_COUNT') ?: 1;

        return \max(1, (int)$raw);
    }

    private function shouldUseReadyGateDynamicDiscovery(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_READY_GATE_DISCOVERY');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_ready_gate_discovery', 'critical');
        }

        return \in_array(
            \strtolower(\trim((string)$rawFlag)),
            ['1', 'true', 'yes', 'on', 'auto', 'discovery'],
            true
        );
    }

    /**
     * @return list<string>
     */
    private function readyGateDynamicCriticalWarmupPaths(): array
    {
        return [
            '/',
            '/catalog/category/clothing',
            '/en_US/catalog/category/clothing',
            '/USD/en_US/catalog/category/clothing',
            '/zh_Hans_CN/catalog/category/clothing',
            '/CNY/zh_Hans_CN/catalog/category/clothing',
            '/product/demo-category-81-sports',
            '/en_US/product/demo-category-81-sports',
            '/product/demo-category-45-clothing',
            '/en_US/product/demo-category-45-clothing',
            '/catalog/category/clothing/sports',
            '/en_US/catalog/category/clothing/sports',
            '/USD/en_US/catalog/category/clothing/sports',
            '/zh_Hans_CN/catalog/category/clothing/sports',
            '/CNY/zh_Hans_CN/catalog/category/clothing/sports',
            '/catalog/category/clothing/women',
            '/en_US/catalog/category/clothing/women',
            '/USD/en_US/catalog/category/clothing/women',
            '/zh_Hans_CN/catalog/category/clothing/women',
            '/CNY/zh_Hans_CN/catalog/category/clothing/women',
            '/catalog/category/clothing/men',
            '/en_US/catalog/category/clothing/men',
            '/USD/en_US/catalog/category/clothing/men',
            '/zh_Hans_CN/catalog/category/clothing/men',
            '/CNY/zh_Hans_CN/catalog/category/clothing/men',
            '/USD/en_US/product/demo-category-81-sports',
            '/zh_Hans_CN/product/demo-category-81-sports',
            '/CNY/zh_Hans_CN/product/demo-category-81-sports',
            '/USD/en_US/product/demo-category-45-clothing',
            '/zh_Hans_CN/product/demo-category-45-clothing',
            '/CNY/zh_Hans_CN/product/demo-category-45-clothing',
            '/product/demo-category-126-food',
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeDynamicWarmupPathList(mixed $configured): array
    {
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!\is_array($configured)) {
            return [];
        }

        $paths = [];
        try {
            $discovery = ObjectManager::getInstance(HotPathDiscoveryService::class);
        } catch (\Throwable) {
            $discovery = null;
        }

        foreach ($configured as $path) {
            if (!\is_scalar($path)) {
                continue;
            }
            $normalized = $discovery instanceof HotPathDiscoveryService
                ? $discovery->normalizeFrontendPagePath($path)
                : $this->normalizeInternalWarmupPath((string)$path);
            if ($normalized !== null && $normalized !== '') {
                $paths[$normalized] = $normalized;
            }
        }

        return \array_values($paths);
    }

    private function isReadyGateDynamicControllerCachePath(string $path): bool
    {
        $lower = \strtolower($path);
        return $lower === '/'
            || \preg_match('#^/[a-z]{2}_[a-z0-9_]+/?$#i', $lower) === 1
            || \str_contains($lower, '/catalog/category/')
            || \str_contains($lower, '/product/')
            || \str_contains($lower, '/product/view')
            || \str_contains($lower, '/pagebuilder/');
    }

    private function assertGeneratedRouteFilesReady(): void
    {
        $routeTables = [];
        $missing = [];
        $invalid = [];

        foreach (Env::router_files_PATH as $routerFile) {
            if (!\is_file($routerFile)) {
                $missing[] = $this->formatGeneratedRoutePath($routerFile);
                continue;
            }

            try {
                $routes = include $routerFile;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Generated router file is not readable: '
                    . $this->formatGeneratedRoutePath($routerFile)
                    . ' (' . $e->getMessage() . ')',
                    0,
                    $e
                );
            }

            if (!\is_array($routes)) {
                $invalid[] = $this->formatGeneratedRoutePath($routerFile) . ' did not return an array';
                continue;
            }

            $routeTables[$routerFile] = $routes;
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Generated router files are missing before worker READY: '
                . \implode(', ', $missing)
                . '. Run php bin/w setup:upgrade --route before starting WLS.'
            );
        }

        $backendRoutes = $routeTables[Env::path_BACKEND_PC_ROUTER_FILE] ?? [];
        if (!$this->routeTableHasAnyKey($backendRoutes, ['admin', 'admin::GET'])) {
            $invalid[] = $this->formatGeneratedRoutePath(Env::path_BACKEND_PC_ROUTER_FILE)
                . ' is missing admin route';
        }
        if (!$this->routeTableHasAnyKey($backendRoutes, ['admin/login', 'admin/login::GET'])) {
            $invalid[] = $this->formatGeneratedRoutePath(Env::path_BACKEND_PC_ROUTER_FILE)
                . ' is missing admin/login route';
        }

        $frontendRoutes = $routeTables[Env::path_FRONTEND_PC_ROUTER_FILE] ?? [];
        if ($frontendRoutes === []) {
            $invalid[] = $this->formatGeneratedRoutePath(Env::path_FRONTEND_PC_ROUTER_FILE) . ' is empty';
        }

        if ($invalid !== []) {
            throw new \RuntimeException(
                'Generated router files are not ready before worker READY: '
                . \implode('; ', $invalid)
                . '. Run php bin/w setup:upgrade --route before starting WLS.'
            );
        }
    }

    /**
     * @param array<string, mixed> $routes
     * @param list<string> $keys
     */
    private function routeTableHasAnyKey(array $routes, array $keys): bool
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $routes)) {
                return true;
            }
        }

        return false;
    }

    private function formatGeneratedRoutePath(string $path): string
    {
        $normalizedBase = \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BP), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (\str_starts_with($normalizedPath, $normalizedBase)) {
            return \str_replace(DIRECTORY_SEPARATOR, '/', \substr($normalizedPath, \strlen($normalizedBase)));
        }

        return $path;
    }

    /**
     * @return array{enabled: bool, warmed: int, failed: int, hosts: list<string>, paths: list<string>, errors: list<string>, elapsed_ms: float}
     */
    private function runReadyGateBackendFirstRenderWarmup(): array
    {
        if (!$this->shouldRunReadyGateBackendFirstRenderWarmup()) {
            return $this->newBackendFirstRenderWarmupResult();
        }

        return $this->runBackendFirstRenderWarmupRequests('ready-gate');
    }

    /**
     * @return array{enabled: bool, warmed: int, failed: int, hosts: list<string>, paths: list<string>, errors: list<string>, elapsed_ms: float}
     */
    private function newBackendFirstRenderWarmupResult(): array
    {
        return [
            'enabled' => false,
            'warmed' => 0,
            'failed' => 0,
            'hosts' => [],
            'paths' => [],
            'errors' => [],
            'elapsed_ms' => 0.0,
        ];
    }

    /**
     * @return array{enabled: bool, warmed: int, failed: int, hosts: list<string>, paths: list<string>, errors: list<string>, elapsed_ms: float}
     */
    private function runBackendFirstRenderWarmupRequests(string $scope): array
    {
        $result = $this->newBackendFirstRenderWarmupResult();
        $startedAt = \microtime(true);
        $result['enabled'] = true;
        $userId = $this->resolveReadyGateBackendWarmupUserId();
        if ($userId <= 0) {
            $result['errors'][] = 'backend warmup user disabled';
            $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
            return $result;
        }

        $hosts = $this->resolveBackendWarmupHosts($scope);
        $paths = $this->resolveBackendWarmupPaths($scope);
        $result['hosts'] = $hosts;
        $result['paths'] = $paths;
        if ($hosts === [] || $paths === []) {
            $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
            return $result;
        }

        $sequence = 0;
        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                $sequence++;
                try {
                    $meta = $this->runBackendFirstRenderWarmupAttempt($host, $path, $sequence, $userId);
                    $statusCode = (int)($meta['status_code'] ?? 0);
                    $location = $this->warmupHeaderValue((array)($meta['headers'] ?? []), 'Location');
                    $bodyLength = (int)($meta['body_length'] ?? 0);
                    $cache = $this->backendWarmupControllerCacheSource((array)($meta['headers'] ?? []));
                    $store = $this->backendWarmupStoreStatus((array)($meta['headers'] ?? []), $cache);
                    if ($statusCode >= 200
                        && $statusCode < 300
                        && $location === ''
                        && $bodyLength > 0
                        && !$this->backendWarmupCacheIsReady($cache)
                        && $this->backendWarmupStoreIndicatesPopulate($store, $cache)
                    ) {
                        $sequence++;
                        SchedulerSystem::yield();
                        $meta = $this->runBackendFirstRenderWarmupAttempt($host, $path, $sequence, $userId);
                        $statusCode = (int)($meta['status_code'] ?? 0);
                        $location = $this->warmupHeaderValue((array)($meta['headers'] ?? []), 'Location');
                        $bodyLength = (int)($meta['body_length'] ?? 0);
                        $cache = $this->backendWarmupControllerCacheSource((array)($meta['headers'] ?? []));
                        $store = $this->backendWarmupStoreStatus((array)($meta['headers'] ?? []), $cache);
                    }
                    $cacheObserved = $cache !== '' || $store !== '';
                    if ($statusCode >= 200
                        && $statusCode < 300
                        && $location === ''
                        && $bodyLength > 0
                        && (!$cacheObserved || $this->backendWarmupCacheIsReady($cache))
                    ) {
                        $result['warmed']++;
                    } else {
                        $result['failed']++;
                        $error = $host . $path . ': status=' . $statusCode . ' body_length=' . $bodyLength;
                        if ($location !== '') {
                            $error .= ' location=' . $location;
                        }
                        if ($cacheObserved) {
                            $error .= ' cache=' . ($cache !== '' ? $cache : 'missing');
                            if ($store !== '') {
                                $error .= ' store=' . $store;
                            }
                        }
                        $result['errors'][] = $error;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = $host . $path . ': ' . $e->getMessage();
                }
                SchedulerSystem::yield();
            }
        }

        $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
        if ((int)$result['failed'] > 0 && \function_exists('w_log_warning')) {
            \w_log_warning('[WlsRuntime] ' . $scope . ' backend warmup incomplete: '
                . \json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function backendWarmupControllerCacheSource(array $headers): string
    {
        foreach (['X-WLS-Controller-Cache', 'X-WLS-Admin-View-Cache'] as $headerName) {
            $value = $this->warmupHeaderValue($headers, $headerName);
            if ($value !== '') {
                return \strtolower($value);
            }
        }

        return '';
    }

    private function backendWarmupCacheIsReady(string $cache): bool
    {
        $cache = \strtolower(\trim($cache));
        return $cache === 'local'
            || $cache === 'shared'
            || \str_starts_with($cache, 'local:')
            || \str_starts_with($cache, 'shared:');
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function backendWarmupStoreStatus(array $headers, string $cache = ''): string
    {
        foreach (['X-WLS-Admin-View-Cache-Full-Html', 'X-WLS-Admin-View-Cache-Store'] as $headerName) {
            $value = $this->warmupHeaderValue($headers, $headerName);
            if ($value !== '') {
                return \strtolower($value);
            }
        }

        return \strtolower(\trim($cache)) === 'stored' ? 'stored' : '';
    }

    private function backendWarmupStoreIndicatesPopulate(string $store, string $cache): bool
    {
        $store = \strtolower(\trim($store));
        $cache = \strtolower(\trim($cache));
        return $cache === 'stored'
            || $store === 'stored'
            || $store === 'ok';
    }

    private function shouldRunReadyGateBackendFirstRenderWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        // Backend first-render warmup is mandatory, but it runs after READY in
        // the deferred worker warmup fiber so cold rendering cannot block pool entry.
        return false;
    }

    private function shouldFailOpenReadyGateBackendWarmup(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_BACKEND_READY_GATE_FAIL_OPEN');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.backend_ready_gate_fail_open', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'fail_open'], true);
    }

    private function shouldRunDeferredBackendFirstRenderWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        // This warmup is part of the WLS startup contract. Do not gate it
        // behind an enable flag; only one owner worker runs it to avoid duplicate load.
        return $this->isDynamicFirstRenderWarmupOwnerWorker(
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP_OWNER_WORKER_ID',
            'wls.worker.backend_deferred_warmup_owner_worker_id',
            1
        );
    }

    private function runDeferredBackendFirstRenderWarmup(): void
    {
        $delayMs = (int)(Env::get('wls.worker.backend_deferred_warmup_delay_ms', 1500) ?: 0);
        $delayMs = \max(0, \min($delayMs, 30000));
        if ($delayMs > 0) {
            SchedulerSystem::yieldDelay($delayMs);
        }

        $result = $this->runBackendFirstRenderWarmupRequests('deferred');
        if ((int)($result['failed'] ?? 0) > 0) {
            return;
        }

        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] deferred backend first-render warmup done warmed='
                . (int)($result['warmed'] ?? 0)
                . ' elapsed_ms=' . (float)($result['elapsed_ms'] ?? 0.0));
        }
    }

    private function resolveReadyGateBackendWarmupUserId(): int
    {
        if (\class_exists(\Weline\Backend\Service\BackendWarmupContext::class)) {
            return \Weline\Backend\Service\BackendWarmupContext::resolveWarmupUserId();
        }

        $raw = \getenv('WLS_WORKER_BACKEND_WARMUP_USER_ID');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.backend_warmup_user_id', 1);
        }

        return \max(0, (int)$raw);
    }

    /**
     * @return list<string>
     */
    private function resolveReadyGateBackendWarmupHosts(): array
    {
        return $this->resolveBackendWarmupHosts('ready-gate');
    }

    /**
     * @return list<string>
     */
    private function resolveBackendWarmupHosts(string $scope): array
    {
        $hosts = [];
        $configured = $scope === 'ready-gate'
            ? Env::get('wls.worker.backend_ready_gate_warmup_hosts', null)
            : Env::get('wls.worker.backend_warmup_hosts', null);
        if ($configured !== null && $configured !== '') {
            if (\is_string($configured)) {
                $decoded = \json_decode($configured, true);
                $configured = \is_array($decoded)
                    ? $decoded
                    : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
            }
            if (\is_array($configured)) {
                foreach ($configured as $host) {
                    if (\is_scalar($host)) {
                        $hosts[] = (string)$host;
                    }
                }
            }
        }

        if ($hosts === [] && $scope === 'ready-gate') {
            foreach ($this->resolveCurrentInstanceWarmupHosts() as $host) {
                $normalizedHost = $this->normalizeInternalWarmupHost($host);
                if ($normalizedHost !== null && \str_starts_with($normalizedHost, '127.0.0.1:')) {
                    $hosts[] = $normalizedHost;
                    break;
                }
            }
        }

        if ($hosts === [] && $scope !== 'ready-gate') {
            foreach ($this->resolveCurrentInstanceWarmupHosts() as $host) {
                $hosts[] = $host;
            }

            foreach ($this->resolveDynamicFirstRenderWarmupHosts() as $host) {
                $hosts[] = $host;
            }
        }

        $normalized = [];
        foreach ($hosts === [] ? ['127.0.0.1'] : $hosts as $host) {
            $host = $this->normalizeInternalWarmupHost($host);
            if ($host === null) {
                continue;
            }
            $normalized[$host] = $host;
            if (\count($normalized) >= ($scope === 'ready-gate' ? 1 : 4)) {
                break;
            }
        }

        return \array_values($normalized) ?: ['127.0.0.1'];
    }

    /**
     * @return list<string>
     */
    private function resolveCurrentInstanceWarmupHosts(): array
    {
        $instance = (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE_NAME'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: '');
        if ($instance === '') {
            return [];
        }

        $file = \defined('BP')
            ? BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instance . '.json'
            : '';
        if ($file === '' || !\is_file($file)) {
            return [];
        }

        $data = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($data)) {
            return [];
        }

        $port = (int)($data['main_port'] ?? $data['port'] ?? 0);
        $hosts = [];
        foreach ([$data['host'] ?? null, $data['public_host'] ?? null] as $host) {
            if (!\is_scalar($host)) {
                continue;
            }
            $host = \trim((string)$host);
            if ($host === '') {
                continue;
            }
            $hosts[] = $port > 0 && !\str_contains($host, ':') ? $host . ':' . $port : $host;
        }

        if ($port > 0) {
            $hosts[] = '127.0.0.1:' . $port;
        }

        return $hosts;
    }

    /**
     * @return list<string>
     */
    private function resolveReadyGateBackendWarmupPaths(): array
    {
        return $this->shardReadyGateWarmupPaths($this->resolveBackendWarmupPaths('ready-gate'));
    }

    /**
     * @return list<string>
     */
    private function resolveBackendWarmupPaths(string $scope): array
    {
        $configured = $scope === 'ready-gate'
            ? Env::get('wls.worker.backend_ready_gate_warmup_paths', null)
            : Env::get('wls.worker.backend_warmup_paths', null);
        if ($configured === null || $configured === '') {
            $configured = $scope === 'ready-gate'
                ? ['admin/login', 'admin']
                : ['admin'];
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $backendPrefix = \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        $plainBase = '/' . \trim($backendPrefix, '/');
        $baseCandidates = [$plainBase];
        foreach ($this->resolveBackendWarmupLocalePairs() as [$currency, $locale]) {
            $localizedBase = '/' . ($backendPrefix !== '' ? $backendPrefix . '/' : '')
                . ($currency !== '' ? $currency . '/' : '')
                . ($locale !== '' ? $locale . '/' : '');
            $localizedBase = '/' . \trim($localizedBase, '/');
            $baseCandidates[$localizedBase] = $localizedBase;
        }
        $baseCandidates = \array_values($baseCandidates);

        $paths = [];
        foreach (\is_array($configured) ? $configured : [] as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$item));
            if ($path === '') {
                continue;
            }
            if (\str_contains($path, '://')) {
                $path = $this->normalizeInternalWarmupPath($path);
                if (\strlen($path) <= 2048) {
                    $paths[$path] = $path;
                }
                continue;
            }
            if ($path[0] === '/') {
                if (\strlen($path) <= 2048) {
                    $paths[$path] = $path;
                }
                continue;
            }

            foreach ($baseCandidates as $base) {
                $candidate = \rtrim($base, '/') . '/' . \ltrim($path, '/');
                if (\strlen($candidate) > 2048) {
                    continue;
                }
                $paths[$candidate] = $candidate;
                if (\count($paths) >= 8) {
                    break 2;
                }
            }
            if (\count($paths) >= 8) {
                break;
            }
        }

        return \array_values($paths);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function resolveBackendWarmupLocalePairs(): array
    {
        $pairs = [];
        $addPair = static function (mixed $currency, mixed $locale) use (&$pairs): void {
            if (!\is_scalar($currency) || !\is_scalar($locale)) {
                return;
            }
            $currency = \strtoupper(\trim((string)$currency, " /\t\r\n"));
            $locale = \trim((string)$locale, " /\t\r\n");
            if ($currency === '' || $locale === '') {
                return;
            }
            $key = $currency . '/' . $locale;
            $pairs[$key] = [$currency, $locale];
        };

        $addPair(
            Env::get('wls.worker.backend_warmup_currency', 'CNY') ?: 'CNY',
            Env::get('wls.worker.backend_warmup_locale', 'zh_Hans_CN') ?: 'zh_Hans_CN'
        );

        try {
            $addPair(State::getCurrency(), State::getLang());
        } catch (\Throwable) {
        }

        $extraPairs = Env::get('wls.worker.backend_warmup_locale_pairs', null);
        if (\is_string($extraPairs)) {
            $decoded = \json_decode($extraPairs, true);
            $extraPairs = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $extraPairs, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($extraPairs)) {
            foreach ($extraPairs as $pair) {
                if (\is_string($pair) && \str_contains($pair, '/')) {
                    [$currency, $locale] = \explode('/', $pair, 2);
                    $addPair($currency, $locale);
                    continue;
                }
                if (\is_array($pair)) {
                    $addPair($pair['currency'] ?? $pair[0] ?? '', $pair['locale'] ?? $pair['language'] ?? $pair[1] ?? '');
                }
            }
        }

        $addPair('USD', 'en_US');
        $addPair('CNY', 'zh_Hans_CN');

        return \array_slice(\array_values($pairs), 0, 4);
    }

    private function runBackendFirstRenderWarmupAttempt(string $host, string $path, int $sequence, int $userId): array
    {
        return $this->runInternalWarmupRequest(
            $host,
            $path,
            $sequence,
            'backend-first-render',
            [
                'WLS_INTERNAL_BACKEND_WARMUP' => '1',
                'WLS_INTERNAL_BACKEND_WARMUP_USER_ID' => (string)$userId,
                'WLS_FPC_BYPASS' => '1',
                'HTTP_X_WLS_FPC_BYPASS' => '1',
            ],
            [
                'User-Agent' => 'WLS-Backend-FirstRender-Warmup/1.0',
                'Accept-Encoding' => 'identity',
                'X-WLS-FPC-Bypass' => '1',
            ]
        );
    }

    /**
     * @param array{enabled: bool, warmed: int, failed: int, paths: list<string>, hosts: list<string>, errors: list<string>, samples: list<array<string, mixed>>, elapsed_ms: float} $result
     * @return array{enabled: bool, warmed: int, failed: int, paths: list<string>, hosts: list<string>, errors: list<string>, samples: list<array<string, mixed>>, elapsed_ms: float}
     */
    private function runDynamicFirstRenderWarmupInternal(int $effectiveMaxPaths, array $result, ?array $pathsOverride = null): array
    {
        $result['enabled'] = true;
        $startedAt = \microtime(true);
        if ($pathsOverride !== null) {
            $paths = \array_slice(\array_values($pathsOverride), 0, $effectiveMaxPaths);
        } else {
            try {
                $discovery = ObjectManager::getInstance(HotPathDiscoveryService::class);
                $paths = $discovery instanceof HotPathDiscoveryService
                    ? $discovery->discover($effectiveMaxPaths)
                    : ['/'];
            } catch (\Throwable $e) {
                $paths = ['/'];
                $result['errors'][] = 'path discovery failed: ' . $e->getMessage();
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[WlsRuntime] dynamic first-render path discovery failed: ' . $e->getMessage());
                }
            }
        }

        $hosts = $this->resolveDynamicFirstRenderWarmupHosts();
        $result['paths'] = $paths;
        $result['hosts'] = $hosts;
        if ($paths === [] || $hosts === []) {
            $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
            return $result;
        }

        $sequence = 0;
        $targetMs = $this->dynamicFirstRenderTargetMs();
        $maxAttempts = $this->dynamicWarmupValidationAttempts();
        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                $sequence++;
                $pathKey = $this->dynamicWarmupPathKey($host, $path);
                $ownsPathLock = false;
                try {
                    $ownsPathLock = $this->acquireDynamicWarmupPathLock($pathKey);
                    if (!$ownsPathLock) {
                        $this->waitForDynamicWarmupPathReady($pathKey);
                    }

                    $attempts = 0;
                    do {
                        $attempts++;
                        $warmupMeta = $this->runDynamicFirstRenderWarmupAttempt($host, $path, $sequence);
                        $validation = $this->validateDynamicFirstRenderWarmup($warmupMeta, $targetMs);
                        if ($validation['ok'] || $attempts >= $maxAttempts) {
                            break;
                        }
                        $sequence++;
                        SchedulerSystem::yield();
                    } while (true);
                    if (\count($result['samples']) < 8 || $path === '/') {
                        $result['samples'][] = [
                            'host' => $host,
                            'path' => $path,
                            'status' => (int)($warmupMeta['status_code'] ?? 0),
                            'cache' => $validation['cache'],
                            'store' => $this->dynamicWarmupStoreStatus($warmupMeta['headers'] ?? []),
                            'body_length' => (int)($warmupMeta['body_length'] ?? 0),
                            'formatted_http' => (bool)($warmupMeta['formatted_http'] ?? false),
                            'elapsed_ms' => (float)($warmupMeta['elapsed_ms'] ?? 0.0),
                            'target_ms' => $targetMs,
                            'attempts' => $attempts,
                            'ready' => (bool)$validation['ok'],
                            'reason' => $validation['reason'],
                        ];
                    }
                    if (!$validation['ok']) {
                        $result['failed']++;
                        $message = $host . $path . ': ' . $validation['reason'];
                        $result['errors'][] = $message;
                        $this->markDynamicWarmupPathReady($pathKey, false, $validation['reason']);
                        if (\function_exists('w_log_warning')) {
                            \w_log_warning('[WlsRuntime] dynamic first-render warmup not ready ' . $message);
                        }
                        continue;
                    }

                    $this->markDynamicWarmupPathReady($pathKey, true, $validation['reason']);
                    $result['warmed']++;
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $message = $host . $path . ': ' . $e->getMessage();
                    $result['errors'][] = $message;
                    $this->markDynamicWarmupPathReady($pathKey, false, $e->getMessage());
                    if (\function_exists('w_log_warning')) {
                        \w_log_warning('[WlsRuntime] dynamic first-render warmup failed ' . $message);
                    }
                } finally {
                    if ($ownsPathLock) {
                        $this->releaseDynamicWarmupPathLock($pathKey);
                    }
                }

                SchedulerSystem::yield();
            }
        }

        $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] dynamic first-render warmed=' . $result['warmed']
                . ' failed=' . $result['failed']
                . ' paths=' . \count($paths)
                . ' samples=' . \json_encode($result['samples'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                . ' elapsed_ms=' . $result['elapsed_ms']);
        }

        return $result;
    }

    /**
     * @return array{headers: array<string, mixed>, status_code: int, body_length: int, elapsed_ms: float}
     */
    private function runDynamicFirstRenderWarmupAttempt(string $host, string $path, int $sequence): array
    {
        return $this->runInternalWarmupRequest(
            $host,
            $path,
            $sequence,
            'dynamic-first-render',
            [
                'WLS_INTERNAL_DYNAMIC_WARMUP' => '1',
                'WLS_FPC_BYPASS' => '1',
                'HTTP_X_WLS_DYNAMIC_WARMUP' => '1',
                'HTTP_X_WLS_FPC_BYPASS' => '1',
            ],
            [
                'User-Agent' => 'WLS-Dynamic-FirstRender-Warmup/1.0',
                'Accept-Encoding' => 'identity',
                'X-WLS-Dynamic-Warmup' => '1',
                'X-WLS-FPC-Bypass' => '1',
            ]
        );
    }

    /**
     * @param array<string, mixed> $warmupMeta
     * @return array{ok: bool, reason: string, cache: string}
     */
    private function validateDynamicFirstRenderWarmup(array $warmupMeta, float $targetMs): array
    {
        $headers = \is_array($warmupMeta['headers'] ?? null) ? $warmupMeta['headers'] : [];
        $statusCode = (int)($warmupMeta['status_code'] ?? 0);
        $elapsedMs = (float)($warmupMeta['elapsed_ms'] ?? 0.0);
        $cache = $this->dynamicWarmupControllerCacheSource($headers);

        if ($statusCode < 200 || $statusCode >= 400) {
            return ['ok' => false, 'reason' => 'status=' . $statusCode, 'cache' => $cache];
        }

        $fpcStatus = \strtoupper($this->warmupHeaderValue($headers, 'X-WLS-FPC-Status')
            ?: $this->warmupHeaderValue($headers, 'X-Weline-FPC'));
        if ($fpcStatus === 'HIT') {
            return ['ok' => false, 'reason' => 'fpc=HIT', 'cache' => $cache];
        }

        if (!$this->dynamicWarmupCacheIsReady($cache)) {
            return ['ok' => false, 'reason' => 'cache=' . ($cache !== '' ? $cache : 'missing'), 'cache' => $cache];
        }

        if ($targetMs > 0.0 && $elapsedMs > $targetMs) {
            $reason = 'elapsed_ms=' . \round($elapsedMs, 2) . ' target_ms=' . \round($targetMs, 2);
            if (!$this->shouldBlockDynamicWarmupOnTargetMs()) {
                return [
                    'ok' => true,
                    'reason' => 'ready:slow ' . $reason,
                    'cache' => $cache,
                ];
            }

            return [
                'ok' => false,
                'reason' => $reason,
                'cache' => $cache,
            ];
        }

        return ['ok' => true, 'reason' => 'ready', 'cache' => $cache];
    }

    private function shouldBlockDynamicWarmupOnTargetMs(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_WARMUP_BLOCK_ON_TARGET_MS');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_warmup_block_on_target_ms', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'strict', 'block'], true);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function dynamicWarmupControllerCacheSource(array $headers): string
    {
        foreach (['X-WLS-Controller-Cache', 'X-WLS-PageBuilder-View-Cache', 'X-WLS-Category-View-Cache', 'X-WLS-Product-View-Cache'] as $headerName) {
            $value = $this->warmupHeaderValue($headers, $headerName);
            if ($value !== '') {
                return \strtolower($value);
            }
        }

        return '';
    }

    private function dynamicWarmupCacheIsReady(string $cache): bool
    {
        $cache = \strtolower(\trim($cache));
        return $cache === 'local'
            || $cache === 'shared'
            || \str_starts_with($cache, 'local:')
            || \str_starts_with($cache, 'shared:');
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function dynamicWarmupStoreStatus(array $headers): string
    {
        foreach (['X-WLS-PageBuilder-View-Cache-Store', 'X-WLS-Category-View-Cache-Store', 'X-WLS-Product-View-Cache-Store'] as $headerName) {
            $value = $this->warmupHeaderValue($headers, $headerName);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function dynamicFirstRenderTargetMs(): float
    {
        $raw = \getenv('WLS_WORKER_DYNAMIC_TARGET_MS');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.dynamic_target_ms', 70);
        }

        return \max(0.0, (float)$raw);
    }

    private function dynamicWarmupValidationAttempts(): int
    {
        $raw = \getenv('WLS_WORKER_DYNAMIC_WARMUP_ATTEMPTS');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.dynamic_warmup_attempts', 3);
        }

        return \max(1, \min(5, (int)$raw));
    }

    private function dynamicWarmupPathKey(string $host, string $path): string
    {
        return \sha1(\strtolower(\trim($host)) . '|' . $this->normalizeInternalWarmupPath($path));
    }

    private function acquireDynamicWarmupPathLock(string $pathKey): bool
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return true;
        }

        try {
            $ready = $coordinator->get(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'ready.' . $pathKey);
            if (\is_array($ready) && !empty($ready['ok'])) {
                return false;
            }

            $owner = (string)\getmypid() . ':' . (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: '0');
            return $coordinator->cas(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'lock.' . $pathKey, null, $owner, 30);
        } catch (\Throwable) {
            return true;
        }
    }

    private function waitForDynamicWarmupPathReady(string $pathKey): void
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return;
        }

        $deadline = \microtime(true) + ($this->dynamicWarmupPathWaitMs() / 1000);
        do {
            try {
                $ready = $coordinator->get(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'ready.' . $pathKey);
                if (\is_array($ready)) {
                    return;
                }
            } catch (\Throwable) {
                return;
            }

            SchedulerSystem::yield();
            \usleep(50_000);
        } while (\microtime(true) < $deadline);
    }

    private function markDynamicWarmupPathReady(string $pathKey, bool $ok, string $reason): void
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return;
        }

        try {
            $coordinator->set(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'ready.' . $pathKey, [
                'ok' => $ok,
                'reason' => $reason,
                'pid' => \getmypid(),
                'worker_id' => (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0),
                'ts' => \microtime(true),
            ], 120);
        } catch (\Throwable) {
        }
    }

    private function releaseDynamicWarmupPathLock(string $pathKey): void
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return;
        }

        try {
            $coordinator->delete(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'lock.' . $pathKey);
        } catch (\Throwable) {
        }
    }

    private function dynamicWarmupPathWaitMs(): int
    {
        $raw = \getenv('WLS_WORKER_DYNAMIC_WARMUP_PATH_WAIT_MS');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.dynamic_warmup_path_wait_ms', 1000);
        }

        return \max(100, \min(30000, (int)$raw));
    }

    private function dynamicWarmupCoordinator(): ?MemoryStateFacade
    {
        if (self::$dynamicWarmupCoordinatorResolved) {
            return self::$dynamicWarmupCoordinator;
        }
        self::$dynamicWarmupCoordinatorResolved = true;

        try {
            self::$dynamicWarmupCoordinator = new MemoryStateFacade([
                'consumer_code' => self::DYNAMIC_WARMUP_COORDINATOR_NS,
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]);
        } catch (\Throwable) {
            self::$dynamicWarmupCoordinator = null;
        }

        return self::$dynamicWarmupCoordinator;
    }

    private function shouldRunDeferredWorkerBootstrapObserverWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_DEFERRED_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_deferred_bootstrap_warmup', null);
        }

        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            return true;
        }

        $flag = \strtolower(\trim((string)$rawFlag));
        return \in_array($flag, ['1', 'true', 'yes', 'on', 'async', 'deferred'], true);
    }

    private function shouldRunDeferredWorkerBootstrapUrlMetadataWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_DEFERRED_URL_METADATA_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_deferred_url_metadata_warmup', null);
        }
        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            return false;
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'async', 'deferred'], true);
    }

    private function shouldRunDeferredDynamicFirstRenderWarmup(): bool
    {
        if (!$this->canRunDynamicFirstRenderWarmupForCurrentRole()) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_deferred_warmup_enabled', '1');
        }

        if (!\in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'async', 'deferred'], true)) {
            return false;
        }

        return $this->isDynamicFirstRenderWarmupOwnerWorker(
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID',
            'wls.worker.dynamic_deferred_warmup_owner_worker_id',
            1
        );
    }

    private function runDeferredDynamicFirstRenderWarmup(): void
    {
        $maxPaths = (int)(Env::get('wls.worker.dynamic_hot_path_max', 32) ?: 32);
        $maxPaths = \max(1, \min(128, $maxPaths));
        $result = $this->runDynamicFirstRenderWarmupInternal($maxPaths, $this->newDynamicFirstRenderWarmupResult());
        if ((int)($result['failed'] ?? 0) > 0) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WlsRuntime] deferred dynamic first-render warmup incomplete: ' . \json_encode([
                    'warmed' => (int)($result['warmed'] ?? 0),
                    'failed' => (int)($result['failed'] ?? 0),
                    'errors' => \array_slice(\is_array($result['errors'] ?? null) ? $result['errors'] : [], 0, 8),
                ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
            return;
        }

        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] deferred dynamic first-render warmup done warmed=' . (int)($result['warmed'] ?? 0)
                . ' elapsed_ms=' . (float)($result['elapsed_ms'] ?? 0.0));
        }
    }

    private function canRunDynamicFirstRenderWarmupForCurrentRole(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));

        return !\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true);
    }

    private function isDynamicFirstRenderWarmupOwnerWorker(string $envName, string $configPath, int $defaultOwnerWorkerId): bool
    {
        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);
        $rawOwner = \getenv($envName);
        if ($rawOwner === false || \trim((string)$rawOwner) === '') {
            $rawOwner = Env::get($configPath, $defaultOwnerWorkerId);
        }

        $ownerWorkerId = (int)$rawOwner;
        return $workerId <= 0 || $ownerWorkerId <= 0 || $workerId === $ownerWorkerId;
    }

    private function shouldRunDeferredFpcBuildAheadWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor'], true)) {
            return false;
        }
        if (!$this->roleCanRunDeferredFpcBuildAhead($role)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_FPC_BUILDAHEAD_ENABLED');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.fpc_buildahead_enabled', null);
        }
        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            $rawFlag = '1';
        }

        $flag = \strtolower(\trim((string)$rawFlag));
        if (!\in_array($flag, ['1', 'true', 'yes', 'on', 'async', 'deferred'], true)) {
            return false;
        }

        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);
        $ownerWorkerId = (int)(Env::get('wls.worker.fpc_buildahead_owner_worker_id', 1) ?: 1);
        if ($workerId > 0 && $ownerWorkerId > 0 && $workerId !== $ownerWorkerId) {
            return false;
        }

        return true;
    }

    private function roleCanRunDeferredFpcBuildAhead(string $role): bool
    {
        $configured = Env::get('wls.worker.fpc_buildahead_roles', null);
        if ($configured === null || $configured === '') {
            $configured = ['maintenance'];
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!\is_array($configured)) {
            return false;
        }

        $roles = [];
        foreach ($configured as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $normalized = \strtolower(\trim((string)$item));
            if ($normalized !== '') {
                $roles[$normalized] = true;
            }
        }

        return isset($roles['all']) || isset($roles[$role]);
    }

    private function shouldRunDeferredFpcProcessPullWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_FPC_PROCESS_PULL_ENABLED');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.fpc_process_pull_enabled', null);
        }
        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            return true;
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'async', 'deferred'], true);
    }

    private function runDeferredFpcBuildAheadWarmup(): void
    {
        $paths = $this->resolveFpcBuildAheadPaths();
        $hosts = $this->resolveFpcBuildAheadHosts();
        if ($paths === [] || $hosts === []) {
            return;
        }

        $built = 0;
        $failed = 0;
        $startedAt = \microtime(true);
        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                try {
                    $this->runInternalWarmupRequest($host, $path, $built + $failed + 1, 'fpc-build-ahead');
                    $built++;
                } catch (\Throwable $e) {
                    $failed++;
                    if (\function_exists('w_log_warning')) {
                        \w_log_warning('[WlsRuntime] FPC build-ahead failed ' . $host . $path . ': ' . $e->getMessage());
                    }
                }
                SchedulerSystem::yield();
            }
        }

        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] FPC build-ahead warmed=' . $built
                . ' failed=' . $failed
                . ' elapsed_ms=' . \round((\microtime(true) - $startedAt) * 1000, 2));
        }
    }

    private function roleCanRunDeferredWorkerBootstrap(string $role): bool
    {
        $configured = Env::get('wls.worker_deferred_bootstrap_roles', null);
        if ($configured === null || $configured === '') {
            $configured = ['maintenance'];
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!\is_array($configured)) {
            return false;
        }

        $roles = [];
        foreach ($configured as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $normalized = \strtolower(\trim((string)$item));
            if ($normalized !== '') {
                $roles[$normalized] = true;
            }
        }

        return isset($roles['all']) || isset($roles[$role]);
    }

    private function runDeferredFpcProcessPullWarmup(bool $ownerBuildAheadRan): void
    {
        if (!\class_exists(\Weline\Theme\Observer\WorkerBootstrapWarmup::class)) {
            return;
        }

        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);
        $defaultRounds = $ownerBuildAheadRan ? 2 : 3;
        $rounds = (int)(Env::get('wls.worker.fpc_process_pull_rounds', $defaultRounds) ?: $defaultRounds);
        $rounds = \max(1, \min($rounds, 10));
        $initialDelayMs = (int)(Env::get(
            'wls.worker.fpc_process_pull_initial_delay_ms',
            $ownerBuildAheadRan ? 0 : 1000
        ) ?: 0);
        $intervalMs = (int)(Env::get('wls.worker.fpc_process_pull_interval_ms', 5000) ?: 5000);
        $intervalMs = \max(0, \min($intervalMs, 30000));

        if ($initialDelayMs > 0) {
            SchedulerSystem::yieldDelay($initialDelayMs + \max(0, $workerId) * 125);
        }

        $startedAt = \microtime(true);
        $completedRounds = 0;
        for ($round = 1; $round <= $rounds; $round++) {
            try {
                $warmup = ObjectManager::getInstance(\Weline\Theme\Observer\WorkerBootstrapWarmup::class);
                if (\method_exists($warmup, 'warmFpcFastPathPayloadsForReady')) {
                    $warmup->warmFpcFastPathPayloadsForReady();
                    $completedRounds++;
                }
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[WlsRuntime] FPC process-pull warmup failed worker=' . $workerId
                        . ' round=' . $round . ': ' . $e->getMessage());
                }
            }

            if ($round < $rounds && $intervalMs > 0) {
                SchedulerSystem::yieldDelay($intervalMs);
            } else {
                SchedulerSystem::yield();
            }
        }

        if ($completedRounds > 0 && \function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] FPC process-pull rounds=' . $completedRounds
                . ' worker=' . $workerId
                . ' elapsed_ms=' . \round((\microtime(true) - $startedAt) * 1000, 2));
        }
    }

    private function normalizeInternalWarmupHost(mixed $host): ?string
    {
        if (!\is_scalar($host)) {
            return null;
        }

        $host = \trim((string)$host);
        if ($host === '') {
            return null;
        }

        if (\str_contains($host, '://')) {
            $parts = $this->parseWarmupUrl($host);
            if ($parts === null || !\is_string($parts['host'] ?? null) || $parts['host'] === '') {
                return null;
            }

            $authority = (string)$parts['host'];
            if (\filter_var($authority, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                $authority = '[' . $authority . ']';
            }
            if (isset($parts['port'])) {
                $authority .= ':' . (int)$parts['port'];
            }

            return $this->normalizeInternalWarmupAuthority($authority);
        }

        return $this->normalizeInternalWarmupAuthority($host);
    }

    private function normalizeInternalWarmupAuthority(string $authority): ?string
    {
        $authority = \trim($authority, " \t\n\r\0\x0B/");
        if ($authority === ''
            || $authority === '0.0.0.0'
            || $authority === '[::]'
            || \str_contains($authority, '/')
            || \str_contains($authority, '\\')
            || \preg_match('/[\r\n]/', $authority)
        ) {
            return null;
        }

        if ($authority[0] === '[') {
            if (!\preg_match('/^\[([0-9A-Fa-f:.]+)\](?::([0-9]{1,5}))?$/', $authority, $matches)) {
                return null;
            }
            if (!\filter_var($matches[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return null;
            }
            if (isset($matches[2]) && !$this->isValidTcpPort($matches[2])) {
                return null;
            }

            return '[' . $matches[1] . ']' . (isset($matches[2]) ? ':' . (int)$matches[2] : '');
        }

        if (\str_contains($authority, '[') || \str_contains($authority, ']')) {
            return null;
        }

        if (\filter_var($authority, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return '[' . $authority . ']';
        }

        $host = $authority;
        $port = null;
        if (\substr_count($authority, ':') === 1) {
            [$host, $port] = \explode(':', $authority, 2);
        } elseif (\str_contains($authority, ':')) {
            return null;
        }

        $host = \trim($host);
        if ($host === ''
            || $host === '0.0.0.0'
            || !\preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host)
        ) {
            return null;
        }
        if ($port !== null && !$this->isValidTcpPort($port)) {
            return null;
        }

        return $host . ($port !== null ? ':' . (int)$port : '');
    }

    private function normalizeInternalWarmupPath(string $path): string
    {
        $path = \str_replace(["\r", "\n", "\t"], '', \trim($path));
        if ($path === '') {
            return '/';
        }

        if (\str_contains($path, '://')) {
            $parts = $this->parseWarmupUrl($path);
            if ($parts === null) {
                throw new \InvalidArgumentException('Invalid internal warmup URL path.');
            }
            $query = \is_string($parts['query'] ?? null) && $parts['query'] !== ''
                ? '?' . $parts['query']
                : '';
            $path = (\is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/') . $query;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (\strlen($path) > 2048 || \preg_match('/\s/', $path)) {
            throw new \InvalidArgumentException('Invalid internal warmup path.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseWarmupUrl(string $url): ?array
    {
        try {
            $parts = \parse_url($url);
        } catch (\ValueError) {
            return null;
        }

        return \is_array($parts) ? $parts : null;
    }

    private function isValidTcpPort(mixed $port): bool
    {
        if (!\is_scalar($port) || !\ctype_digit((string)$port)) {
            return false;
        }

        $port = (int)$port;
        return $port > 0 && $port <= 65535;
    }

    /**
     * @return list<string>
     */
    private function resolveDynamicFirstRenderWarmupHosts(): array
    {
        $configured = Env::get('wls.worker.dynamic_hot_path_hosts', null);
        if ($configured === null || $configured === '') {
            $hosts = [];
            foreach ($this->resolveCurrentInstanceWarmupHosts() as $host) {
                $normalizedHost = $this->normalizeInternalWarmupHost($host);
                if ($normalizedHost !== null) {
                    $hosts[] = $normalizedHost;
                }
            }
            $configured = $hosts !== [] ? $hosts : ['127.0.0.1'];
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $hosts = \is_array($configured) ? $configured : ['127.0.0.1'];
        $normalized = [];
        foreach ($hosts as $host) {
            $host = $this->normalizeInternalWarmupHost($host);
            if ($host === null) {
                continue;
            }
            $normalized[$host] = $host;
            if (\count($normalized) >= 3) {
                break;
            }
        }

        return \array_values($normalized) ?: ['127.0.0.1'];
    }

    /**
     * @return list<string>
     */
    private function resolveFpcBuildAheadHosts(): array
    {
        $hosts = ['127.0.0.1'];
        $configured = Env::get('wls.worker.fpc_buildahead_hosts', null);
        if ($configured === null || $configured === '') {
            $configured = Env::get('wls.worker.fpc_warmup_hosts', []);
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($configured)) {
            foreach ($configured as $host) {
                if (\is_scalar($host)) {
                    $hosts[] = (string)$host;
                }
            }
        }

        $normalized = [];
        foreach ($hosts as $host) {
            $host = $this->normalizeInternalWarmupHost($host);
            if ($host === null) {
                continue;
            }
            $normalized[$host] = $host;
            if (\count($normalized) >= 3) {
                break;
            }
        }

        return \array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function resolveFpcBuildAheadPaths(): array
    {
        $paths = [
            '/',
            '/en_US/catalog/category/sports',
            '/USD/en_US/catalog/category/sports',
            '/zh_Hans_CN/catalog/category/sports',
            '/CNY/zh_Hans_CN/catalog/category/sports',
            '/en_US/catalog/category/men/shirts',
            '/USD/en_US/catalog/category/men/shirts',
            '/zh_Hans_CN/catalog/category/men/shirts',
            '/CNY/zh_Hans_CN/catalog/category/men/shirts',
            '/en_US/catalog/category/women',
            '/USD/en_US/catalog/category/women',
            '/zh_Hans_CN/catalog/category/women',
            '/CNY/zh_Hans_CN/catalog/category/women',
            '/en_US/catalog/category/gear',
            '/en_US/catalog/category/running-gear',
            '/USD/en_US/catalog/category/running-gear',
            '/zh_Hans_CN/catalog/category/running-gear',
            '/CNY/zh_Hans_CN/catalog/category/running-gear',
            '/en_US/product/demo-category-81-sports',
            '/en_US/product/demo-category-45-clothing',
            '/product/demo-category-81-sports',
            '/product/demo-category-45-clothing',
        ];

        try {
            if (\class_exists(\Weline\Theme\Observer\WorkerBootstrapWarmup::class)) {
                $warmup = ObjectManager::getInstance(\Weline\Theme\Observer\WorkerBootstrapWarmup::class);
                if (\method_exists($warmup, 'getFpcWarmupPaths')) {
                    $resolved = $warmup->getFpcWarmupPaths();
                    if (\is_array($resolved) && $resolved !== []) {
                        $paths = $resolved;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $configured = Env::get('wls.worker.fpc_buildahead_paths', []);
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (\is_array($configured)) {
            foreach ($configured as $path) {
                if (\is_scalar($path)) {
                    $paths[] = (string)$path;
                }
            }
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            if (\strlen($path) > 2048) {
                continue;
            }
            $normalized[$path] = $path;
        }

        $maxPaths = (int)(Env::get('wls.worker.fpc_buildahead_max_paths', 96) ?: 96);
        $maxPaths = \max(1, \min($maxPaths, 192));
        return \array_slice(\array_values($normalized), 0, $maxPaths);
    }

    /**
     * @param array<string, string> $serverOverrides
     * @param array<string, string> $requestHeaders
     * @return array{headers: array<string, mixed>, status_code: int, body_length: int, elapsed_ms: float}
     */
    private function runInternalWarmupRequest(
        string $host,
        string $path,
        int $sequence,
        string $label = 'fpc-build-ahead',
        array $serverOverrides = [],
        array $requestHeaders = []
    ): array
    {
        $startedAt = \microtime(true);
        $host = $this->normalizeInternalWarmupHost($host)
            ?? throw new \InvalidArgumentException('Invalid internal warmup host.');
        $path = $this->normalizeInternalWarmupPath($path);
        $headers = \array_merge([
            'Host' => $host,
            'User-Agent' => 'WLS-FPC-BuildAhead/1.0',
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Encoding' => 'gzip',
            'Connection' => 'close',
            'X-WLS-Internal-Request' => $label,
            'Weline-Internal-Warmup' => '1',
        ], $requestHeaders);

        $rawRequest = "GET {$path} HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $name = \preg_replace('/[^A-Za-z0-9-]/', '', (string)$name) ?? '';
            $value = \str_replace(["\r", "\n"], '', (string)$value);
            if ($name === '') {
                continue;
            }
            $rawRequest .= $name . ': ' . $value . "\r\n";
        }
        $rawRequest .= "\r\n";

        $server = \array_merge([
            'WLS_INSTANCE' => (string)($_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: ''),
            'WLS_WORKER_ID' => (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: ''),
            'WLS_PORT' => (string)($_SERVER['WLS_PORT'] ?? $_ENV['WLS_PORT'] ?? \getenv('WLS_PORT') ?: ''),
            'WLS_REQUEST_COUNT' => 'warmup-' . $sequence,
            'WLS_INTERNAL_WARMUP' => '1',
            'HTTPS' => 'on',
            'REQUEST_SCHEME' => 'https',
        ], $serverOverrides);

        $request = WlsRequest::fromRaw($rawRequest, $server);

        $result = $this->handle($request);
        $response = $request->getResponse();
        $responseHeaders = \method_exists($response, 'getHeaders') ? (array)$response->getHeaders() : [];
        $formattedResult = $this->parseFormattedWarmupResult((string)$result);
        foreach ($formattedResult['headers'] as $name => $value) {
            $responseHeaders[(string)$name] = $value;
        }
        $pendingStatus = $this->consumePendingResponseStatus();
        $pendingHeaders = $this->consumePendingHeaders();
        $this->consumePendingCookies();
        foreach ($pendingHeaders as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $responseHeaders[(string)$name] = $value;
        }
        $statusCode = (int)($pendingStatus['status_code'] ?? 0);
        if ($statusCode <= 0 && \method_exists($response, 'getStatusCode')) {
            $statusCode = (int)$response->getStatusCode();
        }
        if ($statusCode <= 0 && $formattedResult['status_code'] > 0) {
            $statusCode = $formattedResult['status_code'];
        }
        $meta = [
            'headers' => $responseHeaders,
            'status_code' => $statusCode,
            'body_length' => $formattedResult['body_length'] >= 0
                ? $formattedResult['body_length']
                : \strlen((string)$result),
            'formatted_http' => $formattedResult['status_code'] > 0,
            'elapsed_ms' => \round((\microtime(true) - $startedAt) * 1000, 2),
        ];
        unset($result, $request, $response);

        return $meta;
    }

    /**
     * @return array{headers: array<string, string>, status_code: int, body_length: int}
     */
    private function parseFormattedWarmupResult(string $result): array
    {
        if (!\str_starts_with($result, 'HTTP/')) {
            return ['headers' => [], 'status_code' => 0, 'body_length' => -1];
        }

        $headerEnd = \strpos($result, "\r\n\r\n");
        if ($headerEnd === false) {
            return ['headers' => [], 'status_code' => 0, 'body_length' => \strlen($result)];
        }

        $headerPart = \substr($result, 0, $headerEnd);
        $body = \substr($result, $headerEnd + 4);
        $lines = \preg_split('/\r\n/', $headerPart) ?: [];
        $statusCode = 0;
        $headers = [];
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                if (\preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches)) {
                    $statusCode = (int)$matches[1];
                }
                continue;
            }

            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = \trim(\substr($line, 0, $colon));
            if ($name === '') {
                continue;
            }
            $headers[$name] = \trim(\substr($line, $colon + 1));
        }

        return [
            'headers' => $headers,
            'status_code' => $statusCode,
            'body_length' => \strlen($body),
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function warmupHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (!\is_scalar($headerName) || \strcasecmp((string)$headerName, $name) !== 0) {
                continue;
            }
            if (\is_array($value)) {
                $value = \reset($value);
            }
            return \is_scalar($value) ? \trim((string)$value) : '';
        }

        return '';
    }

    private function preloadWorkerRegistries(bool $bootstrapDefaults = false): void
    {
        $this->eventManager ??= ObjectManager::getInstance(EventsManager::class);
        $this->runWorkerPreloadPhase(WorkerPreloadContext::PHASE_BOOTSTRAP);
        SchedulerSystem::yield();

        if ($this->isWorkerWarmupStepEnabled('query_provider', $bootstrapDefaults)) {
            $this->warmupStep(static function (): void {
                ObjectManager::getInstance(QueryProviderRegistry::class)->getAllDescriptors();
            }, 'query provider registry');
            SchedulerSystem::yield();
        }

        if ($this->isWorkerWarmupStepEnabled('i18n', $bootstrapDefaults)) {
            $this->warmupStep(static function (): void {
                PhraseParser::preloadWorkerDictionaries();
                I18nParser::preloadWorkerDictionaries();
            }, 'i18n dictionaries');
            SchedulerSystem::yield();
        }

        if ($this->isWorkerWarmupStepEnabled('url_metadata', $bootstrapDefaults)) {
            $this->warmupStep(static function (): void {
                Url::preloadWorkerRoutingMetadata();
            }, 'url metadata');
        }
    }

    private function isWorkerWarmupStepEnabled(string $step, bool $default): bool
    {
        $envName = 'WLS_WORKER_BOOTSTRAP_' . \strtoupper($step) . '_WARMUP';
        $rawFlag = \getenv($envName);
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_bootstrap_' . $step . '_warmup', null);
        }
        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            return $default;
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync'], true);
    }

    /**
     * @return list<string>
     */
    private function hotWorkerBootstrapEvents(): array
    {
        return [
            'Weline_Framework_Runtime::worker_bootstrap_after',
            'Weline_Framework::App::run_before',
            'Weline_Framework::App::run_after',
            'Weline_Framework::App::url_parsed_after',
            'Weline_Framework_Router::before_start',
            'Weline_Framework_Router::process_uri_before',
            'Weline_Framework_Router::route_before',
            'Weline_Framework_Router::route_after',
            'Weline_Framework_View::fetch_file',
            'Weline_Framework_Template::after_render',
            'Weline_Framework_FrontendController::init_before',
            'Weline_Framework_FrontendController::init_after',
            'Weline_Framework_Query::before_execute',
            'Weline_Framework_Query::after_execute',
        ];
    }

    /**
     * @param callable(): void $callback
     */
    private function warmupStep(callable $callback, string $label): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WlsRuntime] worker bootstrap ' . $label . ' warmup failed: ' . $e->getMessage());
            }
        }
    }

    private function dispatchWorkerBootstrapWarmup(): void
    {
        if ($this->eventManager === null) {
            return;
        }

        try {
            $data = [
                'runtime' => $this,
                'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
                'mode' => RuntimeInterface::MODE_WLS,
            ];
            $this->eventManager->dispatch('Weline_Framework_Runtime::worker_bootstrap_after', $data);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WlsRuntime] worker bootstrap warmup failed: ' . $e->getMessage());
            }
        }
    }

    private function installBackendWarmupContext(Request $request): void
    {
        if (!\class_exists(\Weline\Backend\Service\BackendWarmupContext::class)) {
            return;
        }

        if (!\Weline\Backend\Service\BackendWarmupContext::isInternalWarmupRequest($request)) {
            \Weline\Backend\Service\BackendWarmupContext::clear();
            return;
        }

        $warmupUser = \Weline\Backend\Service\BackendWarmupContext::resolveWarmupUser($request);
        if ($warmupUser === null) {
            \Weline\Backend\Service\BackendWarmupContext::clear();
            return;
        }

        \Weline\Backend\Service\BackendWarmupContext::installForUser($warmupUser);
    }
    
    /**
     * @inheritDoc
     */
    public function handle(?Request $request = null): string
    {
        // 确保已初始化
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        if ($request === null) {
            throw new \LogicException('WLS: WlsRuntime::handle() requires a Request instance for fiber-local context isolation.');
        }

        FiberOutputBuffer::ensureInstalled('request_start');

        Context::enter(Context::fromRequest($request, [
            'mode' => RuntimeInterface::MODE_WLS,
            'type' => 'request',
            'instance' => (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_SERVER['WLS_INSTANCE'] ?? ''),
            'process_tag' => (string)($_SERVER['WLS_PROCESS_TAG'] ?? 'WLS'),
        ]));

        $app = new App();

        $globalsEmulator = null;
        $requestMeta = [
            'method' => 'GET',
            'ip' => 'unknown',
            'instance' => '',
            'worker_id' => '',
            'worker_port' => '',
            'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
            'request_count' => $this->requestCount + 1,
        ];
        
        $this->requestCount++;
        
        // 性能统计：仅当请求耗时 > 1 秒时写入 var/log/wls/timing.log，便于定位 TTFB 瓶颈
        $t0 = \microtime(true);
        $timing = [
            'uri' => '',
            'run_before_ms' => 0,
            'url_parser_call_ms' => 0,
            'process_url_parse_ms' => 0,
            'url_parser_ms' => 0,
            'router_init_ms' => 0,
            'router_start_call_ms' => 0,
            'router_start_ms' => 0,
            'run_after_ms' => 0,
            'pre_telemetry_total_ms' => 0,
            'telemetry_ms' => 0,
            'dev_tool_ms' => 0,
            'reset_ms' => 0,
            'total_ms' => 0
        ];
        
        // 重置重定向计数器（每个新请求重置）
        if (!isset($_SERVER['WLS_REDIRECT_COUNT'])) {
            $_SERVER['WLS_REDIRECT_COUNT'] = 0;
            WelineEnv::set('wls.redirect_count', '0', 'WlsRuntime init');
        }
        
        // 直接写入调试日志（WlsRuntime::handle 开始）
        try {
            // WLS 状态管理：必须先按当前请求更新 $_SERVER，再初始化 RequestContext。
            // 否则 RequestContext::init() -> syncFromServer() 会读到上一请求的 $_SERVER，
            // 导致 area_router、WELINE_AREA 等错误，进而出现 502/404 或“存了上一个人的访问链接”。
            if ($request !== null) {
                ObjectManager::setInstance(Request::class, $request);
                $resolvedClass = ObjectManager::parserClass(Request::class);
                if ($resolvedClass !== Request::class) {
                    ObjectManager::setInstance($resolvedClass, $request);
                }
                $request->resetResponse();
                $requestResponse = $request->getResponse();
                ObjectManager::setInstance(Response::class, $requestResponse);
                $resolvedResponseClass = ObjectManager::parserClass(Response::class);
                if ($resolvedResponseClass !== Response::class) {
                    ObjectManager::setInstance($resolvedResponseClass, $requestResponse);
                }
                $globalsEmulator = new GlobalsEmulator();
                $globalsEmulator->emulate($request);
                $requestMeta = [
                    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? $request->getMethod() ?: 'GET'),
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    'instance' => (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_SERVER['WLS_INSTANCE'] ?? ''),
                    'worker_id' => (string)($_SERVER['WLS_WORKER_ID'] ?? ''),
                    'worker_port' => (string)($_SERVER['WLS_PORT'] ?? ''),
                    'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
                    'request_count' => $this->requestCount,
                ];
                WelineEnv::getInstance()->initFromRequest($request);
                // WLS 常驻进程必须在当前 Request/$_SERVER 已就位后创建请求 ID。
                // Template、PreparedContentStore 等请求级状态依赖 RequestContext
                // 分片；缺失时会退回到 Fiber/连接级实例，导致模板数据跨请求串味。
                RequestContext::init();
                RequestContext::set('view.template.profile', []);
                $requestMeta['request_id'] = (string)(RequestContext::getId() ?? '');
            }
            // WLS：请求入口再清一次 URL/ACL 请求级缓存，避免上一 finally 未跑全、fiber 交错或 parser 前
            // 观察者调用 getUrlPath 导致 static $url_paths / Acl 路由判定沿用旧路径，误判无权限跳 admin。
            Request::clearStaticUrlPathCache();
            if ($request !== null) {
                $request->invalidateUriCache();
            }
            if (\class_exists(\Weline\Acl\Service\AclService::class, false)) {
                \Weline\Acl\Service\AclService::resetRequestCache();
            }
            if (\class_exists(\Weline\Acl\Observer\RouteBefore::class, false)) {
                \Weline\Acl\Observer\RouteBefore::resetRequestCache();
            }
            try {
                $ref = new \ReflectionClass(\Weline\Framework\Router\Cache\ProcessUrlCache::class);
                if ($ref->hasProperty('staticCache')) {
                    $prop = $ref->getProperty('staticCache');
                    $prop->setAccessible(true);
                    $prop->setValue(null, null);
                }
            } catch (\Throwable) {
                // 忽略：模块未加载或非 WLS 路由缓存
            }
            // 常驻内存：新请求入口 OM/标签基线（与 StateManager 中对应 reset 回调对齐，供 peer Fiber 存在时 finally 可安全 omit）
            if (Runtime::isPersistent()) {
                StateManager::runWlsPersistentRequestEntryBaseline();
            }
            $_SERVER['WLS_REQUEST_COUNT'] = $this->requestCount;
            Context::current()->set('runtime.request_count', $this->requestCount);
            $app->bootstrapRequestCycle();
            if ($request !== null) {
                $this->installBackendWarmupContext($request);
            }
            $timing['uri'] = ($_SERVER['REQUEST_URI'] ?? '') ?: '/';
            WelineEnv::set('request.uri', $timing['uri'], 'WlsRuntime handle');
            WelineEnv::set('request.method', $_SERVER['REQUEST_METHOD'] ?? 'GET', 'WlsRuntime handle');
            
            // 请求日志：默认始终写入 runtime.log（由 shouldWriteRequestLog 控制），全量调试见 -log
            $isDev = \defined('DEV') && DEV;
            $isFrontend = \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
            if ($request !== null) {
                $this->logWlsRequest($request, $isFrontend);
            }
            
            WelineEnv::set('wls.request_count', (string) $this->requestCount, 'WlsRuntime handle');
            // WLS 请求入口：在 dispatchRunBefore 之前重置 URL 解析器请求级缓存。
            // StateManager::reset() 在请求结束时运行，但 run_before 观察者可能在 URL parser
            // 之前就生成 URL，此时 static 属性（parserServer/parserMatchs/parserCache 等）
            // 仍持有上一个请求的残留值，导致 URL 拼接时生成错误的 website_url 前缀。
            if (Runtime::isPersistent()) {
                \Weline\Framework\Http\Url::resetParserRequestCaches();
            }
            $traceEnabled = RequestLifecycleTrace::isEnabled();
            $t1 = \microtime(true);
            if ($traceEnabled) {
                RequestLifecycleTrace::pushCurrentParent('run_before');
            }
            $app->dispatchRunBefore();
            $t2 = \microtime(true);
            $timing['run_before_ms'] = \round(($t2 - $t1) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('run_before', $timing['run_before_ms'], 'framework');
            }
            // 如果run_before事件耗时过长，记录警告
            if ($timing['run_before_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_before event took ' . $timing['run_before_ms'] . 'ms');
            }
            
            // URL 解析
            // 注意：Url 类的静态变量重置现在由 StateManager 自动处理
            // 通过 Url::registerStateResets() 注册到 StateManager
            $urlParserStart = \microtime(true);
            $parse = $app->parseUrl();
            $urlParserEnd = \microtime(true);
            $timing['url_parser_call_ms'] = \round(($urlParserEnd - $urlParserStart) * 1000, 2);
            if (\is_array($parse) && isset($parse['_perf']) && \is_array($parse['_perf'])) {
                $timing['url_parser_perf'] = $parse['_perf'];
            }
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('url_parser::parse', $timing['url_parser_call_ms'], 'framework', 'url_parser');
            }
            
            if (\is_array($parse)) {
                $processUrlStart = \microtime(true);
                $app->applyParsedUrl($parse);
                $processUrlEnd = \microtime(true);
                $timing['process_url_parse_ms'] = \round(($processUrlEnd - $processUrlStart) * 1000, 2);
                if ($traceEnabled) {
                    RequestLifecycleTrace::recordSpan('url_parser::apply', $timing['process_url_parse_ms'], 'framework', 'url_parser');
                }
            }
            // 关键修复：Url::parser() 修改了 $_SERVER['REQUEST_URI']（去除了区域/货币/语言前缀）
            // 如果在 parser 之前有代码调用了 Request::getUri()（如 run_before 事件观察者），
            // 原始 URI 已被缓存在 Request 对象上，必须清除，否则 Router 会使用旧 URI 导致间歇性 404
            $cachedFpcResponse = RequestContext::get('wls.fpc.cached_response');
            if ($cachedFpcResponse instanceof Response) {
                RequestContext::set('wls.fpc.cached_response', null);
                $timing['fpc_hit'] = true;
                $timing['fpc_source'] = (string)(RequestContext::get('wls.fpc.hit_source', '') ?: 'unknown');
                $timing['fpc_process_items'] = (int)(RequestContext::get('wls.fpc.process_items', 0) ?: 0);
                $timing['fpc_process_bytes'] = (int)(RequestContext::get('wls.fpc.process_bytes', 0) ?: 0);
                $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
                if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                    $timing['app_apply_url'] = $appApplyUrlProfile;
                }
                $timing['pre_telemetry_total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
                $timing['total_ms'] = $timing['pre_telemetry_total_ms'];
                $cachedFpcResponse->setHeader('X-WLS-Performance-Total', (string)$timing['total_ms']);
                $cachedFpcResponse->setHeader('X-WLS-Performance-UrlParser', (string)($timing['url_parser_call_ms'] ?? 0));
                $cachedFpcResponse->setHeader('X-WLS-Performance-UrlParserApply', (string)($timing['process_url_parse_ms'] ?? 0));
                $cachedFpcResponse->setHeader('X-WLS-Performance-FPC-Hit', '1');
                $cachedFpcResponse->setHeader('X-WLS-Performance-FPC-Source', $timing['fpc_source']);
                if (!empty($this->getPerformanceConfig()['response_headers_enabled'])) {
                    $this->applyPerformanceHeaders($timing, $request);
                }
                $this->applyDynamicFirstRenderHeaders($timing, $request, true);

                return $cachedFpcResponse->toHttpString();
            }
            $t3 = \microtime(true);
            $timing['url_parser_ms'] = \round(($t3 - $t2) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('url_parser', $timing['url_parser_ms'], 'framework');
            }
            unset($parse, $cachedFpcResponse, $appApplyUrlProfile);
            $this->releaseCompletedRequestPhase('url_parser');
            
            // WLS：StateManager::reset() 会在请求结束时 removeInstance(Router\Core)，bootstrap 里缓存的
            // $this->router 会变成指向已脱离 ObjectManager 的孤儿实例；若继续对其 __init/start，
            // 会出现 request_area / is_backend 与当前 $_SERVER 不一致（误判后台、命中错误路由缓存）。
            // 每请求必须从 OM 取当前 Router 单例再初始化。
            $routerInitStart = \microtime(true);
            $router = $app->initializeRouter();
            $routerInitEnd = \microtime(true);
            $timing['router_init_ms'] = \round(($routerInitEnd - $routerInitStart) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('router_init', $timing['router_init_ms'], 'framework');
            }
            // 请求早期统一启动 Session（与 App::run 一致）；静态资源不启动，避免 Set-Cookie 与无意义 IO
            $sessionStart = \microtime(true);
            $app->startSessionIfNeeded();
            $timing['session_start_ms'] = \round((\microtime(true) - $sessionStart) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('router_start::session', $timing['session_start_ms'], 'framework', 'router_start');
            }
            // 路由处理（含控制器、视图，通常为主要耗时）；push 使控制器链路与事件挂到 router_start 下
            $routerStartStart = \microtime(true);
            if ($traceEnabled) {
                RequestLifecycleTrace::pushCurrentParent('router_start');
            }
            $result = $app->runRouter($router);
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
            }
            $routerStartEnd = \microtime(true);
            $timing['router_start_call_ms'] = \round(($routerStartEnd - $routerStartStart) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('router_start::dispatch', $timing['router_start_call_ms'], 'framework', 'router_start');
            }
            $t4 = \microtime(true);
            $timing['router_start_ms'] = \round(($t4 - $t3) * 1000, 2);
            $accountTiming = RequestContext::get('account.index.timing');
            if (\is_array($accountTiming) && $accountTiming !== []) {
                $timing['account'] = $accountTiming;
            }
            $accountSidebarContentTiming = RequestContext::get('account.sidebar_content.timing');
            if (\is_array($accountSidebarContentTiming) && $accountSidebarContentTiming !== []) {
                $timing['account_sidebar_content'] = $accountSidebarContentTiming;
            }
            $queryBinTiming = RequestContext::get('query_bin.timing');
            if (\is_array($queryBinTiming) && $queryBinTiming !== []) {
                $timing['query_bin'] = $queryBinTiming;
            }
            $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
            if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                $timing['app_apply_url'] = $appApplyUrlProfile;
            }
            $categoryViewProfile = RequestContext::get('category.view.profile');
            if (\is_array($categoryViewProfile) && $categoryViewProfile !== []) {
                $timing['category_view'] = $categoryViewProfile;
            }
            $productViewProfile = RequestContext::get('product.view.profile');
            if (\is_array($productViewProfile) && $productViewProfile !== []) {
                $timing['product_view'] = $productViewProfile;
            }
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            $routerProfile = RequestContext::get('router.start.profile');
            if (\is_array($routerProfile) && $routerProfile !== []) {
                $timing['router_profile'] = $routerProfile;
            }
            if ($traceEnabled) {
                RequestLifecycleTrace::recordSpan('router_start', $timing['router_start_ms'], 'framework');
            }
            unset(
                $router,
                $accountTiming,
                $accountSidebarContentTiming,
                $queryBinTiming,
                $appApplyUrlProfile,
                $categoryViewProfile,
                $productViewProfile,
                $templateProfile,
                $routerProfile
            );
            $this->releaseCompletedRequestPhase('router_start');
            // 触发 run_after 事件
            $runAfterStart = \microtime(true);
            if ($traceEnabled) {
                RequestLifecycleTrace::pushCurrentParent('run_after');
            }
            $result = $app->dispatchRunAfter($result);
            $runAfterEnd = \microtime(true);
            $timing['run_after_ms'] = \round(($runAfterEnd - $runAfterStart) * 1000, 2);
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('run_after', $timing['run_after_ms'], 'framework');
            }
            $t5 = \microtime(true);
            
            // 如果run_after事件耗时过长，记录警告
            if ($timing['run_after_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_after event took ' . $timing['run_after_ms'] . 'ms');
            }
            
            // 计算总耗时（用于性能监控）
            $t5_end = \microtime(true);
            $timing['total_ms'] = \round(($t5_end - $t0) * 1000, 2);
            
            // 如果总耗时超过阈值或 DEV 模式，按配置追加性能响应头
            $isDev = \defined('DEV') && DEV;
            $performanceConfig = $this->getPerformanceConfig();
            $slowThreshold = (float)($performanceConfig['slow_request_threshold_ms'] ?? 500.0);
            if (!empty($performanceConfig['response_headers_enabled']) && ($timing['total_ms'] >= $slowThreshold || $isDev)) {
                // 尝试将性能数据添加到响应头（如果响应对象可用）
                try {
                    $request = ObjectManager::getInstance(Request::class);
                    if ($request && method_exists($request, 'getResponse')) {
                        $response = $request->getResponse();
                        if ($response && method_exists($response, 'setHeader')) {
                            $response->setHeader('X-WLS-Performance-Total', (string)$timing['total_ms']);
                            $response->setHeader('X-WLS-Performance-RunBefore', (string)$timing['run_before_ms']);
                            $response->setHeader('X-WLS-Performance-UrlParser', (string)($timing['url_parser_call_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-UrlParserApply', (string)($timing['process_url_parse_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-RouterStart', (string)($timing['router_start_call_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-SessionStart', (string)($timing['session_start_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-RunAfter', (string)$timing['run_after_ms']);
                            foreach (($timing['url_parser_perf'] ?? []) as $name => $value) {
                                if (\is_scalar($name) && \is_scalar($value)) {
                                    $response->setHeader('X-WLS-Performance-UrlParser-' . (string)$name, (string)$value);
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略错误，不影响主流程
                }
            }
            
            // 检查是否是 SSE 模式（如果是，响应已经流式发送，返回空字符串）
            // 关键：必须使用“请求级”标记，不能只看 SseContext 全局静态状态。
            // WLS 多 Fiber 并发下，全局静态标记可能被其它 Fiber 的 SSE 请求短暂置为 true，
            // 若据此短路，普通 HTTP 请求会被误判为 SSE 并返回空响应。
            $this->applyDynamicFirstRenderHeaders($timing, $request, false);
            if ($this->isSseStreamHandledInCurrentRequest($request)) {
                $this->logResponseDiagnostic('sse_stream_short_circuit', $request, $timing, [
                    'result_type' => \get_debug_type($result),
                ], false);
                return '';  // SSE 响应已流式发送，不需要返回内容
            }
            
            $resultStr = $app->normalizeOutput($result);
            $resultType = \get_debug_type($result);
            unset($result);
            $this->releaseCompletedRequestPhase('normalize_output');
            $timing['pre_telemetry_total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
            $telemetryStart = \microtime(true);
            // 仅广播遥测事件，具体注入/展示由监听者模块处理（Framework 与上层模块解耦）
            $resultStr = $app->broadcastTelemetry($resultStr, $request, true);
            $timing['telemetry_ms'] = \round((\microtime(true) - $telemetryStart) * 1000, 2);
            $timing['dev_tool_ms'] = RequestLifecycleTrace::sumDurationsByName('dev_tool_panel');
            $accountTiming = RequestContext::get('account.index.timing');
            if (\is_array($accountTiming) && $accountTiming !== []) {
                $timing['account'] = $accountTiming;
            }
            $accountSidebarContentTiming = RequestContext::get('account.sidebar_content.timing');
            if (\is_array($accountSidebarContentTiming) && $accountSidebarContentTiming !== []) {
                $timing['account_sidebar_content'] = $accountSidebarContentTiming;
            }
            $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
            if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                $timing['app_apply_url'] = $appApplyUrlProfile;
            }
            $categoryViewProfile = RequestContext::get('category.view.profile');
            if (\is_array($categoryViewProfile) && $categoryViewProfile !== []) {
                $timing['category_view'] = $categoryViewProfile;
            }
            $productViewProfile = RequestContext::get('product.view.profile');
            if (\is_array($productViewProfile) && $productViewProfile !== []) {
                $timing['product_view'] = $productViewProfile;
            }
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            $routerProfile = RequestContext::get('router.start.profile');
            if (\is_array($routerProfile) && $routerProfile !== []) {
                $timing['router_profile'] = $routerProfile;
            }
            $pageBuilderRenderProfile = RequestContext::get('pagebuilder.render.profile');
            if (\is_array($pageBuilderRenderProfile) && $pageBuilderRenderProfile !== []) {
                $timing['pagebuilder_render'] = $pageBuilderRenderProfile;
            }
            $timing['total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
            if ($resultStr === '') {
                $this->logResponseDiagnostic('empty_runtime_response_body', $request, $timing, [
                    'result_type' => $resultType,
                ]);
            }
            $isDev = \defined('DEV') && DEV;
            $performanceConfig = $this->getPerformanceConfig();
            $slowThreshold = (float)($performanceConfig['slow_request_threshold_ms'] ?? 500.0);
            if (!empty($performanceConfig['response_headers_enabled']) && ($timing['total_ms'] >= $slowThreshold || $isDev)) {
                $this->applyPerformanceHeaders($timing, $request);
            }
            try {
                $request->getResponse()->setHeader('X-Weline-Request-Id', RequestLifecycleTrace::ensureRequestId());
            } catch (\Throwable) {
            }
            $this->applyDynamicFirstRenderHeaders($timing, $request, false);
            $this->clearRequestContextProfileSnapshots();

            return $resultStr;
            
        } catch (\Weline\Framework\Http\StaticFileException $staticEx) {
            // 静态文件异常：转换为文件响应
            return $staticEx->toHttpString();
            
        } catch (\Weline\Framework\Http\DownloadException $downloadEx) {
            // 下载异常：转换为文件下载响应
            return $downloadEx->toHttpString();
            
        } catch (\Weline\Framework\Http\RedirectException $redirectEx) {
            // 重定向异常：转换为重定向响应
            Session::flushRequestSessions();
            if ($this->isSseRequest($request)) {
                $redirectUrl = $redirectEx->getRedirectUrl();
                $statusCode = str_contains(strtolower($redirectUrl), 'admin/login') ? 401 : 403;
                $message = $statusCode === 401
                    ? __('SSE 会话已失效，请重新登录后重试。')
                    : __('当前账号无权限执行该 SSE 操作。');
                return $this->buildSseFailedResponse($statusCode, $message, ['redirect' => $redirectUrl]);
            }
            // 记录重定向信息
            $redirectCount = (int) ($_SERVER['WLS_REDIRECT_COUNT'] ?? 0);
            $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
            // 同步到 WelineEnv
            WelineEnv::set('wls.redirect_count', (string) $redirectCount, 'WlsRuntime catch RedirectException');
            WelineEnv::set('request.uri', $currentUri, 'WlsRuntime catch RedirectException');
            $redirectUrl = $this->withBackendLoginReturnUrl($redirectEx->getRedirectUrl(), $request);
            
            // 如果重定向次数过多，记录警告
            if ($redirectCount > 5) {
                w_log_warning("[WLS Redirect Warning] Too many redirects: {$redirectCount}, current URI: {$currentUri}, redirect to: {$redirectUrl}");
            }
            
            // 创建重定向响应，并立即把 HeaderCollector 中的 Cookie 写入响应（登录 302 必须带 Set-Cookie，不依赖 Worker 合并）
            $redirectResponse = Response::text('', $redirectEx->getStatusCode());
            $redirectResponse->setHeader('Location', $redirectUrl);
            $redirectResponse->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            $redirectResponse->setHeader('Pragma', 'no-cache');
            $redirectResponse->setHeader('Expires', '0');
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            $cookies = $hc->getCookies();
            foreach ($cookies as $cookie) {
                $redirectResponse->setCookie(
                    (string)$cookie['name'],
                    (string)$cookie['value'],
                    (int)($cookie['expire'] ?? 0),
                    (string)($cookie['path'] ?? '/'),
                    (string)($cookie['domain'] ?? ''),
                    (bool)($cookie['secure'] ?? false),
                    (bool)($cookie['httpOnly'] ?? true),
                    (string)($cookie['sameSite'] ?? 'Lax')
                );
            }
            // 诊断头：便于在浏览器中确认 302 是否带 Cookie（0=未带，排查 Session/Nginx）
            $redirectResponse->setHeader('X-WLS-Redirect-Cookies', (string)\count($cookies));
            return $redirectResponse->toHttpString(false);
            
        } catch (\Weline\Framework\Http\NoRouterException $noRouterEx) {
            // 无路由异常：转换为 404/403 响应
            if ($this->isSseRequest($request)) {
                return $this->buildSseFailedResponse(
                    $noRouterEx->getStatusCode(),
                    $noRouterEx->getErrorMessage()
                );
            }
            
            // 尝试加载错误页面模板
            $errorFile = BP . 'pub/errors/' . $noRouterEx->getStatusCode() . '.php';
            $errorContent = '';
            if (is_file($errorFile)) {
                ob_start();
                try {
                    include $errorFile;
                    $errorContent = ob_get_clean();
                } catch (\Throwable $e) {
                    ob_end_clean();
                    $errorContent = '<h1>' . $noRouterEx->getStatusCode() . ' ' . htmlspecialchars($noRouterEx->getErrorMessage()) . '</h1>';
                }
            } else {
                $errorContent = '<h1>' . $noRouterEx->getStatusCode() . ' ' . htmlspecialchars($noRouterEx->getErrorMessage()) . '</h1>';
            }
            
            // 创建错误响应
            return Response::fromContent($errorContent, $noRouterEx->getStatusCode(), 'text/html; charset=utf-8')->toHttpString(false);
            
        } catch (\Weline\Framework\Http\ResponseTerminateException $terminateEx) {
            // 通用响应终止异常：使用异常的 toHttpString() 方法
            if ($this->isSseRequest($request)) {
                $headers = $terminateEx->getHeaders();
                $contentType = strtolower((string)($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
                if (str_contains($contentType, 'text/event-stream')) {
                    return $terminateEx->toHttpString();
                }
                $code = $terminateEx->getStatusCode();
                $message = $this->extractSseErrorMessage($terminateEx);
                return $this->buildSseFailedResponse($code > 0 ? $code : 500, $message);
            }
            return $terminateEx->toHttpString();
            
        } catch (\Throwable $e) {
            // 302 等响应终止异常若落入此处（不应发生），按正常响应处理，不记错误
            if ($e instanceof \Weline\Framework\Http\ResponseTerminateException) {
                return $e->toHttpString();
            }

            // 记录错误日志（DEV 环境）
            if ($e instanceof \OverflowException && \str_contains($e->getMessage(), 'WLS output capture exceeded')) {
                $compaction = [];
                if (\class_exists(\Weline\Server\Service\WorkerResponseMemoryGuard::class, false)) {
                    try {
                        $compaction = \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                        \Weline\Server\Service\WorkerResponseMemoryGuard::requestDrainAfterResponse('fiber_output_buffer_overflow');
                    } catch (\Throwable) {
                        $compaction = [];
                    }
                }
                w_log_warning(
                    '[WlsRuntime] Fiber output capture overflow; worker will drain after response. '
                    . 'memory_usage=' . \memory_get_usage(true)
                    . ' memory_peak=' . \memory_get_peak_usage(true)
                    . ' compaction=' . (\json_encode($compaction, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}')
                    . ' message=' . $e->getMessage()
                );
            }

            $isDev = \defined('DEV') && DEV;
            if ($isDev) {
                $this->logWlsError($e);
            } else {
                w_log_error('[WlsRuntime] Request error: ' . $e->getMessage());
            }
            
            // 返回错误响应
            if ($this->isSseRequest($request)) {
                $message = (\defined('DEV') && DEV)
                    ? $e->getMessage()
                    : __('SSE 请求处理失败，请稍后重试。');
                return $this->buildSseFailedResponse(500, $message);
            }
            return $this->handleException($e);
            
        } finally {
            $t6 = \microtime(true);
            Session::flushRequestSessions();
            if (Runtime::isPersistent() && WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0
                && \defined('DEV') && DEV) {
                w_log_debug(
                    '[WlsRuntime] request ended with other suspended fibers=' . WlsConcurrency::getOtherSuspendedRequestFiberCount(),
                    [],
                    'wls'
                );
            }
            // 在重置前保存 HeaderCollector 的 Cookie/Header（Worker 构建响应时需要）
            // StateManager::reset() 会清空 HeaderCollector，必须在此之前提取
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            try {
                $hc->setHeader('X-Weline-Request-Id', RequestLifecycleTrace::ensureRequestId());
            } catch (\Throwable) {
            }
            $this->snapshotPendingResponseState($hc);
            if (RequestLifecycleTrace::isEnabled()) {
                $traceSpans = RequestLifecycleTrace::getSpansWithDbSummary();
                $traceTopLimit = $this->getRequestTraceSummaryLimit('request_trace_top_limit', 40);
                $traceDbTopLimit = $this->getRequestTraceSummaryLimit('request_trace_db_top_limit', 20);
                $timing['trace_top'] = $this->summarizeTraceSpans($traceSpans, $traceTopLimit);
                $timing['trace_db_top'] = $this->summarizeTraceSpansByCategory($traceSpans, 'db', $traceDbTopLimit);
                $timing['trace_category_totals'] = $this->summarizeTraceCategoryTotals($traceSpans);
                unset($traceSpans);
                RequestLifecycleTrace::reset();
            }
            // 确保总是重置状态（存在挂起 Fiber 时仍执行完整 reset，见 WlsConcurrency 类说明）
            $this->reset();
            FiberOutputBuffer::ensureInstalled('request_end');
            if ($globalsEmulator !== null) {
                $globalsEmulator->reset();
            }
            $t7 = \microtime(true);
            $timing['reset_ms'] = \round(($t7 - $t6) * 1000, 2);
            $timing['total_ms'] = \round(($t7 - $t0) * 1000, 2);
            // 性能监控：记录所有超过500ms的请求，或DEV模式下记录所有请求
            $isDev = \defined('DEV') && DEV;
            // 添加请求方法、IP等信息
            $timing['method'] = $requestMeta['method'] ?: 'GET';
            $timing['ip'] = $requestMeta['ip'] ?: 'unknown';
            $timing['timestamp'] = date('Y-m-d H:i:s');
            $timing['redirect_count'] = (int) ($_SERVER['WLS_REDIRECT_COUNT'] ?? 0);
            $timing['instance'] = $requestMeta['instance'];
            $timing['worker_id'] = $requestMeta['worker_id'];
            $timing['worker_port'] = $requestMeta['worker_port'];
            $timing['pid'] = $requestMeta['pid'];
            $timing['request_count'] = $requestMeta['request_count'];
            $queryBinTiming = RequestContext::get('query_bin.timing');
            if (\is_array($queryBinTiming) && $queryBinTiming !== []) {
                $timing['query_bin'] = $queryBinTiming;
            }
            $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
            if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                $timing['app_apply_url'] = $appApplyUrlProfile;
            }
            $categoryViewProfile = RequestContext::get('category.view.profile');
            if (\is_array($categoryViewProfile) && $categoryViewProfile !== []) {
                $timing['category_view'] = $categoryViewProfile;
            }
            $productViewProfile = RequestContext::get('product.view.profile');
            if (\is_array($productViewProfile) && $productViewProfile !== []) {
                $timing['product_view'] = $productViewProfile;
            }
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            // 同步到 WelineEnv
            WelineEnv::set('request.method', $timing['method'], 'WlsRuntime finally');
            WelineEnv::set('server.remote_addr', $timing['ip'], 'WlsRuntime finally');
            WelineEnv::set('wls.redirect_count', (string) $timing['redirect_count'], 'WlsRuntime finally');
            $this->recordPerformanceTiming($timing, $isDev);
            if (\class_exists(\Weline\Server\Log\WlsLogger::class, false)) {
                \Weline\Server\Log\WlsLogger::flush_(true);
            }
            Context::leave();
            unset(
                $timing,
                $requestMeta,
                $hc,
                $app,
                $request,
                $globalsEmulator,
                $resultStr,
                $resultType,
                $redirectResponse,
                $cookies,
                $cookie,
                $errorContent,
                $headers,
                $accountTiming,
                $accountSidebarContentTiming,
                $queryBinTiming,
                $appApplyUrlProfile,
                $categoryViewProfile,
                $productViewProfile,
                $templateProfile,
                $routerProfile,
                $pageBuilderRenderProfile
            );
            $this->releaseCompletedRequestPhase('request_end');
        }
    }
    
    /**
     * Drop stage-local pressure after large WLS request phases.
     */
    private function releaseCompletedRequestPhase(string $phase): void
    {
        if (!Runtime::isPersistent()
            || !\class_exists(\Weline\Server\Service\WorkerResponseMemoryGuard::class)) {
            return;
        }

        try {
            $threshold = (float)(Env::get('wls.memory_guard.phase_compact_threshold', 0.70) ?: 0.70);
            $compaction = \Weline\Server\Service\WorkerResponseMemoryGuard::compactIfPressure($threshold);
            if ($compaction !== null && \defined('DEV') && DEV && LogConfig::isVerboseWlsLog()) {
                w_log_debug(
                    '[WlsRuntime] phase memory compact phase=' . $phase
                    . ' memory_usage=' . \memory_get_usage(true)
                    . ' compaction=' . (\json_encode($compaction, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}'),
                    [],
                    'wls'
                );
            }
        } catch (\Throwable) {
            // Memory pressure cleanup must never affect request completion.
        }
    }

    private function clearRequestContextProfileSnapshots(): void
    {
        foreach ([
            'account.index.timing',
            'account.sidebar_content.timing',
            'query_bin.timing',
            'app.apply_url.profile',
            'category.view.profile',
            'product.view.profile',
            'view.template.profile',
            'router.start.profile',
            'pagebuilder.render.profile',
        ] as $key) {
            RequestContext::remove($key);
        }
    }

    /**
     * 处理 URL 解析结果
     */
    private function normalizeParsedUri(mixed $uri): string
    {
        if (\is_array($uri)) {
            $path = $uri['path'] ?? $uri['uri'] ?? $uri['REQUEST_URI'] ?? $uri['data'] ?? '';
            if (!\is_scalar($path)) {
                $path = '';
            }
            $path = (string)$path;

            $query = $uri['query'] ?? '';
            if (\is_array($query)) {
                $query = \http_build_query($query);
            }
            if (\is_scalar($query) && $query !== '') {
                $query = (string)$query;
                $path .= (\str_contains($path, '?') ? '&' : '?') . \ltrim($query, '?');
            }

            return $path;
        }

        return \is_scalar($uri) ? (string)$uri : '';
    }

    private function processUrlParse(array $parse): void
    {
        // 防御性检查：如果 parse 缺少 server 字段（如 parserMatchs 早期返回），
        // 则使用 Url::$parserServer 或当前 $_SERVER 作为基础，避免后续代码访问 null
        if (!isset($parse['server']) || !is_array($parse['server'])) {
            w_log_warning('[WlsRuntime] processUrlParse: parse[server] is missing! URL parse data may be incomplete. '
                . 'area=' . ($parse['area'] ?? '(none)') 
                . ', uri=' . ($parse['uri'] ?? '(none)')
                . ', REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '(none)')
            );
            // 回退到当前 $_SERVER（已被 GlobalsEmulator 正确初始化）
            $parse['server'] = [];
        }

        $area = $parse['area'] ?? $parse['server']['WELINE_AREA'] ?? '';
        // 同步到 WelineEnv
        WelineEnv::set('area', $area, 'WlsRuntime processUrlParse');
        $isBackendArea = ($area === 'backend' || $area === 'rest_backend');
        if (isset($parse['uri'])) {
            $uri = \Weline\Framework\Http\Url::decode_url($this->normalizeParsedUri($parse['uri']));
            // 后台/API 后台不覆盖 REQUEST_URI，保留 parser 已设置的带 /admin/ 前缀的路径，否则 Router 会拿到 pure_uri 导致 404
            if (!$isBackendArea) {
                $parse['server']['REQUEST_URI'] = $uri;
            }
            $parse['server']['QUERY_STRING'] = \Weline\Framework\Http\Url::parse_url($uri, 'query');
        }
        // 兜底防污染：WLS 多请求复用进程下，backend 场景也必须确保 REQUEST_URI 来自当前请求，
        // 否则前一个 frontend/preview 请求可能残留，导致后台路由命中错误页面（需手动刷新才恢复）。
        if (!isset($parse['server']['REQUEST_URI']) || $parse['server']['REQUEST_URI'] === '') {
            if (isset($parse['uri']) && $parse['uri'] !== '') {
                $parse['server']['REQUEST_URI'] = \Weline\Framework\Http\Url::decode_url($this->normalizeParsedUri($parse['uri']));
            } else {
                $parse['server']['REQUEST_URI'] = (string)($_SERVER['REQUEST_URI'] ?? '/');
            }
        }
        
        // 合并而非替换 $_SERVER
        foreach ($parse['server'] as $key => $value) {
            $_SERVER[$key] = $value;
        }

        // 确保 WELINE_AREA 与本次解析结果一致（防御 cache/合并遗漏导致 MessageManager、ACL 等误判区域）
        if (isset($parse['area']) && $parse['area'] !== '') {
            $_SERVER['WELINE_AREA'] = $parse['area'];
            RequestContext::area($parse['area']);

            // 诊断日志：记录 WELINE_AREA 设置（已移除临时调试代码）
        }

        // 每次请求都基于当前解析结果重建完整 URI，避免 Fiber/长连接恢复旧值后污染统一路由缓存键。
        $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? 'http');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $currentUri = $this->normalizeParsedUri($parse['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        if ($currentUri === '') {
            $currentUri = '/';
        }
        $currentUri = \Weline\Framework\Http\Url::decode_url($currentUri);
        if (!\str_starts_with($currentUri, '/')) {
            $currentUri = '/' . $currentUri;
        }
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $currentUri;
        $_SERVER['WELINE_FULL_REQUEST_URI'] = $scheme . '://' . $host . $currentUri;
        WelineEnv::set('request.uri', $currentUri, 'WlsRuntime processUrlParse');
        WelineEnv::set('origin_request_uri', $currentUri, 'WlsRuntime processUrlParse');
        WelineEnv::set('full_request_uri', $_SERVER['WELINE_FULL_REQUEST_URI'], 'WlsRuntime processUrlParse');
        
        // 设置后端标识
        $welineArea = $_SERVER['WELINE_AREA'] ?? '';
        $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'backend' || $welineArea === 'rest_backend');
        
        // 存入请求上下文
        RequestContext::area($welineArea);
        
        // 处理语言和货币
        if (!empty($parse['currency'])) {
            $_SERVER['WELINE_USER_CURRENCY'] = $parse['currency'];
            RequestContext::currency($parse['currency']);
            // 同步到 WelineEnv
            WelineEnv::set('user.currency', $parse['currency'], 'WlsRuntime processUrlParse');
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_CURRENCY'] = $_SERVER['WELINE_USER_CURRENCY'] ?? RequestContext::currency();
        }
        if (!empty($parse['language'])) {
            $_SERVER['WELINE_USER_LANG'] = $parse['language'];
            RequestContext::locale($parse['language']);
            // 同步到 WelineEnv
            WelineEnv::set('user.lang', $parse['language'], 'WlsRuntime processUrlParse');
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_LANG'] = $_SERVER['WELINE_USER_LANG'] ?? RequestContext::locale();
        }
        
        // 存储网站信息到上下文
        if (!empty($_SERVER['WELINE_WEBSITE_ID'])) {
            RequestContext::websiteId((int) $_SERVER['WELINE_WEBSITE_ID']);
        }
        
        // 标记 URL 解析已完成
        // CheckFullPageCache 在 url_parsed_after 事件中可以使用此标志判断
        $_SERVER['WELINE_URL_PARSED'] = true;
        WelineEnv::set('url_parsed', true, 'WlsRuntime processUrlParse');
        WelineEnv::getInstance()->initFromSnapshot(
            \is_array($_GET ?? null) ? $_GET : [],
            \is_array($_POST ?? null) ? $_POST : [],
            \is_array($_COOKIE ?? null) ? $_COOKIE : [],
            \is_array($_FILES ?? null) ? $_FILES : [],
            \is_array($_SERVER ?? null) ? $_SERVER : [],
        );
    }
    
    /**
     * 早期 URL 解析：从 URL 路径中快速提取语言和货币
     * 
     * 在 run_before 事件之前调用，确保事件处理器能获取正确的语言/货币。
     * 这是一个轻量级解析，不涉及网站匹配、路由识别等复杂逻辑。
     * 
     * URL 结构示例：
     * - /backendKey/USD/zh_Hans_CN/module/backend/controller
     * - /USD/zh_Hans_CN/module/controller
     * - /zh_Hans_CN/module/controller
     * 
     * 货币识别规则：3 位大写字母（如 USD、CNY、EUR）
     * 语言识别规则：xx_Xxxx_XX 格式（如 zh_Hans_CN、en_US）
     * 
     * @param string $uri 请求 URI
     */
    private function parseUrlLangCurrency(string $uri): void
    {
        if (empty($uri) || $uri === '/') {
            return;
        }
        
        // 分割 URI 路径段
        $segments = \explode('/', \trim($uri, '/'));
        if (empty($segments)) {
            return;
        }
        
        $currency = null;
        $language = null;
        
        // 检查前 4 个路径段（足够覆盖 backendKey/currency/language/... 结构）
        $checkCount = \min(4, \count($segments));
        for ($i = 0; $i < $checkCount; $i++) {
            $segment = $segments[$i];
            if (empty($segment)) {
                continue;
            }
            
            // 货币识别：3 位大写字母
            if ($currency === null && \strlen($segment) === 3 && \ctype_upper($segment)) {
                $currency = $segment;
                continue;
            }
            
            // 语言识别：xx_Xxxx_XX 或 xx_XX 格式
            // 例如：zh_Hans_CN, en_US, fr_FR, pt_BR
            if ($language === null && \strlen($segment) >= 5 && \strlen($segment) <= 11) {
                // 检查是否符合 locale 格式
                if (\preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(_[A-Z]{2})?$/', $segment)) {
                    $language = $segment;
                    continue;
                }
            }
            
            // 如果都找到了，提前退出
            if ($currency !== null && $language !== null) {
                break;
            }
        }
        
        // 设置到 $_SERVER（URL 路径中的值优先级最高）
        if ($currency !== null) {
            $_SERVER['WELINE_USER_CURRENCY'] = $currency;
            RequestContext::currency($currency);
            // 同步到 WelineEnv
            WelineEnv::set('user.currency', $currency, 'WlsRuntime parseUrlLangCurrency');
        }
        if ($language !== null) {
            $_SERVER['WELINE_USER_LANG'] = $language;
            RequestContext::locale($language);
            // 同步到 WelineEnv
            WelineEnv::set('user.lang', $language, 'WlsRuntime parseUrlLangCurrency');
        }
    }
    
    /**
     * 处理异常
     * 
     * 使用统一的 ErrorResponse 类生成错误响应，支持多语言
     * 返回格式与 FPM 模式的 JsonRenderer 一致
     */
    private function handleException(\Throwable $e): string
    {
        $statusCode = \Weline\Framework\Exception\ErrorResponse::getStatusCode($e);
        $message = $e->getMessage() ?: 'Internal Server Error';
        
        // DEV 模式下输出 HTML 格式的错误页面（前端可见）
        $isDev = \defined('DEV') && DEV;
        if ($isDev) {
            $this->logWlsError($e);
            return Response::fromContent(
                $this->formatExceptionAsHtml($e, $statusCode, $message),
                $statusCode,
                'text/html; charset=UTF-8'
            )->toHttpString(false);
        }
        
        // DEBUG 和生产模式：使用统一的 ErrorResponse 生成 JSON
        $isDebug = \defined('DEBUG') && DEBUG;
        $response = \Weline\Framework\Exception\ErrorResponse::fromException($e, $isDebug);
        
        return Response::fromContent(
            \Weline\Framework\Exception\ErrorResponse::toJson($response),
            $statusCode,
            'application/json; charset=UTF-8'
        )->toHttpString(false);
    }
    
    /**
     * 将异常格式化为 HTML 错误页面（DEV 模式）
     */
    private function formatExceptionAsHtml(\Throwable $e, int $statusCode, string $message): string
    {
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTraceAsString();
        $class = \get_class($e);
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WLS Error - ' . \htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .error-container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .error-header { background: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .error-header h1 { margin: 0; font-size: 24px; }
        .error-body { padding: 20px; }
        .error-message { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .error-message strong { color: #856404; }
        .error-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; font-family: "Courier New", monospace; font-size: 13px; }
        .error-details dt { font-weight: bold; color: #495057; margin-top: 10px; }
        .error-details dd { margin-left: 20px; color: #6c757d; }
        .error-trace { background: #212529; color: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; font-family: "Courier New", monospace; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
        .error-link { color: #007bff; text-decoration: none; }
        .error-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <h1>WLS Runtime Error</h1>
        </div>
        <div class="error-body">
            <div class="error-message">
                <strong>错误信息：</strong> ' . \htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '
            </div>
            <div class="error-details">
                <dl>
                    <dt>异常类型：</dt>
                    <dd>' . \htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '</dd>
                    <dt>状态码：</dt>
                    <dd>' . $statusCode . '</dd>
                    <dt>文件位置：</dt>
                    <dd>' . \htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ':' . $line . '</dd>
                    <dt>请求 URI：</dt>
                    <dd>' . \htmlspecialchars(\w_env('request.uri', '/'), ENT_QUOTES, 'UTF-8') . '</dd>
                    <dt>请求方法：</dt>
                    <dd>' . \htmlspecialchars(\w_env('request.method', 'GET'), ENT_QUOTES, 'UTF-8') . '</dd>
                </dl>
            </div>
            <div class="error-trace">' . \htmlspecialchars($trace, ENT_QUOTES, 'UTF-8') . '</div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * 记录 WLS 请求日志（默认写入 runtime.log，可由 wls.performance.request_log_enabled 关闭）
     *
     * @param Request $request 请求对象
     * @param bool $isFrontend 是否前端模式（保留参数供扩展）
     */
    private function logWlsRequest(Request $request, bool $isFrontend = false): void
    {
        if (!$this->shouldWriteRequestLog()) {
            return;
        }
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'type' => 'request',
            'request_uri' => $request->getUri(),
            'request_method' => $request->getMethod(),
            'request_count' => $this->requestCount,
        ];
        
        // 前端模式：输出到控制台（已在 worker.php 中输出，这里不再重复）
        // 注意：请求日志已在 worker.php 接收到请求的第一时间输出
        
        $this->appendJsonLine($this->getRuntimeLogFile(), $logEntry);
    }
    
    /**
     * 记录 WLS 错误日志（DEV 环境）
     */
    private function logWlsError(\Throwable $e): void
    {
        if (!$this->shouldWriteErrorLog()) {
            return;
        }
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'request_uri' => \w_env('request.uri', '/'),
            'request_method' => \w_env('request.method', 'GET'),
            'exception' => \get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->appendJsonLine($this->getRuntimeLogFile(), $logEntry, true);
    }

    private function applyPerformanceHeaders(array $timing, ?Request $request): void
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            if (!$request || !method_exists($request, 'getResponse')) {
                return;
            }

            $response = $request->getResponse();
            if (!$response || !method_exists($response, 'setHeader')) {
                return;
            }

            $response->setHeader('X-WLS-Performance-Total', (string)($timing['total_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RunBefore', (string)($timing['run_before_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-UrlParser', (string)($timing['url_parser_call_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-UrlParserApply', (string)($timing['process_url_parse_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RouterStart', (string)($timing['router_start_call_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-SessionStart', (string)($timing['session_start_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RunAfter', (string)($timing['run_after_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-PreTelemetryTotal', (string)($timing['pre_telemetry_total_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-Telemetry', (string)($timing['telemetry_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-DevTool', (string)($timing['dev_tool_ms'] ?? 0));
            foreach (($timing['url_parser_perf'] ?? []) as $name => $value) {
                if (\is_scalar($name) && \is_scalar($value)) {
                    $response->setHeader('X-WLS-Performance-UrlParser-' . (string)$name, (string)$value);
                }
            }
            $pageBuilderRender = $timing['pagebuilder_render'] ?? null;
            if (\is_array($pageBuilderRender)) {
                foreach (['total', 'layout_config', 'render_header', 'render_content', 'render_footer', 'finalize_output'] as $stage) {
                    $key = $stage === 'total' ? 'total_ms' : $stage . '_ms';
                    if (isset($pageBuilderRender[$key]) && \is_scalar($pageBuilderRender[$key])) {
                        $response->setHeader(
                            'X-WLS-Performance-PageBuilder-' . \str_replace('_', '-', $stage),
                            (string)$pageBuilderRender[$key]
                        );
                    }
                }
            }
        } catch (\Throwable) {
            // 蹇界暐鍝嶅簲澶村啓鍏ラ敊璇紝涓嶅奖鍝嶄富娴佺▼銆?
        }
    }

    private function applyDynamicFirstRenderHeaders(array $timing, ?Request $request, bool $fpcHit): void
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            if (!$request || !method_exists($request, 'getResponse')) {
                return;
            }

            $response = $request->getResponse();
            if (!$response || !method_exists($response, 'setHeader')) {
                return;
            }

            $response->setHeader('X-WLS-First-Render-Total-Ms', (string)($timing['total_ms'] ?? 0));
            $response->setHeader('X-WLS-Warmup-Status', $this->currentWarmupStatus());
            $response->setHeader('X-WLS-FPC-Status', $this->currentFpcStatus($response, $fpcHit));
            $controllerCache = $this->resolveControllerCacheSource($timing, $response);
            if ($controllerCache !== '') {
                $response->setHeader('X-WLS-Controller-Cache', $controllerCache);
            }
        } catch (\Throwable) {
        }
    }

    private function shouldEmitDynamicFirstRenderHeaders(?Request $request = null): bool
    {
        if ((string)($_SERVER['HTTP_X_WLS_DYNAMIC_BENCHMARK'] ?? '') === '1'
            || (string)($_SERVER['HTTP_X_WLS_DYNAMIC_WARMUP'] ?? '') === '1'
            || (string)($_SERVER['HTTP_X_WLS_FPC_BYPASS'] ?? '') === '1'
            || (string)($_SERVER['WLS_FPC_BYPASS'] ?? '') === '1'
            || (string)($_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] ?? '') === '1') {
            return true;
        }
        if ($request !== null) {
            foreach (['X-WLS-Dynamic-Benchmark', 'X-WLS-Dynamic-Warmup', 'X-WLS-FPC-Bypass'] as $headerName) {
                if ((string)($request->getHeader($headerName) ?? '') === '1') {
                    return true;
                }
            }
        }

        $rawFlag = Env::get('wls.worker.dynamic_observability_headers_enabled', '1');
        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on'], true);
    }

    private function currentWarmupStatus(): string
    {
        if ((string)($_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] ?? '') === '1') {
            return 'dynamic-warmup';
        }
        if ((string)($_SERVER['HTTP_X_WLS_DYNAMIC_BENCHMARK'] ?? '') === '1') {
            return 'dynamic-benchmark';
        }
        if ((string)($_SERVER['WLS_INTERNAL_WARMUP'] ?? '') === '1') {
            return 'internal-warmup';
        }

        return 'ready';
    }

    private function currentFpcStatus(Response $response, bool $fpcHit): string
    {
        if ($fpcHit) {
            return 'HIT';
        }
        if ((string)($_SERVER['WLS_FPC_BYPASS'] ?? '') === '1'
            || (string)($_SERVER['HTTP_X_WLS_FPC_BYPASS'] ?? '') === '1'
            || (string)($_SERVER['HTTP_X_WLS_DYNAMIC_BENCHMARK'] ?? '') === '1') {
            return 'BYPASS';
        }

        $header = $response->getHeader('X-Weline-FPC');
        if (\is_scalar($header) && \trim((string)$header) !== '') {
            return \strtoupper(\trim((string)$header));
        }

        return 'MISS';
    }

    private function resolveControllerCacheSource(array $timing, Response $response): string
    {
        foreach (['X-WLS-Category-View-Cache', 'X-WLS-Product-View-Cache', 'X-WLS-PageBuilder-View-Cache'] as $headerName) {
            $value = $response->getHeader($headerName);
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return \strtolower(\trim((string)$value));
            }
        }

        foreach (['category_view', 'product_view'] as $profileKey) {
            $profile = $timing[$profileKey] ?? null;
            if (!\is_array($profile)) {
                continue;
            }
            foreach (\array_reverse($profile) as $step) {
                if (!\is_array($step) || ($step['name'] ?? '') !== ($profileKey === 'category_view' ? 'category::runtime_cache_get' : 'product::runtime_cache_get')) {
                    continue;
                }
                $meta = \is_array($step['meta'] ?? null) ? $step['meta'] : [];
                $status = $meta['status'] ?? '';
                if (\is_scalar($status) && \trim((string)$status) !== '') {
                    return \strtolower(\trim((string)$status));
                }
            }
        }

        return '';
    }

    private function recordPerformanceTiming(array $timing, bool $isDev): void
    {
        $config = $this->getPerformanceConfig();
        $slowThreshold = (float)($config['slow_request_threshold_ms'] ?? 500.0);
        $shouldLog = ($timing['total_ms'] >= $slowThreshold) || ($isDev && !empty($config['log_all_in_dev']));
        if (!$shouldLog) {
            return;
        }

        if (!empty($config['debug_log_enabled'])) {
            $performanceSummary = sprintf(
                '[WLS Performance] URI=%s Total=%.2fms | run_before=%.2fms | url_parser=%.2fms | router_init=%.2fms | router_start=%.2fms | run_after=%.2fms | telemetry=%.2fms | dev_tool=%.2fms',
                $timing['uri'],
                $timing['total_ms'],
                $timing['run_before_ms'],
                $timing['url_parser_call_ms'] ?? 0,
                $timing['router_init_ms'] ?? 0,
                $timing['router_start_call_ms'] ?? 0,
                $timing['run_after_ms'],
                $timing['telemetry_ms'] ?? 0,
                $timing['dev_tool_ms'] ?? 0
            );
            w_log_debug($performanceSummary);
            w_log_debug('[WLS Performance Detail] ' . \json_encode($timing, \JSON_UNESCAPED_UNICODE));
        }

        // 进入本方法前已由 shouldLog 过滤；timing 文件记录慢请求与全量调试场景
        $this->appendJsonLine($this->getPerformanceLogFile(), $timing);

        if (!empty($config['analysis_log_enabled']) && $timing['total_ms'] >= 1000) {
            $analysis = [];
            if ($timing['run_before_ms'] > 200) {
                $analysis[] = "run_before事件耗时过长: {$timing['run_before_ms']}ms";
            }
            if (($timing['url_parser_call_ms'] ?? 0) > 200) {
                $analysis[] = "URL解析耗时过长: {$timing['url_parser_call_ms']}ms";
            }
            if (($timing['router_start_call_ms'] ?? 0) > 500) {
                $analysis[] = "路由处理耗时过长: {$timing['router_start_call_ms']}ms";
            }
            if ($timing['run_after_ms'] > 200) {
                $analysis[] = "run_after事件耗时过长: {$timing['run_after_ms']}ms";
            }
            if (($timing['telemetry_ms'] ?? 0) > 100) {
                $analysis[] = "telemetry闃舵鑰楁椂杩囬暱: {$timing['telemetry_ms']}ms";
            }
            if (($timing['dev_tool_ms'] ?? 0) > 50) {
                $analysis[] = "dev_tool闈㈡澘鑰楁椂杩囬暱: {$timing['dev_tool_ms']}ms";
            }
            if (!empty($analysis)) {
                w_log_debug('[WLS Performance Analysis] ' . implode('; ', $analysis));
            }
        }
    }

    private function getPerformanceConfig(): array
    {
        if ($this->performanceConfig !== null) {
            return $this->performanceConfig;
        }

        $serverConfig = Env::getInstance()->getConfig('wls') ?? [];
        $performanceConfig = \is_array($serverConfig['performance'] ?? null) ? $serverConfig['performance'] : [];
        $verbose = LogConfig::isVerboseWlsLog();
        $this->performanceConfig = \array_merge([
            'slow_request_threshold_ms' => 500,
            'response_headers_enabled' => true,
            // 以下项默认随「全量日志」(-log) 开启；未开启时仅保留慢请求 timing 落盘（见 recordPerformanceTiming）
            'file_log_enabled' => $verbose,
            'debug_log_enabled' => $verbose,
            'analysis_log_enabled' => $verbose,
            'log_all_in_dev' => $verbose,
            'request_log_enabled' => $verbose,
            'error_log_enabled' => null,
            'runtime_log_file' => 'var/log/wls/runtime.log',
            'timing_log_file' => 'var/log/wls/timing.log',
        ], $performanceConfig);

        return $this->performanceConfig;
    }

    private function getRequestTraceSummaryLimit(string $key, int $default): int
    {
        $configured = (int) Env::get('wls.debug.' . $key, $default);
        if ($configured <= 0) {
            return $default;
        }

        return \min(200, \max(1, $configured));
    }

    private function shouldWriteRequestLog(): bool
    {
        $enabled = $this->getPerformanceConfig()['request_log_enabled'];
        if ($enabled === null) {
            return true;
        }

        return (bool)$enabled;
    }

    private function shouldWriteErrorLog(): bool
    {
        $enabled = $this->getPerformanceConfig()['error_log_enabled'];
        if ($enabled === null) {
            return true;
        }

        return (bool)$enabled;
    }

    private function getRuntimeLogFile(): string
    {
        return $this->resolveLogPath((string)$this->getPerformanceConfig()['runtime_log_file']);
    }

    private function getPerformanceLogFile(): string
    {
        return $this->resolveLogPath((string)$this->getPerformanceConfig()['timing_log_file']);
    }

    /**
     * @param array<int, array{name?:string,duration_ms?:float|int,category?:string,parent?:string,db_duration_ms?:float|int,meta?:array}> $spans
     * @return array<int, array{name:string,duration_ms:float,category:string,parent:string,db_duration_ms?:float,meta?:array}>
     */
    private function summarizeTraceSpans(array $spans, int $limit = 12): array
    {
        if (empty($spans)) {
            return [];
        }

        \usort($spans, static function (array $left, array $right): int {
            return ((float)($right['duration_ms'] ?? 0)) <=> ((float)($left['duration_ms'] ?? 0));
        });

        $summary = [];
        foreach (\array_slice($spans, 0, \max(1, $limit)) as $span) {
            $item = [
                'name' => (string)($span['name'] ?? ''),
                'duration_ms' => \round((float)($span['duration_ms'] ?? 0), 2),
                'category' => (string)($span['category'] ?? 'framework'),
                'parent' => (string)($span['parent'] ?? ''),
            ];
            if (isset($span['db_duration_ms'])) {
                $item['db_duration_ms'] = \round((float)$span['db_duration_ms'], 2);
            }
            if (isset($span['meta']) && \is_array($span['meta'])) {
                $item['meta'] = $span['meta'];
            }
            $summary[] = $item;
        }

        return $summary;
    }

    /**
     * @param array<int, array{name?:string,duration_ms?:float|int,category?:string,parent?:string,db_duration_ms?:float|int,meta?:array}> $spans
     * @return array<int, array{name:string,duration_ms:float,category:string,parent:string,db_duration_ms?:float,meta?:array}>
     */
    private function summarizeTraceSpansByCategory(array $spans, string $category, int $limit = 12): array
    {
        if ($category === '') {
            return [];
        }

        return $this->summarizeTraceSpans(
            \array_values(\array_filter($spans, static fn(array $span): bool => (string)($span['category'] ?? '') === $category)),
            $limit
        );
    }

    /**
     * @param array<int, array{duration_ms?:float|int,category?:string}> $spans
     * @return array<string, float>
     */
    private function summarizeTraceCategoryTotals(array $spans): array
    {
        $totals = [];
        foreach ($spans as $span) {
            $category = (string)($span['category'] ?? 'framework');
            if ($category === '') {
                $category = 'unknown';
            }
            $totals[$category] = ($totals[$category] ?? 0.0) + (float)($span['duration_ms'] ?? 0);
        }

        \arsort($totals);
        foreach ($totals as $category => $duration) {
            $totals[$category] = \round((float)$duration, 2);
        }

        return $totals;
    }

    private function resolveLogPath(string $path): string
    {
        if ($path === '') {
            $path = 'var/log/wls/runtime.log';
        }

        if (\str_starts_with($path, '/') || \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return BP . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
    }

    private function appendJsonLine(string $logFile, array $data, bool $pretty = false): void
    {
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        $flags = \JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        @\file_put_contents($logFile, \json_encode($data, $flags) . "\n", \FILE_APPEND);
    }

    private function absorbResponseObject(Response $response): string
    {
        $requestResponse = ObjectManager::getInstance(Request::class)->getResponse();
        $requestResponse->setHttpResponseCode($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $requestResponse->setHeader($name, (string)$headerValue);
                }
            } else {
                $requestResponse->setHeader($name, (string)$value);
            }
        }

        foreach ($response->getCookies() as $cookie) {
            $requestResponse->setCookie(
                (string)$cookie['name'],
                (string)$cookie['value'],
                (int)($cookie['expire'] ?? 0),
                (string)($cookie['path'] ?? '/'),
                (string)($cookie['domain'] ?? ''),
                (bool)($cookie['secure'] ?? false),
                (bool)($cookie['httpOnly'] ?? true),
                (string)($cookie['sameSite'] ?? 'Lax')
            );
        }

        return $response->getBody();
    }

    /**
     * SSE 协议请求（EventSource）统一识别。
     *
     * 只有当 Accept 头明确以 text/event-stream 开头，或者是唯一的 Accept 类型时，才认为是 SSE 请求。
     * 避免误判：浏览器可能在 Accept 头中包含多种类型（如 text/html,text/event-stream;q=0.8），
     * 此时应优先按照 q 值最高的类型处理，而不是简单地检查是否包含 text/event-stream。
     *
     * @param Request|null $request 请求对象，如果为 null 则从 $_SERVER 读取（兜底）
     */
    private function withBackendLoginReturnUrl(string $redirectUrl, ?Request $request): string
    {
        $method = strtoupper((string)($request?->getMethod() ?: ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $redirectUrl;
        }

        $redirectPath = (string)(parse_url($redirectUrl, PHP_URL_PATH) ?: '');
        $normalizedRedirectPath = strtolower($redirectPath);
        if ($normalizedRedirectPath === ''
            || !str_ends_with($normalizedRedirectPath, '/admin/login')
        ) {
            return $redirectUrl;
        }

        $uri = (string)(
            ($request?->getServer('WELINE_ORIGIN_REQUEST_URI') ?: null)
            ?: ($request?->getServer('REQUEST_URI') ?: null)
            ?: ($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? null)
            ?: ($_SERVER['REQUEST_URI'] ?? '')
        );
        if ($uri === '') {
            return $redirectUrl;
        }
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? $request?->getServer('QUERY_STRING') ?? '');
        if ($queryString !== '' && !str_contains($uri, '?')) {
            $uri .= '?' . $queryString;
        }

        $currentPath = strtolower((string)(parse_url($uri, PHP_URL_PATH) ?: ''));
        if ($currentPath === ''
            || str_ends_with($currentPath, '/admin/login')
            || str_ends_with($currentPath, '/admin/login/post')
            || str_ends_with($currentPath, '/admin/login/logout')
        ) {
            return $redirectUrl;
        }

        $backendPrefix = substr($redirectPath, 0, -strlen('/admin/login'));
        $uriPath = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        if ($backendPrefix !== '' && $uriPath !== '' && !str_starts_with($uriPath, $backendPrefix . '/')) {
            $uri = $backendPrefix . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        }
        $uri = $this->normalizeBackendLoginReturnUri($uri);

        $scheme = $request?->isSecure() ? 'https' : 'http';
        $host = (string)(
            ($request?->getServer('HTTP_HOST') ?: null)
            ?: ($request?->getServer('SERVER_NAME') ?: null)
            ?: ($_SERVER['HTTP_HOST'] ?? null)
            ?: ($_SERVER['SERVER_NAME'] ?? 'localhost')
        );
        $returnUrl = $scheme . '://' . $host . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        $query = [
            'no_access_reason' => 'not_logged_in',
            'return_url' => $returnUrl,
        ];

        return $this->removeBackendLoginReturnParams($redirectUrl) . (str_contains($this->removeBackendLoginReturnParams($redirectUrl), '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizeBackendLoginReturnUri(string $uri): string
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return $uri;
        }

        $segments = explode('/', trim($path, '/'));
        $firstSegment = (string)($segments[0] ?? '');
        if (!isset($segments[1], $segments[2], $segments[3])
            || $firstSegment === ''
            || !$this->isBackendReturnCurrencySegment($segments[1])
            || !$this->isBackendReturnLocaleSegment($segments[2])
            || $segments[3] !== $firstSegment
        ) {
            return $uri;
        }

        array_splice($segments, 3, 1);
        $normalized = '/' . implode('/', $segments);
        $query = (string)(parse_url($uri, PHP_URL_QUERY) ?: '');
        $fragment = (string)(parse_url($uri, PHP_URL_FRAGMENT) ?: '');
        return $normalized . ($query !== '' ? '?' . $query : '') . ($fragment !== '' ? '#' . $fragment : '');
    }

    private function isBackendReturnCurrencySegment(string $segment): bool
    {
        return strlen($segment) === 3 && ctype_upper($segment);
    }

    private function isBackendReturnLocaleSegment(string $segment): bool
    {
        return (bool)preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
    }

    private function removeBackendLoginReturnParams(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }

        parse_str((string)$parts['query'], $params);
        unset($params['no_access_reason'], $params['return_url']);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $base .= $parts['path'] ?? '';
        return $query === '' ? $base : $base . '?' . $query;
    }

    private function logResponseDiagnostic(string $event, ?Request $request, array $timing = [], array $extra = [], bool $warning = true): void
    {
        $logger = $warning ? 'w_log_warning' : 'w_log_info';
        if (!\function_exists($logger)) {
            return;
        }

        $context = $extra;
        try {
            $response = $request?->getResponse();
            $router = $request?->getRouter() ?? [];
            $context += [
                'request_id' => RequestLifecycleTrace::ensureRequestId(),
                'method' => $request?->getMethod() ?? '',
                'uri' => $request?->getUri() ?: (\function_exists('w_env_request_uri') ? (string)\w_env_request_uri() : ''),
                'lang' => (string)State::getLang(),
                'lang_local' => (string)State::getLangLocal(),
                'currency' => (string)State::getCurrency(),
                'status' => $response instanceof Response ? $response->getStatusCode() : 0,
                'content_type' => $response instanceof Response ? (string)($response->getHeader('Content-Type') ?? '') : '',
                'content_length_header' => $response instanceof Response ? (string)($response->getHeader('Content-Length') ?? '') : '',
                'location' => $response instanceof Response ? (string)($response->getHeader('Location') ?? '') : '',
                'body_length' => $response instanceof Response ? \strlen((string)$response->getBody()) : 0,
                'module' => (string)($router['module'] ?? ($request?->getModuleName() ?? '')),
                'controller' => (string)($router['controller'] ?? ''),
                'action' => (string)($router['action'] ?? ''),
                'is_sse_request' => $this->isSseRequest($request),
                'sse_writer_started' => $this->isSseStreamHandledInCurrentRequest($request),
            ];
        } catch (\Throwable) {
        }

        if ($timing !== []) {
            $context['timing'] = $timing;
        }

        $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logger('[WlsRuntimeResponseDiagnostics] ' . $event . ' ' . ($payload ?: '{}'));
    }

    private function isSseRequest(?Request $request = null): bool
    {
        // 优先从 Request 对象获取 Accept 头，避免 WLS 并发下 $_SERVER 污染
        if ($request !== null) {
            $acceptHeader = $request->getHeader('Accept');
            // getHeader 可能返回 array|string|null，统一转为字符串
            if (is_array($acceptHeader)) {
                $accept = strtolower(implode(',', $acceptHeader));
            } else {
                $accept = strtolower((string)$acceptHeader);
            }
        } else {
            // 兜底：从 $_SERVER 读取（仅在 Request 对象不可用时）
            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        }

        if ($accept === '') {
            return false;
        }

        // 精确匹配：Accept 头只包含 text/event-stream（可能带参数）
        if (str_starts_with($accept, 'text/event-stream')) {
            return true;
        }

        // 如果 Accept 头包含多个类型，检查 text/event-stream 是否是第一个（优先级最高）
        // 例如：text/event-stream,*/*;q=0.8
        $parts = explode(',', $accept);
        if (count($parts) > 0) {
            $firstType = trim(explode(';', $parts[0])[0]);
            return $firstType === 'text/event-stream';
        }

        return false;
    }

    /**
     * 当前 Fiber/请求是否已经进入 SSE 流式发送。
     *
     * 判定必须是请求级：
     * 1) 当前请求上下文中，SSE Writer 已调用 start() 并打上请求级标记
     * 2) 不再强依赖 Accept: text/event-stream，因为 fetch + POST 的流式读取
     *    场景也会走 SSE Writer，但请求头未必声明 EventSource 风格的 Accept
     */
    private function isSseStreamHandledInCurrentRequest(?Request $request = null): bool
    {
        return (bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false);
    }

    /**
     * WLS 下统一将 SSE 异常转换为 failed 事件响应。
     */
    private function buildSseFailedResponse(int $statusCode, string $message, array $extra = []): string
    {
        $statusCode = $statusCode > 0 ? $statusCode : 500;
        $payload = 'event: failed' . "\n";
        $data = array_merge([
            'code' => $statusCode,
            'http_status' => $statusCode,
            'message' => $message,
        ], $extra);
        $payload .= 'data: ' . \json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // EventSource 对非 200 状态码兼容性差，可能导致 failed 事件体无法被前端读取。
        // 统一使用 200 作为传输状态，真实业务错误码放在 data.code/http_status 中。
        $response = Response::fromContent($payload, 200, 'text/event-stream; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('X-Accel-Buffering', 'no');
        return $response->toHttpString(false);
    }

    /**
     * 从通用终止异常中提取可用于 SSE 的友好错误文本。
     */
    private function extractSseErrorMessage(\Weline\Framework\Http\ResponseTerminateException $terminateEx): string
    {
        $body = trim((string)$terminateEx->getBody());
        if ($body === '') {
            return __('SSE 请求被终止。');
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $msg = (string)($decoded['msg'] ?? $decoded['message'] ?? '');
            if ($msg !== '') {
                return $msg;
            }
        }

        $plain = trim(strip_tags($body));
        if ($plain !== '') {
            return mb_substr($plain, 0, 300);
        }

        return __('SSE 请求失败。');
    }
    
    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $omitCallbacks = null;
        if (Runtime::isPersistent() && WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0) {
            $omitCallbacks = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        }
        StateManager::reset($omitCallbacks);
        
        // 重置超全局变量
        
        // 触发状态重置事件（允许其他模块清理状态）
        $eventManager = $this->eventManager;
        if (Runtime::isPersistent()) {
            try {
                $eventManager = ObjectManager::getInstance(EventsManager::class);
            } catch (\Throwable) {
            }
        }
        if ($eventManager !== null) {
            try {
                $eventManager->dispatch('Weline_Framework::Runtime::reset');
            } catch (\Throwable $e) {
                w_log_error('[WlsRuntime] Reset event error: ' . $e->getMessage());
            }
        }

        ObjectManager::clearCurrentFiberInstances();
    }
    
    /**
     * @inheritDoc
     */
    public function terminate(): void
    {
        // 完全清理状态
        StateManager::cleanup();
        
        // 触发终止事件
        if ($this->eventManager !== null) {
            try {
                $this->eventManager->dispatch('Weline_Framework::Runtime::terminate');
            } catch (\Throwable $e) {
                w_log_error('[WlsRuntime] Terminate event error: ' . $e->getMessage());
            }
        }
        
        $this->bootstrapped = false;
        $this->eventManager = null;
        $this->router = null;
    }
    
    /**
     * @inheritDoc
     */
    public function getMode(): string
    {
        return self::MODE_WLS;
    }
    
    /**
     * @inheritDoc
     */
    public function isPersistent(): bool
    {
        return true;
    }
    
    /**
     * 获取待发送的 Cookie（在 reset 前从 HeaderCollector 提取的副本）
     * 
     * Worker 在构建 HTTP 响应时调用此方法获取 Cookie 并添加 Set-Cookie 头。
     * 每次调用后清空，避免重复发送。
     * 
     * @return array
     */
    public function consumePendingCookies(): array
    {
        $state = $this->getPendingResponseState();
        $cookies = $state['cookies'];
        $state['cookies'] = [];
        $this->setPendingResponseState($state);
        return $cookies;
    }
    
    /**
     * 获取待发送的响应头（在 reset 前从 HeaderCollector 提取的副本）
     * 
     * @return array
     */
    public function consumePendingHeaders(): array
    {
        $state = $this->getPendingResponseState();
        $headers = $state['headers'];
        $state['headers'] = [];
        $this->setPendingResponseState($state);
        return $headers;
    }

    /**
     * @return array{status_code:?int, explicit:bool, sse_started:bool}
     */
    public function consumePendingResponseStatus(): array
    {
        $state = $this->getPendingResponseState();
        $status = [
            'status_code' => $state['status_code'],
            'explicit' => $state['explicit'],
            'sse_started' => $state['sse_started'],
        ];
        $state['status_code'] = null;
        $state['explicit'] = false;
        $state['sse_started'] = false;
        $this->setPendingResponseState($state);

        return $status;
    }

    protected function snapshotPendingResponseState(\Weline\Framework\Http\HeaderCollector $headerCollector): void
    {
        $headers = $headerCollector->getHeaders();
        $sseStarted = (bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false);
        if ($sseStarted) {
            // 强制覆盖：避免 HeaderCollector / 中间层先写入的 Content-Type（如 text/plain）
            // 在 Worker 合并响应头时盖掉 SSE，导致浏览器/devtools 显示为普通文档请求。
            $headers['Content-Type'] = 'text/event-stream; charset=utf-8';
        }

        $this->setPendingResponseState([
            'cookies' => $headerCollector->getCookies(),
            'headers' => $headers,
            'status_code' => $headerCollector->getStatusCode(),
            'explicit' => $headerCollector->hasExplicitStatusCode(),
            'sse_started' => $sseStarted,
        ]);
    }

    /**
     * @return array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private function getPendingResponseState(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainPendingResponseState;
        }

        if ($this->fiberPendingResponseStates === null) {
            $this->fiberPendingResponseStates = new \WeakMap();
        }

        return $this->fiberPendingResponseStates[$fiber] ?? $this->emptyPendingResponseState();
    }

    /**
     * @param array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool} $state
     */
    private function setPendingResponseState(array $state): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainPendingResponseState = $state;
            return;
        }

        if ($this->fiberPendingResponseStates === null) {
            $this->fiberPendingResponseStates = new \WeakMap();
        }

        $this->fiberPendingResponseStates[$fiber] = $state;
    }

    /**
     * @return array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private function emptyPendingResponseState(): array
    {
        return [
            'cookies' => [],
            'headers' => [],
            'status_code' => null,
            'explicit' => false,
            'sse_started' => false,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }
    
    /**
     * 获取请求计数
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
    
    /**
     * 重新加载配置（热更新使用）
     */
    /**
     * 软重载 - 仅清理运行时缓存
     * 
     * 注意：PHP 常驻内存进程无法真正重新加载已加载的类。
     * 要加载新代码，需要重启 Worker 进程（由 Master 自动完成）。
     * 此方法仅用于清理运行时状态，不会加载新的代码文件。
     */
    public function reload(): void
    {
        // 清理运行时状态
        $this->router = null;
        $this->eventManager = null;
        
        // 重新获取实例（注意：这不会加载新的类定义）
        $this->router = ObjectManager::getInstance(Router::class);
        $this->eventManager = ObjectManager::getInstance(EventsManager::class);
    }
}
