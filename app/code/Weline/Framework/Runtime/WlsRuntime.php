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
use Weline\Framework\Cache\Contract\SharedCacheStateHealthInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Context;
use Weline\Framework\Container\ContainerRuntime;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser as PhraseParser;
use Weline\Framework\Router\Core as Router;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadManager;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Service\Query\QueryProviderRegistry;
/**
 * WLS 运行时
 * 
 * 特点：
 * - 进程启动时初始化框架（只执行一次）
 * - 每个请求调用 handle()
 * - 请求结束调用 reset() 清理状态
 * - 常驻内存，高性能
 */
class WlsRuntime implements RuntimeInterface, RequestPipelineStageListenerInterface
{
    private const DYNAMIC_WARMUP_COORDINATOR_NS = 'wls_dynamic_warmup';
    private const HOMEPAGE_WARMUP_COORDINATOR_POOL_PREFIX = 'wls_homepage_warmup:';
    private const HOMEPAGE_WARMUP_OWNER_KEY = 'owner';
    private const HOMEPAGE_WARMUP_READY_KEY = 'ready';
    // POSIX keeps the tight 4.5s/6s gate. Windows x64 PHP on ARM64 can spend
    // materially longer loading a cold homepage from a shared UNC tree, so its
    // bounded 12s/15s pair still preserves budget < lease < two attempts.
    private const HOMEPAGE_WARMUP_OWNER_LEASE_SECONDS = 6;
    private const HOMEPAGE_WARMUP_WINDOWS_OWNER_LEASE_SECONDS = 15;
    private const HOMEPAGE_WARMUP_READY_BUDGET_MILLISECONDS = 4500;
    private const HOMEPAGE_WARMUP_WINDOWS_READY_BUDGET_MILLISECONDS = 12000;
    private const HOMEPAGE_WARMUP_OWNER_RECORD_TTL_SECONDS = 180;
    private const HOMEPAGE_WARMUP_RETRY_DELAY_MILLISECONDS = 25;
    private const HOMEPAGE_WARMUP_FOLLOWER_POLL_MILLISECONDS = 50;
    private const HOMEPAGE_WARMUP_READY_TTL_SECONDS = 120;

    /**
     * 是否已初始化
     */
    private bool $bootstrapped = false;

    private static ?SharedCacheStateInterface $dynamicWarmupCoordinator = null;
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

    private ?RequestPipelineInterface $requestPipeline = null;

    private array $preloadPhasesCompleted = [];

    private bool $readyGateWorkerBootstrapWarmupCompleted = false;

    /**
     * @var array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int}|null
     */
    private ?array $readyGateHomepageFpcProof = null;

    private bool $readyGateWorkerRegistryWarmupCompleted = false;

    private bool $readyGateDynamicFirstRenderWarmupCompleted = false;

    /**
     * @var array{ready:bool,host:string,path:string,status_code:int,body_length:int,elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,cache:string,reason:string}
     */
    private array $readyGateDynamicFirstRenderProof = [];

    private bool $homepageKeepWarmRunning = false;

    private float $homepageKeepWarmNextAt = 0.0;

    private float $homepageLastNaturalHitAt = 0.0;

    private float $homepageNaturalHitRescheduleNotBefore = 0.0;

    private string $homepageCacheFullUri = '';

    /**
     * @var array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}
     */
    private array $homepageCacheWarmupReceipt = [];

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

        // Cold-start fail-closed gate. Registry loading and validation happen
        // once per Worker before App bootstrap; requests only read the cached
        // compiled container and can never fall back to ObjectManager.
        ContainerRuntime::preflight();
        
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
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_bootstrap_warmup', null);
        }

        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            // READY already owns the mandatory homepage/dynamic bootstrap.
            // Keep the post-READY registry pass opt-in so a Worker never
            // repeats the same preload immediately after becoming routable.
            $rawFlag = '0';
        }

        $flag = \strtolower(\trim((string)$rawFlag));
        return \in_array($flag, ['1', 'true', 'yes', 'on', 'sync', 'async', 'deferred'], true);
    }

    /**
     * @return array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int}
     */
    public function runReadyGateWorkerBootstrapWarmup(): array
    {
        if ($this->readyGateWorkerBootstrapWarmupCompleted) {
            return $this->readyGateHomepageFpcProof
                ?? throw new \LogicException('Worker READY warmup completed without homepage FPC proof.');
        }

        $startedAt = \microtime(true);
        $backendResult = $this->newBackendFirstRenderWarmupResult();
        $dynamicResult = $this->newDynamicFirstRenderWarmupResult();
        $workerId = (int)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: 0);

        $this->logReadyGateWarmupStep('route_assert_begin', $workerId, $startedAt);
        $this->assertGeneratedRouteFilesReady();
        $this->logReadyGateWarmupStep('route_assert_done', $workerId, $startedAt);

        // Homepage process FPC is the non-optional business READY gate. It is
        // deliberately independent from optional registry/backend/dynamic
        // warmups and their fail-open switches.
        $this->readyGateHomepageFpcProof = $this->runRequiredHomepageProcessFpcReadyGate(
            $workerId,
            $startedAt
        );

        if (!$this->shouldRunReadyGateWorkerBootstrapWarmup()) {
            $this->logReadyGateWarmupStep('optional_bootstrap_skipped', $workerId, $startedAt);
            $this->logReadyGateWarmupStep('homepage_fpc_final_begin', $workerId, $startedAt);
            $this->readyGateHomepageFpcProof = $this->runRequiredHomepageProcessFpcReadyGate(
                $workerId,
                $startedAt
            );
            $this->logReadyGateWarmupStep('homepage_fpc_final_done', $workerId, $startedAt);
            $this->readyGateWorkerBootstrapWarmupCompleted = true;
            $this->scheduleNextHomepageKeepWarm();
            return $this->readyGateHomepageFpcProof;
        }

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

        $this->logReadyGateWarmupStep('dynamic_begin', $workerId, $startedAt);
        if ($this->shouldRunReadyGateDynamicFirstRenderWarmup()) {
            $dynamicResult = $this->runReadyGateDynamicFirstRenderWarmup();
            $this->readyGateDynamicFirstRenderProof = $this->buildDynamicFirstRenderReadyProof($dynamicResult);
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
        if ($this->readyGateDynamicFirstRenderProof === []) {
            $this->logReadyGateWarmupStep('dynamic_nonblocking_deferred', $workerId, $startedAt);
        }

        // Optional registry/controller warmups may reset request-local state or
        // leave the first subsequent homepage lookup at Shared L2. Rehydrate
        // and prove Process L1 only after every warmup step, so READY describes
        // the state that will actually receive public traffic.
        $this->logReadyGateWarmupStep('homepage_fpc_final_begin', $workerId, $startedAt);
        $this->readyGateHomepageFpcProof = $this->runRequiredHomepageProcessFpcReadyGate(
            $workerId,
            $startedAt
        );
        $this->logReadyGateWarmupStep('homepage_fpc_final_done', $workerId, $startedAt);

        $this->readyGateWorkerBootstrapWarmupCompleted = true;
        $this->scheduleNextHomepageKeepWarm();
        if (\function_exists('w_log_info')) {
            \w_log_info('[WlsRuntime] ready-gate bootstrap warmup done worker=' . $workerId
                . ' backend_warmed=' . (int)($backendResult['warmed'] ?? 0)
                . ' backend_failed=' . (int)($backendResult['failed'] ?? 0)
                . ' dynamic_warmed=' . (int)($dynamicResult['warmed'] ?? 0)
                . ' dynamic_failed=' . (int)($dynamicResult['failed'] ?? 0)
                . ' homepage_fpc_source=' . $this->readyGateHomepageFpcProof['source']
                . ' hosts=' . \json_encode($backendResult['hosts'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                . ' paths=' . \json_encode($backendResult['paths'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                . ' elapsed_ms=' . \round((\microtime(true) - $startedAt) * 1000, 2));
        }

        return $this->readyGateHomepageFpcProof;
    }

    /**
     * @return array{ready:bool,host:string,path:string,status_code:int,body_length:int,elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,cache:string,reason:string}
     */
    public function readyGateDynamicFirstRenderProof(): array
    {
        return $this->readyGateDynamicFirstRenderProof;
    }

    /**
     * @param array{samples?:list<array<string,mixed>>} $result
     * @return array{ready:bool,host:string,path:string,status_code:int,body_length:int,elapsed_ms:float,target_ms:float,attempts:int,fpc_status:string,cache:string,reason:string}
     */
    private function buildDynamicFirstRenderReadyProof(array $result): array
    {
        foreach ((array)($result['samples'] ?? []) as $sample) {
            if (!\is_array($sample) || (string)($sample['path'] ?? '') !== '/') {
                continue;
            }

            return [
                'ready' => (bool)($sample['ready'] ?? false),
                'host' => (string)($sample['host'] ?? ''),
                'path' => '/',
                'status_code' => (int)($sample['status'] ?? 0),
                'body_length' => (int)($sample['body_length'] ?? 0),
                'elapsed_ms' => (float)($sample['elapsed_ms'] ?? 0.0),
                'target_ms' => (float)($sample['target_ms'] ?? 0.0),
                'attempts' => (int)($sample['attempts'] ?? 0),
                'fpc_status' => \strtoupper((string)($sample['fpc_status'] ?? '')),
                'cache' => (string)($sample['cache'] ?? ''),
                'reason' => (string)($sample['reason'] ?? ''),
            ];
        }

        return [];
    }

    /**
     * @return array{hit:bool,fpc_status:string,source:string,full_uri:string,reason:string,http_status:int}
     */
    private function runRequiredHomepageProcessFpcReadyGate(int $workerId, float $startedAt): array
    {
        $this->logReadyGateWarmupStep('homepage_fpc_begin', $workerId, $startedAt);
        $host = $this->resolveCanonicalHomepageWarmupHost($this->resolveDynamicFirstRenderWarmupHosts());
        $maxAttempts = (int)Env::get('wls.worker.ready_gate_homepage_attempts', 3);
        $maxAttempts = \max(1, \min(5, $maxAttempts));
        $perAttemptBudgetNs = self::homepageWarmupReadyBudgetMilliseconds() * 1000000;
        $retryDelayBudgetNs = self::HOMEPAGE_WARMUP_RETRY_DELAY_MILLISECONDS
            * \max(0, $maxAttempts - 1)
            * 1000000;
        // Each transaction owns its independent READY deadline. The outer
        // deadline therefore covers every configured attempt instead of
        // expiring as soon as attempt one consumes its platform-specific budget.
        $deadlineNs = \hrtime(true) + ($perAttemptBudgetNs * $maxAttempts) + $retryDelayBudgetNs;
        $proof = [
            'hit' => false,
            'fpc_status' => '',
            'source' => '',
            'full_uri' => '',
            'reason' => 'homepage validation missing',
            'http_status' => 0,
        ];
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1 && \hrtime(true) >= $deadlineNs) {
                $reason = (string)($proof['reason'] ?? 'homepage validation missing')
                    . ':total-ready-budget-exhausted';
                $proof['reason'] = $reason;
                break;
            }
            $transaction = $this->runHomepageFpcWarmupTransaction($host, $attempt, true);
            $meta = \is_array($transaction['meta'] ?? null) ? $transaction['meta'] : [];
            $validation = \is_array($transaction['validation'] ?? null) ? $transaction['validation'] : [];
            $headers = \is_array($meta['headers'] ?? null) ? $meta['headers'] : [];
            $fpcStatus = \strtoupper($this->warmupHeaderValue($headers, 'X-WLS-FPC-Status')
                ?: $this->warmupHeaderValue($headers, 'X-Weline-FPC'));
            $source = \strtolower(\trim((string)($validation['cache'] ?? '')));
            $fullUri = \trim((string)($meta['full_uri'] ?? ''));
            $reason = \trim((string)($validation['reason'] ?? 'homepage validation missing'));
            $hit = (bool)($validation['ok'] ?? false)
                && $fpcStatus === 'HIT'
                && \str_starts_with($source, 'process')
                && $fullUri !== '';
            $proof = [
                'hit' => $hit,
                'fpc_status' => $fpcStatus,
                'source' => $source,
                'full_uri' => $fullUri,
                'reason' => $reason,
                'http_status' => (int)($meta['status_code'] ?? 0),
            ];
            if ($hit) {
                break;
            }
            if ($attempt >= $maxAttempts) {
                $reason .= ':attempts-exhausted=' . $maxAttempts;
                $proof['reason'] = $reason;
                break;
            }
            if (\hrtime(true) >= $deadlineNs) {
                $reason .= ':total-ready-budget-exhausted';
                $proof['reason'] = $reason;
                break;
            }
            $this->logReadyGateWarmupStep(
                'homepage_fpc_retry attempt=' . $attempt . ' status=' . $proof['http_status'] . ' reason=' . $reason,
                $workerId,
                $startedAt
            );
            SchedulerSystem::yieldDelay(self::HOMEPAGE_WARMUP_RETRY_DELAY_MILLISECONDS);
        }
        if (!$hit) {
            throw new \RuntimeException(
                'READY gate homepage process FPC proof failed worker=' . $workerId
                . ' fpc=' . ($fpcStatus !== '' ? $fpcStatus : 'missing')
                . ' source=' . ($source !== '' ? $source : 'missing')
                . ' uri=' . ($fullUri !== '' ? $fullUri : 'missing')
                . ' reason=' . $reason
            );
        }

        $this->logReadyGateWarmupStep(
            'homepage_fpc_done source=' . $source . ' status=' . $proof['http_status'],
            $workerId,
            $startedAt
        );
        return $proof;
    }

    private function shouldRunReadyGateWorkerBootstrapWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_READY_GATE_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.ready_gate_bootstrap_warmup', '1');
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

    public function shouldScheduleHomepageKeepWarm(
        int $activeRequests,
        bool $draining,
        bool $memoryPressure
    ): bool {
        if (!$this->readyGateWorkerBootstrapWarmupCompleted
            || !$this->homepageKeepWarmEnabled()
            || $this->homepageKeepWarmRunning
            || $activeRequests > 0
            || $draining
            || $memoryPressure
        ) {
            return false;
        }

        $now = \microtime(true);
        if ($this->homepageKeepWarmNextAt <= 0.0) {
            $this->scheduleNextHomepageKeepWarm($now);
            return false;
        }
        if ($now < $this->homepageKeepWarmNextAt) {
            return false;
        }
        if ($this->homepageLastNaturalHitAt > 0.0
            && ($now - $this->homepageLastNaturalHitAt) < $this->homepageKeepWarmIntervalSeconds()
        ) {
            // The natural-hit fast path updates the exact last-hit timestamp
            // but bounds schedule recomputation. If the old deadline happens
            // to mature inside that one-second window, advance it once here.
            $this->scheduleNextHomepageKeepWarm($this->homepageLastNaturalHitAt);
            return false;
        }

        return true;
    }

    public function noteHomepageNaturalHit(string $requestTarget): void
    {
        if ($requestTarget !== '' && $requestTarget !== '/' && !$this->isRootRequestUri($requestTarget)) {
            return;
        }

        $now = \microtime(true);
        $this->homepageLastNaturalHitAt = $now;
        if ($now < $this->homepageNaturalHitRescheduleNotBefore) {
            return;
        }

        // A high-QPS homepage can produce thousands of natural hits per second.
        // Keep the latest-hit timestamp exact while bounding Env/jitter schedule
        // recomputation to once per second per Worker.
        $this->homepageNaturalHitRescheduleNotBefore = $now + 1.0;
        $this->scheduleNextHomepageKeepWarm($now);
    }

    /**
     * Return the exact immutable homepage identity proven during READY.
     *
     * The public request can omit the default language/currency cookies while
     * the warmup receipt intentionally carries them. Reconstructing that
     * identity in a transport adapter creates a different FPC variant and
     * forces every otherwise-hot homepage request back through Framework.
     * Only an anonymous root request for the same scheme and host may reuse
     * the receipt; all personalized/non-root requests retain their own facts.
     *
     * @return array{full_uri:string,cookie_header:string}|null
     */
    public function resolveHomepageFastPathIdentity(
        string $requestFullUri,
        string $cookieHeader = ''
    ): ?array {
        if (\trim($cookieHeader) !== '') {
            return null;
        }

        $receipt = $this->normalizeHomepageWarmupReceipt($this->homepageCacheWarmupReceipt);
        if ($receipt === []) {
            return null;
        }

        try {
            $request = \parse_url($requestFullUri);
            $warmed = \parse_url($receipt['full_uri']);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($request) || !\is_array($warmed)) {
            return null;
        }

        $requestPath = (string)($request['path'] ?? '/');
        $warmedPath = (string)($warmed['path'] ?? '/');
        if (($requestPath === '' ? '/' : $requestPath) !== '/'
            || ($warmedPath === '' ? '/' : $warmedPath) !== '/'
            || \strtolower((string)($request['scheme'] ?? '')) !== \strtolower((string)($warmed['scheme'] ?? ''))
            || \strtolower((string)($request['host'] ?? '')) !== \strtolower((string)($warmed['host'] ?? ''))
        ) {
            return null;
        }

        return [
            'full_uri' => $receipt['full_uri'],
            'cookie_header' => $receipt['cookie_header'],
        ];
    }

    /**
     * @return array{ok:bool,reason:string,host:string,elapsed_ms:float}
     */
    public function runHomepageKeepWarmCycle(): array
    {
        if ($this->homepageKeepWarmRunning) {
            return ['ok' => false, 'reason' => 'already-running', 'host' => '', 'elapsed_ms' => 0.0];
        }

        $this->homepageKeepWarmRunning = true;
        $startedAt = \microtime(true);
        $host = ($this->homepageCacheFullUri !== ''
            ? $this->normalizeInternalWarmupHost($this->homepageCacheFullUri)
            : null)
            ?? $this->resolveCanonicalHomepageWarmupHost($this->resolveDynamicFirstRenderWarmupHosts());
        $result = ['ok' => false, 'reason' => 'not-run', 'host' => $host, 'elapsed_ms' => 0.0];
        try {
            $transaction = $this->runHomepageFpcWarmupTransaction($host, 1, false);
            $validation = \is_array($transaction['validation'] ?? null) ? $transaction['validation'] : [];
            $result['ok'] = (bool)($validation['ok'] ?? false);
            $result['reason'] = (string)($validation['reason'] ?? 'validation-missing');
        } catch (\Throwable $e) {
            $result['reason'] = $e->getMessage();
        } finally {
            $result['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
            $this->homepageKeepWarmRunning = false;
            $this->scheduleNextHomepageKeepWarm();
        }

        if (\function_exists($result['ok'] ? 'w_log_info' : 'w_log_warning')) {
            $message = '[WlsRuntime] homepage keep-warm ' . ($result['ok'] ? 'done' : 'degraded')
                . ' worker=' . $this->currentWorkerId()
                . ' host=' . $host
                . ' reason=' . $result['reason']
                . ' elapsed_ms=' . $result['elapsed_ms'];
            $result['ok'] ? \w_log_info($message) : \w_log_warning($message);
        }

        return $result;
    }

    private function homepageKeepWarmEnabled(): bool
    {
        $raw = \getenv('WLS_WORKER_HOMEPAGE_KEEP_WARM_ENABLED');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.homepage_keep_warm_enabled', '1');
        }

        return \in_array(\strtolower(\trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function homepageKeepWarmIntervalSeconds(): int
    {
        $raw = \getenv('WLS_WORKER_HOMEPAGE_KEEP_WARM_INTERVAL_SEC');
        if ($raw === false || \trim((string)$raw) === '') {
            $raw = Env::get('wls.worker.homepage_keep_warm_interval_sec', 300);
        }

        return \max(30, \min(3600, (int)$raw));
    }

    private function scheduleNextHomepageKeepWarm(?float $from = null): void
    {
        $interval = $this->homepageKeepWarmIntervalSeconds();
        $maxJitter = \max(0, \min(30, (int)\floor($interval / 4)));
        $workerId = \max(1, $this->currentWorkerId());
        $jitter = $maxJitter > 0 ? (($workerId * 7919) % ($maxJitter * 1000 + 1)) / 1000 : 0.0;
        $this->homepageKeepWarmNextAt = ($from ?? \microtime(true)) + $interval + $jitter;
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
            // Dynamic first-render is valuable warmup, but it must not block
            // worker admission by default. The READY gate is the process-local
            // homepage FPC proof; strict deployments can opt in explicitly.
            $rawFlag = Env::get('wls.worker.dynamic_ready_gate_enabled', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'sync', 'ready_gate'], true);
    }

    private function shouldFailOpenReadyGateDynamicWarmup(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_READY_GATE_FAIL_OPEN');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.dynamic_ready_gate_fail_open', '0');
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
            $raw = Env::get('wls.worker.dynamic_ready_gate_max', 1);
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
            $paths = $this->wlsRuntimeAdapter()?->discoverHotPaths(\max($maxPaths * 4, 16)) ?? [];
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
        $common = [];
        $shard = [];
        foreach (\array_values($paths) as $index => $path) {
            if ($index === 0 && $path === '/') {
                $common[] = $path;
                continue;
            }
            if (($index % $workerCount) === $workerIndex) {
                $shard[] = $path;
            }
        }

        return \array_values(\array_unique([...$common, ...$shard]));
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
        // Framework only owns the universal homepage contract. Business
        // Modules publish their own business paths through the runtime adapter
        // or explicit instance configuration.
        return ['/'];
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
        $adapter = $this->wlsRuntimeAdapter();

        foreach ($configured as $path) {
            if (!\is_scalar($path)) {
                continue;
            }
            $normalized = $adapter !== null
                ? $adapter->normalizeFrontendPagePath($path)
                : $this->normalizeInternalWarmupPath((string)$path);
            if ($normalized !== null && $normalized !== '') {
                $paths[$normalized] = $normalized;
            }
        }

        return \array_values($paths);
    }

    private function isReadyGateDynamicControllerCachePath(string $path): bool
    {
        // Hot-path discovery belongs to the runtime provider. Framework only
        // verifies that the provider returned a valid frontend page path; it
        // must not know module-owned route conventions.
        return $this->normalizeDynamicWarmupPathList([$path]) !== [];
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
        return \strtolower($this->warmupHeaderValue($headers, 'X-WLS-Controller-Cache'));
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
        foreach (['X-WLS-Controller-Cache-Full-Html', 'X-WLS-Controller-Cache-Store'] as $headerName) {
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

        $rawFlag = \getenv('WLS_WORKER_BACKEND_DEFERRED_WARMUP_ENABLED');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = \getenv('WLS_WORKER_BACKEND_DEFERRED_WARMUP');
        }
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker.backend_deferred_warmup_enabled', '1');
        }
        if (!\in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'async', 'deferred'], true)) {
            return false;
        }

        return $this->isDynamicFirstRenderWarmupOwnerWorker(
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP_OWNER_WORKER_ID',
            'wls.worker.backend_deferred_warmup_owner_worker_id',
            0
        );
    }

    private function runDeferredBackendFirstRenderWarmup(): void
    {
        $delayMs = (int)(Env::get('wls.worker.backend_deferred_warmup_delay_ms', 250) ?: 0);
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
        $provider = $this->runtimeProvider(BackendWarmupProviderInterface::class);
        if ($provider instanceof BackendWarmupProviderInterface) {
            return $provider->resolveWarmupUserId();
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
        $data = $this->readCurrentInstanceWarmupMetadata();
        if ($data === []) {
            return [];
        }

        $port = (int)($data['main_port'] ?? $data['port'] ?? 0);
        $hosts = [];
        foreach ([$data['public_host'] ?? null, $data['host'] ?? null] as $host) {
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
     * @return array<string,mixed>
     */
    private function readCurrentInstanceWarmupMetadata(): array
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
        return \is_array($data) ? $data : [];
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
            $configured = ['admin/login', 'admin'];
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
                $paths = $this->wlsRuntimeAdapter()?->discoverHotPaths($effectiveMaxPaths) ?? ['/'];
            } catch (\Throwable $e) {
                $paths = ['/'];
                $result['errors'][] = 'path discovery failed: ' . $e->getMessage();
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[WlsRuntime] dynamic first-render path discovery failed: ' . $e->getMessage());
                }
            }
        }

        $hosts = $this->resolveDynamicFirstRenderWarmupHosts();
        if ($paths === ['/'] && $hosts !== []) {
            $hosts = [$this->resolveCanonicalHomepageWarmupHost($hosts)];
        }
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
                $isHomepage = $path === '/';
                $pathKey = $this->dynamicWarmupPathKey($host, $path);
                $ownsPathLock = false;
                $renderLockToken = null;
                try {
                    $ownsPathLock = $this->acquireDynamicWarmupPathLock($pathKey);
                    if (!$ownsPathLock) {
                        $this->waitForDynamicWarmupPathReady($pathKey);
                        // Shared readiness only proves that the owner finished
                        // generation-wide work. Router/controller/template
                        // caches remain process-local, so every peer must still
                        // execute its own bounded render before it can be READY.
                        // Skipping here leaves most Workers cold and creates a
                        // 100ms+ tail on the first public request routed to each
                        // process.
                    }

                    // Shared generation prerequisites are published once, but
                    // controller/router/template caches are process-local. A
                    // cold 16-Worker batch used to let every peer render at the
                    // same instant after the owner proof arrived, turning an
                    // otherwise 10-30ms local render into a 300ms+ CPU-contention
                    // spike. Serialize only this bounded bootstrap render; the
                    // lock is never used by public request traffic.
                    $renderLockToken = $this->acquireDynamicWarmupRenderLock($pathKey);
                    if ($renderLockToken === null) {
                        throw new \RuntimeException('Timed out waiting for the process-local warmup render slot.');
                    }

                    if ($isHomepage) {
                        // The mandatory READY gate above has already proved
                        // this worker owns a process-local homepage FPC hit.
                        // One owner publishes generation-wide prerequisites;
                        // after observing that proof, every peer warms its own
                        // controller/template chain before advertising READY.
                        $attempts = 0;
                        do {
                            $attempts++;
                            $warmupMeta = $this->runDynamicFirstRenderWarmupAttempt($host, $path, $sequence);
                            $validation = $this->validateDynamicFirstRenderWarmup(
                                $warmupMeta,
                                $targetMs,
                                false
                            );
                            if ($validation['ok']) {
                                $reason = (string)($validation['reason'] ?? '');
                                $elapsedMs = (float)($warmupMeta['elapsed_ms'] ?? 0.0);
                                if (!\str_starts_with($reason, 'ready:slow')
                                    || $attempts >= $maxAttempts
                                    || $targetMs <= 0.0
                                    || $elapsedMs < $targetMs
                                ) {
                                    break;
                                }
                            } elseif ($attempts >= $maxAttempts) {
                                break;
                            }
                            $sequence++;
                            SchedulerSystem::yield();
                        } while (true);
                    } else {
                        $attempts = 0;
                        do {
                            $attempts++;
                            $warmupMeta = $this->runDynamicFirstRenderWarmupAttempt($host, $path, $sequence);
                            $validation = $this->validateDynamicFirstRenderWarmup(
                                $warmupMeta,
                                $targetMs,
                                true
                            );
                            if ($validation['ok']) {
                                $reason = (string)($validation['reason'] ?? '');
                                $elapsedMs = (float)($warmupMeta['elapsed_ms'] ?? 0.0);
                                if (!\str_starts_with($reason, 'ready:slow')
                                    || $attempts >= $maxAttempts
                                    || $targetMs <= 0.0
                                    || $elapsedMs < $targetMs
                                ) {
                                    break;
                                }
                            } elseif ($attempts >= $maxAttempts) {
                                break;
                            }
                            $sequence++;
                            SchedulerSystem::yield();
                        } while (true);
                    }
                    $warmupHeaders = \is_array($warmupMeta['headers'] ?? null)
                        ? $warmupMeta['headers']
                        : [];
                    $sample = [
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
                        'fpc_status' => \strtoupper(
                            $this->warmupHeaderValue($warmupHeaders, 'X-WLS-FPC-Status')
                            ?: $this->warmupHeaderValue($warmupHeaders, 'X-Weline-FPC')
                        ),
                        'ready' => (bool)$validation['ok'],
                        'reason' => $validation['reason'],
                    ];
                    if (\count($result['samples']) < 8 || $path === '/') {
                        $result['samples'][] = $sample;
                    }
                    if (!$validation['ok']) {
                        $result['failed']++;
                        $message = $host . $path . ': ' . $validation['reason'];
                        $result['errors'][] = $message;
                        $this->markDynamicWarmupPathReady($pathKey, false, $validation['reason'], $sample);
                        if (\function_exists('w_log_warning')) {
                            \w_log_warning('[WlsRuntime] dynamic first-render warmup not ready ' . $message);
                        }
                        continue;
                    }

                    $this->markDynamicWarmupPathReady($pathKey, true, $validation['reason'], $sample);
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
                    if ($renderLockToken !== null) {
                        $this->releaseDynamicWarmupRenderLock($pathKey, $renderLockToken);
                    }
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
     * @return array{meta:array<string,mixed>,validation:array{ok:bool,reason:string,cache:string}}
     */
    private function runHomepageFpcWarmupTransaction(
        string $host,
        int $sequence,
        bool $allowPrime = true
    ): array {
        $host = $this->normalizeInternalWarmupHost($host)
            ?? throw new \InvalidArgumentException('Invalid homepage warmup host.');
        $fullUri = $this->resolveInternalWarmupScheme() . '://' . $host . '/';
        $cacheFullUri = $this->homepageCacheFullUri !== '' ? $this->homepageCacheFullUri : $fullUri;
        $activeReceipt = $this->normalizeHomepageWarmupReceipt($this->homepageCacheWarmupReceipt);
        if ($activeReceipt === []) {
            $activeReceipt = $this->buildHomepageWarmupReceipt($cacheFullUri);
        } else {
            $cacheFullUri = $activeReceipt['full_uri'];
        }

        // READY elects exactly one shared publisher. Runtime keep-warm cycles
        // only refresh from an existing fresh shared entry and never cold-render.
        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        $pulledFromShared = $this->warmHomepageProcessCacheForReceipt(
            $coordinator,
            $activeReceipt,
            !$allowPrime
        );
        if (!$pulledFromShared) {
            if (!$allowPrime) {
                return [
                    'meta' => [],
                    'validation' => ['ok' => false, 'reason' => 'shared-fpc-miss', 'cache' => ''],
                ];
            }
            $candidateReceipts = [];
            $this->addHomepageWarmupReceipt($candidateReceipts, $activeReceipt);
            $this->addHomepageWarmupReceipt(
                $candidateReceipts,
                $this->buildHomepageWarmupReceipt($fullUri)
            );
            $waitMs = \max(250, \min(10000, (int)Env::get(
                'wls.worker.homepage_warmup_peer_wait_ms',
                3000
            )));
            $readyBudgetMilliseconds = self::homepageWarmupReadyBudgetMilliseconds();
            $readyDeadlineNs = \hrtime(true) + ($readyBudgetMilliseconds * 1000000);

            $sharedCoordinator = $this->dynamicWarmupCoordinator();
            if ($sharedCoordinator === null) {
                return [
                    'meta' => ['full_uri' => $cacheFullUri],
                    'validation' => [
                        'ok' => false,
                        'reason' => 'homepage-warmup-coordinator-unavailable',
                        'cache' => '',
                    ],
                ];
            }

            $coordinationPool = $this->homepageWarmupCoordinationPool($fullUri);
            $ownerToken = $this->homepageWarmupOwnerToken();
            $ownerLease = null;
            $publishedPublicationId = '';
            $publishedReceipt = $this->readHomepageWarmupPublishedReceipt(
                $sharedCoordinator,
                $coordinationPool,
                $publishedPublicationId
            );
            $this->addHomepageWarmupReceipt($candidateReceipts, $publishedReceipt);
            $lastHydratedPublicationId = '';
            $leaseFailureReason = '';
            $ownsPrimeLease = $this->tryAcquireHomepageWarmupLease(
                $sharedCoordinator,
                $coordinationPool,
                $ownerToken,
                $ownerLease,
                $leaseFailureReason,
            );
            $coordinatorFailureCount = $leaseFailureReason === 'coordinator_unavailable' ? 1 : 0;
            $selectionStartedAt = \microtime(true);
            $peerDeadline = $selectionStartedAt
                + (\min($waitMs, $readyBudgetMilliseconds) / 1000);
            $observedLiveOwner = false;
            $observedOwnerLease = null;

            // A follower never enters Router/Controller while a live owner is
            // priming. A missing owner still fails within the peer window; once
            // a fenced owner is observed, the follower yields until publication,
            // owner release/takeover, or the independent READY budget is exhausted.
            while (!$ownsPrimeLease && \hrtime(true) < $readyDeadlineNs) {
                $publishedPublicationId = '';
                $publishedReceipt = $this->readHomepageWarmupPublishedReceipt(
                    $sharedCoordinator,
                    $coordinationPool,
                    $publishedPublicationId
                );
                if ($publishedPublicationId !== ''
                    && $publishedPublicationId !== $lastHydratedPublicationId
                ) {
                    $lastHydratedPublicationId = $publishedPublicationId;
                    $this->addHomepageWarmupReceipt($candidateReceipts, $publishedReceipt);
                    if ($this->warmHomepageProcessCacheForReceipt(
                        $coordinator,
                        $publishedReceipt,
                        true
                    )) {
                        $activeReceipt = $publishedReceipt;
                        $cacheFullUri = $activeReceipt['full_uri'];
                        $pulledFromShared = true;
                        break;
                    }
                }

                $ownsPrimeLease = $this->tryAcquireHomepageWarmupLease(
                    $sharedCoordinator,
                    $coordinationPool,
                    $ownerToken,
                    $ownerLease,
                    $leaseFailureReason,
                );
                if ($ownsPrimeLease) {
                    break;
                }
                if ($leaseFailureReason === 'coordinator_unavailable') {
                    $coordinatorFailureCount++;
                    if ($coordinatorFailureCount >= 2) {
                        break;
                    }
                } else {
                    $coordinatorFailureCount = 0;
                }

                $now = \microtime(true);
                $nowMs = (int)\floor($now * 1000);
                try {
                    $currentOwnerLease = $sharedCoordinator->getCache(
                        $coordinationPool,
                        self::HOMEPAGE_WARMUP_OWNER_KEY
                    );
                } catch (\Throwable) {
                    $currentOwnerLease = null;
                }
                $ownerIsLive = \is_array($currentOwnerLease)
                    && \trim((string)($currentOwnerLease['token'] ?? '')) !== ''
                    && (int)($currentOwnerLease['expires_at_ms'] ?? 0) > $nowMs;
                if ($ownerIsLive) {
                    $observedLiveOwner = true;
                    $observedOwnerLease = $currentOwnerLease;
                } elseif (!$observedLiveOwner && $now >= $peerDeadline) {
                    break;
                }

                $remainingMs = (int)\max(
                    0,
                    \ceil(($readyDeadlineNs - \hrtime(true)) / 1000000)
                );
                if ($remainingMs <= 0) {
                    break;
                }
                $jitterMs = (int)((\getmypid() ?: 0) % 11) - 5;
                SchedulerSystem::yieldDelay(\min(
                    self::HOMEPAGE_WARMUP_FOLLOWER_POLL_MILLISECONDS + $jitterMs,
                    $remainingMs
                ));
            }

            if (!$ownsPrimeLease && !$pulledFromShared) {
                $ownerExpiresAtMs = \is_array($observedOwnerLease)
                    ? (int)($observedOwnerLease['expires_at_ms'] ?? 0)
                    : 0;
                return [
                    'meta' => [
                        'full_uri' => $cacheFullUri,
                        'status_code' => 0,
                    ],
                    'validation' => [
                        'ok' => false,
                        'reason' => ($leaseFailureReason === 'coordinator_unavailable'
                            ? 'homepage-warmup-coordinator-unavailable'
                            : ($leaseFailureReason === 'owner_state_invalid'
                                ? 'homepage-warmup-owner-state-invalid'
                                : ($observedLiveOwner
                                    ? 'homepage-warmup-owner-startup-deadline'
                                    : 'homepage-warmup-owner-election-timeout')))
                            . ':owner-observed=' . ($observedLiveOwner ? '1' : '0')
                            . ':owner-expires-at-ms=' . $ownerExpiresAtMs,
                        'cache' => '',
                    ],
                ];
            }

            $primeMeta = [];
            if ($ownsPrimeLease) {
                // Election/follower wait and owner work have independent
                // budgets. A takeover must receive the same complete prime
                // window as the first owner instead of inheriting time already
                // spent waiting for the previous lease to expire.
                $readyDeadlineNs = \hrtime(true) + ($readyBudgetMilliseconds * 1000000);
                $ownsPublishFence = true;
                try {
                    // Close the miss->CAS race: another owner may have
                    // published immediately before this Worker acquired.
                    $publishedReceipt = $this->readHomepageWarmupPublishedReceipt(
                        $sharedCoordinator,
                        $coordinationPool
                    );
                    $this->addHomepageWarmupReceipt($candidateReceipts, $publishedReceipt);
                    foreach ($candidateReceipts as $candidateReceipt) {
                        if ($this->warmHomepageProcessCacheForReceipt(
                            $coordinator,
                            $candidateReceipt,
                            true
                        )) {
                            $activeReceipt = $candidateReceipt;
                            $cacheFullUri = $activeReceipt['full_uri'];
                            $pulledFromShared = true;
                            break;
                        }
                    }

                    if (!$pulledFromShared) {
                        $ownsPublishFence = $this->renewHomepageWarmupLease(
                            $sharedCoordinator,
                            $coordinationPool,
                            $ownerLease
                        );
                        if (!$ownsPublishFence) {
                            return [
                                'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                'validation' => [
                                    'ok' => false,
                                    'reason' => 'homepage-warmup-owner-lease-lost:stage=before-prime',
                                    'cache' => '',
                                ],
                            ];
                        }
                        if (\hrtime(true) >= $readyDeadlineNs) {
                            return [
                                'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                'validation' => [
                                    'ok' => false,
                                    'reason' => 'homepage-warmup-ready-deadline:stage=before-prime',
                                    'cache' => '',
                                ],
                            ];
                        }
                        $remainingPrimeNanoseconds = $readyDeadlineNs - \hrtime(true);
                        $remainingPrimeSeconds = \PHP_OS_FAMILY === 'Windows'
                            ? (int)\ceil($remainingPrimeNanoseconds / 1000000000)
                            : (int)\floor($remainingPrimeNanoseconds / 1000000000);
                        if ($remainingPrimeSeconds < 1) {
                            return [
                                'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                'validation' => [
                                    'ok' => false,
                                    'reason' => 'homepage-warmup-ready-deadline:stage=before-prime',
                                    'cache' => '',
                                ],
                            ];
                        }
                        $restoreExecutionTimeLimit = null;
                        $restorePosixAlarm = null;
                        if (\PHP_OS_FAMILY === 'Windows') {
                            if (!\function_exists('set_time_limit')) {
                                return [
                                    'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                    'validation' => [
                                        'ok' => false,
                                        'reason' => 'homepage-warmup-hard-timeout-unavailable',
                                        'cache' => '',
                                    ],
                                ];
                            }
                            $restoreExecutionTimeLimit = (int)\ini_get('max_execution_time');
                            if (@\set_time_limit($remainingPrimeSeconds) === false) {
                                return [
                                    'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                    'validation' => [
                                        'ok' => false,
                                        'reason' => 'homepage-warmup-hard-timeout-arm-failed',
                                        'cache' => '',
                                    ],
                                ];
                            }
                        } else {
                            if (!\defined('SIGALRM')
                                || !\defined('SIG_DFL')
                                || !\function_exists('pcntl_alarm')
                                || !\function_exists('pcntl_signal')
                                || !\function_exists('pcntl_signal_get_handler')
                            ) {
                                return [
                                    'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                    'validation' => [
                                        'ok' => false,
                                        'reason' => 'homepage-warmup-hard-timeout-unavailable',
                                        'cache' => '',
                                    ],
                                ];
                            }
                            $previousAlarmHandler = \pcntl_signal_get_handler(\SIGALRM);
                            $previousAlarmSeconds = \pcntl_alarm(0);
                            if ($previousAlarmSeconds > 0) {
                                \pcntl_alarm($previousAlarmSeconds);
                                return [
                                    'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                    'validation' => [
                                        'ok' => false,
                                        'reason' => 'homepage-warmup-hard-timeout-alarm-in-use',
                                        'cache' => '',
                                    ],
                                ];
                            }
                            // SIG_DFL is installed in the kernel rather than a
                            // PHP callback. It can terminate a Worker even when
                            // the prime is blocked inside native code and never
                            // returns to the Zend VM to dispatch user handlers.
                            if (!@\pcntl_signal(\SIGALRM, \SIG_DFL)) {
                                @\pcntl_signal(\SIGALRM, $previousAlarmHandler);
                                return [
                                    'meta' => ['full_uri' => $cacheFullUri, 'status_code' => 0],
                                    'validation' => [
                                        'ok' => false,
                                        'reason' => 'homepage-warmup-hard-timeout-arm-failed',
                                        'cache' => '',
                                    ],
                                ];
                            }
                            \pcntl_alarm($remainingPrimeSeconds);
                            $restorePosixAlarm = [
                                'handler' => $previousAlarmHandler,
                            ];
                        }
                        try {
                            $primeMeta = $this->runInternalWarmupRequest(
                                $host,
                                '/',
                                $sequence,
                                'homepage-fpc-prime',
                                [
                                    'WLS_INTERNAL_DYNAMIC_WARMUP' => '1',
                                    'WLS_INTERNAL_HOMEPAGE_PRIME' => '1',
                                ],
                                [
                                    'User-Agent' => 'WLS-Homepage-Warmup/1.0',
                                    'Accept-Encoding' => 'identity',
                                    'X-WLS-Dynamic-Warmup' => '1',
                                    'X-WLS-FPC-Prime' => '1',
                                ]
                            );
                        } finally {
                            if ($restorePosixAlarm !== null) {
                                \pcntl_alarm(0);
                                @\pcntl_signal(\SIGALRM, $restorePosixAlarm['handler']);
                            }
                            if ($restoreExecutionTimeLimit !== null) {
                                @\set_time_limit($restoreExecutionTimeLimit > 0 ? $restoreExecutionTimeLimit : 0);
                            }
                        }
                        if (\hrtime(true) >= $readyDeadlineNs) {
                            return [
                                'meta' => $primeMeta,
                                'validation' => [
                                    'ok' => false,
                                    'reason' => 'homepage-warmup-ready-deadline:stage=prime',
                                    'cache' => '',
                                ],
                            ];
                        }
                        $ownsPublishFence = $this->renewHomepageWarmupLease(
                            $sharedCoordinator,
                            $coordinationPool,
                            $ownerLease
                        );
                        $primeValidation = $this->validateHomepageWarmupResponse($primeMeta, false);
                        if (!$primeValidation['ok']) {
                            return ['meta' => $primeMeta, 'validation' => $primeValidation];
                        }
                        $primeReceipt = $this->normalizeHomepageWarmupReceipt($primeMeta['fpc_receipt'] ?? []);
                        if ($primeReceipt === []) {
                            return [
                                'meta' => $primeMeta,
                                'validation' => [
                                    'ok' => false,
                                    'reason' => 'homepage-fpc-receipt-missing',
                                    'cache' => (string)($primeValidation['cache'] ?? ''),
                                ],
                            ];
                        }
                        $activeReceipt = $primeReceipt;
                        $cacheFullUri = $activeReceipt['full_uri'];
                        $this->addHomepageWarmupReceipt($candidateReceipts, $activeReceipt);

                        // Publishing is not considered successful until this
                        // owner can read the same exact entry back from Shared
                        // L2 and install it into Process L1.
                        $publishDeadlineNs = \min(
                            $readyDeadlineNs,
                            \hrtime(true) + ($waitMs * 1000000)
                        );
                        while (\hrtime(true) < $publishDeadlineNs) {
                            foreach ($candidateReceipts as $candidateReceipt) {
                                if ($this->warmHomepageProcessCacheForReceipt(
                                    $coordinator,
                                    $candidateReceipt,
                                    true
                                )) {
                                    $activeReceipt = $candidateReceipt;
                                    $cacheFullUri = $activeReceipt['full_uri'];
                                    $pulledFromShared = true;
                                    break 2;
                                }
                            }

                            $remainingMs = (int)\max(
                                0,
                                \ceil(($publishDeadlineNs - \hrtime(true)) / 1000000)
                            );
                            if ($remainingMs <= 0) {
                                break;
                            }
                            SchedulerSystem::yieldDelay(\min(10, $remainingMs));
                        }

                    }
                    if ($pulledFromShared) {
                        $pulledFromShared = \hrtime(true) < $readyDeadlineNs
                            && $ownsPublishFence
                            && $this->renewHomepageWarmupLease(
                                $sharedCoordinator,
                                $coordinationPool,
                                $ownerLease
                            )
                            && $this->publishHomepageWarmupReceipt(
                                $sharedCoordinator,
                                $coordinationPool,
                                $activeReceipt,
                                $ownerLease
                            );
                    }
                } finally {
                    $this->releaseHomepageWarmupLease(
                        $sharedCoordinator,
                        $coordinationPool,
                        $ownerLease
                    );
                }
            }

            if (!$pulledFromShared) {
                $primeHeaders = \is_array($primeMeta['headers'] ?? null) ? $primeMeta['headers'] : [];
                $primeFpc = $this->warmupHeaderValue($primeHeaders, 'X-WLS-FPC-Status')
                    ?: $this->warmupHeaderValue($primeHeaders, 'X-Weline-FPC');
                $primeSource = $this->warmupHeaderValue($primeHeaders, 'X-WLS-Performance-FPC-Source');
                return [
                    'meta' => [
                        'full_uri' => $cacheFullUri,
                        'status_code' => (int)($primeMeta['status_code'] ?? 0),
                    ],
                    'validation' => [
                        'ok' => false,
                        'reason' => (\hrtime(true) >= $readyDeadlineNs
                            ? 'homepage-warmup-ready-deadline:stage=publish'
                            : 'shared-fpc-publish-timeout')
                            . ':prime-status=' . (int)($primeMeta['status_code'] ?? 0)
                            . ':prime-fpc=' . ($primeFpc !== '' ? $primeFpc : 'none')
                            . ':prime-source=' . ($primeSource !== '' ? $primeSource : 'none')
                            . ':prime-uri=' . (string)($primeMeta['full_uri'] ?? 'none'),
                        'cache' => '',
                    ],
                ];
            }
        }

        $cached = $coordinator->getFormattedCachedResponseForFullUri(
            $activeReceipt['full_uri'],
            $activeReceipt['method'],
            'text/html,application/xhtml+xml',
            'identity',
            $activeReceipt['cookie_header'],
            false,
            true
        );
        if (!\is_array($cached) || !\is_string($cached['response'] ?? null)) {
            return [
                'meta' => ['full_uri' => $cacheFullUri],
                'validation' => ['ok' => false, 'reason' => 'process-cache-miss', 'cache' => ''],
            ];
        }

        $formatted = $this->parseFormattedWarmupResult($cached['response']);
        $headers = $formatted['headers'];
        $headers['X-WLS-Performance-FPC-Source'] = (string)($cached['source'] ?? '');
        $probeMeta = [
            'headers' => $headers,
            'status_code' => (int)$formatted['status_code'],
            'body_length' => (int)$formatted['body_length'],
            'formatted_http' => true,
            'set_cookie_count' => 0,
            'elapsed_ms' => 0.0,
            'full_uri' => $activeReceipt['full_uri'],
            'fpc_receipt' => $activeReceipt,
        ];

        $validation = $this->validateHomepageWarmupResponse($probeMeta, true);
        if ($validation['ok']) {
            $this->homepageCacheFullUri = $activeReceipt['full_uri'];
            $this->homepageCacheWarmupReceipt = $activeReceipt;
        }

        return [
            'meta' => $probeMeta,
            'validation' => $validation,
        ];
    }

    private function homepageWarmupCoordinationPool(string $fullUri): string
    {
        $metadata = $this->readCurrentInstanceWarmupMetadata();
        $instance = \trim((string)($metadata['instance_name'] ?? $metadata['name'] ?? (
            $_SERVER['WLS_INSTANCE_NAME']
                ?? $_SERVER['WLS_INSTANCE']
                ?? $_ENV['WLS_INSTANCE_NAME']
                ?? $_ENV['WLS_INSTANCE']
                ?? \getenv('WLS_INSTANCE')
                ?: 'default'
        )));
        $epoch = $this->workerArgValue('epoch')
            ?: (string)($metadata['master_epoch'] ?? $metadata['epoch'] ?? '0');
        $policyDigest = \strtolower(\trim((string)($metadata['policy_digest'] ?? (
            $_SERVER['WLS_POLICY_DIGEST']
                ?? $_ENV['WLS_POLICY_DIGEST']
                ?? \getenv('WLS_POLICY_DIGEST')
                ?: ''
        ))));
        $cacheEpoch = $this->workerArgValue('cache-epoch')
            ?: (string)($metadata['cache_epoch'] ?? (
                $_SERVER['WLS_CACHE_EPOCH']
                    ?? $_ENV['WLS_CACHE_EPOCH']
                    ?? \getenv('WLS_CACHE_EPOCH')
                    ?: '0'
            ));
        $identity = \implode('|', [
            $instance !== '' ? $instance : 'default',
            $epoch,
            $policyDigest,
            $cacheEpoch,
            \strtolower(\trim($fullUri)),
        ]);

        return self::HOMEPAGE_WARMUP_COORDINATOR_POOL_PREFIX . \hash('sha256', $identity);
    }

    private function workerArgValue(string $name): string
    {
        $prefix = '--' . \trim($name) . '=';
        $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
        foreach (\is_array($argv) ? $argv : [] as $argument) {
            if (\is_string($argument) && \str_starts_with($argument, $prefix)) {
                return \trim(\substr($argument, \strlen($prefix)));
            }
        }

        return '';
    }

    private function homepageWarmupOwnerToken(): string
    {
        try {
            $nonce = \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            $nonce = \str_replace('.', '', \uniqid('', true));
        }

        return (string)\getmypid()
            . ':' . (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: '0')
            . ':' . $nonce;
    }

    private static function homepageWarmupReadyBudgetMilliseconds(): int
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? self::HOMEPAGE_WARMUP_WINDOWS_READY_BUDGET_MILLISECONDS
            : self::HOMEPAGE_WARMUP_READY_BUDGET_MILLISECONDS;
    }

    private static function homepageWarmupOwnerLeaseSeconds(): int
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? self::HOMEPAGE_WARMUP_WINDOWS_OWNER_LEASE_SECONDS
            : self::HOMEPAGE_WARMUP_OWNER_LEASE_SECONDS;
    }

    private function tryAcquireHomepageWarmupLease(
        SharedCacheStateInterface $coordinator,
        string $pool,
        string $ownerToken,
        ?array &$acquiredLease,
        string &$failureReason,
    ): bool {
        $failureReason = '';
        try {
            $currentLease = $coordinator->getCache($pool, self::HOMEPAGE_WARMUP_OWNER_KEY);
            $nowMs = (int)\floor(\microtime(true) * 1000);
            $newLease = [
                'token' => $ownerToken,
                'fence' => $nowMs . ':' . \hash('sha256', $ownerToken),
                'owner_pid' => (int)(\getmypid() ?: 0),
                'acquired_at_ms' => $nowMs,
                'expires_at_ms' => $nowMs + (self::homepageWarmupOwnerLeaseSeconds() * 1000),
                'renewal_count' => 0,
            ];
            $expected = null;
            if ($currentLease !== null) {
                if (!\is_array($currentLease)) {
                    $failureReason = 'owner_state_invalid';
                    return false;
                }
                if ((int)($currentLease['expires_at_ms'] ?? 0) > $nowMs) {
                    $failureReason = 'owner_live';
                    return false;
                }
                // expires_at_ms is the takeover authority. The CAS fence below
                // prevents an expired owner from publishing, while avoiding a
                // recycled PID (or a live Worker after an aborted prime) from
                // blocking every follower indefinitely.
                $expected = $currentLease;
            }

            $acquired = $coordinator->compareAndSetCache(
                $pool,
                self::HOMEPAGE_WARMUP_OWNER_KEY,
                $expected,
                $newLease,
                \max(
                    self::HOMEPAGE_WARMUP_OWNER_RECORD_TTL_SECONDS,
                    self::homepageWarmupOwnerLeaseSeconds() + 5
                )
            );
            if ($acquired) {
                $acquiredLease = $newLease;
                return true;
            }

            $failureReason = $coordinator instanceof SharedCacheStateHealthInterface
                && !$coordinator->isSharedCacheAvailable()
                ? 'coordinator_unavailable'
                : 'lease_contended';
            return false;
        } catch (\Throwable) {
            $failureReason = 'coordinator_unavailable';
            return false;
        }
    }

    private function renewHomepageWarmupLease(
        SharedCacheStateInterface $coordinator,
        string $pool,
        ?array &$acquiredLease
    ): bool {
        if ($acquiredLease === null) {
            return false;
        }

        $nowMs = (int)\floor(\microtime(true) * 1000);
        if ((int)($acquiredLease['expires_at_ms'] ?? 0) <= $nowMs) {
            return false;
        }
        $renewedLease = $acquiredLease;
        $renewedLease['expires_at_ms'] = $nowMs
            + (self::homepageWarmupOwnerLeaseSeconds() * 1000);
        $renewedLease['renewal_count'] = (int)($acquiredLease['renewal_count'] ?? 0) + 1;
        try {
            if (!$coordinator->compareAndSetCache(
                $pool,
                self::HOMEPAGE_WARMUP_OWNER_KEY,
                $acquiredLease,
                $renewedLease,
                \max(
                    self::HOMEPAGE_WARMUP_OWNER_RECORD_TTL_SECONDS,
                    self::homepageWarmupOwnerLeaseSeconds() + 5
                )
            )) {
                return false;
            }
            $acquiredLease = $renewedLease;
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ownsHomepageWarmupLease(
        SharedCacheStateInterface $coordinator,
        string $pool,
        ?array $ownerLease
    ): bool {
        if ($ownerLease === null) {
            return false;
        }
        try {
            $currentLease = $coordinator->getCache($pool, self::HOMEPAGE_WARMUP_OWNER_KEY);
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($currentLease)
            || (int)($currentLease['expires_at_ms'] ?? 0) <= (int)\floor(\microtime(true) * 1000)
        ) {
            return false;
        }
        $currentToken = (string)($currentLease['token'] ?? '');
        $currentFence = (string)($currentLease['fence'] ?? '');
        $ownerToken = (string)($ownerLease['token'] ?? '');
        $ownerFence = (string)($ownerLease['fence'] ?? '');
        return $currentToken !== ''
            && $currentFence !== ''
            && \hash_equals($currentToken, $ownerToken)
            && \hash_equals($currentFence, $ownerFence);
    }

    private function releaseHomepageWarmupLease(
        SharedCacheStateInterface $coordinator,
        string $pool,
        ?array $ownerLease
    ): void {
        if ($ownerLease === null) {
            return;
        }
        try {
            $coordinator->compareAndSetCache(
                $pool,
                self::HOMEPAGE_WARMUP_OWNER_KEY,
                $ownerLease,
                null,
                0
            );
        } catch (\Throwable) {
            // Explicit expires_at_ms is the owner-crash fence. Never block
            // READY cleanup on a best-effort release.
        }
    }

    /**
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}
     */
    private function readHomepageWarmupPublishedReceipt(
        SharedCacheStateInterface $coordinator,
        string $pool,
        ?string &$publicationId = null
    ): array {
        try {
            $ready = $coordinator->getCache($pool, self::HOMEPAGE_WARMUP_READY_KEY);
        } catch (\Throwable) {
            $publicationId = '';
            return [];
        }

        if (!\is_array($ready)) {
            $publicationId = '';
            return [];
        }
        $publicationId = \trim((string)($ready['publication_id'] ?? ''));
        if ($publicationId === '') {
            $legacy = \json_encode($ready, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $publicationId = \is_string($legacy) ? \hash('sha256', $legacy) : '';
        }
        return $this->normalizeHomepageWarmupReceipt($ready['receipt'] ?? []);
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function publishHomepageWarmupReceipt(
        SharedCacheStateInterface $coordinator,
        string $pool,
        array $receipt,
        ?array $ownerLease
    ): bool {
        $receipt = $this->normalizeHomepageWarmupReceipt($receipt);
        if ($receipt === [] || !$this->ownsHomepageWarmupLease($coordinator, $pool, $ownerLease)) {
            return false;
        }

        try {
            $fence = (string)($ownerLease['fence'] ?? '');
            $publicationId = \hash(
                'sha256',
                $fence . '|' . $receipt['identity_digest'] . '|' . \sprintf('%.6F', \microtime(true))
            );
            return $coordinator->setCache($pool, self::HOMEPAGE_WARMUP_READY_KEY, [
                'receipt' => $receipt,
                'publication_id' => $publicationId,
                'owner_fence' => $fence,
                'pid' => \getmypid(),
                'worker_id' => (int)($_SERVER['WLS_WORKER_ID']
                    ?? $_ENV['WLS_WORKER_ID']
                    ?? \getenv('WLS_WORKER_ID')
                    ?: 0),
                'published_at' => \microtime(true),
            ], self::HOMEPAGE_WARMUP_READY_TTL_SECONDS);
        } catch (\Throwable) {
            // Followers retain their bounded takeover path when shared state is
            // transiently unavailable.
            return false;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}
     */
    private function normalizeHomepageWarmupReceipt(mixed $receipt): array
    {
        if (!\is_array($receipt) || (int)($receipt['version'] ?? 0) !== 1) {
            return [];
        }
        $fullUri = $this->normalizeHomepageWarmupFullUri($receipt['full_uri'] ?? '');
        $method = \strtoupper(\trim((string)($receipt['method'] ?? '')));
        $cookieHeader = $this->normalizeHomepageWarmupCookieHeader($receipt['cookie_header'] ?? '');
        $identityDigest = \strtolower(\trim((string)($receipt['identity_digest'] ?? '')));
        if ($fullUri === ''
            || $method !== 'GET'
            || $cookieHeader === null
            || \preg_match('/^[a-f0-9]{64}$/D', $identityDigest) !== 1
        ) {
            return [];
        }

        return [
            'version' => 1,
            'full_uri' => $fullUri,
            'method' => 'GET',
            'cookie_header' => $cookieHeader,
            'identity_digest' => $identityDigest,
        ];
    }

    private function encodeHomepageWarmupReceipt(mixed $receipt): string
    {
        $receipt = $this->normalizeHomepageWarmupReceipt($receipt);
        if ($receipt === []) {
            return '';
        }
        $json = \json_encode($receipt, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json) || $json === '') {
            return '';
        }

        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}
     */
    private function decodeHomepageWarmupReceipt(mixed $encoded): array
    {
        if (!\is_string($encoded)
            || $encoded === ''
            || \strlen($encoded) > 2048
            || \preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
        ) {
            return [];
        }
        $padding = (4 - (\strlen($encoded) % 4)) % 4;
        $json = \base64_decode(\strtr($encoded . \str_repeat('=', $padding), '-_', '+/'), true);
        if (!\is_string($json) || $json === '') {
            return [];
        }
        $receipt = \json_decode($json, true);

        return $this->normalizeHomepageWarmupReceipt($receipt);
    }

    /**
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}
     */
    private function buildHomepageWarmupReceipt(string $fullUri, string $cookieHeader = ''): array
    {
        $fullUri = $this->normalizeHomepageWarmupFullUri($fullUri);
        $cookieHeader = $this->normalizeHomepageWarmupCookieHeader($cookieHeader);
        if ($fullUri === '' || $cookieHeader === null) {
            return [];
        }

        return [
            'version' => 1,
            'full_uri' => $fullUri,
            'method' => 'GET',
            'cookie_header' => $cookieHeader,
            'identity_digest' => \hash('sha256', 'fallback|' . $fullUri . '|' . $cookieHeader),
        ];
    }

    private function normalizeHomepageWarmupCookieHeader(mixed $cookieHeader): ?string
    {
        if (!\is_string($cookieHeader) || \strlen($cookieHeader) > 512) {
            return null;
        }
        if (\trim($cookieHeader) === '') {
            return '';
        }

        $allowed = ['WELINE_USER_LANG' => '', 'WELINE_USER_CURRENCY' => ''];
        foreach (\preg_split('/;\s*/', \trim($cookieHeader), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (!\str_contains($part, '=')) {
                return null;
            }
            [$name, $value] = \explode('=', $part, 2);
            $name = \trim($name);
            if (!\array_key_exists($name, $allowed)) {
                return null;
            }
            $value = \urldecode(\trim($value));
            if ($value === '' || \strlen($value) > 64 || \preg_match('/^[A-Za-z0-9_.-]+$/D', $value) !== 1) {
                return null;
            }
            $allowed[$name] = $value;
        }
        if ($allowed['WELINE_USER_LANG'] === '' || $allowed['WELINE_USER_CURRENCY'] === '') {
            return null;
        }

        return 'WELINE_USER_LANG=' . \rawurlencode($allowed['WELINE_USER_LANG'])
            . '; WELINE_USER_CURRENCY=' . \rawurlencode($allowed['WELINE_USER_CURRENCY']);
    }

    /**
     * @param array<string,array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string}> $receipts
     * @param array<string,mixed> $receipt
     */
    private function addHomepageWarmupReceipt(array &$receipts, array $receipt): void
    {
        $receipt = $this->normalizeHomepageWarmupReceipt($receipt);
        if ($receipt !== []) {
            $receipts[$receipt['identity_digest']] = $receipt;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function warmHomepageProcessCacheForReceipt(
        FullPageCacheCoordinator $coordinator,
        array $receipt,
        bool $forceSharedRead
    ): bool {
        $receipt = $this->normalizeHomepageWarmupReceipt($receipt);
        return $receipt !== [] && $coordinator->warmProcessCacheForFullUri(
            $receipt['full_uri'],
            $receipt['method'],
            $receipt['cookie_header'],
            $forceSharedRead
        );
    }

    private function normalizeHomepageWarmupFullUri(mixed $fullUri): string
    {
        if (!\is_string($fullUri)) {
            return '';
        }
        $fullUri = \trim($fullUri);
        if ($fullUri === '' || \strlen($fullUri) > 4096) {
            return '';
        }

        try {
            $parts = \parse_url($fullUri);
        } catch (\ValueError) {
            return '';
        }
        if (!\is_array($parts)
            || !\in_array(\strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || (string)$parts['host'] === ''
        ) {
            return '';
        }

        return $fullUri;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{ok:bool,reason:string,cache:string}
     */
    private function validateHomepageWarmupResponse(array $meta, bool $requireProcessHit): array
    {
        $headers = \is_array($meta['headers'] ?? null) ? $meta['headers'] : [];
        $statusCode = (int)($meta['status_code'] ?? 0);
        $bodyLength = (int)($meta['body_length'] ?? 0);
        $source = \strtolower($this->warmupHeaderValue($headers, 'X-WLS-Performance-FPC-Source'));
        if ($statusCode < 200 || $statusCode >= 400) {
            return ['ok' => false, 'reason' => 'status=' . $statusCode, 'cache' => $source];
        }
        if ($bodyLength <= 0) {
            return ['ok' => false, 'reason' => 'empty-body', 'cache' => $source];
        }
        if ((int)($meta['set_cookie_count'] ?? 0) > 0
            || $this->warmupHeaderValue($headers, 'Set-Cookie') !== ''
        ) {
            $cookieNames = \array_values(\array_filter(
                \array_map('strval', (array)($meta['set_cookie_names'] ?? [])),
                static fn(string $name): bool => $name !== '',
            ));
            return [
                'ok' => false,
                'reason' => 'set-cookie' . ($cookieNames !== [] ? ':' . \implode(',', $cookieNames) : ''),
                'cache' => $source,
            ];
        }

        $cacheControl = \strtolower($this->warmupHeaderValue($headers, 'Cache-Control'));
        if (\preg_match('/(?:^|,)\s*(?:private|no-store)\b/', $cacheControl) === 1) {
            return ['ok' => false, 'reason' => 'cache-control=' . $cacheControl, 'cache' => $source];
        }
        if (!$requireProcessHit) {
            return ['ok' => true, 'reason' => 'published-or-shared-ready', 'cache' => $source];
        }

        $fpcStatus = \strtoupper($this->warmupHeaderValue($headers, 'X-WLS-FPC-Status')
            ?: $this->warmupHeaderValue($headers, 'X-Weline-FPC'));
        if ($fpcStatus !== 'HIT' || !\str_starts_with($source, 'process')) {
            return [
                'ok' => false,
                'reason' => 'process-hit-required fpc=' . ($fpcStatus !== '' ? $fpcStatus : 'missing')
                    . ' source=' . ($source !== '' ? $source : 'missing'),
                'cache' => $source,
            ];
        }

        return ['ok' => true, 'reason' => 'ready:process-hit', 'cache' => $source];
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
    private function validateDynamicFirstRenderWarmup(
        array $warmupMeta,
        float $targetMs,
        bool $requireControllerCache = true
    ): array
    {
        $headers = \is_array($warmupMeta['headers'] ?? null) ? $warmupMeta['headers'] : [];
        $statusCode = (int)($warmupMeta['status_code'] ?? 0);
        $elapsedMs = (float)($warmupMeta['elapsed_ms'] ?? 0.0);
        $bodyLength = (int)($warmupMeta['body_length'] ?? 0);
        $cache = $this->dynamicWarmupControllerCacheSource($headers);

        if ($statusCode < 200 || $statusCode >= 400) {
            return ['ok' => false, 'reason' => 'status=' . $statusCode, 'cache' => $cache];
        }

        $fpcStatus = \strtoupper($this->warmupHeaderValue($headers, 'X-WLS-FPC-Status')
            ?: $this->warmupHeaderValue($headers, 'X-Weline-FPC'));
        if ($fpcStatus === 'HIT') {
            return ['ok' => false, 'reason' => 'fpc=HIT', 'cache' => $cache];
        }

        if ($requireControllerCache && !$this->dynamicWarmupCacheIsReady($cache)) {
            return ['ok' => false, 'reason' => 'cache=' . ($cache !== '' ? $cache : 'missing'), 'cache' => $cache];
        }
        if (!$requireControllerCache && $bodyLength <= 0) {
            return ['ok' => false, 'reason' => 'empty-body', 'cache' => $cache];
        }

        if ($targetMs > 0.0 && $elapsedMs >= $targetMs) {
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

        return [
            'ok' => true,
            'reason' => $requireControllerCache ? 'ready:controller-cache' : 'ready:rendered',
            'cache' => $cache,
        ];
    }

    private function shouldBlockDynamicWarmupOnTargetMs(): bool
    {
        $rawFlag = \getenv('WLS_WORKER_DYNAMIC_WARMUP_BLOCK_ON_TARGET_MS');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            // The first-render target is a release/performance gate, not a
            // process-liveness condition. On a many-worker cold start every
            // process can render concurrently and temporarily exceed the
            // target even though the response and controller cache are valid.
            // Keeping strict mode opt-in prevents load-driven restart storms
            // while preserving the explicit diagnostic mode.
            $rawFlag = Env::get('wls.worker.dynamic_warmup_block_on_target_ms', '0');
        }

        return \in_array(\strtolower(\trim((string)$rawFlag)), ['1', 'true', 'yes', 'on', 'strict', 'block'], true);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function dynamicWarmupControllerCacheSource(array $headers): string
    {
        return \strtolower($this->warmupHeaderValue($headers, 'X-WLS-Controller-Cache'));
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
        return $this->warmupHeaderValue($headers, 'X-WLS-Controller-Cache-Store');
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

    /** @return array<string,mixed> */
    private function waitForDynamicWarmupPathReady(string $pathKey): array
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return [];
        }

        $deadline = \microtime(true) + ($this->dynamicWarmupPathWaitMs() / 1000);
        do {
            try {
                $ready = $coordinator->get(self::DYNAMIC_WARMUP_COORDINATOR_NS, 'ready.' . $pathKey);
                if (\is_array($ready)) {
                    return $ready;
                }
            } catch (\Throwable) {
                return [];
            }

            SchedulerSystem::yieldDelay(50);
        } while (\microtime(true) < $deadline);

        return [];
    }

    private function acquireDynamicWarmupRenderLock(string $pathKey): ?string
    {
        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return 'local:' . (string)\getmypid();
        }

        $token = (string)\getmypid()
            . ':' . (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: '0')
            . ':' . \bin2hex(\random_bytes(6));
        $deadline = \microtime(true) + (\max(5000, $this->dynamicWarmupPathWaitMs()) / 1000);
        do {
            try {
                if ($coordinator->cas(
                    self::DYNAMIC_WARMUP_COORDINATOR_NS,
                    'render.' . $pathKey,
                    null,
                    $token,
                    30
                )) {
                    return $token;
                }
            } catch (\Throwable) {
                // Shared state is an optimization boundary. A transient IPC
                // failure must not deadlock Worker startup.
                return 'local:' . $token;
            }

            SchedulerSystem::yieldDelay(5);
        } while (\microtime(true) < $deadline);

        return null;
    }

    private function releaseDynamicWarmupRenderLock(string $pathKey, string $token): void
    {
        if (\str_starts_with($token, 'local:')) {
            return;
        }

        $coordinator = $this->dynamicWarmupCoordinator();
        if ($coordinator === null) {
            return;
        }

        try {
            $coordinator->cas(
                self::DYNAMIC_WARMUP_COORDINATOR_NS,
                'render.' . $pathKey,
                $token,
                null,
                1
            );
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $sample */
    private function markDynamicWarmupPathReady(string $pathKey, bool $ok, string $reason, array $sample = []): void
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
                'sample' => $sample,
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

    private function dynamicWarmupCoordinator(): ?SharedCacheStateInterface
    {
        if (self::$dynamicWarmupCoordinatorResolved) {
            return self::$dynamicWarmupCoordinator;
        }
        self::$dynamicWarmupCoordinatorResolved = true;

        try {
            self::$dynamicWarmupCoordinator = $this->wlsRuntimeAdapter()?->createSharedState([
                'consumer_code' => self::DYNAMIC_WARMUP_COORDINATOR_NS,
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
                // Startup coordination is not a request hot-path lookup. The
                // normal 10ms/50ms WLS cache budget is too small for Windows
                // ARM x64 emulation and can turn a healthy local CAS into a
                // false election timeout.
                'connect_timeout' => 0.5,
                'timeout' => 1.0,
                'acquire_timeout' => 0.5,
                'pool_size' => 4,
                'fail_fast_on_cooldown' => false,
                'pool_profile' => 'homepage_warmup_coordinator',
            ]);
        } catch (\Throwable) {
            self::$dynamicWarmupCoordinator = null;
        }

        return self::$dynamicWarmupCoordinator;
    }

    private function wlsRuntimeAdapter(): ?WlsRuntimeAdapterInterface
    {
        try {
            return ObjectManager::getInstance(WlsRuntimeAdapterResolver::class)->resolve();
        } catch (\Throwable) {
            return null;
        }
    }

    private function runtimeProvider(string $contract): ?object
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldRunDeferredWorkerBootstrapObserverWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
            return false;
        }

        $rawFlag = \getenv('WLS_WORKER_DEFERRED_BOOTSTRAP_WARMUP');
        if ($rawFlag === false || \trim((string)$rawFlag) === '') {
            $rawFlag = Env::get('wls.worker_deferred_bootstrap_warmup', null);
        }

        if ($rawFlag === null || \trim((string)$rawFlag) === '') {
            $rawFlag = '0';
        }

        $flag = \strtolower(\trim((string)$rawFlag));
        return \in_array($flag, ['1', 'true', 'yes', 'on', 'async', 'deferred'], true);
    }

    private function shouldRunDeferredWorkerBootstrapUrlMetadataWarmup(): bool
    {
        $role = \strtolower(\trim((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: 'worker')));
        if (\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true)) {
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
        if ($this->readyGateDynamicFirstRenderWarmupCompleted) {
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
            $configured = [];
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
        $warmup = $this->runtimeProvider(FpcWarmupProviderInterface::class);
        if (!$warmup instanceof FpcWarmupProviderInterface) {
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
                $warmup->warmProcessFastPathPayloads();
                $completedRounds++;
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
     * @param list<string> $candidates
     */
    private function resolveCanonicalHomepageWarmupHost(array $candidates): string
    {
        $configured = Env::get('wls.worker.homepage_warmup_host', null);
        if (\is_scalar($configured) && \trim((string)$configured) !== '') {
            $host = $this->normalizeInternalWarmupHost((string)$configured);
            if ($host !== null) {
                return $host;
            }
        }

        $publicOrigin = $this->resolveWorkerPublicOrigin();
        if ($publicOrigin !== null) {
            return $publicOrigin['host'];
        }

        $fallback = null;
        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $host = $this->normalizeInternalWarmupHost((string)$candidate);
            if ($host === null) {
                continue;
            }
            $fallback ??= $host;
            $lower = \strtolower($host);
            if (!\str_starts_with($lower, '127.')
                && !\str_starts_with($lower, '[::1]')
                && $lower !== 'localhost'
                && !\str_starts_with($lower, 'localhost:')
            ) {
                return $host;
            }
        }

        return $fallback ?? '127.0.0.1';
    }

    /**
     * @return array{scheme:'http'|'https',host:string}|null
     */
    private function resolveWorkerPublicOrigin(): ?array
    {
        $raw = (string)(
            $_SERVER['WLS_PUBLIC_ORIGIN']
            ?? $_ENV['WLS_PUBLIC_ORIGIN']
            ?? \getenv('WLS_PUBLIC_ORIGIN')
            ?: ''
        );
        if ($raw === '') {
            return null;
        }

        $parts = $this->parseWarmupUrl($raw);
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = $this->normalizeInternalWarmupHost($raw);
        if (!\in_array($scheme, ['http', 'https'], true) || $host === null) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host];
    }

    private function resolveInternalWarmupScheme(): string
    {
        $configured = Env::get('wls.worker.homepage_warmup_host', null);
        if (\is_scalar($configured) && \str_contains((string)$configured, '://')) {
            $parts = $this->parseWarmupUrl((string)$configured);
            $scheme = \strtolower((string)($parts['scheme'] ?? ''));
            if (\in_array($scheme, ['http', 'https'], true)) {
                return $scheme;
            }
        }

        $publicOrigin = $this->resolveWorkerPublicOrigin();
        if ($publicOrigin !== null) {
            return $publicOrigin['scheme'];
        }

        $instance = $this->readCurrentInstanceWarmupMetadata();
        if (\array_key_exists('ssl_enabled', $instance)) {
            return (bool)$instance['ssl_enabled'] ? 'https' : 'http';
        }

        return 'https';
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
        // Framework owns only the universal homepage contract. Modules publish
        // additional page paths through FpcWarmupProviderInterface.
        $paths = ['/'];

        try {
            $warmup = $this->runtimeProvider(FpcWarmupProviderInterface::class);
            if ($warmup instanceof FpcWarmupProviderInterface) {
                $resolved = $warmup->warmupPaths();
                if ($resolved !== []) {
                    $paths = $resolved;
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

        $scheme = $this->resolveInternalWarmupScheme();
        $server = \array_merge([
            'WLS_INSTANCE' => (string)($_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: ''),
            'WLS_WORKER_ID' => (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: ''),
            'WLS_PORT' => (string)($_SERVER['WLS_PORT'] ?? $_ENV['WLS_PORT'] ?? \getenv('WLS_PORT') ?: ''),
            'WLS_REQUEST_COUNT' => 'warmup-' . $sequence,
            'WLS_INTERNAL_WARMUP' => '1',
            'HTTPS' => $scheme === 'https' ? 'on' : '',
            'REQUEST_SCHEME' => $scheme,
        ], $serverOverrides);

        $request = WlsRequest::fromRaw($rawRequest, $server);
        // Keep only a transport-origin fallback here. The exact FPC identity
        // can change when `/` is mapped to the configured start-page route and
        // is returned later through the internal receipt before Context reset.
        $requestFullUri = (string)($request->getServer('WELINE_FULL_REQUEST_URI')
            ?: ($scheme . '://' . $host . $path));

        // The first synthetic request may run on the Worker bootstrap Fiber.
        // Clear response state left by bootstrap providers so the anonymous
        // homepage prime cannot inherit unrelated localization/session cookies.
        \Weline\Framework\Http\HeaderCollector::reset();
        $result = $this->handle($request);
        $response = $request->getResponse();
        $responseHeaders = \method_exists($response, 'getHeaders') ? (array)$response->getHeaders() : [];
        $responseCookies = \method_exists($response, 'getCookies') ? (array)$response->getCookies() : [];
        $formattedResult = $this->parseFormattedWarmupResult((string)$result);
        foreach ($formattedResult['headers'] as $name => $value) {
            $responseHeaders[(string)$name] = $value;
        }
        $pendingStatus = $this->consumePendingResponseStatus();
        $pendingHeaders = $this->consumePendingHeaders();
        $pendingCookies = $this->consumePendingCookies();
        foreach ($pendingHeaders as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $responseHeaders[(string)$name] = $value;
        }
        // A formatted HTTP result is a terminal response produced by an
        // exception/early-exit path. Its status line is authoritative; the
        // response object and HeaderCollector may still contain their default
        // 200 and must not make a failed warmup look successful.
        $statusCode = (int)($formattedResult['status_code'] ?? 0);
        if ($statusCode <= 0) {
            $statusCode = (int)($pendingStatus['status_code'] ?? 0);
        }
        if ($statusCode <= 0 && \method_exists($response, 'getStatusCode')) {
            $statusCode = (int)$response->getStatusCode();
        }
        $cookieNames = $this->warmupCookieNames($responseCookies, $pendingCookies, $responseHeaders);
        $fpcReceipt = $this->decodeHomepageWarmupReceipt(
            $this->warmupHeaderValue($responseHeaders, 'X-WLS-Internal-FPC-Receipt')
        );
        $meta = [
            'headers' => $responseHeaders,
            'status_code' => $statusCode,
            'body_length' => $formattedResult['body_length'] >= 0
                ? $formattedResult['body_length']
                : \strlen((string)$result),
            'formatted_http' => $formattedResult['status_code'] > 0,
            'set_cookie_count' => \count($cookieNames),
            'set_cookie_names' => $cookieNames,
            'full_uri' => $fpcReceipt['full_uri'] ?? $requestFullUri,
            'fpc_receipt' => $fpcReceipt,
            'elapsed_ms' => \round((\microtime(true) - $startedAt) * 1000, 2),
        ];
        unset($result, $request, $response);

        return $meta;
    }

    /**
     * Return cookie names only. Values are deliberately excluded from startup
     * diagnostics because session/auth cookies are secrets.
     *
     * @param array<int|string, mixed> $responseCookies
     * @param array<int|string, mixed> $pendingCookies
     * @param array<string, mixed> $headers
     * @return list<string>
     */
    private function warmupCookieNames(array $responseCookies, array $pendingCookies, array $headers): array
    {
        $names = [];
        foreach ([$responseCookies, $pendingCookies] as $cookies) {
            foreach ($cookies as $key => $cookie) {
                $name = \is_array($cookie) ? (string)($cookie['name'] ?? '') : (\is_string($key) ? $key : '');
                $name = \trim($name);
                if ($name !== '' && \preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $name) === 1) {
                    $names[$name] = true;
                }
            }
        }
        foreach ($headers as $name => $value) {
            if (\strcasecmp((string)$name, 'Set-Cookie') !== 0) {
                continue;
            }
            foreach ((array)$value as $line) {
                if (\preg_match('/^\s*([^=;\s]{1,128})=/', (string)$line, $match) === 1) {
                    $names[(string)$match[1]] = true;
                }
            }
        }

        $names = \array_keys($names);
        \sort($names, \SORT_STRING);
        return $names;
    }

    private function isInternalHomepageFpcPrimeRequest(): bool
    {
        return InternalHomepagePrime::isCurrentRequest();
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
            $this->warmupStep(function (): void {
                PhraseParser::preloadWorkerDictionaries();
                $provider = $this->runtimeProvider(DictionaryWarmupProviderInterface::class);
                if ($provider instanceof DictionaryWarmupProviderInterface) {
                    $provider->preloadWorkerDictionaries();
                }
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
            'Weline_Framework::App::pre_route_gate',
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
        $provider = $this->runtimeProvider(BackendWarmupProviderInterface::class);
        if ($provider instanceof BackendWarmupProviderInterface) {
            $provider->installRequestContext($request);
        }
    }

    private function requestPipeline(): RequestPipelineInterface
    {
        return $this->requestPipeline ??= new RequestPipeline($this);
    }

    public function afterRequestPipelineStage(string $stage, float $elapsedMilliseconds): void
    {
        unset($elapsedMilliseconds);
        if ($stage === RequestPipeline::STAGE_URL || $stage === RequestPipeline::STAGE_ROUTE) {
            $this->releaseCompletedRequestPhase($stage);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function handle(?Request $request = null): string
    {
        if ($request === null) {
            throw new \LogicException('WLS: WlsRuntime::handle() requires a Request instance for fiber-local context isolation.');
        }

        if ($this->isRawBenchmarkRequest($request)) {
            return self::buildRawBenchmarkResponse();
        }

        $parsedServer = \method_exists($request, 'getParsedServerSnapshot')
            ? (array)$request->getParsedServerSnapshot()
            : [];
        $isInternalWarmup = (string)($parsedServer['WLS_INTERNAL_WARMUP']
            ?? $request->getServer('WLS_INTERNAL_WARMUP')
            ?? '') === '1';
        $requestOriginalUri = (string)($request->getUri() ?: '/');

        // 确保已初始化
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }
        $this->bindProcessScopedServicesForCurrentRequest();

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
            'request_count' => $this->requestCount + ($isInternalWarmup ? 0 : 1),
        ];

        if (!$isInternalWarmup) {
            $this->requestCount++;
        }
        
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
                $canonicalBackendRedirect = $this->buildCanonicalLocalizedBackendRedirect($request);
                if ($canonicalBackendRedirect !== '') {
                    return $this->buildEarlyRedirectResponse($canonicalBackendRedirect);
                }
                $requestMeta = [
                    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? $request->getMethod() ?: 'GET'),
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    'instance' => (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_SERVER['WLS_INSTANCE'] ?? ''),
                    'worker_id' => (string)($_SERVER['WLS_WORKER_ID'] ?? ''),
                    'worker_port' => (string)($_SERVER['WLS_PORT'] ?? ''),
                    'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
                    'request_count' => $this->requestCount,
                ];
                // GlobalsEmulator::emulate() 已用当前原始请求建立完整 Context。
                // 这里不能再次从复用的 ServerBag 重建，否则会用上一请求覆盖
                // WLS_INTERNAL_* 等仅由传输层注入的 server 标记。
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
            \Weline\Framework\App\State::resetRequestPathLocalizationCache();
            if ($request !== null) {
                $request->invalidateUriCache();
            }
            try {
                $processUrlCacheClass = 'Weline\\Framework\\Router\\Cache\\ProcessUrlCache';
                if (\class_exists($processUrlCacheClass, false) && \method_exists($processUrlCacheClass, 'resetRequestState')) {
                    $processUrlCacheClass::resetRequestState();
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
            if ($request !== null) {
                $this->applyFrontendRootStartPageRoute($request);
                $timing['uri'] = (string)(WelineEnv::get('request.uri', $timing['uri']) ?: $timing['uri']);
            }
            
            // 请求日志：默认始终写入 runtime.log（由 shouldWriteRequestLog 控制），全量调试见 -log
            $isDev = \defined('DEV') && DEV;
            $isFrontend = \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
            if ($request !== null && !$isInternalWarmup) {
                $this->logWlsRequest($request, $isFrontend);
            }
            
            WelineEnv::set('wls.request_count', (string) $this->requestCount, 'WlsRuntime handle');
            // WLS 请求入口：在统一 Pipeline 的 PreRouteGate/URL 阶段前重置
            // parserServer/parserMatchs/parserCache 等请求级静态状态，防止上一
            // 请求的 website/locale 前缀污染当前 URL。
            if (Runtime::isPersistent()) {
                \Weline\Framework\Http\Url::resetParserRequestCaches();
            }
            // FPM/WLS share the same application-stage executor. Worker
            // policy/static/FPC L1 already ran in the transport layer; this
            // pipeline owns pre-route gate -> URL/apply -> internal FPC ->
            // before -> lazy session -> router/controller -> after.
            $pipelineExecution = $this->requestPipeline()->execute(
                $app,
                false,
                !$this->isInternalHomepageFpcPrimeRequest(),
            );
            foreach ($pipelineExecution->timings as $name => $durationMs) {
                $timing[$name] = $durationMs;
            }
            $parse = $pipelineExecution->parsedUrl;
            if (\is_array($parse) && isset($parse['_perf']) && \is_array($parse['_perf'])) {
                $timing['url_parser_perf'] = $parse['_perf'];
            }
            if ($timing['run_before_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_before event took ' . $timing['run_before_ms'] . 'ms');
            }
            if ($timing['run_after_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_after event took ' . $timing['run_after_ms'] . 'ms');
            }

            $cachedFpcResponse = $pipelineExecution->earlyResponse;
            if ($cachedFpcResponse instanceof Response) {
                RequestPipeline::clearEarlyResponse();
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
                $this->applyDynamicFirstRenderHeaders($timing, $request, true, $cachedFpcResponse);
                $this->decorateCachedFpcResponseForTelemetry($cachedFpcResponse, $request);

                return $cachedFpcResponse->toHttpString();
            }
            unset($parse, $cachedFpcResponse, $appApplyUrlProfile);
            $result = $pipelineExecution->result;
            // Preserve the historical aggregate metric while the shared
            // pipeline also exposes each fixed stage independently.
            $timing['router_start_ms'] = \round(
                (float)$timing['session_start_ms']
                + (float)$timing['router_init_ms']
                + (float)$timing['router_start_call_ms'],
                2
            );
            $queryBinTiming = RequestContext::get('query_bin.timing');
            if (\is_array($queryBinTiming) && $queryBinTiming !== []) {
                $timing['query_bin'] = $queryBinTiming;
            }
            $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
            if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                $timing['app_apply_url'] = $appApplyUrlProfile;
            }
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            $routerProfile = RequestContext::get('router.start.profile');
            if (\is_array($routerProfile) && $routerProfile !== []) {
                $timing['router_profile'] = $routerProfile;
            }
            unset(
                $pipelineExecution,
                $queryBinTiming,
                $appApplyUrlProfile,
                $templateProfile,
                $routerProfile
            );

            // 计算总耗时（用于性能监控）
            $timing['total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
            
            // 如果总耗时超过阈值或 DEV 模式，按配置追加性能响应头
            $isDev = \defined('DEV') && DEV;
            $performanceConfig = $this->getPerformanceConfig();
            $slowThreshold = (float)($performanceConfig['slow_request_threshold_ms'] ?? 500.0);
            if ($this->shouldEmitDynamicFirstRenderHeaders($request)
                || (!empty($performanceConfig['response_headers_enabled'])
                    && ($timing['total_ms'] >= $slowThreshold || $isDev))) {
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
            $appApplyUrlProfile = RequestContext::get('app.apply_url.profile');
            if (\is_array($appApplyUrlProfile) && $appApplyUrlProfile !== []) {
                $timing['app_apply_url'] = $appApplyUrlProfile;
            }
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            $routerProfile = RequestContext::get('router.start.profile');
            if (\is_array($routerProfile) && $routerProfile !== []) {
                $timing['router_profile'] = $routerProfile;
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
            $emitBenchmarkTiming = $this->shouldEmitDynamicFirstRenderHeaders($request);
            if ($emitBenchmarkTiming
                || (!empty($performanceConfig['response_headers_enabled'])
                    && ($timing['total_ms'] >= $slowThreshold || $isDev))) {
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
                try {
                    $adapter = $this->wlsRuntimeAdapter();
                    if ($adapter !== null) {
                        $compaction = $adapter->compactResponseMemory();
                        $adapter->requestDrainAfterResponse('fiber_output_buffer_overflow');
                    }
                } catch (\Throwable) {
                    $compaction = [];
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
                $timing['request_id'] = RequestLifecycleTrace::ensureRequestId();
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
            if (!$isInternalWarmup && $this->isRootRequestUri($requestOriginalUri)) {
                $this->noteHomepageNaturalHit($requestOriginalUri);
            }
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
            $templateProfile = RequestContext::get('view.template.profile');
            if (\is_array($templateProfile) && $templateProfile !== []) {
                $timing['template_profile'] = $templateProfile;
            }
            // 同步到 WelineEnv
            WelineEnv::set('request.method', $timing['method'], 'WlsRuntime finally');
            WelineEnv::set('server.remote_addr', $timing['ip'], 'WlsRuntime finally');
            WelineEnv::set('wls.redirect_count', (string) $timing['redirect_count'], 'WlsRuntime finally');
            if (!$isInternalWarmup) {
                $this->recordPerformanceTiming($timing, $isDev);
            }
            $this->wlsRuntimeAdapter()?->flushLogs();
            Context::leave();
            unset(
                $timing,
                $requestMeta,
                $requestOriginalUri,
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
                $queryBinTiming,
                $appApplyUrlProfile,
                $templateProfile,
                $routerProfile,
                $pageBuilderRenderProfile
            );
            $this->releaseCompletedRequestPhase('request_end');
        }
    }

    private function applyFrontendRootStartPageRoute(Request $request): void
    {
        if ($request->isBackend() || $request->isApiBackend() || $request->isApiFrontend()) {
            return;
        }

        $currentUri = (string)(WelineEnv::get('request.uri', $_SERVER['REQUEST_URI'] ?? '/') ?: '/');
        $this->ensureWebsiteContextForStartPage($request);
        if (!$this->isRootRequestUri($currentUri) && !$this->isWebsiteRootRequestUri($request, $currentUri)) {
            return;
        }

        $mappedPath = trim((string)$request->getUrlPath());
        if ($mappedPath === '' || $this->isRootRequestUri($mappedPath)) {
            $mappedPath = $this->resolveConfiguredStartPageRoute($request);
        }
        if ($mappedPath === '' || $this->isRootRequestUri($mappedPath)) {
            return;
        }

        $canonicalUri = $this->buildStartPageRouteUri($mappedPath, $currentUri, $this->getWebsitePathPrefix($request));
        if ($canonicalUri === null || $canonicalUri === $currentUri) {
            return;
        }

        $canonicalPath = $this->parseUriPath($canonicalUri);
        if ($canonicalPath === '') {
            $canonicalPath = '/';
        }
        $canonicalQuery = $this->parseUriQuery($canonicalUri);

        $_SERVER['REQUEST_URI'] = $canonicalUri;
        $_SERVER['PATH_INFO'] = $canonicalPath;
        $_SERVER['QUERY_STRING'] = $canonicalQuery;
        $fullRequestUri = $this->buildStartPageFullRequestUri($request, $canonicalUri);

        $request->setServer('REQUEST_URI', $canonicalUri);
        $request->setServer('PATH_INFO', $canonicalPath);
        $request->setServer('QUERY_STRING', $canonicalQuery);
        $request->setServer('WELINE_ORIGIN_REQUEST_URI', $canonicalUri);
        $request->setServer('WELINE_FULL_REQUEST_URI', $fullRequestUri);
        if ($request instanceof WlsRequest) {
            $request->replaceParsedUriForRouting($canonicalUri);
        }
        $request->invalidateUriCache();
        Request::clearStaticUrlPathCache();

        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $canonicalUri;
        $_SERVER['WELINE_FULL_REQUEST_URI'] = $fullRequestUri;
        WelineEnv::set('request.uri', $canonicalUri, 'WlsRuntime start page route');
        WelineEnv::set('origin_request_uri', $canonicalUri, 'WlsRuntime start page route');
        WelineEnv::set('full_request_uri', $fullRequestUri, 'WlsRuntime start page route');
        WelineEnv::set('request.query_string', $canonicalQuery, 'WlsRuntime start page route');
        WelineEnv::setServer('REQUEST_URI', $canonicalUri, 'WlsRuntime start page route');
        WelineEnv::setServer('PATH_INFO', $canonicalPath, 'WlsRuntime start page route');
        WelineEnv::setServer('QUERY_STRING', $canonicalQuery, 'WlsRuntime start page route');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $canonicalUri, 'WlsRuntime start page route');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', $fullRequestUri, 'WlsRuntime start page route');
    }

    private function resolveConfiguredStartPageRoute(Request $request): string
    {
        $provider = $this->runtimeProvider(StartPageRouteProviderInterface::class);
        if ($provider instanceof StartPageRouteProviderInterface) {
            return $provider->resolveConfiguredRoute($request);
        }

        return '';
    }

    private function ensureWebsiteContextForStartPage(Request $request): void
    {
        $websiteCode = \trim(RequestContext::getWelineWebsiteCode());
        if ($websiteCode === '') {
            $websiteCode = \trim((string)($request->getServer('WELINE_WEBSITE_CODE') ?: ($_SERVER['WELINE_WEBSITE_CODE'] ?? '')));
        }
        if ($websiteCode !== '') {
            RequestContext::setWelineWebsiteCode($websiteCode);
            return;
        }

        $url = $this->buildStartPageRequestUrl($request);
        if ($url === '') {
            return;
        }
        try {
            $eventData = new DataObject(['url' => $url]);
            $this->eventManager ??= ObjectManager::getInstance(EventsManager::class);
            $this->eventManager->dispatch('Weline_Framework_Url::detect_website', $eventData);
        } catch (\Throwable) {
            return;
        }

        $websiteId = (int)$eventData->getData('website_id');
        if ($eventData->hasData('website_id') && $websiteId >= 0) {
            $request->setServer('WELINE_WEBSITE_ID', (string)$websiteId);
            WelineEnv::set('server.WELINE_WEBSITE_ID', (string)$websiteId, 'WlsRuntime detect website');
        }

        $websiteCode = \trim((string)$eventData->getData('code'));
        if ($websiteCode !== '') {
            $request->setServer('WELINE_WEBSITE_CODE', $websiteCode);
            WelineEnv::set('server.WELINE_WEBSITE_CODE', $websiteCode, 'WlsRuntime detect website');
        }

        $websiteUrl = \trim((string)$eventData->getData('website_url'));
        if ($websiteUrl !== '') {
            $request->setServer('WELINE_WEBSITE_URL', $websiteUrl);
            WelineEnv::set('server.WELINE_WEBSITE_URL', $websiteUrl, 'WlsRuntime detect website');
        }
    }

    private function buildStartPageRequestUrl(Request $request): string
    {
        $host = \trim((string)(
            $request->getServer('HTTP_HOST')
            ?: $request->getServer('HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: ($_SERVER['HTTP_HOST'] ?? '')
        ));
        if ($host === '') {
            return '';
        }

        $scheme = \strtolower(\trim((string)(
            $request->getServer('REQUEST_SCHEME')
            ?: ($_SERVER['REQUEST_SCHEME'] ?? '')
        )));
        if ($scheme === '') {
            $https = \strtolower(\trim((string)($request->getServer('HTTPS') ?: ($_SERVER['HTTPS'] ?? ''))));
            $scheme = ($https !== '' && !\in_array($https, ['off', '0', 'false'], true)) ? 'https' : 'http';
        }

        $uri = (string)(WelineEnv::get('request.uri', $_SERVER['REQUEST_URI'] ?? '/') ?: '/');
        if ($uri === '') {
            $uri = '/';
        }
        if (!\str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }

        return $scheme . '://' . $host . $uri;
    }

    private function buildStartPageFullRequestUri(Request $request, string $uri): string
    {
        $host = \trim((string)(
            $request->getServer('HTTP_HOST')
            ?: $request->getServer('HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: ($_SERVER['HTTP_HOST'] ?? '')
        ));
        if ($host === '') {
            return $uri;
        }

        $scheme = \strtolower(\trim((string)(
            $request->getServer('REQUEST_SCHEME')
            ?: ($_SERVER['REQUEST_SCHEME'] ?? '')
        )));
        if ($scheme === '') {
            $https = \strtolower(\trim((string)($request->getServer('HTTPS') ?: ($_SERVER['HTTPS'] ?? ''))));
            $scheme = ($https !== '' && !\in_array($https, ['off', '0', 'false'], true)) ? 'https' : 'http';
        }

        return $scheme . '://' . $host . ($uri === '' || \str_starts_with($uri, '/') ? $uri : '/' . $uri);
    }

    private function buildStartPageRouteUri(string $mappedPath, string $currentUri, string $websitePathPrefix = ''): ?string
    {
        $path = trim($this->parseUriPath($mappedPath), '/');
        if ($path === '') {
            $path = trim($mappedPath, '/');
        }
        if ($path === '') {
            return null;
        }

        $query = $this->parseUriQuery($mappedPath);
        if ($query === '') {
            $query = $this->parseUriQuery($currentUri);
        }

        $websitePathPrefix = trim($websitePathPrefix, '/');
        if ($websitePathPrefix !== ''
            && $path !== $websitePathPrefix
            && !\str_starts_with($path, $websitePathPrefix . '/')) {
            $path = $websitePathPrefix . '/' . $path;
        }

        return '/' . $path . ($query !== '' ? '?' . $query : '');
    }

    private function isWebsiteRootRequestUri(Request $request, string $uri): bool
    {
        $websitePathPrefix = trim($this->getWebsitePathPrefix($request), '/');
        if ($websitePathPrefix === '') {
            return false;
        }

        return trim($this->parseUriPath($uri), '/') === $websitePathPrefix;
    }

    private function getWebsitePathPrefix(Request $request): string
    {
        $websiteUrl = (string)(
            RequestContext::getWelineWebsiteUrl()
            ?: $request->getServer('WELINE_WEBSITE_URL')
            ?: ($_SERVER['WELINE_WEBSITE_URL'] ?? '')
        );
        if ($websiteUrl === '') {
            return '';
        }

        return trim($this->parseUriPath($websiteUrl), '/');
    }

    private function isRootRequestUri(string $uri): bool
    {
        return trim($this->parseUriPath($uri), '/') === '';
    }

    private function parseUriPath(string $uri): string
    {
        try {
            $path = \parse_url($uri, \PHP_URL_PATH);
        } catch (\ValueError) {
            $path = null;
        }

        return \is_string($path) ? $path : '';
    }

    private function parseUriQuery(string $uri): string
    {
        try {
            $query = \parse_url($uri, \PHP_URL_QUERY);
        } catch (\ValueError) {
            $query = null;
        }

        return \is_string($query) ? $query : '';
    }
    
    /**
     * Drop stage-local pressure after large WLS request phases.
     */
    private function releaseCompletedRequestPhase(string $phase): void
    {
        if (!Runtime::isPersistent() || !WlsConcurrency::canCompactProcessCaches()) {
            return;
        }

        try {
            $adapter = $this->wlsRuntimeAdapter();
            if ($adapter === null) {
                return;
            }
            $threshold = (float)(Env::get('wls.memory_guard.phase_compact_threshold', 0.70) ?: 0.70);
            $compaction = $adapter->compactResponseMemoryIfPressure($threshold);
            if ($compaction !== null && \defined('DEV') && DEV && $adapter->isVerboseLog()) {
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
            'query_bin.timing',
            'app.apply_url.profile',
            'view.template.profile',
            'router.start.profile',
        ] as $key) {
            RequestContext::remove($key);
        }
    }

    private function isRawBenchmarkRequest(Request $request): bool
    {
        try {
            $uri = (string)$request->getUri();
        } catch (\Throwable) {
            return false;
        }

        $path = \parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            return false;
        }

        return \rtrim($path, '/') === '/__bench/raw';
    }

    private static function buildRawBenchmarkResponse(): string
    {
        $body = 'ok';

        return "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: keep-alive\r\n"
            . 'Server: ' . Response::SERVER_SIGNATURE . "\r\n"
            . 'X-Powered-By: WLS/' . Response::SERVER_VERSION . ' PHP/' . \PHP_VERSION . "\r\n"
            . "\r\n"
            . $body;
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

    private function buildCanonicalLocalizedBackendRedirect(Request $request): string
    {
        $backendPrefix = \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($backendPrefix === '') {
            return '';
        }

        $uri = (string)($request->getServer('REQUEST_URI') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($uri === '') {
            return '';
        }

        try {
            $parsed = \parse_url($uri);
        } catch (\ValueError) {
            return '';
        }
        $path = \is_array($parsed) ? (string)($parsed['path'] ?? '') : '';
        if ($path === '') {
            return '';
        }

        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        if (!isset($segments[0], $segments[1]) || \strcasecmp((string)$segments[0], $backendPrefix) !== 0) {
            return '';
        }

        $localization = State::resolveLocalizationFromPathSegments(\array_slice($segments, 0, 3));
        $currency = (string)($localization['currency'] ?? '');
        $language = (string)($localization['language'] ?? '');
        if ($currency === '' && $language === '') {
            return '';
        }

        $stripCount = 0;
        foreach (\array_slice($segments, 1, 2) as $segment) {
            $segment = (string)$segment;
            if ($currency !== ''
                && $this->isBackendLocalizedCurrencySegment($segment)
                && \strtoupper($segment) === $currency
            ) {
                $stripCount++;
                continue;
            }
            if ($language !== ''
                && $this->isBackendLocalizedLocaleSegment($segment)
                && \str_replace('-', '_', $segment) === $language
            ) {
                $stripCount++;
                continue;
            }
            break;
        }
        if ($stripCount === 0) {
            return '';
        }

        $canonicalSegments = \array_merge([$backendPrefix], \array_slice($segments, 1 + $stripCount));
        $canonicalPath = '/' . \implode('/', $canonicalSegments);
        if ($canonicalPath === $path) {
            return '';
        }

        $query = \is_array($parsed) && isset($parsed['query']) && $parsed['query'] !== ''
            ? '?' . $parsed['query']
            : '';
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = (string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: 'localhost');

        return $scheme . '://' . $host . $canonicalPath . $query;
    }

    private function isBackendLocalizedCurrencySegment(string $segment): bool
    {
        return \strlen($segment) === 3
            && $segment === \strtoupper($segment)
            && \ctype_alpha($segment);
    }

    private function isBackendLocalizedLocaleSegment(string $segment): bool
    {
        return (bool)\preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
    }

    private function buildEarlyRedirectResponse(string $location): string
    {
        return Response::fromContent('', 302, 'text/plain; charset=utf-8')
            ->setHeader('Location', $location)
            ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0')
            ->toHttpString(false);
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
        $scheme = (string)($parse['server']['REQUEST_SCHEME'] ?? WelineEnv::get('request.scheme', '') ?: 'http');
        $host = (string)(
            ($parse['server']['HTTP_HOST'] ?? null)
            ?: ($parse['server']['HOST'] ?? null)
            ?: ($parse['server']['SERVER_NAME'] ?? null)
            ?: WelineEnv::get('server.http_host', '')
            ?: WelineEnv::get('server.host', '')
            ?: WelineEnv::get('server.server_name', '')
            ?: 'localhost'
        );
        WelineEnv::set('request.scheme', $scheme, 'WlsRuntime processUrlParse');
        WelineEnv::set('server.http_host', $host, 'WlsRuntime processUrlParse');
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
        
        $localization = State::resolveLocalizationFromPathSegments(\array_slice($segments, 0, 4));
        $currency = (string)($localization['currency'] ?? '');
        $language = (string)($localization['language'] ?? '');
        
        // 设置到 $_SERVER（URL 路径中的值优先级最高）
        if ($currency !== '') {
            $_SERVER['WELINE_USER_CURRENCY'] = $currency;
            RequestContext::currency($currency);
            // 同步到 WelineEnv
            WelineEnv::set('user.currency', $currency, 'WlsRuntime parseUrlLangCurrency');
        }
        if ($language !== '') {
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
        } catch (\Throwable) {
            // 蹇界暐鍝嶅簲澶村啓鍏ラ敊璇紝涓嶅奖鍝嶄富娴佺▼銆?
        }
    }

    private function applyDynamicFirstRenderHeaders(
        array $timing,
        ?Request $request,
        bool $fpcHit,
        ?Response $targetResponse = null
    ): void
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            if ($targetResponse === null && (!$request || !method_exists($request, 'getResponse'))) {
                return;
            }

            $response = $targetResponse ?? $request->getResponse();
            if (!$response || !method_exists($response, 'setHeader')) {
                return;
            }

            $response->setHeader('X-WLS-First-Render-Total-Ms', (string)($timing['total_ms'] ?? 0));
            $response->setHeader('X-WLS-Warmup-Status', $this->currentWarmupStatus());
            $response->setHeader('X-WLS-FPC-Status', $this->currentFpcStatus($response, $fpcHit));
            if ($this->isInternalHomepageFpcPrimeRequest()) {
                $receipt = ObjectManager::getInstance(FullPageCacheCoordinator::class)
                    ->currentInternalHomepageWarmupReceipt();
                $encodedReceipt = $this->encodeHomepageWarmupReceipt($receipt);
                if ($encodedReceipt !== '') {
                    // Internal-only identity handoff. This header is never
                    // emitted for public traffic and x-wls-* is excluded from
                    // cached response headers by FullPageCacheCoordinator.
                    $response->setHeader('X-WLS-Internal-FPC-Receipt', $encodedReceipt);
                }
            }
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
        $header = $response->getHeader('X-WLS-Controller-Cache');
        if (\is_scalar($header) && \trim((string)$header) !== '') {
            return \strtolower(\trim((string)$header));
        }

        // Modules may expose a generic runtime-cache lookup span. The module
        // profile key and span prefix remain opaque to Framework.
        foreach ($timing as $profile) {
            if (!\is_array($profile)) {
                continue;
            }
            foreach (\array_reverse($profile) as $step) {
                $name = \is_array($step) && \is_scalar($step['name'] ?? null)
                    ? (string)$step['name']
                    : '';
                if ($name === '' || !\str_ends_with($name, '::runtime_cache_get')) {
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
        $this->recordWlsPerformancePanelTiming($timing, $isDev);
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

    private function recordWlsPerformancePanelTiming(array $timing, bool $isDev): void
    {
        if (!$isDev
            && !(\defined('DEBUG') && DEBUG)
            && !\Weline\Framework\Manager\ObjectManager::getInstance(DeveloperAccessPolicy::class)->canAccessApi()
        ) {
            return;
        }

        try {
            $timing['request_id'] = (string)($timing['request_id'] ?? RequestLifecycleTrace::ensureRequestId());
            $timing['pid'] = (int)($timing['pid'] ?? (\getmypid() ?: 0));
            $timing['worker_id'] = (string)($timing['worker_id'] ?? ($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: ''));
            $timing['worker_port'] = (string)($timing['worker_port'] ?? ($_SERVER['WLS_WORKER_PORT'] ?? $_ENV['WLS_WORKER_PORT'] ?? \getenv('WLS_WORKER_PORT') ?: ''));
            $timing['instance'] = (string)($timing['instance'] ?? ($_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: ''));
            $this->wlsRuntimeAdapter()?->recordPerformanceTrace($timing);
        } catch (\Throwable) {
        }
    }

    private function decorateCachedFpcResponseForTelemetry(Response $response, Request $request): void
    {
        if (!$this->shouldDecorateCachedFpcResponseForTelemetry()) {
            return;
        }

        try {
            $body = $response->getBody();
            if ($body === '') {
                return;
            }

            $contentEncodingHeader = $response->getHeader('Content-Encoding');
            $contentEncoding = \strtolower(\trim(\is_array($contentEncodingHeader)
                ? (string)($contentEncodingHeader[0] ?? '')
                : (string)($contentEncodingHeader ?? '')
            ));
            $isGzip = $contentEncoding === 'gzip';
            if ($isGzip) {
                $decoded = \gzdecode($body);
                if (!\is_string($decoded)) {
                    return;
                }
                $body = $decoded;
            } elseif ($contentEncoding !== '') {
                return;
            }

            $preparedBody = TelemetryBroadcaster::broadcast($body, $request, true);
            if ($preparedBody === $body) {
                return;
            }
            if ($isGzip) {
                $encoded = \gzencode($preparedBody, 6);
                if (!\is_string($encoded)) {
                    return;
                }
                $preparedBody = $encoded;
            }
            $response->setBody($preparedBody);
            if ($response->getHeader('Content-Length') !== null) {
                $response->setHeader('Content-Length', (string)\strlen($preparedBody));
            }
            $response->markTelemetryPrepared(true);
        } catch (\Throwable) {
        }
    }

    private function shouldDecorateCachedFpcResponseForTelemetry(): bool
    {
        try {
            return \Weline\Framework\Manager\ObjectManager::getInstance(DeveloperAccessPolicy::class)->canAccessApi();
        } catch (\Throwable) {
            return false;
        }
    }

    private function getPerformanceConfig(): array
    {
        if ($this->performanceConfig !== null) {
            return $this->performanceConfig;
        }

        $serverConfig = Env::getInstance()->getConfig('wls') ?? [];
        $performanceConfig = \is_array($serverConfig['performance'] ?? null) ? $serverConfig['performance'] : [];
        $verbose = $this->wlsRuntimeAdapter()?->isVerboseLog() ?? false;
        $this->performanceConfig = \array_merge([
            'slow_request_threshold_ms' => 500,
            'response_headers_enabled' => $verbose,
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
        if (!$this->isBackendLoginReturnDocumentRequest($request, $method)) {
            return $redirectUrl;
        }

        $redirectPath = (string)(parse_url($redirectUrl, PHP_URL_PATH) ?: '');
        $normalizedRedirectPath = strtolower($redirectPath);
        if ($normalizedRedirectPath === ''
            || !str_ends_with($normalizedRedirectPath, '/admin/login')
        ) {
            return $redirectUrl;
        }
        if ($this->redirectUrlHasBackendReturnUrl($redirectUrl)) {
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
        if ($this->isBackendLoginReturnApiOrInterfaceUri($uri)) {
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
        $baseBackendPrefix = $this->resolveBackendAreaPrefixPath($backendPrefix);
        $hasLocalizedPrefix = $backendPrefix !== ''
            && ($uriPath === $backendPrefix || str_starts_with($uriPath, $backendPrefix . '/'));
        $hasBasePrefix = $baseBackendPrefix !== ''
            && ($uriPath === $baseBackendPrefix || str_starts_with($uriPath, $baseBackendPrefix . '/'));
        if ($backendPrefix !== '' && $uriPath !== '' && !$hasLocalizedPrefix && !$hasBasePrefix) {
            $uri = $backendPrefix . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        }
        $uri = $this->normalizeBackendLoginReturnUri($uri);

        $scheme = $request?->isSecure() ? 'https' : 'http';
        $host = $this->resolveBackendLoginReturnHost($request, $scheme);
        $returnUrl = $scheme . '://' . $host . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        $query = [
            'no_access_reason' => 'not_logged_in',
            'return_url' => $returnUrl,
        ];

        return $this->removeBackendLoginReturnParams($redirectUrl) . (str_contains($this->removeBackendLoginReturnParams($redirectUrl), '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function isBackendLoginReturnDocumentRequest(?Request $request, string $method): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if ($request instanceof Request) {
            return $request->isDocumentNavigationRequest();
        }

        $fetchDest = strtolower(trim($this->backendLoginReturnHeaderValue(null, 'Sec-Fetch-Dest')));
        if ($fetchDest !== '' && $fetchDest !== 'document') {
            return false;
        }

        $fetchMode = strtolower(trim($this->backendLoginReturnHeaderValue(null, 'Sec-Fetch-Mode')));
        if ($fetchMode !== '' && $fetchMode !== 'navigate') {
            return false;
        }

        $requestedWith = strtolower(trim($this->backendLoginReturnHeaderValue(null, 'X-Requested-With')));
        if ($requestedWith === 'xmlhttprequest') {
            return false;
        }

        $accept = strtolower(trim($this->backendLoginReturnHeaderValue(null, 'Accept')));
        if ($accept === '' || $accept === '*/*') {
            return true;
        }
        if (str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml')) {
            return true;
        }

        return !str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/event-stream')
            && !str_contains($accept, 'application/xml')
            && !str_contains($accept, 'text/xml');
    }

    private function backendLoginReturnHeaderValue(?Request $request, string $name): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = (string)(
            ($request?->getServer($serverKey) ?: null)
            ?: ($_SERVER[$serverKey] ?? '')
        );
        if ($value !== '') {
            return $value;
        }

        $header = $request?->getHeader($name);
        if (is_array($header)) {
            return implode(',', $header);
        }

        return is_scalar($header) ? (string)$header : '';
    }

    private function isBackendLoginReturnApiOrInterfaceUri(string $uri): bool
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return false;
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), ['api', 'rest', 'graphql'], true)) {
                return true;
            }
        }

        return false;
    }

    private function redirectUrlHasBackendReturnUrl(string $url): bool
    {
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        parse_str($query, $params);
        $returnUrl = $params['return_url'] ?? '';
        return is_string($returnUrl) && trim($returnUrl) !== '';
    }

    private function resolveBackendAreaPrefixPath(string $localizedBackendPrefix): string
    {
        $configuredPrefix = trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($configuredPrefix !== '') {
            return '/' . $configuredPrefix;
        }

        $segments = explode('/', trim($localizedBackendPrefix, '/'));
        $firstSegment = (string)($segments[0] ?? '');
        return $firstSegment !== '' ? '/' . $firstSegment : '';
    }

    private function resolveBackendLoginReturnHost(?Request $request, string $scheme): string
    {
        $host = trim((string)(
            ($request?->getServer('HTTP_HOST') ?: null)
            ?: WelineEnv::get('server.http_host', '')
            ?: WelineEnv::get('server.host', '')
            ?: WelineEnv::get('server.server_name', '')
            ?: ($request?->getServer('SERVER_NAME') ?: null)
            ?: 'localhost'
        ));

        if ($host === '' || str_contains($host, ':') || str_starts_with($host, '[')) {
            return $host !== '' ? $host : 'localhost';
        }

        $port = trim((string)(
            ($request?->getServer('HTTP_WELINE_ORIGINAL_PORT') ?: null)
            ?: WelineEnv::get('http_weline_original_port', '')
            ?: ''
        ));
        if ($port === '' || !ctype_digit($port)) {
            return $host;
        }

        if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
            return $host;
        }

        return $host . ':' . $port;
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
        return State::isAllowedCurrencyCode($segment)
            || (bool)preg_match('/^[A-Z]{3}$/', $segment);
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
        if ($this->eventManager !== null) {
            try {
                $this->eventManager->resetRequestState();
                $this->eventManager->dispatch('Weline_Framework::Runtime::reset');
            } catch (\Throwable $e) {
                w_log_error('[WlsRuntime] Reset event error: ' . $e->getMessage());
            }
        }

        ObjectManager::clearCurrentFiberInstances();
        if (Runtime::isPersistent()) {
            ObjectManager::clearCurrentRequestScope();
        }
        FiberOutputBuffer::resetCurrent();
    }

    private function bindProcessScopedServicesForCurrentRequest(): void
    {
        if ($this->eventManager !== null) {
            $eventManager = $this->eventManager;
            ObjectManager::setInstance(EventsManager::class, $eventManager);
        }
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
