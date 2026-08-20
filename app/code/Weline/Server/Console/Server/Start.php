<?php
declare(strict_types=1);

/**
 * Weline Server - 启动命令
 * 
 * 跨平台多进程服务器，支持 Windows/Linux/Mac
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Compilation\FrameworkCompileManifest;
use Weline\Framework\Compilation\FrameworkCompiler;
use Weline\Framework\Container\CompiledContainer;
use Weline\Framework\Container\ContainerRuntime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Console\Server\Stop as MainStop;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\MasterChildCredentialStore;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\SharedSidecarInspector;
use Weline\Server\Service\SharedStateRuntimeScope;
use Weline\Server\Service\SharedStateRuntimeResolver;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\WlsLogService;
use Weline\Server\Log\LogConfig;
use Weline\Server\Service\Policy\RuntimePolicyControlService;
use Weline\Server\Service\Policy\RuntimePolicyCompiler;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\SavedInstanceConfigStore;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayProjectEndpointReader;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\Runtime\PhpRuntimeSafetyProfile;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;
use Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper;
use Weline\Server\Service\Runtime\RuntimeDiagnosticsFormatter;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\WindowsListenerHandoff;
use Weline\Server\Service\Runtime\TlsProcessProfileConfigurator;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Service\Runtime\ServerLifecycleOperationLock;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\CertificateTrustProvenanceException;
use Weline\Server\Service\Provider\GatewayFallbackProvider;
use Weline\Server\Service\Provider\GatewayJoinBackendProvider;
use Weline\Server\Service\Provider\GatewayProvider;
use Weline\Server\Service\Provider\RuntimeTaskWatchdogProvider;

/**
 * server:start - 启动常驻内存服务器
 */
class Start extends CommandAbstract
{
    use StartupChangingArgsInspector;

    /** Exact one-release cleanup fence for the retired protocol sidecar. */

    /**
     * 默认 HTTP 端口（WLS 原生直连）
     */
    public const DEFAULT_PORT = 80;
    
    /**
     * 默认 HTTPS 端口
     */
    public const DEFAULT_PORT_HTTPS = 443;
    
    /**
     * 默认端口（80/443）被占用时的备用端口
     */
    public const DEFAULT_PORT_FALLBACK = 9981;

    /**
     * Worker 端口分配锁等待超时（秒）
     */
    private const WORKER_PORT_ALLOCATION_LOCK_TIMEOUT = 5;

    private const PANEL_MODE_DEFAULT_MEMORY_LIMIT = '512M';

    private const PUBLIC_HOST_IP_PROBE_TIMEOUT_MS = 1200;

    /**
     * Project certificate selectors are local state and must never inherit the
     * stores' compatibility 300-second lock wait during an interactive start.
     */
    private const STARTUP_CERTIFICATE_STATE_BUDGET_SECONDS = 8.0;

    /**
     * One retained startup listener must reach its immutable Master handoff
     * within the allocator reservation lifetime. Every allocator observation
     * in that phase receives this same absolute deadline, while each lock wait
     * remains capped by GatewayPortLeaseAllocator at 250ms.
     */
    private const STARTUP_LISTENER_STATE_BUDGET_SECONDS = 120.0;

    /**
     * Each state-store operation remains inside the platform's 300-second
     * lock contract. The separate total budget below bounds the cold compile
     * plus the renewed target-bound handoff phase.
     */
    private const STARTUP_LISTENER_STATE_EMULATED_WINDOWS_BUDGET_SECONDS = 300.0;

    private const STARTUP_LISTENER_STATE_EMULATED_WINDOWS_TOTAL_BUDGET_SECONDS = 600.0;

    /** Failed-start reservation cleanup is best effort and must never stall shutdown. */
    private const STARTUP_LISTENER_CLEANUP_BUDGET_SECONDS = 1.0;

    /**
     * 启动维护事务必须在一个总 deadline 内看到控制操作终态。
     * Direct Master 仅在全部 READY Worker ACK 后提交 maintenance_mode，
     * 因此“operation 已退出队列 + 状态相符”才允许启动命令报告成功。
     */
    private const MAINTENANCE_SYNC_TIMEOUT_SEC = 12.0;
    private const WINDOWS_MAINTENANCE_SYNC_TIMEOUT_SEC = 30.0;

    private const MAINTENANCE_SYNC_POLL_INTERVAL_USEC = 50_000;
    private const RESTART_CLEANUP_TIMEOUT_SECONDS = 12.0;

    private const WINDOWS_RESTART_CLEANUP_TIMEOUT_SECONDS = 30.0;

    private const FAST_RESTART_CLEANUP_TIMEOUT_SECONDS = 6.0;


    private const PUBLIC_IPV4_PROBE_URLS = [
        'https://checkip.amazonaws.com',
        'https://api.ipify.org',
    ];

    private const PUBLIC_IPV6_PROBE_URLS = [
        'https://api64.ipify.org',
    ];

    /**
     * Container is promoted last: until every data-only registry is complete,
     * an old Master can still replace a Worker with its original digest.
     */
    private const FRAMEWORK_RUNTIME_REGISTRY_FILES = [
        'modules.php',
        'query_providers.php',
        'runtime_policy_providers.php',
        'template_cache_policies.php',
        'compile_manifest.php',
        'container.php',
    ];

    /**
     * 启动中实例的 worker 端口预留 TTL（秒）
     */
    private const WORKER_PORT_RESERVATION_TTL = 120;

    /**
     * Listener ports must stay below the lowest default ephemeral TCP range
     * shared by Linux, macOS and Windows. Otherwise a short-lived loopback
     * connection can claim the future Worker port between preflight and bind.
     */
    private const PRIVATE_WORKER_PORT_MIN = 10000;
    private const PRIVATE_WORKER_PORT_SPAN = 7000;
    private const CONSERVATIVE_EPHEMERAL_PORT_START = 32768;
    
    /**
     * 可用的进程控制函数
     */
    protected array $availableFunctions = [];

    private ?\Weline\Server\Service\AdministratorAuthorizationSession $administratorAuthorizationSession = null;
    
    /**
     * 使用的启动方式
     */
    protected string $usedMethod = '';
    
    /**
     * 启动锁文件句柄（防止并发启动）
     */
    private $startLockHandle = null;
    
    /**
     * 启动锁文件路径
     */
    private string $startLockFile = '';

    private ?ServerLifecycleOperationLock $lifecycleOperationLock = null;

    private bool $startLockBlockedByLifecycle = false;

    /**
     * Worker 端口分配锁句柄
     */
    private $workerPortAllocationLockHandle = null;

    /**
     * Worker 端口分配锁文件路径
     */
    private string $workerPortAllocationLockFile = '';

    /**
     * 与启动锁对应的实例名（shutdown 清理用）
     */
    private string $startLockInstanceName = '';

    /**
     * 已向独立 Master 或前台 Master 完成子进程交接；为 true 时不在 shutdown 中杀 WLS
     */
    private bool $wlsStartupProcessHandoffDone = false;

    /**
     * 已执行 startMasterInBackground / runMasterProcess 尝试拉起子进程；fatal 退出时需清理残留
     */
    private bool $wlsChildProcessesMayExist = false;

    /**
     * Exact process-birth authority for the detached Master created by this
     * launcher. It is never reconstructed from a port or name-prefix scan.
     *
     * @var array{pid:int,process_birth:string,pid_namespace_id:string,process_name:string,launch_id:string,pname:string}|null
     */
    private ?array $spawnedMasterTerminationLease = null;

    /** @var array<string,mixed>|null pre-start public listener lease */
    private ?array $startupPublicEdgeLease = null;

    /** @var array<string,mixed>|null pre-start gateway loopback backend lease */
    private ?array $startupGatewayBackendLease = null;

    /** @var resource|null POSIX listener inherited by the new Master as FD 3 */
    private mixed $startupPublicEdgeListener = null;

    /** @var resource|null POSIX loopback listener inherited by the new Master as FD 3 */
    private mixed $startupGatewayBackendListener = null;

    private ?MasterLeaseManager $masterLeaseManager = null;

    private ?MasterLeaseRuntimeIdentity $masterLeaseRuntimeIdentity = null;

    /** @var array<string,mixed>|null target-bound Windows socket handoff intent */
    private ?array $startupWindowsListenerHandoffIntent = null;

    /** Public instance identity owning the one listener reserved by this Start. */
    private string $startupListenerInstanceName = '';

    /**
     * 当前启动事务已向共享状态服务登记 consumer；只有 Master 成功接管后才转移释放责任。
     */
    private bool $sharedStateConsumerAcquired = false;

    private bool $sharedStateConsumerHandoffDone = false;

    /**
     * 启动完成后尾部输出的延迟告警（用于确保提示位于最后且醒目）
     */
    private ?string $deferredStartupWarning = null;

    /**
     * Last startup preflight profile, reused by the post-start advisor so it
     * cannot drift from the resolver or rerun a listener capability probe.
     */
    private ?WlsRuntimeProfile $latestRuntimeProfile = null;
    private ?string $latestRuntimeProfileListenHost = null;

    private ?float $startupCertificateStateDeadlineMonotonic = null;

    private ?float $startupListenerStateDeadlineMonotonic = null;

    private ?float $startupListenerStateTotalDeadlineMonotonic = null;

    /** Native cold recovery discarded an authentic but superseded manifest. */
    private bool $nativeServingManifestRebuildRequired = false;

    /** @var list<string> */
    private array $nativeServingManifestRebuildActiveDomains = [];

    private ?SslCertificateService $deferredCertificatePreparationService = null;

    private string $latestRuntimeStrategy = RuntimeStrategyResolver::STRATEGY_AUTO;

    /**
     * 平滑重启临时修改维护态前的持久配置快照。
     *
     * @var array{instance_name:string, enabled:bool}|null
     */
    private ?array $restartMaintenanceSnapshot = null;

    private bool $restartMaintenanceShutdownRegistered = false;

    /**
     * Required-sync is invocation state rather than part of the protected
     * extension signature. Existing Start subclasses may still override the
     * historical two-argument, void method without a PHP signature break.
     */
    private bool $wlsMaintenanceSyncRequired = false;

    /**
     * 旧实例停止前确认正在监听的数据面/控制面端口。
     * Session/Memory 共享 sidecar 不属于单实例重启交接。
     *
     * @var list<int>
     */
    private array $restartHandoffPorts = [];

    /**
     * 空端口集合也是已经冻结的有效快照，不能在新 sidecar 创建后重新捕获。
     */
    private bool $restartHandoffCaptured = false;
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->traceStartupPhase('(unparsed)', 'execute:enter');
        $this->nativeServingManifestRebuildRequired = false;
        $this->nativeServingManifestRebuildActiveDomains = [];

        if (\array_key_exists('independent', $args)
            || $this->hasCliArgvToken(['--independent', '-independent'])) {
            $this->printer->error(__(
                'WLS 已不再支持 independent 拓扑；所有平台请使用 --direct，兼容诊断时可显式使用 --dispatcher。'
            ));
            return 1;
        }

        // Mutually exclusive topology flags are pure CLI validation and must run
        // before getServerConfig(), which may touch certificate/database state.
        $directRequested = \array_key_exists('direct', $args)
            || $this->hasCliArgvToken(['--direct', '-direct']);
        $dispatcherRequested = \array_key_exists('dispatcher', $args)
            || $this->hasCliArgvToken(['--dispatcher', '-dispatcher']);
        if ($directRequested && $dispatcherRequested) {
            $this->printer->error(__('Conflicting WLS topology CLI options: --direct and --dispatcher.'));
            return 1;
        }
        $noNginxRequested = \array_key_exists('no-nginx', $args)
            || \array_key_exists('no_nginx', $args)
            || $this->hasCliArgvToken(['--no-nginx']);
        try {
            $edgeCliMode = $this->resolveEdgeCliMode($args);
        } catch (\InvalidArgumentException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        if ($noNginxRequested && $edgeCliMode !== null && $edgeCliMode !== 'wls') {
            $this->printer->error(__('--no-nginx 只能与 --edge=wls 一起使用。'));
            return 1;
        }
        try {
            // Every WLS 2.0 mode persists project-owned state. Prove the
            // platform's atomic publication primitive before welcome/config
            // discovery, start-lock mutation, master-only launch, certificate
            // access, or any process cleanup can touch runtime state.
            \Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem::assertAtomicWriteRuntimeCapability();
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        // 欢迎语
        $this->printWelcome();

        // --cli / -cli：强制使用 PHP 内置 CLI 服务器
        $useCli = isset($args['cli']);
        if (!$useCli) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--cli' || $val === '-cli')) {
                    $useCli = true;
                    break;
                }
            }
        }
        if ($useCli) {
            $this->printer->error(__('WLS 启动不使用 PHP 内置 CLI 服务器；需要纯 WLS 时请使用 --no-nginx。'));
            return 1;
        }

        // Platform topology exclusions must fail before capability fallback;
        // otherwise an explicit Windows Direct request could silently become
        // a PHP CLI server when WLS dependencies are unavailable.
        $instanceName = $this->parseInstanceName($args);
        $runtimeResolver = new RuntimeStrategyResolver();
        try {
            $config = $this->getServerConfig($instanceName, $args);
            $runtimeResolver->resolveTopologyIntent($config, $args);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }

        // Capture the launcher's immutable birth before runtime setup creates
        // locks, native sockets or child processes. Later lifecycle/listener
        // payloads reuse this process-local cache instead of entering Windows
        // process FFI from a native critical section.
        try {
            (new MasterLeaseRuntimeIdentity())->captureProcessIdentity((int)\getmypid());
        } catch (\Throwable $exception) {
            $this->printer->error(__(
                'WLS 启动器进程出生身份不可用：%{1}',
                [$exception->getMessage()],
            ));
            return 1;
        }

        // 必须在 Master、共享侧车和 Worker 创建前发布；全部 WLS PHP 子进程继承同一安全档案。
        $phpRuntimeSafetyProfile = PhpRuntimeSafetyProfile::applyForWlsProcessTree();
        if (($phpRuntimeSafetyProfile['applied'] ?? false) === true) {
            $this->printer->warning(__(
                '检测到 Windows ARM64 上运行 x64 PHP 仿真；WLS 已对后续 PHP 子进程关闭 CLI OPcache 与 JIT，避免已确认的原生访问冲突；请使用原生 ARM64 PHP 恢复字节码缓存。'
            ));
        }

        // 检测可用函数
        $this->detectAvailableFunctions();
        
        // WLS 运行时不可用时不回退到 PHP CLI Server；--no-nginx 仍使用
        // 完整的 WLS Master/Worker 数据面。
        $cliService = ObjectManager::getInstance(CliServerService::class);
        if (!$cliService->isWelineServerAvailable()) {
            $this->printer->warning(__('Weline Server 不可用：%{1}', [$cliService->getUnavailableReason()]));
            $this->printer->error(__('WLS 不执行 PHP CLI 回退；请修复 WLS 运行时依赖。'));
            return 1;
        }
        
        // 解析实例名称
        $this->restartHandoffPorts = [];
        $this->restartHandoffCaptured = false;
        $this->traceStartupPhase($instanceName, 'execute:instance-parsed');
        
        // 仅运行 Master 进程（由 daemon 模式后台启动时调用，内部使用）
        // master-only 不需要启动锁，因为它是由已经获取锁的父进程启动的
        if (isset($args['master-only']) || getenv('WLS_MASTER_ONLY')) {
            try {
                $this->runMasterOnly($instanceName);
            } catch (\Throwable $exception) {
                $this->recordMasterOnlyStartupFailure($instanceName, $exception);
                $this->printer->error(__(
                    'Master 启动失败：%{1}',
                    [$exception->getMessage()]
                ));
            }
            return;
        }
        
        // 获取启动锁，防止并发启动同一实例
        if (!$this->acquireStartLock($instanceName)) {
            if ($this->startLockBlockedByLifecycle) {
                $this->printer->error(__(
                    '无法启动：实例 [%{1}] 正在执行启动、停止、重载或清理事务，请等待该有界事务结束后重试。',
                    [$instanceName],
                ));
                return 1;
            }
            $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $instanceName . '.lock';
            $lockInfo = [];
            try {
                $lockData = GatewayProjectStateFilesystem::readOptional(
                    $lockFile,
                    16_384,
                    'WLS persistent start lock',
                    true,
                );
                $decodedLock = $lockData !== null ? \json_decode($lockData, true) : null;
                $lockInfo = \is_array($decodedLock) ? $decodedLock : [];
            } catch (\Throwable) {
                // An unsafe/oversized lock payload is not ownership evidence.
                $lockInfo = [];
            }
            $ownerPid = (int) ($lockInfo['pid'] ?? 0);
            $ownerBirth = \strtolower(\trim((string)($lockInfo['process_birth'] ?? '')));
            $ownerPidNamespace = \trim((string)($lockInfo['pid_namespace_id'] ?? ''));

            $this->printer->error(__('无法启动：实例 [%{1}] 正在被另一个进程启动中', [$instanceName]));
            if ($ownerPid > 0) {
                $this->printer->note(__('锁持有进程 PID：%{1}', [$ownerPid]));
            }
            if (!\defined('STDIN')) {
                $this->printer->note(__('请稍后重试，或在交互式终端中确认是否强制启动。'));
                return;
            }
            $this->printer->warning(__('是否直接强制启动并终止另一个启动进程？[y/N]: '));
            echo '  > ';
            $input = \trim((string) @\fgets(STDIN));
            if (!\in_array(\strtolower($input), ['y', 'yes', '是'], true)) {
                $this->printer->note(__('已取消强制启动。请稍后重试，或检查是否有其他终端正在启动服务器'));
                return;
            }

            $this->printer->warning(__('正在强制终止另一个启动进程并清理实例 [%{1}] 的启动残留...', [$instanceName]));
            $ownerRuntimeIdentity = new MasterLeaseRuntimeIdentity();
            if ($ownerPid > 0
                && $ownerPid !== \getmypid()
                && $ownerRuntimeIdentity->isProcessAlive($ownerPid)
            ) {
                if (!$this->isVerifiedStartLockOwnerProcess(
                    $ownerPid,
                    $ownerBirth,
                    $ownerPidNamespace,
                )) {
                    $this->printer->error(__(
                        '启动锁 PID %{1} 的进程出生身份或命令行无法验证；'
                        . '已拒绝终止可能复用的无关 PID。',
                        [$ownerPid],
                    ));
                    return 1;
                }
                // Re-observe immediately before the destructive operation;
                // a launcher which exited during confirmation is left alone
                // and the normal flock acquisition decides the takeover.
                if ($this->isVerifiedStartLockOwnerProcess(
                    $ownerPid,
                    $ownerBirth,
                    $ownerPidNamespace,
                )) {
                    $takeover = $ownerRuntimeIdentity->terminateExactProcessIdentity(
                        $ownerPid,
                        $ownerBirth,
                        $ownerPidNamespace,
                        0.5,
                    );
                    if (!(bool)($takeover['released'] ?? false)) {
                        $this->printer->error(__(
                            '启动锁持有者无法通过稳定进程句柄安全终止；'
                            . '已拒绝使用裸 PID 强制接管。reason=%{1}',
                            [(string)($takeover['reason'] ?? 'stable termination unavailable')],
                        ));
                        return 1;
                    }
                }
            }
            if ($this->acquireStartLock($instanceName, 2)) {
                $this->printer->success(__('已强制接管启动锁，继续启动实例 [%{1}]', [$instanceName]));
            } else {
                $this->printer->error(__('强制接管时仍未获取启动锁，已拒绝在无锁状态下启动实例 [%{1}]。', [$instanceName]));
                return 1;
            }
            // Only enumerate and kill same-instance leftovers after this
            // launcher owns the persistent lock inode. Otherwise a third
            // launcher could win the post-kill race and be mistaken for the
            // generation being force-cleaned.
            if (!$this->cleanupFailedStartupProcesses($instanceName, 16)) {
                $this->printer->error(__(
                    '强制接管后无法证明旧代 WLS 进程全部退出；已保持启动锁并拒绝启动新代。'
                ));
                return 1;
            }
        }
        
        // 注册关闭时释放锁；fatal / 未交接时按实例前缀清理可能残留的 WLS 子进程
        $this->startLockInstanceName = $instanceName;
        $this->startupListenerInstanceName = $instanceName;
        $this->wlsStartupProcessHandoffDone = false;
        $this->wlsChildProcessesMayExist = false;
        $this->sharedStateConsumerAcquired = false;
        $this->sharedStateConsumerHandoffDone = false;
        $this->traceStartupPhase($instanceName, 'start-lock:acquired');
        // Cleanup must run while this process still owns the launch lock.
        // Releasing first would allow a same-name launch to start before the
        // old failure cleanup enumerates process names, letting it kill the
        // new generation. The lock release callback is deliberately last.
        \register_shutdown_function([$this, 'shutdownCleanupOrphanWlsProcessesIfNeeded']);
        \register_shutdown_function([$this, 'releaseStartLock']);
        
        // --win：Windows 子进程可见窗口；--foreground：阻塞前台 Master（daemon=false）。
        $windowMode = $this->resolveWindowModeFlag($args);
        $foregroundMode = $this->resolveForegroundOnlyFlag($args);

        // -log / --log：启用进程管理日志 + verbose；-foreground 默认同步开启全量日志便于排障
        $enableLog = isset($args['log']);
        if (!$enableLog) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--log' || $val === '-log')) {
                    $enableLog = true;
                    break;
                }
            }
        }
        if ($foregroundMode) {
            $enableLog = true;
        }
        if ($enableLog) {
            Processer::setLogEnabled(true);
        }
        LogConfig::bootstrapVerbose($enableLog);

        // 获取配置（命令行参数 > 已保存实例配置 > env配置 > 默认值）
        $this->beginStartupListenerStateDeadline();
        $this->traceStartupPhase($instanceName, 'config:before');
        $host = $config['host'];
        $portExplicit = ($config['port_explicit'] ?? false) === true;
        $configuredEdgeMode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? '')
        )));
        if ($configuredEdgeMode === '') {
            $configuredEdgeMode = 'auto';
        }
        try {
            $projectIdentity = new \Weline\Server\Service\Edge\Gateway\ProjectIdentityStore();
            $projectUuid = $projectIdentity->projectUuid(
                $this->startupListenerStateDeadline(),
            );
            $launchId = \bin2hex(\random_bytes(16));
            $previousEndpoint = $this->getInstanceManager()->getRawInstanceData($instanceName);
            $previousGateway = \is_array($previousEndpoint['gateway'] ?? null)
                ? $previousEndpoint['gateway']
                : [];
            $previousEndpointName = \trim((string)(
                $previousEndpoint['instance_name']
                ?? $previousEndpoint['name']
                ?? $instanceName
            ));
            $previousEndpointPort = (int)(
                $previousEndpoint['main_port']
                ?? $previousEndpoint['port']
                ?? 0
            );
            // A same-instance endpoint is only a reason to defer the bind; it
            // is never accepted as proof that the old listener is alive or
            // owned by WLS. Occupant inspection below remains authoritative.
            $configuredStartupPort = (int)($config['port'] ?? 0);
            $requestedGatewayBackendPort = \in_array(
                $configuredEdgeMode,
                [
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY,
                ],
                true,
            ) && $portExplicit
                    ? (int)($config['requested_port'] ?? $configuredStartupPort)
                    : 0;
            $restartWasRequested = isset($args['r']) || isset($args['restart']);
            $deferStartupListenerReservation = (\is_array($previousEndpoint)
                    && $previousEndpoint !== []
                    && $previousEndpointName === $instanceName
                    && (int)($previousEndpoint['master_pid'] ?? $previousEndpoint['pid'] ?? 0) > 0
                    && $previousEndpointPort > 0
                    && ($previousEndpointPort === $configuredStartupPort
                        || ($configuredEdgeMode
                                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO
                            && \strtolower(\trim((string)(
                                $previousGateway['requested_mode'] ?? ''
                            ))) === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO)))
                || ($restartWasRequested
                    && $configuredStartupPort > 0
                    && Processer::isPortInUse($configuredStartupPort));
            $instanceGenerationState = $projectIdentity->advanceInstanceGeneration(
                $instanceName,
                deadlineMonotonic: $this->startupListenerStateDeadline(),
            );
            if (!\hash_equals(
                $projectUuid,
                (string)($instanceGenerationState['project_uuid'] ?? ''),
            )
                || !\hash_equals(
                    $instanceName,
                    (string)($instanceGenerationState['instance_id'] ?? ''),
                )
                || (int)($instanceGenerationState['generation'] ?? 0) < 1
            ) {
                throw new \RuntimeException(
                    'WLS project instance generation allocation failed closed.',
                );
            }
            $instanceGeneration = (int)$instanceGenerationState['generation'];
            $gatewayCapabilityDeclaration = (
                new \Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityDeclaration()
            )->resolve(
                \is_array($config['gateway'] ?? null) ? $config['gateway'] : [],
                $instanceGeneration,
            );
            $publicLeaseBindHost = $configuredEdgeMode
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO
                    ? $this->resolveGatewayFallbackBindHost(
                        $config,
                        (string)($config['host'] ?? '127.0.0.1'),
                    )
                    : $this->resolveServerListenHost(
                        (string)($config['host'] ?? '127.0.0.1'),
                    );
            $startupDecision = $this->createGatewayStartupDecisionForListenerPhase();
            // In auto/gateway modes `-p` is a loopback join-backend intent,
            // never a request to expose that number as the degraded public
            // WLS address. Only explicit pure-WLS mode owns a public exact
            // port request.
            $publicPortExplicit = $configuredEdgeMode
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
                && $portExplicit;
            $edgeDecision = $startupDecision->decide(
                    $configuredEdgeMode,
                    $instanceName,
                    $publicPortExplicit,
                    (string)($config['source'] ?? 'runtime'),
                    $publicLeaseBindHost,
                    $publicPortExplicit ? (int)($config['port'] ?? 0) : null,
                    !$deferStartupListenerReservation,
                    $this->startupListenerStateDeadline(),
                );
            $this->startupPublicEdgeListener = $startupDecision->takeReservedListener();
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS 2.0 边缘模式解析失败：%{1}', [$exception->getMessage()]));
            return 1;
        }
        $effectiveEdgeMode = $edgeDecision->mode;
        if ($effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY
        ) {
            $deferStartupListenerReservation = false;
        }
        $this->startupPublicEdgeLease = $edgeDecision->portLease !== []
            ? $edgeDecision->portLease
            : null;
        $gatewayMode = $edgeDecision->isGateway();
        $noNginxRequested = $effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS;
        if ($noNginxRequested) {
            $config['edge'] = \array_merge(
                \is_array($config['edge'] ?? null) ? $config['edge'] : [],
                [
                    'adapter' => $edgeDecision->adapter,
                    'scope' => $edgeDecision->scope,
                    'source' => $edgeDecision->source,
                    'fallback_reason' => $edgeDecision->fallbackReason,
                ],
            );
            $config['edge_adapter'] = $edgeDecision->adapter;
            $fallbackPort = $edgeDecision->fallbackPort;
            if ($fallbackPort > 0) {
                $config['port'] = $fallbackPort;
            }
        } else {
            $config['edge'] = \array_merge(
                \is_array($config['edge'] ?? null) ? $config['edge'] : [],
                [
                    'adapter' => $edgeDecision->adapter,
                    'scope' => $edgeDecision->scope,
                    'source' => $edgeDecision->source,
                    'fallback_reason' => $edgeDecision->fallbackReason,
                ],
            );
            $config['edge_adapter'] = $edgeDecision->adapter;
        }
        $config['edge_mode'] = $effectiveEdgeMode;
        $config['edge']['mode'] = $effectiveEdgeMode;
        $config['gateway'] = \array_merge(
            \is_array($config['gateway'] ?? null) ? $config['gateway'] : [],
            [
                'mode' => $effectiveEdgeMode,
                'serving_mode' => $gatewayMode
                    ? 'gateway'
                    : ($effectiveEdgeMode
                        === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY
                            ? 'legacy'
                            : ($edgeDecision->isAutoFallback()
                                ? 'fallback_wls'
                                : 'native_wls')),
                'requested_mode' => $edgeDecision->requestedMode,
                'requested_backend_port' => $requestedGatewayBackendPort,
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceName,
                'launch_id' => $launchId,
                'instance_generation' => $instanceGeneration,
                'registration_lifecycle' =>
                    \Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle::initial(
                        $projectUuid,
                        $instanceName,
                        $instanceGeneration,
                        $launchId,
                    ),
                'backend_identity_schema' =>
                    \Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                'edge_decision' => $edgeDecision->toArray(),
                'public_lease' => $edgeDecision->portLease,
                // `requested_mode=auto` remains a WLS Edge Protocol 2 tenant
                // while its current serving surface is native fallback WLS.
                // This protocol identity is intent, not proof that a gateway
                // route has already been published.
                'protocol' => \in_array($edgeDecision->requestedMode, [
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY,
                ], true)
                    ? \Weline\Server\Service\Edge\Gateway\GatewayPaths::PROTOCOL
                    : '',
                'degraded_reason' => $gatewayMode ? '' : $edgeDecision->fallbackReason,
                'public_http' => $gatewayMode
                    ? (int)($edgeDecision->gateway['public_http'] ?? 0)
                    : 0,
                'public_https' => $gatewayMode
                    ? (int)($edgeDecision->gateway['public_https'] ?? 0)
                    : 0,
                'epoch' => $gatewayMode
                    ? (string)($edgeDecision->gateway['epoch'] ?? '')
                    : '',
            ],
            $gatewayCapabilityDeclaration,
        );
        if ($effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
        ) {
            unset($config['gateway'][
                \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
            ]);
            try {
                $manifestRecovery = \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::fromEndpoint(
                    \is_array($previousEndpoint) ? $previousEndpoint : [],
                    $instanceName,
                    $this->resolveCertificateTrustProfile($config),
                    $this->startupCertificateStateDeadline(),
                    $this->resolveCertificateHost($config, (string)$host),
                );
                if (\is_array($manifestRecovery)) {
                    // A schema-3 desired-state manifest outranks every configured
                    // or app/etc PEM fallback. It either supplies one active
                    // bootstrap snapshot or makes startup fail closed.
                    $config['gateway'][
                        \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
                    ] = $manifestRecovery;
                    $manifestDecision = \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::validate(
                        $manifestRecovery,
                        $this->resolveCertificateTrustProfile($config),
                        $this->startupCertificateStateDeadline(),
                        $this->resolveCertificateHost($config, (string)$host),
                    );
                    $config['ssl_cert'] = (string)$manifestDecision['cert_path'];
                    $config['ssl_key'] = (string)$manifestDecision['key_path'];
                    $config['ssl_domain'] = (string)$manifestDecision['domain'];
                }
            } catch (\Weline\Server\Service\Edge\NativeServingManifestRebuildRequiredException $exception) {
                // The endpoint proof was exact before certificate authority
                // advanced. Discard it and let ensureSslCertificate consume
                // only the current selector/tombstone; never reactivate its
                // stale PEM paths as a new generation.
                $this->nativeServingManifestRebuildRequired = true;
                $this->nativeServingManifestRebuildActiveDomains = $exception->activeDomains;
                unset($config['gateway'][
                    \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
                ]);
            } catch (\Throwable $throwable) {
                $this->printer->error(__('纯 WLS 启动拒绝损坏或不匹配的服务清单：%{1}', [
                    $throwable->getMessage(),
                ]));
                return 1;
            }
        }
        if ($gatewayMode && !$deferStartupListenerReservation) {
            try {
                $configuredBackendPort = (int)($config['port'] ?? self::DEFAULT_PORT);
                $gatewayBackendPort = $this->resolveGatewayInitialBackendPort(
                    $instanceName,
                    $configuredBackendPort,
                    $portExplicit,
                    true,
                );
                $config['port'] = $gatewayBackendPort;
                $config['gateway']['backend_port'] = $gatewayBackendPort;
                $config['gateway']['backend_lease'] = $this->startupGatewayBackendLease ?? [];
                if ($gatewayBackendPort !== $configuredBackendPort) {
                    $this->printer->note(__('已为项目分配稳定的宿主协调 loopback 回源端口：%{1}', [
                        (string)$gatewayBackendPort,
                    ]));
                }
            } catch (\Throwable $exception) {
                $this->printer->error(__('WLS 2.0 网关回源端口分配失败：%{1}', [
                    $exception->getMessage(),
                ]));
                return 1;
            }
        } elseif ($gatewayMode) {
            $deferredBackendPort = (int)($config['port'] ?? 0);
            if ($deferredBackendPort < 1 || $deferredBackendPort > 65535) {
                $this->printer->error(__('WLS 2.0 网关重启缺少有效的旧代回源端口。'));
                return 1;
            }
            $config['gateway']['backend_port'] = $deferredBackendPort;
            $config['gateway']['backend_lease'] = [];
        }
        try {
            $this->publishStartupListenerHandoffIntent($config);
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS 启动端口句柄交接准备失败：%{1}', [
                $exception->getMessage(),
            ]));
            return 1;
        }
        $this->printer->note(__('WLS 2.0 边缘模式：%{1}（请求：%{2}）', [
            $effectiveEdgeMode,
            $edgeDecision->requestedMode,
        ]));
        if ($edgeDecision->isAutoFallback()) {
            $this->printer->warning(__('共享网关不可用，已降级纯 WLS：%{1}', [
                $edgeDecision->fallbackReason,
            ]));
            if ($edgeDecision->fallbackPort > 0) {
                $this->printer->note(__('备用入口端口：%{1}；该地址不等价于 80/443，请同步检查防火墙、DNS 或负载均衡。', [
                    $edgeDecision->fallbackPort,
                ]));
            }
        }
        $this->traceStartupPhase($instanceName, 'config:after', [
            'host' => (string)($config['host'] ?? ''),
            'port' => (int)($config['port'] ?? 0),
            'workers' => (int)($config['worker_count'] ?? 0),
            'no_ssl' => !empty($config['no_ssl']),
        ]);

        // 依赖决策必须基于与最终 RuntimeSelection 相同的 requested/effective
        // 拓扑事实。Direct 缺失依赖时 fail-closed；显式 Dispatcher 只把
        // ext-event 当作可选优化，安装失败也不允许改写拓扑。
        // master-only 是已经过父进程预检的内部重入路径，禁止重复安装。
        try {
            $dependencyTopologyIntent = $runtimeResolver->resolveTopologyIntent($config, $args);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        $this->traceStartupPhase($instanceName, 'dependency-runtime:before', [
            'requested_topology' => $dependencyTopologyIntent['requested']->value,
            'effective_topology' => $dependencyTopologyIntent['effective']->value,
        ]);
        if (!isset($args['master-only']) && !\getenv('WLS_MASTER_ONLY')) {
            /** @var RuntimeDependencyBootstrapper $dependencyBootstrapper */
            $dependencyBootstrapper = ObjectManager::getInstance(RuntimeDependencyBootstrapper::class);
            $dependencyResult = $dependencyBootstrapper->ensureOptimalRuntime(
                $args,
                $dependencyTopologyIntent['requested'],
                $dependencyTopologyIntent['effective'],
                false,
                \strtolower(\trim((string)($config['runtime']['listener_mode'] ?? 'auto'))) === 'reuseport',
            );
            $dependencyStatus = (string)($dependencyResult['status'] ?? 'failed');
            $dependencyMessage = (string)($dependencyResult['message'] ?? '');

            if ($dependencyStatus === 'failed') {
                $this->printer->error(__('WLS 运行时依赖检查或显式安装失败：%{1}', [$dependencyMessage]));
                if (!empty($dependencyResult['output'])) {
                    $this->printer->note((string)$dependencyResult['output']);
                }
                if ($dependencyTopologyIntent['effective']->isDirect()) {
                    $this->printer->note(__('Direct 不会静默切换到 Dispatcher；Linux auto 优先验证 reuseport（需要 sockets/SO_REUSEPORT），能力不可用时回退 shared_fd（需要 ext-event 与 POSIX FD/进程原语）；macOS 使用 shared_fd，Nginx 模式的 Windows 使用 worker_ports。也可显式改用 --dispatcher。公网 TLS 与 HTTP 协议由最终边缘模式负责：默认 Nginx，--no-nginx 时为纯 WLS。'));
                }
                return 1;
            }

            if ($dependencyStatus === 'platform_optimal' || $dependencyStatus === 'skipped') {
                $this->printer->note($dependencyMessage);
                if (!empty($dependencyResult['output'])) {
                    $this->printer->note((string)$dependencyResult['output']);
                }
            } elseif ($dependencyStatus === 'installed') {
                $this->printer->success(__('WLS 运行时依赖已按显式请求安装并验证。'));
                if (!empty($dependencyResult['restart_required'])) {
                    $this->printer->note(__('正在使用已加载新扩展的 PHP 进程继续启动...'));
                    // 此时尚未创建任何 WLS 子进程；先释放实例启动锁，
                    // 否则重入的 server:start 会被父进程自己阻塞。
                    $this->releaseStartLock();
                    $exitCode = $dependencyBootstrapper->relaunchCurrentStartCommand();
                    if ($exitCode !== 0) {
                        $this->printer->error(__('依赖安装后的 WLS 重新启动失败，退出码：%{1}', [$exitCode]));
                    }
                    return $exitCode;
                }
            }
        }
        $this->traceStartupPhase($instanceName, 'dependency-runtime:after', [
            'status' => (string)($dependencyResult['status'] ?? 'not_required'),
            'restart_required' => !empty($dependencyResult['restart_required']),
        ]);
        
        // 提示配置来源（已保存的实例配置时特别提示，让用户知道为什么不用指定端口）
        $source = $config['source'] ?? '';
        if (\is_string($source) && \str_contains($source, $instanceName) && $this->loadSavedInstanceConfig($instanceName) !== null) {
            $savedConfig = $this->loadSavedInstanceConfig($instanceName);
            $savedPort = $savedConfig['port'] ?? '?';
            $savedHost = $savedConfig['host'] ?? '?';
            $this->printer->note(__('使用已保存的实例配置：%{1} (%{2}:%{3})', [$instanceName, $savedHost, $savedPort]));
        }
        
        $port = $config['port'];
        $count = $config['worker_count'];
        $this->traceStartupPhase($instanceName, 'host-allowlist:before', [
            'host' => (string)$host,
        ]);
        if (!$this->validateExternalHostAllowlist($instanceName, $host, $config)) {
            return 1;
        }
        $this->traceStartupPhase($instanceName, 'host-allowlist:after', [
            'public_host' => (string)($config['public_host'] ?? $host),
        ]);
        try {
            $publicHost = $this->resolveCertificateHost($config, (string)$host);
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS 公开 Host 规范化失败：%{1}', [
                $exception->getMessage(),
            ]));
            return 1;
        }
        $config['public_host'] = $publicHost;
        $this->beginStartupCertificateStateDeadline();
        if (($config['_certificate_preparation_deferred'] ?? false) === true) {
            $this->completeDeferredCertificatePreparation(
                $instanceName,
                $config,
                $gatewayMode,
                $publicHost,
            );
            unset($config['_certificate_preparation_deferred']);
        }
        if (\in_array(
            $edgeDecision->requestedMode,
            [
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY,
            ],
            true,
        )) {
            try {
                $publicLease = \is_array($config['gateway']['public_lease'] ?? null)
                    ? $config['gateway']['public_lease']
                    : [];
                $leaseBindHost = \strtolower(\trim((string)(
                    $publicLease['bind_host'] ?? ''
                ), " \t\n\r\0\x0B[]"));
                if ((int)($publicLease['schema_version'] ?? 0)
                        === GatewayPortLeaseAllocator::SCHEMA_VERSION
                    && \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                        $publicLease['lease_id'] ?? ''
                    )) === 1
                    && \filter_var($leaseBindHost, FILTER_VALIDATE_IP) !== false
                ) {
                    $config['gateway']['fallback_bind_host'] = $leaseBindHost;
                } else {
                    $config['gateway']['fallback_bind_host'] = $this->resolveGatewayFallbackBindHost(
                        $config,
                        (string)$host,
                    );
                }
            } catch (\Throwable $exception) {
                $this->printer->error(__('WLS 2.0 备用入口监听地址无效：%{1}', [
                    $exception->getMessage(),
                ]));
                return 1;
            }
        }
        $daemon = $this->resolveDaemonMode($config, $foregroundMode);
        if (!$daemon && !$noNginxRequested) {
            $this->printer->error(__('Nginx-only 启动必须使用后台 Master，才能在 Worker READY 后完成 Nginx 配置事务与真实协议门禁；请移除 --foreground/--no-daemon。'));
            return 1;
        }
        
        // 默认 Nginx 公网入口必须启用 TLS。纯 WLS 默认同样启用 HTTPS，
        // 但仍尊重显式 --no-ssl 诊断请求。
        $noSsl = !empty($config['no_ssl']);
        if ($noSsl && !$noNginxRequested) {
            $this->printer->error(__('Nginx-only 公网端点强制 TLS 1.3；--no-ssl 与 wls.https=false 已停用。'));
            return 1;
        }
        $portExplicit = ($config['port_explicit'] ?? false) === true
            && !$edgeDecision->isAutoFallback();
        $certificateTrustProfile = $this->resolveCertificateTrustProfile($config);
        $config['gateway']['certificate_profile'] = $certificateTrustProfile;
        
        $this->traceStartupPhase($instanceName, 'ssl-ensure:before');
            $sslResult = $this->ensureSslCertificate($instanceName, $config);
            $this->traceStartupPhase($instanceName, 'ssl-ensure:after', [
                'success' => !empty($sslResult['success']),
                'ssl_enabled' => (bool)($sslResult['ssl_enabled'] ?? true),
                'is_new' => !empty($sslResult['is_new']),
            ]);
            if (!$sslResult['success']) {
                $this->printer->error($sslResult['message']);
                return 1;
            }
            $sslCert = $sslResult['cert_path'] ?? '';
            $sslKey = $sslResult['key_path'] ?? '';
            if (IS_WIN) {
                $sslCert = Processer::resolveWindowsPersistentPath((string)$sslCert);
                $sslKey = Processer::resolveWindowsPersistentPath((string)$sslKey);
            }
            $sslEnabled = (bool) ($sslResult['ssl_enabled'] ?? true);
            $retiredHttpOnly = \hash_equals(
                'TLS_CERTIFICATE_RETIRED_HTTP_ONLY',
                (string)($sslResult['code'] ?? ''),
            );
            $certificatePending = $gatewayMode
                && (($sslResult['pending_certificate'] ?? false) === true);
            $activeCertificate = null;
            $servingManifestRecovery = ($sslResult['serving_manifest_recovery'] ?? false)
                === true;
            if ($servingManifestRecovery) {
                $activeCertificate = [
                    'domain' => (string)($sslResult['domain'] ?? ''),
                    'generation' => (int)($sslResult['certificate_generation'] ?? 0),
                    'source_digest' => (string)(
                        $sslResult['certificate_source_digest'] ?? ''
                    ),
                    'trust_profile' => (string)($sslResult['trust_profile'] ?? ''),
                    'provider' => (string)($sslResult['provider'] ?? ''),
                    'material_class' => (string)($sslResult['material_class'] ?? ''),
                    'provenance_digest' => (string)(
                        $sslResult['certificate_provenance_digest'] ?? ''
                    ),
                    'leaf_fingerprint_sha256' => (string)(
                        $sslResult['leaf_fingerprint_sha256'] ?? ''
                    ),
                    'cert_path' => (string)$sslCert,
                    'key_path' => (string)$sslKey,
                ];
            } elseif (($sslResult['project_generation_reused'] ?? false) === true) {
                // The immutable selector is already the committed project
                // authority. Re-running activate() would allocate a newer
                // generation from the same bytes and can revive a manifest
                // that was deliberately superseded during crash recovery.
                $activeCertificate = [
                    'domain' => (string)($sslResult['domain'] ?? ''),
                    'generation' => (int)($sslResult['generation'] ?? 0),
                    'source_digest' => (string)($sslResult['source_digest'] ?? ''),
                    'trust_profile' => (string)($sslResult['trust_profile'] ?? ''),
                    'provider' => (string)($sslResult['provider'] ?? ''),
                    'material_class' => (string)($sslResult['material_class'] ?? ''),
                    'provenance_digest' => (string)(
                        $sslResult['provenance_digest'] ?? ''
                    ),
                    'leaf_fingerprint_sha256' => (string)(
                        $sslResult['leaf_fingerprint_sha256'] ?? ''
                    ),
                    'cert_path' => (string)$sslCert,
                    'key_path' => (string)$sslKey,
                    'chain_path' => (string)($sslResult['chain_path'] ?? ''),
                    'cert_sha256' => (string)($sslResult['cert_sha256'] ?? ''),
                    'key_sha256' => (string)($sslResult['key_sha256'] ?? ''),
                    'chain_sha256' => (string)($sslResult['chain_sha256'] ?? ''),
                ];
            } elseif ($sslEnabled && $sslCert !== '' && $sslKey !== '') {
                // ACME or local certificate generation may legitimately take
                // longer than a state-lock budget. Activation is a new bounded
                // local phase, and every selector read below shares its one
                // absolute deadline.
                $this->beginStartupCertificateStateDeadline();
                try {
                    $projectRoot = \realpath((string)BP);
                    if (!\is_string($projectRoot) || $projectRoot === '') {
                        throw new \RuntimeException('Unable to resolve the WLS project root.');
                    }
                    $certificateRoots = (new GatewayRegistrationBuilder())
                        ->enrollmentCertificateRoots($projectRoot);
                    $certificateDomain = $this->resolveCertificateHost(
                        $config,
                        (string)$host,
                    );
                    $resultDomain = $this->normalizeCertificateDomainCandidate(
                        (string)($sslResult['domain'] ?? $certificateDomain),
                    );
                    if ($resultDomain === '' || !\hash_equals($certificateDomain, $resultDomain)) {
                        throw new \RuntimeException(
                            'Selected certificate generation does not match the public Host fence.'
                        );
                    }
                    $activeCertificate = (new ProjectCertificateGenerationStore())->activate(
                        $certificateDomain,
                        (string)$sslCert,
                        (string)$sslKey,
                        '',
                        $certificateRoots,
                        $this->startupCertificateStateDeadline(),
                        $certificateTrustProfile,
                        $this->resolveCertificateProvider($sslResult),
                    );
                    $sslCert = (string)$activeCertificate['cert_path'];
                    $sslKey = (string)$activeCertificate['key_path'];
                    if (($activeCertificate['retained_previous'] ?? false) === true) {
                        $this->printer->warning(__('新证书未通过完整校验，继续使用上一代有效证书：%{1}', [
                            (string)($activeCertificate['activation_error'] ?? ''),
                        ]));
                    } elseif ((string)($activeCertificate['activation_error'] ?? '') !== '') {
                        $this->printer->warning(__('新证书代际已提交并按完整 after-image 对账，但后置持久化确认异常：%{1}', [
                            (string)$activeCertificate['activation_error'],
                        ]));
                    }
                } catch (CertificateTrustProvenanceException $throwable) {
                    if ($gatewayMode) {
                        $this->printer->warning(__('%{1}；共享网关保持 challenge-only，未发布普通 443 路由。', [
                            $throwable->getMessage(),
                        ]));
                        $activeCertificate = null;
                        $sslEnabled = false;
                        $certificatePending = true;
                        $sslCert = '';
                        $sslKey = '';
                        $sslResult = [
                            'success' => true,
                            'message' => $throwable->getMessage(),
                            'code' => 'PENDING_CERTIFICATE',
                            'domain' => $this->resolveCertificateHost($config, (string)$host),
                            'cert_path' => '',
                            'key_path' => '',
                            'ssl_enabled' => false,
                            'pending_certificate' => true,
                            'is_new' => false,
                            'trust_profile' => $certificateTrustProfile,
                            'provider' => ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
                        ];
                    } else {
                        $this->printer->error(__('无法激活项目证书代际：%{1}', [
                            $throwable->getMessage(),
                        ]));
                        return 1;
                    }
                } catch (\Throwable $throwable) {
                    $this->printer->error(__('无法激活项目证书代际：%{1}', [
                        $throwable->getMessage(),
                    ]));
                    return 1;
                }
            }
            $config['ssl_cert'] = $sslCert;
            $config['ssl_key'] = $sslKey;
            $config['ssl_domain'] = ($servingManifestRecovery
                    || ($sslResult['project_generation_reused'] ?? false) === true)
                ? (string)($sslResult['domain'] ?? '')
                : $this->resolveCertificateHost($config, (string)$host);
            if ($activeCertificate !== null) {
                try {
                    $activeDomain = $this->normalizeCertificateDomainCandidate(
                        (string)$config['ssl_domain'],
                    );
                    $activeDigest = \strtolower(\trim((string)(
                        $activeCertificate['source_digest'] ?? ''
                    )));
                    $activeProvenanceDigest = \strtolower(\trim((string)(
                        $activeCertificate['provenance_digest'] ?? ''
                    )));
                    if ($activeDomain === ''
                        || !\hash_equals(
                            $activeDomain,
                            $this->normalizeCertificateDomainCandidate((string)(
                                $activeCertificate['domain'] ?? ''
                            )),
                        )
                        || (int)($activeCertificate['generation'] ?? 0) < 1
                        || \preg_match('/\A[a-f0-9]{64}\z/D', $activeDigest) !== 1
                        || \preg_match('/\A[a-f0-9]{64}\z/D', $activeProvenanceDigest) !== 1
                        || !\hash_equals(
                            $certificateTrustProfile,
                            (string)($activeCertificate['trust_profile'] ?? ''),
                        )
                        || !\hash_equals(
                            (string)$sslCert,
                            (string)($activeCertificate['cert_path'] ?? ''),
                        )
                        || !\hash_equals(
                            (string)$sslKey,
                            (string)($activeCertificate['key_path'] ?? ''),
                        )
                    ) {
                        throw new \RuntimeException(
                            'Active certificate after-image is incomplete before edge launch.'
                        );
                    }
                    $activeCertificate['source_digest'] = $activeDigest;
                    $activeCertificate['provenance_digest'] = $activeProvenanceDigest;
                } catch (\Throwable $throwable) {
                    $this->printer->error(__('无法确认边缘启动所需的项目证书代际：%{1}', [
                        $throwable->getMessage(),
                    ]));
                    return 1;
                }
            }
            if ($this->shouldPersistGatewayCertificateSource(
                $sslEnabled,
                $certificatePending,
                $gatewayMode,
                $noNginxRequested,
                $edgeDecision->requestedMode,
            )) {
                $config['gateway']['certificate_source'] = [
                    'domain' => (string)$config['ssl_domain'],
                    'cert_path' => (string)$sslCert,
                    'key_path' => (string)$sslKey,
                    'generation' => (int)($activeCertificate['generation'] ?? 0),
                    'source_digest' => (string)($activeCertificate['source_digest'] ?? ''),
                    'trust_profile' => (string)($activeCertificate['trust_profile']
                        ?? $certificateTrustProfile),
                    'provider' => (string)($activeCertificate['provider']
                        ?? ProjectCertificateGenerationStore::PROVIDER_EXTERNAL),
                    'material_class' => (string)($activeCertificate['material_class'] ?? ''),
                    'provenance_digest' => (string)($activeCertificate['provenance_digest'] ?? ''),
                    'leaf_fingerprint_sha256' => (string)(
                        $activeCertificate['leaf_fingerprint_sha256'] ?? ''
                    ),
                    'pending' => $certificatePending,
                ];
                $config['gateway']['certificate_pending'] = $certificatePending;
            }
            if (!$sslEnabled) {
                if ($certificatePending) {
                    if (!$portExplicit && $port === self::DEFAULT_PORT) {
                        $port = self::DEFAULT_PORT_FALLBACK;
                        $config['port'] = $port;
                    }
                    $this->printer->note(__(
                        '项目将先运行 loopback HTTP 回源；网关仅开放精确 ACME challenge，证书激活前普通 HTTPS 路由保持关闭。'
                    ));
                } else {
                    $disableReason = \trim((string)($sslResult['message'] ?? ''));
                    $this->printer->warning(__('HTTPS 未启用：%{1}', [
                        $disableReason !== '' ? $disableReason : __('SSL 证书服务返回 HTTP 模式'),
                    ]));
                    $this->printer->note(__('本次将以 HTTP 运行；如需 HTTPS，请检查 wls.https 配置和证书管理中的域名 HTTPS 开关。'));
                }
                $sslCert = '';
                $sslKey = '';
            } else {
                $port = !$portExplicit && $port === self::DEFAULT_PORT
                    ? self::DEFAULT_PORT_FALLBACK
                    : $port;
                $config['port'] = $port;

                if ($sslResult['is_new'] ?? false) {
                    $this->printer->success(__('已生成新证书：%{1}', [$sslResult['issuer']]));
                } else {
                    $this->printer->note(__('使用已有证书：%{1}', [$sslResult['issuer']]));
                }
                if (!empty($sslResult['expires_at'])) {
                    $this->printer->note(__('证书有效期至：%{1}', [$sslResult['expires_at']]));
                }

                if ($this->isLegacyEdgeRuntimeConfig($config)
                    && !($sslResult['storage_sync_deferred'] ?? false)
                ) {
                    // 新签发/恢复路径已启用持久层，可安全发布 SNI 映射但不等待旧 Master reload ACK。
                    /** @var SslCertificateService $sslMapSync */
                    $this->traceStartupPhase($instanceName, 'ssl-map-sync:before');
                    $sslMapSync = ObjectManager::getInstance(SslCertificateService::class);
                    $sslMapSync->regenerateCertificateMap(false);
                    $this->traceStartupPhase($instanceName, 'ssl-map-sync:after');
                } elseif ($this->isLegacyEdgeRuntimeConfig($config)) {
                    $this->traceStartupPhase($instanceName, 'ssl-map-sync:deferred-file-mode');
                } else {
                    $this->traceStartupPhase(
                        $instanceName,
                        'ssl-map-sync:skipped-wls2-serving-manifest',
                    );
                }
            }

        // 在停止旧实例前完成拓扑、事件循环与策略能力预检，避免预检失败造成停机。
        $this->traceStartupPhase($instanceName, 'runtime-strategy:before');
        $this->traceStartupPhase($instanceName, 'runtime-profile:before');
        $runtimeProfile = $this->detectRuntimeProfile($this->resolveServerListenHost((string)$host));
        $this->traceStartupPhase($instanceName, 'runtime-profile:after');
        try {
            $runtimeStrategy = $runtimeResolver->resolve($config, $args, $runtimeProfile);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        $runtimeSelection = $runtimeStrategy['runtime_selection'] ?? null;
        if (!$runtimeSelection instanceof RuntimeSelection) {
            $this->printer->error(__('WLS 运行时解析器未返回完整 RuntimeSelection；已拒绝启动。'));
            return 1;
        }
        if ($runtimeSelection->requestedTopology !== $dependencyTopologyIntent['requested']
            || $runtimeSelection->effectiveTopology !== $dependencyTopologyIntent['effective']
        ) {
            $this->printer->error(__('WLS 依赖预检与最终 RuntimeSelection 拓扑不一致；已拒绝启动。'));
            return 1;
        }

        $count = (int)$runtimeStrategy['worker_count'];
        $config['worker_count'] = $count;
        $config['runtime_strategy'] = (string)$runtimeStrategy['runtime_strategy'];
        $config['runtime_selection'] = $runtimeSelection;
        if (!\is_array($config['supervisor'] ?? null)) {
            $config['supervisor'] = [];
        }
        $config['supervisor']['enabled'] = (bool)$runtimeStrategy['supervisor_enabled'];
        $dispatcherEnabled = $runtimeSelection->isDispatcher();
        $supportsReusePort = $runtimeProfile->supportsReusePort();
        $useDirectMode = $runtimeSelection->isDirect();
        $usesWorkerPorts = $useDirectMode && $runtimeSelection->listenerMode === 'worker_ports';
        try {
            $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())->resolveFromWlsSection($config);
        } catch (\InvalidArgumentException $exception) {
            $this->printer->error(__('WLS 边缘适配器无效：%{1}', [$exception->getMessage()]));
            return 1;
        }
        $config['edge'] = \array_merge(
            \is_array($config['edge'] ?? null) ? $config['edge'] : [],
            ['adapter' => $edgeAdapter->name()],
        );
        $pureWls = $edgeAdapter->name()
            === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        if (!$pureWls
            && !$sslEnabled
            && !($gatewayMode && $certificatePending)
            && !($gatewayMode && $retiredHttpOnly)
        ) {
            $this->printer->error(__('Nginx-only 公网端点要求 TLS 1.3；已拒绝 --no-ssl。'));
            return 1;
        }
        $backendSslEnabled = $pureWls && $sslEnabled;
        $requestedGatewayMode = \strtolower(\trim((string)(
            $config['gateway']['requested_mode'] ?? ''
        )));
        $autoFallback = $pureWls && $requestedGatewayMode === 'auto';
        $gatewayFallbackCapable = \in_array(
            $requestedGatewayMode,
            ['auto', 'gateway'],
            true,
        );
        // An auto gateway instance may run its Dispatcher topology and a
        // supplemental direct TLS fallback under the same Master. Publish one
        // immutable bundle that both roles can validate to the same digest.
        $policyTopology = $gatewayFallbackCapable
            ? 'both'
            : $runtimeSelection->effectiveTopology->value;
        $backendListenHost = $pureWls
            ? ($autoFallback
                ? (string)($config['gateway']['fallback_bind_host'] ?? '127.0.0.1')
                : $this->resolveServerListenHost((string)$host))
            : '127.0.0.1';
        $config['edge_adapter'] = $edgeAdapter->name();
        // Validate the selected endpoint policy before canonicalizing it.
        try {
            $httpProtocolSelection = HttpProtocolSelection::fromConfig($config, $backendSslEnabled);
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS HTTP 协议配置无效：%{1}', [$exception->getMessage()]));
            return 1;
        }
        $configuredHttp3 = $config['http3'] ?? [];
        $configuredHttp3Enabled = \is_array($configuredHttp3)
            ? ($configuredHttp3['enabled'] ?? false)
            : $configuredHttp3;
        $configuredHttp3RuntimeVerified = \is_array($configuredHttp3)
            ? ($configuredHttp3['runtime_verified'] ?? false)
            : false;
        if ($this->isTruthyCliFlagValue($configuredHttp3Enabled)
            || $this->isTruthyCliFlagValue($configuredHttp3RuntimeVerified)
        ) {
            $this->printer->error(__('纯 WLS HTTP/3 不可用；HTTP/3 仅由项目托管 Nginx 提供。'));
            return 1;
        }
        $config['http'] = \array_merge(
            \is_array($config['http'] ?? null) ? $config['http'] : [],
            $httpProtocolSelection->toConfig(),
        );
        $runtimeStrategy['http_protocol_selection'] = $httpProtocolSelection->toArray();
        $this->printer->note($pureWls
            ? __('纯 WLS 协议：HTTP/2（默认）→ HTTP/1.1（自动回退）')
            : __('WLS 回源协议：HTTP/1.1（公网协议协商由 Nginx 负责）'));
        foreach ((new RuntimeDiagnosticsFormatter())->formatStartupSummary($runtimeProfile, $runtimeStrategy) as $runtimeLine) {
            if (\str_starts_with($runtimeLine, 'WARNING:') || \str_starts_with($runtimeLine, 'Warning:')) {
                $this->printer->warning($runtimeLine);
            } else {
                $this->printer->note($runtimeLine);
            }
        }
        try {
            $tlsProcessProfile = (new TlsProcessProfileConfigurator())->activate(
                $config,
                $backendSslEnabled,
                $this->startupListenerStateDeadline(),
            );
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        if (!\is_array($config['ssl'] ?? null)) {
            $config['ssl'] = [];
        }
        $config['ssl']['key_exchange_profile'] = $tlsProcessProfile['requested'];
        $config['ssl']['effective_key_exchange_profile'] = $tlsProcessProfile['effective'];
        $config['ssl']['process_openssl_conf'] = $tlsProcessProfile['openssl_conf'];
        $runtimeStrategy['tls_key_exchange_profile'] = $tlsProcessProfile['effective'];
        if ($backendSslEnabled) {
            $this->printer->note(
                'TLS key exchange: ' . $tlsProcessProfile['effective'] . ' - ' . $tlsProcessProfile['reason']
            );
        }

        $config['http3'] = [
            'enabled' => false,
            'runtime_verified' => false,
            'reason' => $pureWls
                ? 'Pure WLS HTTP/3 is unavailable; use managed Nginx for HTTP/3.'
                : 'Managed Nginx owns public HTTP/3 negotiation.',
        ];
        $this->printer->note(__('边缘适配器：%{1}', [$edgeAdapter->name()]));
        if (isset($args['install-nginx']) || isset($args['install_nginx'])) {
            $this->printer->error(__(
                '--install-nginx 已退役；启动路径禁止下载或编译，请先单独执行 php bin/w server:nginx:install。'
            ));
            return 1;
        }
        if ($pureWls) {
            $originHost = $this->isUsablePublicHost($publicHost) ? $publicHost : '127.0.0.1';
            $publicOrigin = \Weline\Server\Service\Edge\PureWlsPublicOrigin::fromHostAndPort(
                $originHost,
                $port,
                $backendSslEnabled,
            );
            $config['public_origin'] = $publicOrigin;
            if ($autoFallback) {
                $fallbackAuthority = \filter_var(
                    $backendListenHost,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV6,
                ) !== false
                    ? '[' . $backendListenHost . ']'
                    : $backendListenHost;
                $config['gateway']['fallback_state'] = 'DEGRADED_WLS';
                $config['gateway']['fallback_bind_host'] = $backendListenHost;
                $config['gateway']['fallback_bind'] = $fallbackAuthority . ':' . $port;
                $config['gateway']['fallback_urls'] = [\rtrim($publicOrigin, '/')];
                $config['gateway']['fallback_limitations'] = [
                    'not_public_80_443',
                    'dns_mapping_required',
                    'firewall_or_load_balancer_may_block',
                ];
            }
            $this->printer->note(__('纯 WLS 负责公网 TLS/HTTP；HTTP/3 不启用。'));
        } elseif ($gatewayMode) {
            $gatewayHttpsPort = (int)($config['gateway']['public_https'] ?? 0);
            if ($gatewayHttpsPort < 1 || $gatewayHttpsPort > 65535) {
                $this->printer->error(__('WLS 2.0 网关没有返回有效 HTTPS 监听端口。'));
                return 1;
            }
            try {
                $publicOrigin = \Weline\Server\Service\Edge\Nginx\ManagedNginxPublicOrigin::fromHostAndPort(
                    $publicHost,
                    $gatewayHttpsPort,
                );
            } catch (\Throwable $exception) {
                $this->printer->error(__('WLS 2.0 网关公网 origin 固化失败：%{1}', [$exception->getMessage()]));
                return 1;
            }
            $config['public_origin'] = $publicOrigin;
            $this->printer->note(__('宿主级 Weline Gateway 负责公网 TLS/HTTP；本项目只提供 loopback WLS 后端。'));
        } else {
            $this->printer->note(__('Nginx 负责公网 TLS/HTTP；WLS 后端固定为回环地址明文 HTTP/1.1。'));
            $managedNginxService = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
            $managedNginxPaths = $managedNginxService->paths();
            if (!$managedNginxPaths->managedEnabled()) {
                $this->printer->error(__('Nginx 默认启动要求 wls.edge.nginx.managed=true；不可用时请显式使用 --no-nginx。'));
                return 1;
            }
            if (!$managedNginxPaths->autoStartEnabled()) {
                $this->printer->error(__('Nginx 默认启动要求 wls.edge.nginx.auto_start=true；不可用时请显式使用 --no-nginx。'));
                return 1;
            }
            if (!$managedNginxPaths->isInstalled()) {
                $this->printer->error(__(
                    '项目隔离 Nginx 尚未安装；请先执行 server:nginx:install，或显式使用 --no-nginx 启动纯 WLS。'
                ));
                return 1;
            }
            try {
                $managedNginxPorts = (new \Weline\Server\Service\Edge\Nginx\ManagedNginxPortAllocator(
                    $managedNginxPaths,
                ))->allocate();
                $publicOrigin = \Weline\Server\Service\Edge\Nginx\ManagedNginxPublicOrigin::fromHostAndPort(
                    $publicHost,
                    (int)$managedNginxPorts['https'],
                );
            } catch (\Throwable $exception) {
                $this->printer->error(__('Nginx 公网 origin 固化失败：%{1}', [$exception->getMessage()]));
                return 1;
            }
            $config['public_origin'] = $publicOrigin;
            $managedNginxStatus = $managedNginxService->doctorSnapshot();
            $managedOwner = \trim((string)($managedNginxStatus['owner_instance'] ?? ''));
            if ((bool)($managedNginxStatus['running'] ?? false) && $managedOwner === '') {
                $this->printer->error(__('托管 Nginx 正在运行但缺少可验证 owner；请先执行身份审查与显式停止。'));
                return 1;
            }
            if ($managedOwner !== '' && !\hash_equals($managedOwner, $instanceName)) {
                $this->printer->error(__(
                    '托管 Nginx 已绑定实例 %{1}；请先停止该 owner，再启动实例 %{2}。',
                    [$managedOwner, $instanceName],
                ));
                return 1;
            }
        }
        $this->traceStartupPhase($instanceName, 'runtime-strategy:after', [
            'topology' => $runtimeSelection->effectiveTopology->value,
            'event_loop' => $runtimeSelection->eventLoopDriver,
            'workers' => $count,
        ]);

        // 检查是否强制重启（-r）及是否强制直接切换（-f：不等待 worker 空闲，直接停再启）
        // 仅承认帮助文档明示的开关 -r / --restart；--force 未文档化，过去会隐式触发 -r 平滑路径，
        // 容易让用户在毫不知情下进入"停旧实例 + 等空闲"分支，移除以减少认知裂缝。
        $forceRestart = isset($args['r']) || isset($args['restart']);
        $forceSwitch = isset($args['f']); // -f：直接切换，不进入平滑重启（不开维护模式、不等待）
        $mainStop = null;
        $skipPostStopPortInspection = false;
        $fastRestartMetadata = $this->resolveFastRestartInstanceMetadata($instanceName, $port, $forceRestart, $forceSwitch);
        $this->traceStartupPhase($instanceName, 'occupant-detect:before', [
            'port' => (int)$port,
            'fast_metadata' => $fastRestartMetadata !== null,
            'skip_port_reverse_lookup' => $skipPostStopPortInspection,
        ]);
        $mainPortInspect = ['in_use' => false];
        if ($fastRestartMetadata !== null) {
            $occupantWls = $instanceName;
            $occupantCli = false;
        } elseif ($skipPostStopPortInspection) {
            $occupantWls = null;
            $occupantCli = false;
        } elseif (!$skipPostStopPortInspection) {
            $mainPortInspect = $this->inspectStartupPortIfOccupied($port);
            if ($mainPortInspect['in_use'] ?? false) {
                $mainStop = ObjectManager::getInstance(MainStop::class);
                $occupantWls = $mainStop->findWelineServerInstanceNameByPort($port);
                $cliStatus = $cliService->getCliServerStatus();
                $occupantCli = $cliStatus && (($cliStatus['port'] ?? 0) === (int) $port);
            } else {
                $occupantWls = null;
                $occupantCli = false;
            }
        }
        $this->traceStartupPhase($instanceName, 'occupant-detect:after', [
            'occupant_wls' => $occupantWls,
            'occupant_cli' => $occupantCli,
            'port_in_use' => (bool)($mainPortInspect['in_use'] ?? false),
            'fast_metadata' => $fastRestartMetadata !== null,
            'skip_port_reverse_lookup' => $skipPostStopPortInspection,
        ]);

        // 同端口已被其他 WLS 实例占用 → 报错提示，不自动停旧实例（支持多实例并行）
        if ($occupantWls !== null && $occupantWls !== $instanceName) {
            $this->printer->error(__('端口 %{1} 已被 Weline Server 实例 [%{2}] 占用！', [$port, $occupantWls]));
            $this->printer->note('');
            $this->printer->setup(__('解决方案：'));
            $this->printer->note('  ' . __('1. 使用 -p 参数指定其他端口启动新实例：'));
            $this->printer->note('     php bin/w server:start ' . $instanceName . ' -p ' . ($port + 1000));
            $this->printer->note('  ' . __('2. 或先停止实例 [%{1}]：', [$occupantWls]));
            $this->printer->note('     php bin/w server:stop ' . $occupantWls);
            $this->printer->note('  ' . __('3. 查看所有运行中的实例：'));
            $this->printer->note('     php bin/w server:status --all');
            $this->printer->note('');
            return 1;
        }

        // 跨项目作用域占用：另一项目（不同 BP 目录哈希派生的 pXXXXXXXX）的 WLS 占了该端口。
        // 这里要立刻友好报错，禁止冒充自家 default 实例进入 -r -f 空转的清理流程。
        $foreignScope = ($fastRestartMetadata !== null || !($mainPortInspect['in_use'] ?? false))
            ? null
            : ($mainStop !== null ? $mainStop->findForeignWelineServerScopeByPort($port) : null);
        if ($foreignScope !== null && $foreignScope !== '' && $foreignScope !== MasterProcess::getProjectScopeToken()) {
            $this->printer->error(__('端口 %{1} 已被其他项目的 Weline Server 占用（项目作用域：%{2}）', [$port, $foreignScope]));
            $this->printer->note('');
            $this->printer->setup(__('解决方案：'));
            $this->printer->note('  ' . __('1. 该端口属于不同 BP 目录下的项目实例，与本项目相互隔离，请直接换一个端口启动：'));
            $this->printer->note('     php bin/w server:start ' . $instanceName . ' -p ' . ($port + 1000));
            $this->printer->note('  ' . __('2. 查看占用进程：'));
            $this->printer->note('     netstat -anp 2>/dev/null | grep ' . $port);
            $this->printer->note('  ' . __('3. 或前往实际项目目录处理：'));
            $this->printer->note('     php bin/w server:status --all');
            $this->printer->note('');
            return 1;
        }
        // CLI 服务器占用该端口 → 先停
        if ($occupantCli) {
            $this->printer->note(__('端口 %{1} 已被 PHP 内置服务器占用，正在停止...', [$port]));
            ObjectManager::getInstance(CliStop::class)->execute(['force' => true, 'f' => true], []);
            SchedulerSystem::sleep(2);
        }
        // 预探测 HTTPS=443 时的 HTTP Redirect 端口占用归属：
        // 旧实例可能只残留 Redirect 子进程（80），主端口已释放，此时也应纳入 -r 重启清理路径。
        $preflightHttpRedirectPort = ($sslEnabled && $port === self::DEFAULT_PORT_HTTPS) ? self::DEFAULT_PORT : 0;
        $redirectOccupantWls = null;
        if ($preflightHttpRedirectPort > 0) {
            if ($fastRestartMetadata !== null) {
                $redirectOccupantWls = $this->resolveFastRestartRedirectOccupant($fastRestartMetadata, $preflightHttpRedirectPort, $instanceName);
            } elseif (!$skipPostStopPortInspection) {
                $redirectPortInspect = $this->inspectStartupPortIfOccupied($preflightHttpRedirectPort);
                if ($redirectPortInspect['in_use'] ?? false) {
                    $mainStop ??= ObjectManager::getInstance(MainStop::class);
                    $redirectOccupantWls = $mainStop->findWelineServerInstanceNameByPort($preflightHttpRedirectPort);
                }
            }
        }

        // 本实例已运行（含 Redirect 残留）：未指定 -r 则提示并退出；指定 -r 则平滑重启（先维护模式+等待）或 -f 直接切换
        $maintenanceEnabledByUs = false;
        $maintenanceResetAfterForceSwitch = false;
        // 用户传入 -r 不代表旧实例真实存在；只有成功 stop 后才允许按旧代端口强制释放。
        $restartCleanupPerformed = false;
        $instanceRunning = $fastRestartMetadata !== null
            || ($occupantWls === $instanceName)
            || (!$skipPostStopPortInspection && $this->isServerRunning($instanceName, $port));
        $instanceRedirectResidue = ($redirectOccupantWls === $instanceName) && !$instanceRunning;
        $this->traceStartupPhase($instanceName, 'restart-preflight:after', [
            'force_restart' => $forceRestart,
            'force_switch' => $forceSwitch,
            'instance_running' => $instanceRunning,
            'redirect_residue' => $instanceRedirectResidue,
            'redirect_occupant' => $redirectOccupantWls,
        ]);
        if (($instanceRunning || $instanceRedirectResidue) && !$forceRestart) {
            $this->showAlreadyRunningInfo($instanceName, $port);
            return;
        }

        if ($instanceRunning
            && $forceRestart
            && !$forceSwitch
            && !$this->hasStartupChangingArgs($args)
        ) {
            $this->wlsStartupProcessHandoffDone = true;
            $this->releaseStartLock();
            $this->printer->note(__('检测到服务器已运行，执行滚动重启（Master 保持运行）...'));
            $reloadCommand = ObjectManager::getInstance(Reload::class);

            return $reloadCommand->execute($args, $data);
        }

        if ($instanceRunning
            && $forceRestart
            && !$forceSwitch
            && $this->hasStartupChangingArgs($args)
        ) {
            $this->printer->warning(__('检测到端口/拓扑/Worker/SSL 等启动参数变更，start -r 将自动切换为完整重启。'));
            $this->printer->note(__('将先执行平滑停机，再使用新启动参数重新拉起实例。'));
        }

        // Only a confirmed fresh start or explicit restart may publish a new
        // compiled generation. This gate still runs before stopping the old
        // instance, so compile/registry failures cannot manufacture downtime.
        try {
            $this->traceStartupPhase($instanceName, 'framework-compile:before');
            $compiledRuntime = $this->compileFrameworkRuntimeRegistries(
                $policyTopology,
                $instanceName,
                $config,
            );
            $containerRegistryDigest = $compiledRuntime['container_registry_digest'];
            $policyCheck = $compiledRuntime['policy_check'];
            $this->traceStartupPhase($instanceName, 'framework-compile:after', [
                'container_registry_digest' => $containerRegistryDigest,
                'policy_valid' => (bool)($policyCheck['valid'] ?? false),
                'cache_hit' => (bool)($compiledRuntime['cache_hit'] ?? false),
            ]);
            $this->renewStartupListenerStateDeadlineAfterColdPreflight();
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS 编译运行时预检失败：%{1}', [$exception->getMessage()]));
            return 1;
        }
        if (empty($policyCheck['valid'])) {
            $this->printer->error(__('WLS 运行时策略不支持当前拓扑：%{1}', [
                $runtimeSelection->effectiveTopology->value,
            ]));
            foreach ((array)($policyCheck['errors'] ?? []) as $policyError) {
                $this->printer->note('  - ' . (string)$policyError);
            }
            if ($runtimeSelection->isDirect()) {
                $this->printer->note(__('Direct 不会静默忽略关键策略；请修复策略能力，或显式使用 --dispatcher。'));
            }
            return 1;
        }
        // The staging validator is intentionally discarded. Policy activation
        // later reloads the atomically promoted final registry.
        $policyControl = new RuntimePolicyControlService();
        $runtimeStrategy['policy_digest'] = (string)($policyCheck['bundle']['digest'] ?? '');
        $runtimeStrategy['container_registry_digest'] = $containerRegistryDigest;
        if (!\is_array($config['runtime'] ?? null)) {
            $config['runtime'] = [];
        }
        $config['runtime']['container_registry_digest'] = $containerRegistryDigest;

        if ($deferStartupListenerReservation) {
            $deferredBindHost = $gatewayMode ? '127.0.0.1' : $publicLeaseBindHost;
            if (!$this->preflightDeferredStartupListenerCapability(
                $deferredBindHost,
                $port,
            )) {
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return 1;
            }
        }

        if ($instanceRunning || $instanceRedirectResidue) {
            // 强制重启：先停旧 Master，其通过 IPC 广播 shutdown，子进程收后不复活
            if ($forceSwitch) {
                if ($instanceRedirectResidue) {
                    $this->printer->warning(__('检测到旧实例仅残留 HTTP Redirect 子进程，先执行本地快速清场...'));
                } else {
                    $this->printer->warning(__('检测到服务器已运行，-f 直接切换（不等待）...'));
                }
                $this->printer->warning(__('注意：-f 强制切换属于停机型更新，不会自动等待请求排空；如需对外升级，请先确认维护模式已开启。滚动模式不需要。'));
                $this->beginRestartMaintenanceTransaction($instanceName);
                $forceSwitchStopStart = self::monotonicSeconds();
                $this->traceStartupPhase($instanceName, 'force-switch-stop:before');
                if (!$this->stopExistingServer($instanceName, $port, $count, true, 0, true)) {
                    $this->rollbackRestartMaintenanceTransactionIfPending();
                    return 1;
                }
                $restartCleanupPerformed = true;
                $this->traceStartupPhase($instanceName, 'force-switch-stop:after', [
                    'elapsed_ms' => (int) \round((self::monotonicSeconds() - $forceSwitchStopStart) * 1000),
                ]);
                // -r -f 是停机型切换：新实例启动结束后仍由启动事务恢复原始 system.maintenance，
                // 既不残留本次临时状态，也不覆盖运维人员原本主动开启的维护态。
                $maintenanceResetAfterForceSwitch = true;
                $waited = 0;
                $this->traceStartupPhase($instanceName, 'force-switch-port-settle:before', [
                    'skipped' => true,
                    'reason' => 'restart_hot_path_uses_master_bind_result',
                ]);
                $this->traceStartupPhase($instanceName, 'force-switch-port-settle:after', [
                    'waited_ms' => $waited,
                    'skipped' => true,
                ]);
            } else {
                $this->printer->warning(__('检测到服务器已运行，完整代际重启：先开启维护模式并等待全部 Worker 请求排空...'));
                $this->beginRestartMaintenanceTransaction($instanceName);
                $this->enableMaintenanceMode($instanceName);
                $maintenanceEnabledByUs = true;
                $this->printer->success(__('全部 READY Worker 已完成维护门禁与请求排水，开始切换...'));
                
                if (!$this->stopExistingServer($instanceName, $port, $count)) {
                    $this->disableMaintenanceMode($instanceName);
                    return 1;
                }
                $restartCleanupPerformed = true;
            }
        }

        // fresh `-r` 也必须在任何新 sidecar/Worker 创建前冻结旧代事实。
        // 即使结果为空也标记为 captured，禁止后续从已被本次启动改写的运行态重算。
        if ($forceRestart && !$this->restartHandoffCaptured) {
            $this->restartHandoffPorts = $this->captureRestartHandoffPorts(
                $instanceName,
                $port,
                $count
            );
            $this->restartHandoffCaptured = true;
        }

        // A same-instance restart cannot reserve the old generation's TCP
        // endpoint while that generation is still serving. Compile and policy
        // preflight above therefore use an immutable edge plan only. Once the
        // old listener/process snapshot is frozen and proven gone, perform the
        // one exact bind while the per-instance start lock is still held.
        if ($deferStartupListenerReservation) {
            try {
                // The old generation may have spent its complete drain budget
                // before releasing the endpoint. Listener ownership begins a
                // new explicit phase here; reserve/read/transfer still share
                // this one absolute deadline rather than resetting per call.
                $this->beginStartupListenerStateDeadline();
                $startupDecision = $this->createGatewayStartupDecisionForListenerPhase();
                if ($gatewayMode) {
                    $port = $this->allocateGatewayInitialBackendPort(
                        $instanceName,
                        $port,
                    );
                    $config['port'] = $port;
                    $config['gateway']['backend_port'] = $port;
                    $config['gateway']['backend_lease'] =
                        $this->startupGatewayBackendLease ?? [];
                } else {
                    $edgeDecision = $startupDecision->materializePublicListener(
                        $edgeDecision,
                        $instanceName,
                        $publicLeaseBindHost,
                        $port,
                        $this->startupListenerStateDeadline(),
                    );
                    $this->startupPublicEdgeListener =
                        $startupDecision->takeReservedListener();
                    $this->startupPublicEdgeLease = $edgeDecision->portLease;
                    $config['port'] = (int)($edgeDecision->portLease['port'] ?? 0);
                    $port = (int)$config['port'];
                    $config['gateway']['public_lease'] = $edgeDecision->portLease;
                    $config['gateway']['fallback_bind_host'] = \strtolower(\trim(
                        (string)($edgeDecision->portLease['bind_host'] ?? ''),
                        " \t\n\r\0\x0B[]",
                    ));
                    $config['gateway']['edge_decision'] = $edgeDecision->toArray();
                }
                $this->publishStartupListenerHandoffIntent($config);
                $materializedTopology = $runtimeResolver->resolveTopologyIntent(
                    $config,
                    $args,
                );
                if ($materializedTopology['requested'] !== $runtimeSelection->requestedTopology
                    || $materializedTopology['effective'] !== $runtimeSelection->effectiveTopology
                ) {
                    throw new \RuntimeException(
                        'Materialized startup listener changed the preflighted runtime topology.',
                    );
                }
            } catch (\Throwable $exception) {
                $this->closeStartupListenerCopies();
                $this->printer->error(__('WLS 旧代停止后端口接管失败：%{1}', [
                    $exception->getMessage(),
                ]));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return 1;
            }
            if ($restartCleanupPerformed) {
                $this->printer->note(__(
                    'WLS 本机重启在旧代完全退出后才绑定新代端口；当前版本不宣称跨代无缝 FD 接管。'
                ));
            }
        }

        // Worker 基础端口：默认 10000 + 项目偏移量，确保多项目不冲突
        $defaultWorkerBasePort = 10000 + MasterProcess::getProjectPortOffset();
        $workerBasePort = (int) ($config['worker_base_port'] ?? $defaultWorkerBasePort);
        $this->printer->note(__('Worker基础端口: %{1}', [$workerBasePort]));
        try {
            $this->traceStartupPhase($instanceName, 'shared-runtime:before');
            $sharedStateRuntime = $this->resolveSharedStateRuntimeConfig($instanceName, $config, $forceRestart, $windowMode);
            $this->sharedStateConsumerAcquired = (bool)(
                ($sharedStateRuntime['session']['registered'] ?? false)
                || ($sharedStateRuntime['memory']['registered'] ?? false)
            );
            $this->printer->note(__('共享状态运行时: %{1}', [$sharedStateRuntime]));
            $this->traceStartupPhase($instanceName, 'shared-runtime:after', [
                'session_port' => (int)($sharedStateRuntime['session']['port'] ?? 0),
                'memory_port' => (int)($sharedStateRuntime['memory']['port'] ?? 0),
            ]);
        } catch (\RuntimeException $exception) {
            $this->printer->note(__('共享状态运行时解析失败: %{1}', [$exception->getMessage()]));
            $this->printer->error($exception->getMessage());
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return 1;
        }
        $sessionServerPort = (int) ($sharedStateRuntime['session']['port'] ?? 0);
        if ($sessionServerPort <= 0) {
            $sessionServerPort = 19970 + MasterProcess::getProjectPortOffset();
        }
        $memoryServerPort = (int) ($sharedStateRuntime['memory']['port'] ?? 0);
        if ($memoryServerPort <= 0) {
            $memoryServerPort = 19971 + MasterProcess::getProjectPortOffset();
        }
        $config['session_server_port'] = $sessionServerPort;
        $config['session_server_token_file_name'] = (string) ($sharedStateRuntime['session']['token_file_name']
            ?? SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $sessionServerPort));
        $config['memory_server_port'] = $memoryServerPort;
        $config['memory_server_token_file_name'] = (string) ($sharedStateRuntime['memory']['token_file_name']
            ?? SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $memoryServerPort));
        $config['shared_state'] = $sharedStateRuntime;
        // Gateway and pure-WLS both freeze the non-secret shared-session
        // capability fact before Master spawns children. Workers only accept
        // this launch snapshot; live probes must not replace it later.
        try {
            if (!\is_array($config['gateway'] ?? null)) {
                $config['gateway'] = [];
            }
            $capabilityResolver = new \Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityResolver();
            $capability = (new \Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityStateStore())
                ->stabilize($capabilityResolver->resolve([
                    'gateway' => $config['gateway'],
                    'shared_state' => $sharedStateRuntime,
                ]), $this->startupListenerStateDeadline());
            $config['gateway']['backend_capability_launch'] =
                $capabilityResolver->createLaunchSnapshot(
                    $capability,
                    $instanceGeneration,
                    $launchId,
                );
        } catch (\Throwable $exception) {
            $this->printer->error(__(
                'WLS 2.0 回源会话能力启动快照创建失败：%{1}',
                [$exception->getMessage()],
            ));
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return 1;
        }
        $this->printSharedStateRuntimeSummary($instanceName, $sharedStateRuntime);

        // Worker 端口计算移至端口冲突检测之后，避免重复计算
        // Public TLS and HTTP redirects terminate at Nginx.
        
        // WLS owns only the private plaintext backend.
        $httpRedirectPort = 0;
        
        // 主端口（Dispatcher 端口）被非框架进程占用时：
        // - 用户未指定 -p 且端口为 80/443（通用 web 端口，可能被宝塔/nginx 占用）→ 自动降级到 9981
        // - 用户指定了 -p 或降级端口 9981 也被占用 → 报错退出
        $autoDowngradedFromDefaultPort = false;
        $this->traceStartupPhase($instanceName, 'main-port-preflight:before', [
            'port' => (int)$port,
        ]);
        $mainPortInspect = $this->inspectStartupPortIfOccupied($port, $skipPostStopPortInspection);
        if ($forceRestart && !$forceSwitch && ($mainPortInspect['in_use'] ?? false)) {
            $this->printer->error(__('强制重启后主端口 %{1} 仍被占用，已中止启动，避免同名实例切换到新端口。', [$port]));
            $this->printer->note(__('请先确认旧实例已完全停止，再重新执行启动命令。'));
            $this->rollbackRestartMaintenanceTransactionIfPending();
            throw new \RuntimeException(__('强制重启后主端口 %{1} 仍被占用，启动已中止。', [$port]));
        }
        if (($mainPortInspect['in_use'] ?? false) && !($mainPortInspect['is_weline'] ?? false)) {
            if (!$portExplicit && ($port === self::DEFAULT_PORT || $port === self::DEFAULT_PORT_HTTPS)) {
                $this->printer->warning(__('默认端口 %{1} 被占用（可能被宝塔/nginx 等 web 服务占用），已降级到 %{2}', [$port, self::DEFAULT_PORT_FALLBACK]));
                $port = self::DEFAULT_PORT_FALLBACK;
                $config['port'] = $port;
                $httpRedirectPort = 0;
                $autoDowngradedFromDefaultPort = true;
            }
            // 降级后的端口占用由下方统一处理：异常占用尝试自动切换，其他场景保持报错。
            $mainPortInspect = $this->inspectStartupPortIfOccupied($port, $skipPostStopPortInspection);
        }

        $fallbackPort = $this->resolveOrphanMainPortFallback(
            $port,
            $portExplicit,
            $autoDowngradedFromDefaultPort,
            $mainPortInspect
        );
        if ($fallbackPort !== $port) {
                $this->printer->warning(__('主端口 %{1} 处于异常占用状态（系统返回的 PID 已失效），已自动切换到 %{2}', [$port, $fallbackPort]));
                $this->printer->note(__('自动切换仅对未显式指定端口的异常占用生效；启动成功后会记住新端口'));
                $port = $fallbackPort;
                $config['port'] = $port;
                $mainPortInspect = $this->inspectStartupPortIfOccupied($port, $skipPostStopPortInspection);
            }
        if (($mainPortInspect['in_use'] ?? false) && !($mainPortInspect['is_weline'] ?? false)) {
            if (($mainPortInspect['state'] ?? '') === 'orphan') {
                $this->printer->error(__('主端口 %{1} 处于异常占用状态（系统返回的 PID 已失效）', [$port]));
            } else {
                $this->printer->error(__('主端口 %{1} 被非框架进程占用', [$port]));
            }
            $this->printer->note(__('主端口是业务入口，不会自动切换以避免服务地址变化'));
            $this->printer->note('');
            $this->printer->setup(__('解决方案：'));
            $this->printer->note(__('  1. 手动停止占用端口 %{1} 的进程', [$port]));
            $this->printer->note(__('  2. 或使用 -p 参数显式指定其他端口：'));
            $this->printer->note('     php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-p <port>');
            $this->printer->note(__('  3. 查看端口占用：'));
            $this->printer->note('     php bin/w server:kill-port ' . $port . ' --info');
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return 1;
        }
        $this->traceStartupPhase($instanceName, 'main-port-preflight:after', [
            'port' => (int)$port,
            'in_use' => (bool)($mainPortInspect['in_use'] ?? false),
        ]);

        $reservedWorkerPorts = $this->getWorkerAllocationReservedPorts(
            $port,
            $dispatcherEnabled || $usesWorkerPorts,
        );
        $requiresWorkerPortAllocationLock = $usesWorkerPorts
            || (!$useDirectMode && $count > 1);
        $workerPortAllocationLocked = false;
        if ($requiresWorkerPortAllocationLock) {
            $this->traceStartupPhase($instanceName, 'worker-port-lock:before', [
                'workers' => (int)$count,
            ]);
            if (!$this->acquireWorkerPortAllocationLock()) {
                $this->printer->error(__('无法分配 Worker 端口：全局端口分配锁正被其他启动流程占用'));
                $this->printer->note(__('请稍后重试，或等待其他实例启动完成'));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return 1;
            }
            $workerPortAllocationLocked = true;
            $this->traceStartupPhase($instanceName, 'worker-port-lock:after');
        }

        try {
            $this->traceStartupPhase($instanceName, 'worker-port-plan:before', [
                'worker_base_port' => (int)$workerBasePort,
                'dispatcher' => $dispatcherEnabled,
                'direct' => $useDirectMode,
            ]);
            $workerPort = $this->resolveInitialWorkerPort(
                $port,
                $workerBasePort,
                $count,
                $dispatcherEnabled,
                $useDirectMode,
                $usesWorkerPorts,
            );

            if ($forceRestart && !$forceSwitch && !$skipPostStopPortInspection && $this->hasRestartCleanupResidue($instanceName, $port, $count, $workerPort, $forceSwitch)) {
                $this->reportRestartHandoffTimeout($instanceName);
                $this->printer->error(__('强制重启前仍检测到旧实例 [%{1}] 的残留 WLS 进程或端口，已中止启动。', [$instanceName]));
                $this->printer->note(__('必须先完成旧实例清理，禁止自动切换主端口或 Worker 端口启动第二个同名实例。'));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                throw new \RuntimeException(__('旧实例 [%{1}] 仍有残留 WLS 进程或端口，启动已中止。', [$instanceName]));
            }

        // Dispatcher 模式或独立端口模式：Worker 端口段需智能分配
        // - WLS 进程占用的端口：释放后分配给新进程
        // - 非 WLS 进程占用的端口：跳过，使用下一个可用端口
        if (!$restartCleanupPerformed
            && ($dispatcherEnabled || $usesWorkerPorts || (!$useDirectMode && $count > 1))
        ) {
            $nextWorkerPort = $this->findAvailableWorkerPortBase(
                $workerPort,
                $count,
                500,
                $instanceName,
                $reservedWorkerPorts,
            );
            if ($nextWorkerPort !== $workerPort) {
                $this->printer->warning(__('Worker 端口段 %{1}-%{2} 存在端口冲突或系统预留，自动切换到 %{3}-%{4}', [
                    $workerPort,
                    $workerPort + $count - 1,
                    $nextWorkerPort,
                    $nextWorkerPort + $count - 1
                ]));
                $workerPort = $nextWorkerPort;
            }
        }
        $this->traceStartupPhase($instanceName, 'worker-port-plan:after', [
            'worker_port' => (int)$workerPort,
            'workers' => (int)$count,
        ]);

        // Unknown port owners are outside WLS authority. Keep HTTPS available,
        // disable only the optional redirect listener and never prompt, stop or
        // modify the occupying process.
        $httpRedirectInspect = (!$skipPostStopPortInspection && $sslEnabled && $httpRedirectPort > 0)
            ? $this->inspectStartupPortIfOccupied($httpRedirectPort)
            : [];
        $httpRedirectOwner = null;
        if (($httpRedirectInspect['in_use'] ?? false) && $fastRestartMetadata === null) {
            $mainStop ??= ObjectManager::getInstance(MainStop::class);
            $httpRedirectOwner = $mainStop->findWelineServerInstanceNameByPort($httpRedirectPort);
        }
        if (!$skipPostStopPortInspection && $sslEnabled && $httpRedirectPort > 0
            && ($httpRedirectInspect['in_use'] ?? false)
            && !$this->isFrameworkOwnedHttpRedirectPortOccupant($httpRedirectInspect, $httpRedirectOwner)
        ) {
            $occupantPid = (int) ($httpRedirectInspect['pid'] ?? 0);
            $occupantState = (string) ($httpRedirectInspect['state'] ?? '');
            $occupantName = $this->resolvePortOccupantDisplayName(
                $httpRedirectInspect,
                $httpRedirectOwner ?? $instanceName
            );
            $occupantCmdline = ($occupantPid > 0)
                ? \trim((string) Processer::getProcessCommandLine($occupantPid))
                : '';

            if ($occupantState === 'orphan') {
                $this->printer->error(__('HTTP 重定向端口 %{1} 处于异常占用状态（系统返回的 PID 已失效）', [$httpRedirectPort]));
            } else {
                $this->printer->error(__('HTTP 重定向端口 %{1} 被非框架进程占用', [$httpRedirectPort]));
            }
            $this->printer->note(__('  占用进程：%{1}', [$occupantName]));
            if ($occupantPid > 0) {
                $this->printer->note(__('  PID：%{1}', [$occupantPid]));
            }
            if ($occupantCmdline !== '') {
                $this->printer->note(__('  命令行：%{1}', [$occupantCmdline]));
            }

            $this->printer->warning(__(
                'WLS 不会操作未知端口 owner；本次仅禁用可选的 HTTP→HTTPS 重定向监听。'
            ));
            $httpRedirectPort = 0;
        }

        $legacyPrivilegeMutation = $effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY;
        // WLS 2.0 never mutates host privileges during project startup. Only
        // the explicit legacy edge retains the historical setcap/sudo path.
        $this->traceStartupPhase($instanceName, 'permission-check:before');
        if (!$this->ensurePrivilegedPortPermission(
            $port,
            $httpRedirectPort,
            $backendSslEnabled,
            $legacyPrivilegeMutation,
        )) {
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return;
        }
        
        // Linux/macOS 下检测 socket 权限（即使高端口也可能因系统安全设置需要 sudo）
        if (!$this->ensureUnixSocketPermission(
            $backendListenHost,
            $port,
            $legacyPrivilegeMutation,
        )) {
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return 1;
        }
        $this->traceStartupPhase($instanceName, 'permission-check:after');
        
        // 80/443 端口自我处理提示（特权端口、单端口建议）
        if ($useDirectMode && $count > 1) {
            if ($usesWorkerPorts) {
                $this->printer->note(__('提示：当前为 Nginx 直连模式，Worker 独立监听回环端口 %{1}-%{2}。', [
                    $workerPort,
                    $workerPort + $count - 1,
                ]));
            } else {
                $listenerLabel = $runtimeSelection->listenerMode === 'shared_fd'
                    ? __('Master 共享监听 FD')
                    : 'SO_REUSEPORT';
                $this->printer->note(__('提示：当前为 %{1} 直连模式，多 Worker 共用同一端口 %{2}。', [$listenerLabel, $port]));
            }
        }

        // 检查端口是否被占用（框架进程占用时最多重试 3 次，仍占用则按 Master 前缀清理逃逸 Master 后再试）
        $this->traceStartupPhase($instanceName, 'port-release-check:before', [
            'main_port' => (int)$port,
            'worker_port' => (int)$workerPort,
            'workers' => (int)$count,
            'dispatcher' => $dispatcherEnabled,
            'skipped' => $skipPostStopPortInspection,
        ]);
        if (!$skipPostStopPortInspection && $dispatcherEnabled) {
            // Dispatcher 模式：检查主端口（Dispatcher 用）+ Worker 内网端口
            if (!$this->checkAndReleasePort($host, $port, $restartCleanupPerformed, 'Dispatcher', $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return 1;
            }
            if (!$this->checkAndReleasePorts($host, $workerPort, $count, $restartCleanupPerformed, $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return 1;
            }
        } elseif (!$skipPostStopPortInspection) {
            // 直连模式：
            // - SO_REUSEPORT: 多 Worker 复用同一端口，只检查主端口
            // - worker_ports: Nginx 直连连续的 Worker 回环端口段
            $checkResult = $usesWorkerPorts
                ? $this->checkAndReleasePorts('127.0.0.1', $workerPort, $count, $restartCleanupPerformed, $instanceName)
                : ($useDirectMode
                    ? $this->checkAndReleasePort($host, $port, $restartCleanupPerformed, 'Worker(Main)', $instanceName)
                    : $this->checkAndReleasePorts($host, $workerPort, $count, $restartCleanupPerformed, $instanceName));
            if (!$checkResult) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return 1;
            }
        }
        $this->traceStartupPhase($instanceName, 'port-release-check:after', [
            'skipped' => $skipPostStopPortInspection,
        ]);
        
        // ========== 检查 HTTP 重定向端口（在启动前检测，避免启动到一半才报错） ==========
        if (!$skipPostStopPortInspection && $sslEnabled && $httpRedirectPort > 0) {
            // HTTP Redirect 端口被占用时，提示用户确认是否强制停用
            if (Processer::isPortInUse($httpRedirectPort)) {
                $portInspect = $this->inspectStartupPortIfOccupied($httpRedirectPort);
                $redirectOwner = $fastRestartMetadata === null && $mainStop !== null
                    ? $mainStop->findWelineServerInstanceNameByPort($httpRedirectPort)
                    : null;
                $isWelineOccupant = $this->isFrameworkOwnedHttpRedirectPortOccupant($portInspect, $redirectOwner);
                $shouldAutoRelease = $this->shouldAutoReleaseHttpRedirectPortOccupant($portInspect) || $redirectOwner === $instanceName;
                $processName = $this->resolvePortOccupantDisplayName($portInspect, $redirectOwner ?? $instanceName);

                // 被其它 WLS 实例占用时不进入“杀进程确认”流程，避免误停其它实例
                if ($isWelineOccupant && $redirectOwner !== null && $redirectOwner !== $instanceName) {
                    $this->printer->error(__('HTTP Redirect 端口 %{1} 已被实例 [%{2}] 占用: %{3}', [
                        $httpRedirectPort,
                        $redirectOwner,
                        $processName,
                    ]));
                    $this->printer->note(__('请先停止实例 [%{1}]，或改用非 443 主端口启动。', [$redirectOwner]));
                    $this->rollbackRestartMaintenanceTransactionIfPending();
                    return 1;
                }

                if ($shouldAutoRelease) {
                    $this->printer->warning(__('HTTP Redirect port %{1} is occupied by %{2}', [$httpRedirectPort, $processName]));
                    $this->printer->note(__('Detected framework-owned process on port %{1}; releasing it automatically...', [$httpRedirectPort]));
                    if (!$this->releaseFrameworkOwnedHttpRedirectPort($host, $httpRedirectPort, $instanceName)) {
                        $this->printer->note(__('HTTP Redirect port %{1} could not be released; HTTP to HTTPS redirect will be disabled.', [$httpRedirectPort]));
                        $this->printer->note(__('Tip: start on a non-443 main port to run without a dedicated HTTP redirect worker.'));
                        $httpRedirectPort = 0;
                    }
                    goto wls_http_redirect_conflict_done;
                }

                // WLS 2.0 never turns a port observation into permission to
                // kill an unknown process, even after an interactive prompt.
                $this->printer->warning(__('HTTP Redirect 端口 %{1} 被未知进程占用: %{2}', [
                    $httpRedirectPort,
                    $processName,
                ]));
                $this->printer->note(__(
                    '已保留该进程；HTTP→HTTPS 重定向将不启用。请手动处理 owner 或改用非 443 主端口。'
                ));
                $httpRedirectPort = 0;
            }
        }
        
            // 创建 Worker 脚本路径（Dispatcher 模式下使用非 SSL 脚本）
            wls_http_redirect_conflict_done:
            // 旧代已退出、新 Master/Worker 尚未创建：在此激活启动预检选中的同一份策略。
            // Worker READY 会以该 digest 为准，禁止同一代出现编译参数不同的混合策略。
            $this->traceStartupPhase($instanceName, 'policy-activate:before');
            try {
                $policyBundle = $policyControl->activateForStart(
                    $instanceName,
                    $policyTopology,
                    $config,
                );
                $runtimeStrategy['policy_digest'] = $policyBundle->digest;
                $runtimeStrategy['http3'] = $config['http3'];
                $this->traceStartupPhase($instanceName, 'policy-activate:after', [
                    'policy_digest' => $policyBundle->digest,
                ]);
            } catch (\Throwable $exception) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                }
                $this->printer->error(__('WLS 启动策略激活失败：%{1}', [$exception->getMessage()]));
                return 1;
            }
            try {
                $this->prepareStartupListenerTransfer($config);
            } catch (\Throwable $exception) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                }
                $this->printer->error(__('WLS 启动监听交接意图固化失败：%{1}', [
                    $exception->getMessage(),
                ]));
                return 1;
            }
            // 保存实例信息（Master 将从这里读取配置并启动所有进程）
            $workerScript = $this->ensureWorkerScript();
            $orchestratorRuntimeOptions = $this->buildOrchestratorRuntimeOptions($windowMode);
            $listenHost = $backendListenHost;
            $this->traceStartupPhase($instanceName, 'save-instance:before');
            $this->saveInstanceInfo(
                $instanceName,
                $listenHost,
                $port,
                $count,
                $daemon,
                $backendSslEnabled,
                $backendSslEnabled ? $sslCert : '',
                $backendSslEnabled ? $sslKey : '',
                $runtimeSelection,
                $workerPort,
                $httpRedirectPort,
                $windowMode,
                $enableLog,
                $workerBasePort,
                $sharedStateRuntime,
                $orchestratorRuntimeOptions,
                (string)($config['worker_memory_limit'] ?? '256M'),
                (string)($config['dispatcher_memory_limit'] ?? ''),
                $publicHost,
                \is_array($config['gateway'] ?? null) ? $config['gateway'] : [],
                \array_merge($runtimeStrategy, [
                    'http3' => $config['http3'],
                    'edge_adapter' => $edgeAdapter->name(),
                    'public_origin' => $publicOrigin,
                ]),
            );
            $this->traceStartupPhase($instanceName, 'save-instance:after');
        } finally {
            if ($workerPortAllocationLocked) {
                $this->releaseWorkerPortAllocationLock();
            }
        }
        
        // 保存实例配置（配置记忆：下次 server:start <name> 直接使用相同配置）
        // 公网 host/port/https 仅在 Nginx 实际就绪后同步，避免发布不可达地址。
        $this->traceStartupPhase($instanceName, 'save-config:before');
        $this->saveInstanceConfig($instanceName, $args, $config);
        $this->traceStartupPhase($instanceName, 'save-config:after');
        
        // 显示优化建议
        $this->traceStartupPhase($instanceName, 'optimization-tips:before');
        $this->showOptimizationTips($count, $config['mode'] ?? 'io', $dispatcherEnabled, $supportsReusePort, $useDirectMode);
        $this->traceStartupPhase($instanceName, 'optimization-tips:after');
        
        // 显示使用说明（按实际协议显示 http/https）
        
        // ========== 开发模式热重载支持 ==========
        $this->traceStartupPhase($instanceName, 'hot-reload:before');
        $this->startHotReloadIfEnabled($config, $instanceName);
        $this->traceStartupPhase($instanceName, 'hot-reload:after');
        // ========== 热重载结束 ==========
        
        // 注意：平滑重启 / -r -f 引入的维护态不在此处提前关闭。
        // 旧 Master 已死、新 Master 尚未起来期间若提前关维护态，会出现"半裸 RST"空窗。
        // 改为在 daemon 分支拿到 startMasterInBackground=true 后、或前台分支 runMasterProcess 即将占用端口前再关闭。
        
        // ========== Master 进程负责启动所有进程 ==========
        $config['worker_port'] = $workerPort;
        $config['runtime_selection'] = $runtimeSelection;
        $config['orchestrator_runtime_options'] = $this->buildOrchestratorRuntimeOptions($windowMode);
        // 同步 daemon 标志到 config，确保前台/后台 Master 行为一致。
        $config['daemon'] = $daemon;

        // 将 .local 域名转换为 127.0.0.1 用于实际监听
        // 域名仅用于 SSL 证书，实际监听使用 IP 避免 PHP DNS 解析问题
        $listenHost = $backendListenHost;

        if ($daemon) {
            $this->wlsChildProcessesMayExist = true;
            $this->traceStartupPhase($instanceName, 'master-background:before');
            $startupCompleted = $this->startMasterInBackground(
                $instanceName,
                $backendSslEnabled,
                $listenHost,
                $port,
                $launchId,
                $foregroundMode,
                $windowMode,
                $args,
                $maintenanceEnabledByUs,
                $maintenanceResetAfterForceSwitch,
            );
            $this->traceStartupPhase($instanceName, 'master-background:after', [
                'completed' => $startupCompleted,
            ]);
            if (!$startupCompleted && $this->wlsChildProcessesMayExist) {
                $this->retirePossibleGatewayRegistrationBeforeFailedStartupCleanup(
                    $instanceName,
                );
                try {
                    if ($this->cleanupFailedStartupProcesses($instanceName, $count)) {
                        $this->wlsChildProcessesMayExist = false;
                        $this->cancelUntransferredPublicEdgeLease();
                    } else {
                        $this->printer->warning(__(
                            '启动失败后未能证明所有 WLS 子进程已退出；启动锁将保持到 shutdown 再次回收。'
                        ));
                    }
                } catch (\Throwable $throwable) {
                    $this->printer->warning(__(
                        '启动失败后的 WLS 子进程回收异常：%{1}；启动锁将保持到 shutdown 再次回收。',
                        [\substr($throwable->getMessage(), 0, 512)],
                    ));
                }
            }
            $this->wlsStartupProcessHandoffDone = $startupCompleted || !$this->wlsChildProcessesMayExist;
            $this->sharedStateConsumerHandoffDone = $startupCompleted;
            // A failed cleanup keeps the launch lock through the shutdown
            // cleanup callback. Successful startup/cleanup may release now.
            if ($this->wlsStartupProcessHandoffDone) {
                $this->releaseStartLock();
            }
            // 关闭由本次启动流程引入的维护态：仅在新 Master 全部就绪后才关，避免空窗。
            if ($this->restartMaintenanceSnapshot !== null) {
                $this->finalizeMaintenanceModeAfterStartup(
                    $instanceName,
                    $maintenanceEnabledByUs,
                    $maintenanceResetAfterForceSwitch,
                    $startupCompleted
                );
            }
            $this->finalizeBackgroundStartupOutput(
                $startupCompleted,
                $instanceName,
                $publicHost,
                $port,
                $count,
                (string) ($config['source'] ?? ''),
                $sslEnabled,
                $dispatcherEnabled,
                $workerPort,
                $httpRedirectPort,
                $useDirectMode ? $runtimeSelection->listenerMode : ''
            );
            if ($startupCompleted) {
                $this->printGoodbye(true, __('所有服务已就绪，可使用 %{1}php bin/w server:status%{2} 查看状态', ['<info>', '</info>']));
                $this->flushDeferredStartupWarning();
            }
            // Convert the final READY decision into an explicit command status
            // only after lock, maintenance and startup-output finalizers ran.
            return $this->resolveStartupCommandExitCode($startupCompleted);
        }

        // 前台运行：Master 将占用当前终端
        $this->printer->note(__('Master 进程启动中，将管理所有 Worker 和 Dispatcher...'));
        if (\function_exists('flush')) {
            @\flush();
        }

        // 前台模式也使用 listenHost；对外展示的访问域名保留为项目 host（如 *.weline.test / *.weline.localhost）
        $config['public_host'] = (string)($config['public_host'] ?? $host);
        $config['host'] = $listenHost;
        $this->warnWindowsLocalDomainProxyRisk((string)$config['public_host']);

        // 前台模式：runMasterProcess 即将同步占用端口，此时关闭维护态空窗最短（亚秒级）。
        // 视为"成功路径"清理；若 runMasterProcess 后续抛错，PHP 进程退出由系统兜底。
        $this->finalizeMaintenanceModeAfterStartup(
            $instanceName,
            $maintenanceEnabledByUs,
            $maintenanceResetAfterForceSwitch,
            true,
            false
        );

        // Master owns all child-process startup.
        $this->wlsChildProcessesMayExist = true;
        $this->installStartupListenerInCurrentMaster($instanceName, $config);
        $this->runMasterProcess($instanceName, $config, $workerScript, '', '', $backendSslEnabled, $httpRedirectPort, $windowMode);
    }

    protected function shouldPersistGatewayCertificateSource(
        bool $sslEnabled,
        bool $certificatePending,
        bool $gatewayMode,
        bool $pureWlsMode,
        string $requestedMode,
    ): bool {
        return ($sslEnabled || $certificatePending)
            && ($gatewayMode
                || $pureWlsMode
                || $requestedMode
                    === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO);
    }

    protected function resolveStartupCommandExitCode(bool $startupCompleted): int
    {
        return $startupCompleted ? 0 : 1;
    }

    /**
     * 启动流程结束后统一关闭"由本次启动引入"的维护态，避免提前关导致空窗期裸 RST。
     *
     * - $maintenanceEnabledByUs：本次启动主动开启的维护态（-r 平滑路径）
     * - $maintenanceResetAfterForceSwitch：-r -f 停机型切换时残留维护态的兜底清理
     * - $startupCompleted=false：启动事务失败，必须恢复重启前的持久维护态
     * - $runtimeControlAvailable=false：前台 Master 尚未进入控制循环，只提交持久快照；
     *   Worker READY 时由 Master 初始维护态门禁统一应用，不能在此时自连 IPC。
     */
    protected function finalizeMaintenanceModeAfterStartup(
        string $instanceName,
        bool $maintenanceEnabledByUs,
        bool $maintenanceResetAfterForceSwitch,
        bool $startupCompleted,
        bool $runtimeControlAvailable = true
    ): void {
        if (!$maintenanceEnabledByUs && !$maintenanceResetAfterForceSwitch) {
            return;
        }
        $originalMaintenanceEnabled = $this->restartMaintenanceSnapshot !== null
            && $this->restartMaintenanceSnapshot['instance_name'] === $instanceName
            && $this->restartMaintenanceSnapshot['enabled'];
        if ($runtimeControlAvailable) {
            $this->disableMaintenanceMode($instanceName, $startupCompleted);
        } else {
            $this->restoreRestartMaintenanceConfigurationOnly($instanceName);
        }
        if (!$startupCompleted) {
            $this->printer->warning(__('新 Master 未在预期时间内就绪，已回滚到重启前的维护态，禁止在启动失败后污染持久配置。'));
            return;
        }
        if ($originalMaintenanceEnabled) {
            $this->printer->success(__('已恢复重启前的维护模式（保持开启）。'));
        } elseif ($maintenanceResetAfterForceSwitch && !$maintenanceEnabledByUs) {
            $this->printer->success(__('已清理残留维护态，恢复业务流量模式。'));
        } else {
            $this->printer->success(__('维护模式已关闭。'));
        }
    }

    /**
     * 前台 Master 与启动命令处于同一进程；runMasterProcess() 之前没有可用
     * control endpoint。这里只恢复持久事务，Master 随后以该权威状态启动，
     * 并在 Worker READY 路径应用同一维护门禁。
     */
    protected function restoreRestartMaintenanceConfigurationOnly(string $instanceName): void
    {
        $snapshot = $this->restartMaintenanceSnapshot;
        if ($snapshot === null || $snapshot['instance_name'] !== $instanceName) {
            return;
        }

        try {
            $restored = Env::getInstance()->setConfig('system.maintenance', $snapshot['enabled']);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                (string)__('恢复重启前维护态失败：%{1}', [$throwable->getMessage()]),
                0,
                $throwable
            );
        }
        if (!$restored) {
            throw new \RuntimeException((string)__('恢复重启前维护态失败，请检查 app/etc/env.php 写入权限。'));
        }

        $this->restartMaintenanceSnapshot = null;
    }

    /**
     * @param array<string, mixed> $mainPortInspect
     */
    protected function resolveOrphanMainPortFallback(
        int $port,
        bool $portExplicit,
        bool $autoDowngradedFromDefaultPort,
        array $mainPortInspect
    ): int {
        if (!($mainPortInspect['in_use'] ?? false)
            || ($mainPortInspect['is_weline'] ?? false)
            || $portExplicit
            || (($mainPortInspect['state'] ?? '') !== 'orphan')
            || $autoDowngradedFromDefaultPort
        ) {
            return $port;
        }

        return $this->findAvailableMainPort($port + 1);
    }

    protected function warnWindowsLocalDomainProxyRisk(string $host): void
    {
        if (!IS_WIN || $this->isLoopbackLikeHost($host)) {
            return;
        }

        $settings = $this->readWindowsInternetSettings();
        if (!$this->isWindowsProxyLikelyToInterceptHost($host, $settings)) {
            return;
        }

        $proxyServer = (string)($settings['proxy_server'] ?? '');
        $suggestedRule = $this->buildSuggestedWindowsProxyBypassRule($host);
        $this->printer->warning(__('检测到 Windows 系统代理 %{1} 已启用，浏览器访问 %{2} 可能被代理截流并报 ERR_CONNECTION_CLOSED', [$proxyServer, $host]));
        $this->printer->note(__('建议将 %{1} 加入系统代理绕过名单（ProxyOverride）后再访问本地 WLS 域名', [$suggestedRule]));
    }

    /**
     * @return array{proxy_enabled: bool, proxy_server: string, proxy_override: string}
     */
    protected function readWindowsInternetSettings(): array
    {
        $proxyEnable = $this->readWindowsInternetSettingValue('ProxyEnable');
        $proxyServer = $this->readWindowsInternetSettingValue('ProxyServer');
        $proxyOverride = $this->readWindowsInternetSettingValue('ProxyOverride');

        return [
            'proxy_enabled' => $this->parseWindowsProxyEnableValue($proxyEnable),
            'proxy_server' => $proxyServer,
            'proxy_override' => $proxyOverride,
        ];
    }

    protected function readWindowsInternetSettingValue(string $valueName): string
    {
        if (\preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $valueName) !== 1) {
            return '';
        }
        $systemRoot = \rtrim((string)(\getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
        $reg = $systemRoot . '\\System32\\reg.exe';
        if (!\is_file($reg) || \is_link($reg)) {
            return '';
        }
        try {
            $result = GatewayBoundedCommandRunner::run([
                $reg,
                'query',
                'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings',
                '/v',
                $valueName,
            ], 1.0);
        } catch (\Throwable) {
            return '';
        }
        $output = (string)($result['output'] ?? '');
        if ((int)($result['code'] ?? 1) !== 0 || $output === '') {
            return '';
        }

        if (\preg_match('/^\s*' . \preg_quote($valueName, '/') . '\s+REG_\w+\s+(.+)$/mi', $output, $matches)) {
            return \trim($matches[1]);
        }

        return '';
    }

    protected function parseWindowsProxyEnableValue(string $value): bool
    {
        $normalized = \strtolower(\trim($value));
        if ($normalized === '') {
            return false;
        }

        if (\str_starts_with($normalized, '0x')) {
            return \hexdec(\substr($normalized, 2)) > 0;
        }

        return (int)$normalized > 0;
    }

    /**
     * @param array{proxy_enabled?: bool, proxy_server?: string, proxy_override?: string} $settings
     */
    protected function isWindowsProxyLikelyToInterceptHost(string $host, array $settings): bool
    {
        if (!($settings['proxy_enabled'] ?? false)) {
            return false;
        }

        $proxyServer = \trim((string)($settings['proxy_server'] ?? ''));
        if ($proxyServer === '') {
            return false;
        }

        return !$this->hostMatchesWindowsProxyOverride($host, (string)($settings['proxy_override'] ?? ''));
    }

    protected function hostMatchesWindowsProxyOverride(string $host, string $proxyOverride): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return false;
        }

        $rules = \preg_split('/\s*;\s*/', \trim($proxyOverride)) ?: [];
        foreach ($rules as $rule) {
            $rule = \strtolower(\trim($rule));
            if ($rule === '') {
                continue;
            }

            if ($rule === '<local>' && \strpos($host, '.') === false) {
                return true;
            }

            if ($rule === $host) {
                return true;
            }

            if ($this->windowsProxyRuleMatchesHost($rule, $host)) {
                return true;
            }
        }

        return false;
    }

    protected function windowsProxyRuleMatchesHost(string $rule, string $host): bool
    {
        if ($rule === '') {
            return false;
        }

        $quoted = \preg_quote($rule, '/');
        $pattern = '/^' . \str_replace(['\*', '\?'], ['.*', '.'], $quoted) . '$/i';

        return (bool)\preg_match($pattern, $host);
    }

    protected function buildSuggestedWindowsProxyBypassRule(string $host): string
    {
        $host = \strtolower(\trim($host));
        if (\str_ends_with($host, '.weline.test')) {
            return '*.weline.test;weline.test';
        }

        if (\str_ends_with($host, '.weline.localhost')) {
            return '*.weline.localhost;weline.localhost';
        }

        return $host;
    }

    protected function isLoopbackLikeHost(string $host): bool
    {
        $host = \strtolower(\trim($host));

        return $host === ''
            || $host === 'localhost'
            || $host === '::1'
            || $host === '0.0.0.0'
            || $host === '127.0.0.1'
            || (bool)\preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
    }

    protected function isWildcardBindHost(string $host): bool
    {
        $host = \strtolower(\trim($host));

        return $host === '0.0.0.0' || $host === '::';
    }

    protected function isUsablePublicHost(string $host): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return false;
        }
        if ($this->isWildcardBindHost($host)) {
            return false;
        }

        return !$this->isLoopbackLikeHost($host);
    }

    protected function validateExternalHostAllowlist(string $instanceName, string $host, array &$config): bool
    {
        if (!$this->isWildcardBindHost($host)) {
            return true;
        }

        $envConfig = $this->getEnvConfig();
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $serverConfig = \is_array($envConfig['server'] ?? null) ? $envConfig['server'] : [];
        $servers = \is_array($wlsConfig['servers'] ?? null) ? $wlsConfig['servers'] : [];
        $instanceConfig = \is_array($servers[$instanceName] ?? null) ? $servers[$instanceName] : [];

        // 启动白名单仅以 env.php 为准，不读取历史实例/public_host，避免“旧值兜底导致不提示”。
        $publicCandidates = [
            (string)($instanceConfig['host'] ?? ''),
            (string)($instanceConfig['ssl_domain'] ?? ''),
            (string)($wlsConfig['public_host'] ?? ''),
            (string)($wlsConfig['ssl_domain'] ?? ''),
            (string)($wlsConfig['host'] ?? ''),
            (string)($serverConfig['public_host'] ?? ''),
            (string)($serverConfig['host'] ?? ''),
        ];
        // 兼容误配：wls.servers.default 写成纯索引数组时，提取首个可用值作为候选
        if (isset($instanceConfig[0]) && \is_scalar($instanceConfig[0])) {
            $publicCandidates[] = (string)$instanceConfig[0];
        }
        if (isset($instanceConfig[1]) && \is_scalar($instanceConfig[1])) {
            $publicCandidates[] = (string)$instanceConfig[1];
        }
        foreach ($publicCandidates as $candidate) {
            if ($this->isUsablePublicHost($candidate)) {
                $config['public_host'] = $candidate;
                return true;
            }
        }

        $defaultProjectHost = $this->getDefaultHost();
        if ($this->isUsablePublicHost($defaultProjectHost)) {
            $config['public_host'] = $defaultProjectHost;
            return true;
        }

        $this->printer->error(__('启动已阻止：当前监听地址为 %{1}，且无法确定默认项目域名白名单。', [$host]));
        $this->printer->note(__('请配置 app/etc/env.php -> wls.servers.%{1}.host（推荐）或 wls.host（非 0.0.0.0）。', [$instanceName]));
        $this->printer->note(__('后台域名池域名不需要写入此处。'));

        return false;
    }

    protected function flushDeferredStartupWarning(): void
    {
        if ($this->deferredStartupWarning === null || $this->deferredStartupWarning === '') {
            return;
        }

        echo "\n";
        $this->printer->error($this->deferredStartupWarning);
        $this->printer->note(__('如果默认内网访问可忽略该提示。'));
        $this->deferredStartupWarning = null;
    }

    protected function resolveServerListenHost(string $host): string
    {
        $host = \trim($host);
        if ($host === '' || $host === 'localhost' || LocalDomainPolicy::isManagedLocalDomain($host)) {
            return '127.0.0.1';
        }

        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        return '0.0.0.0';
    }

    /**
     * Resolve the supplemental TLS listener independently from the loopback
     * gateway backend. A literal public start host is explicit bind intent;
     * otherwise only an explicit fallback/bind host may widen loopback.
     *
     * @param array<string,mixed> $config
     */
    protected function resolveGatewayFallbackBindHost(array $config, string $startHost): string
    {
        $gateway = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        $literalStartHost = \strtolower(\trim($startHost, " \t\n\r\0\x0B[]"));
        if (\filter_var($literalStartHost, FILTER_VALIDATE_IP) !== false) {
            return $literalStartHost;
        }

        $candidate = \array_key_exists('fallback_bind_host', $gateway)
            ? $gateway['fallback_bind_host']
            : ($config['bind_host'] ?? null);
        if ($candidate === null || !\is_scalar($candidate)) {
            return '127.0.0.1';
        }

        $host = \strtolower(\trim((string)$candidate, " \t\n\r\0\x0B[]"));
        if ($host === '' || $host === 'localhost') {
            return '127.0.0.1';
        }
        if (\filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(
                'fallback_bind_host must be a resolved IPv4 or IPv6 address.'
            );
        }

        return $host;
    }

    /**
     * 仅运行 Master 进程（由 startMasterInBackground 通过子进程调用，从实例文件恢复状态）
     * 非 Windows 下调用 posix_setsid() 脱离控制终端，避免 SSH 断开或父进程退出时收到 SIGHUP 导致 Master 退出。
     */
    protected function runMasterOnly(string $instanceName): void
    {
        $this->traceStartupPhase($instanceName, 'master-only:endpoint-read:before');
        if (!IS_WIN && \function_exists('posix_setsid')) {
            @\posix_setsid();
        }

        $instanceFile = $this->getRuntimeInstanceFile($instanceName);
        if (!\is_file($instanceFile)) {
            throw new \RuntimeException('WLS instance endpoint does not exist: ' . $instanceFile);
        }

        $content = GatewayProjectStateFilesystem::read(
            $instanceFile,
            2_097_152,
            'WLS instance endpoint',
        );
        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            throw new \RuntimeException('WLS instance endpoint contains invalid JSON.');
        }
        $this->traceStartupPhase($instanceName, 'master-only:endpoint-read:after', [
            'bytes' => \strlen((string)$content),
        ]);

        $this->traceStartupPhase($instanceName, 'master-only:schema-validate:before', [
            'schema_version' => $data['schema_version'] ?? null,
        ]);
        try {
            $persistedRuntimeSelection = RuntimeSelection::fromEndpoint($data);
            if (!$persistedRuntimeSelection->policyCompatible) {
                throw new \RuntimeException('WLS endpoint policy compatibility is false.');
            }
            if (!\is_string($data['policy_digest'] ?? null) || \trim($data['policy_digest']) === '') {
                throw new \RuntimeException('WLS endpoint schema v4 requires policy_digest.');
            }
            if (!\is_string($data['container_registry_digest'] ?? null)
                || \preg_match('/^[a-f0-9]{64}$/D', \strtolower(\trim($data['container_registry_digest']))) !== 1
            ) {
                throw new \RuntimeException('WLS endpoint schema v4 requires container_registry_digest.');
            }
            $persistedEdgeAdapterName = \strtolower(\trim((string)($data['edge_adapter'] ?? '')));
            if (!\in_array($persistedEdgeAdapterName, [
                \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
            ], true)) {
                throw new \RuntimeException('Master-only startup rejected invalid edge_adapter.');
            }
            $publicOrigin = $persistedEdgeAdapterName
                === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                    ? \Weline\Server\Service\Edge\PureWlsPublicOrigin::normalize(
                        (string)($data['public_origin'] ?? ''),
                    )
                    : \Weline\Server\Service\Edge\Nginx\ManagedNginxPublicOrigin::normalize(
                        (string)($data['public_origin'] ?? ''),
                    );
            if (!\is_bool($data['supervisor_enabled'] ?? null)) {
                throw new \RuntimeException('WLS endpoint schema v4 requires boolean supervisor_enabled.');
            }
            if (!\is_string($data['supervisor_reason'] ?? null) || \trim($data['supervisor_reason']) === '') {
                throw new \RuntimeException('WLS endpoint schema v4 requires supervisor_reason.');
            }
            $persistedHttp3 = \is_array($data['http3'] ?? null) ? $data['http3'] : [];
            $http3Enabled = (bool)($persistedHttp3['enabled'] ?? false);
            if ($http3Enabled) {
                throw new \RuntimeException('Nginx-only endpoint must not enable WLS native HTTP/3.');
            }
            $persistedGateway = \is_array($data['gateway'] ?? null) ? $data['gateway'] : [];
            $persistedEdgeDecisionData = \is_array($persistedGateway['edge_decision'] ?? null)
                ? $persistedGateway['edge_decision']
                : [];
            $persistedEdgeDecision = $persistedEdgeDecisionData === []
                ? null
                : \Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision::fromArray(
                    $persistedEdgeDecisionData,
                );
            if ($persistedEdgeDecision !== null
                && $persistedEdgeDecision->adapter !== $persistedEdgeAdapterName
            ) {
                throw new \RuntimeException(
                    'Master-only startup rejected an edge decision/adapter mismatch.'
                );
            }
            if (isset($persistedGateway['serving_mode'])
                && !\in_array((string)$persistedGateway['serving_mode'], [
                    'gateway',
                    'fallback_wls',
                    'native_wls',
                    'legacy',
                ], true)
            ) {
                throw new \RuntimeException(
                    'Master-only startup rejected invalid gateway serving_mode.'
                );
            }
            if ($persistedEdgeDecision !== null
                && isset($persistedGateway['mode'])
                && (string)$persistedGateway['mode'] !== $persistedEdgeDecision->mode
            ) {
                throw new \RuntimeException(
                    'Master-only startup rejected inconsistent edge decision copies.'
                );
            }
            if (\array_key_exists('enabled', $persistedGateway)
                && $this->isTruthyCliFlagValue($persistedGateway['enabled'])
            ) {
                throw new \RuntimeException(
                    'gateway.enabled=true: ' . (string)__('Nginx 是唯一公网边缘，不能跳过其启动。')
                );
            }
            if ((bool)($persistedHttp3['runtime_verified'] ?? false)
                || \trim((string)($persistedHttp3['reason'] ?? '')) === ''
            ) {
                throw new \RuntimeException(
                    'Disabled HTTP/3 endpoint metadata must include a reason and must not claim runtime verification.'
                );
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Master endpoint schema v4 validation failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        $this->traceStartupPhase($instanceName, 'master-only:schema-validate:after');

        $inheritedStartupListener = IS_WIN
            ? $this->adoptWindowsStartupListenerFromEndpoint(
                $instanceName,
                $data,
                $persistedGateway,
            )
            : $this->adoptPosixStartupListenerFromEndpoint(
                $instanceName,
                $data,
                $persistedGateway,
            );

        $expectedContainerDigest = \strtolower(\trim((string)$data['container_registry_digest']));
        $this->traceStartupPhase($instanceName, 'master-only:container-preflight:before');
        try {
            ContainerRuntime::preflight($expectedContainerDigest);
            $this->traceStartupPhase($instanceName, 'master-only:container-preflight:after');
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Master compiled-container preflight failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!IS_WIN
            && !$inheritedStartupListener
            && \function_exists('posix_geteuid')
        ) {
            $mainPort = (int)($data['port'] ?? 0);
            $redirectPort = $mainPort === 443 ? 80 : 0;
            $sslEnabledFlag = (bool)($data['ssl_enabled'] ?? false);
            $needsPrivileged = ($mainPort > 0 && $mainPort < 1024)
                || ($sslEnabledFlag && $redirectPort > 0 && $redirectPort < 1024);
            if ($needsPrivileged && (int)\posix_geteuid() !== 0) {
                $testPort = $mainPort > 0 && $mainPort < 1024 ? $mainPort : $redirectPort;
                $testSock = @\stream_socket_server(
                    "tcp://0.0.0.0:{$testPort}",
                    $errno,
                    $errstr,
                    STREAM_SERVER_BIND
                );
                if ($testSock) {
                    @\fclose($testSock);
                } else {
                    throw new \RuntimeException(
                        'Master cannot bind privileged port ' . $testPort . ': ' . $errstr
                    );
                }
            }
        }

        if (IS_WIN
            && $persistedRuntimeSelection->isDirect()
            && $persistedRuntimeSelection->listenerMode !== 'worker_ports'
        ) {
            throw new \RuntimeException('Windows Direct requires worker_ports listener mode.');
        }

        $sslEnabled = (bool)($data['ssl_enabled'] ?? false);
        try {
            $selectionData = $data['http_protocol_selection'] ?? null;
            if (!\is_array($selectionData) || $selectionData === []) {
                throw new \RuntimeException('persisted http_protocol_selection is missing');
            }
            $httpProtocolSelection = HttpProtocolSelection::fromArray($selectionData);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Master-only startup rejected invalid HTTP protocol selection: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        $edgeAdapterName = $persistedEdgeAdapterName;
        $httpProtocolSelection->assertCompatibleEdgeAdapter($edgeAdapterName);
        if ($edgeAdapterName === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
            && $sslEnabled
        ) {
            throw new \RuntimeException('Master-only startup rejected TLS on the private Nginx backend.');
        }
        if ((bool)($persistedHttp3['enabled'] ?? false)) {
            throw new \RuntimeException('Master-only startup rejected WLS native HTTP/3 activation.');
        }
        $workerScript = $this->ensureWorkerScript();
        $port = (int)($data['port'] ?? 443);
        $workerPort = (int)($data['worker_port'] ?? $port);
        $workerBasePort = (int)($data['worker_base_port'] ?? (10000 + MasterProcess::getProjectPortOffset()));
        $workerCount = \max(1, (int)($data['count'] ?? 1));
        $orchestratorRuntimeOptions = \is_array($data['orchestrator_runtime_options'] ?? null)
            ? $data['orchestrator_runtime_options']
            : [];

        $config = [
            'host' => (string)($data['host'] ?? '127.0.0.1'),
            'public_host' => (string)($data['public_host'] ?? ($data['host'] ?? '127.0.0.1')),
            'public_origin' => $publicOrigin,
            'port' => $port,
            'worker_count' => $workerCount,
            'runtime_strategy' => 'auto',
            'runtime_selection' => $persistedRuntimeSelection,
            'edge' => [
                'adapter' => $edgeAdapterName,
                'mode' => $persistedEdgeDecision?->mode ?? (string)($persistedGateway['mode'] ?? (
                    $edgeAdapterName === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                        ? \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
                        : \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY
                )),
                'scope' => $persistedEdgeDecision?->scope ?? (
                    $edgeAdapterName === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                        ? \Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision::SCOPE_PROJECT
                        : \Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision::SCOPE_LEGACY
                ),
                'source' => $persistedEdgeDecision?->source ?? 'endpoint',
                'fallback_reason' => $persistedEdgeDecision?->fallbackReason
                    ?? (string)($persistedGateway['degraded_reason'] ?? ''),
            ],
            'runtime' => [
                'topology' => $persistedRuntimeSelection->requestedTopology->value,
                'listener_mode' => $persistedRuntimeSelection->listenerMode,
                'container_registry_digest' => $expectedContainerDigest,
            ],
            'loop' => ['driver' => $persistedRuntimeSelection->eventLoopDriver],
            'ssl' => ['engine' => $persistedRuntimeSelection->sslEngine],
            'http' => $httpProtocolSelection->toConfig(),
            'http3' => \is_array($data['http3'] ?? null) ? $data['http3'] : [],
            'supervisor' => ['enabled' => (bool)$data['supervisor_enabled']],
            'worker_port' => $workerPort,
            'worker_base_port' => $workerBasePort,
            'worker_memory_limit' => ServiceContext::normalizeMemoryLimit($data['worker_memory_limit'] ?? '256M'),
            'dispatcher_memory_limit' => ServiceContext::normalizeMemoryLimit(
                $data['dispatcher_memory_limit'] ?? ($data['worker_memory_limit'] ?? '256M'),
                ServiceContext::normalizeMemoryLimit($data['worker_memory_limit'] ?? '256M')
            ),
            'session_server_port' => (int)($data['session_server_port'] ?? (19970 + MasterProcess::getProjectPortOffset())),
            'session_server_token_file_name' => (string)($data['session_server_token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'session_server',
                    (int)($data['session_server_port'] ?? (19970 + MasterProcess::getProjectPortOffset()))
                )),
            'memory_server_port' => (int)($data['memory_server_port'] ?? (19971 + MasterProcess::getProjectPortOffset())),
            'memory_server_token_file_name' => (string)($data['memory_server_token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'memory_server',
                    (int)($data['memory_server_port'] ?? (19971 + MasterProcess::getProjectPortOffset()))
                )),
            'shared_state' => \is_array($data['shared_state'] ?? null) ? $data['shared_state'] : [],
            'gateway' => \is_array($data['gateway'] ?? null) ? $data['gateway'] : [],
            'daemon' => (bool)($data['daemon'] ?? true),
            'orchestrator_runtime_options' => $orchestratorRuntimeOptions,
        ];

        $httpRedirectPort = $sslEnabled && $port === 443 ? 80 : 0;
        $windowMode = (bool)($data['window_mode'] ?? false);
        $daemonSaved = (bool)($data['daemon'] ?? true);
        $enableLog = (bool)($data['enable_log'] ?? false) || !$daemonSaved;
        if ($enableLog) {
            Processer::setLogEnabled(true);
        }
        LogConfig::bootstrapVerbose($enableLog);

        $mainPort = (int)($data['main_port'] ?? $port);
        /** @var MasterProcess $master */
        $this->traceStartupPhase($instanceName, 'master-only:master-resolve:before');
        $master = ObjectManager::getInstance(MasterProcess::class);
        $this->traceStartupPhase($instanceName, 'master-only:master-resolve:after');
        $this->traceStartupPhase($instanceName, 'master-only:master-init:before');
        $this->configureMasterRuntime(
            $master,
            $persistedRuntimeSelection,
            $workerCount,
            $workerBasePort,
            $workerPort,
            $mainPort
        )->setPrinter($this->printer)
            ->init(
                $instanceName,
                $config,
                $workerScript,
                (string)($data['ssl_cert'] ?? ''),
                (string)($data['ssl_key'] ?? ''),
                $sslEnabled,
                $httpRedirectPort,
                $windowMode
            );
        $this->traceStartupPhase($instanceName, 'master-only:master-init:after');
        $this->traceStartupPhase($instanceName, 'master-only:master-run:enter');
        $master->run();
    }
    
    /**
     * 在后台启动 Master 进程（默认模式：启动后立即返回，不阻塞终端）
     * Windows：用 PowerShell Start-Process 独立启动 Master，避免 cmd/batch 退出时牵连子进程导致 Master 被关。
     * 传参使用 -ArgumentList 数组，保证 server:start instanceName --master-only 被正确解析。
     * 后台模式下将 Windows + HTTPS 相关提示放在「服务器已在后台运行」之后，便于用户看到。
     * 
     * 启动确认机制：
     * - 轮询检查实例文件中的 master_pid 和 control_port 是否已写入
     * - 验证 Master 进程是否存活
     * - 超时（5秒）时输出警告而非假成功
     */
    protected function recordMasterOnlyStartupFailure(string $instanceName, \Throwable $exception): void
    {
        try {
            $this->getInstanceManager()->recordStartupFailure(
                $instanceName,
                'MASTER_BOOTSTRAP_FAILED',
                $exception->getMessage(),
                'master_bootstrap',
                [\get_class($exception)]
            );
        } catch (\Throwable $recordException) {
            \error_log(
                '[WLS] failed to publish Master bootstrap failure for '
                . $instanceName . ': ' . $recordException->getMessage()
            );
        }
    }

    /**
     * Start the Master through one argv-only platform launcher and wait for its
     * control-plane handshake. The spawned PHP PID is known before polling, so
     * an early exit is reported immediately instead of becoming a generic
     * 30/60 second timeout.
     */
    protected function startMasterInBackground(
        string $instanceName,
        bool $sslEnabled = false,
        string $host = '127.0.0.1',
        int $port = 443,
        string $launchId = '',
        bool $foregroundMode = false,
        bool $windowMode = false,
        array $args = [],
        bool $maintenanceEnabledByUs = false,
        bool $maintenanceResetAfterForceSwitch = false,
    ): bool {
        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = BP . 'bin' . DS . 'w';
        $masterName = MasterProcess::getMasterProcessName($instanceName);
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1) {
            throw new \RuntimeException('Detached Master launch identity is invalid.');
        }
        $argv = $this->buildMasterBackgroundArgv(
            $phpBinary,
            $script,
            $instanceName,
            $masterName,
            $launchId,
            $foregroundMode,
            $windowMode
        );
        $processIdentity = '--name=' . $masterName . ' --launch-id=' . $launchId;
        $inheritedDescriptors = $this->startupMasterInheritedDescriptors();
        $spawnTransport = IS_WIN
            ? 'windows_wmi_isolated_argv'
            : ($inheritedDescriptors !== []
                ? 'posix_inherited_fd3_argv'
                : 'detached_php_argv');

        $this->traceStartupPhase($instanceName, 'master-spawn:before', [
            'windows' => IS_WIN,
            'foreground' => $foregroundMode,
            'window_mode' => $windowMode,
            'transport' => $spawnTransport,
        ]);

        $spawnedMasterPid = 0;
        $this->spawnedMasterTerminationLease = null;
        try {
            if (IS_WIN) {
                $spawnedMasterPid = Processer::createWindowsIsolatedArgv(
                    $argv,
                    BP,
                    $processIdentity,
                );
            } elseif ($inheritedDescriptors !== []) {
                $command = \implode(' ', \array_map('escapeshellarg', $argv));
                $spawnResults = Processer::batchCreate([
                    'wls-master-startup-handoff' => [
                        'command' => $command,
                        'argv' => $argv,
                        'cwd' => BP,
                        'block' => false,
                        'foreground' => false,
                        'enableLog' => null,
                        'childOwnsPid' => true,
                        'masterOwned' => false,
                        'inheritDescriptors' => $inheritedDescriptors,
                    ],
                ]);
                $spawnedMasterPid = (int)($spawnResults['wls-master-startup-handoff'] ?? 0);
                if ($spawnedMasterPid <= 0) {
                    throw new \RuntimeException(
                        'POSIX Master inherited-listener launcher did not return a child PID.'
                    );
                }
            } else {
                $spawnedMasterPid = Processer::createDetachedPhpArgv(
                    $argv,
                    BP,
                    $processIdentity,
                    null
                );
            }
            if ($spawnedMasterPid <= 0) {
                throw new \RuntimeException(
                    'Detached Master launcher did not return an authoritative child PID.'
                );
            }
            // Publish the generation-aware Processer lease on every transport,
            // then freeze the boot-stable PID birth before any socket handoff.
            Processer::setPid($processIdentity, $spawnedMasterPid, false);
            $spawnedIdentity = $this->getMasterLeaseRuntimeIdentity()
                ->captureProcessIdentity($spawnedMasterPid);
            $this->spawnedMasterTerminationLease = [
                'pid' => $spawnedMasterPid,
                'process_birth' => (string)$spawnedIdentity['birth'],
                'pid_namespace_id' => (string)$spawnedIdentity['pid_namespace_id'],
                'process_name' => $masterName,
                'launch_id' => $launchId,
                'pname' => $processIdentity,
            ];
            if (IS_WIN && \is_array($this->startupWindowsListenerHandoffIntent)) {
                $intent = $this->startupWindowsListenerHandoffIntent;
                $listener = $this->startupListenerForLeaseId(
                    (string)($intent['lease_id'] ?? ''),
                );
                WindowsListenerHandoff::publishStreamToMaster(
                    $listener,
                    $intent,
                    $spawnedMasterPid,
                    $this->startupListenerStateDeadline(),
                );
            }
        } catch (\Throwable $exception) {
            $retired = $spawnedMasterPid <= 0
                || $this->terminateSpawnedMasterProcess($instanceName, $spawnedMasterPid);
            $this->closeStartupListenerCopies();
            $failure = $retired
                ? $exception
                : new \RuntimeException(
                    $exception->getMessage()
                    . ' The spawned Master could not be retired through an exact stable process handle.',
                    0,
                    $exception,
                );
            $this->recordMasterOnlyStartupFailure($instanceName, $failure);
            $this->traceStartupPhase($instanceName, 'master-spawn:failed', [
                'failure_class' => \get_class($exception),
            ]);
            $this->printer->error(__(
                'WLS Master 进程创建失败：%{1}',
                [$exception->getMessage()]
            ));

            return false;
        }

        $this->traceStartupPhase($instanceName, 'master-spawn:after', [
            'spawned_pid' => $spawnedMasterPid,
            'transport' => $spawnTransport,
        ]);

        $instanceFile = $this->getRuntimeInstanceFile($instanceName);
        $softWaitMs = $this->resolveBackgroundMasterConfirmWaitMs($spawnedMasterPid);
        $hardWaitMs = \max(
            $softWaitMs,
            $this->resolveBackgroundMasterControlHardWaitMs($spawnedMasterPid)
        );
        $waitStepMs = 50;
        $waitStartedNs = \hrtime(true);
        $hardDeadlineNs = $waitStartedNs + ($hardWaitMs * 1_000_000);
        $waited = 0;
        $lastLivenessCheckMs = 0;
        $softDeadlineReported = false;
        $applicationReceiptPid = 0;
        $masterStarted = false;
        $startupCompleted = false;
        $lastMasterPid = 0;
        $lastControlPort = 0;
        $lastStartupPhase = '';
        $lastData = [];
        $readyResult = null;

        $this->traceStartupPhase($instanceName, 'master-control-wait:before', [
            'soft_wait_ms' => $softWaitMs,
            'hard_wait_ms' => $hardWaitMs,
            'spawned_pid' => $spawnedMasterPid,
        ]);

        while ($waited < $hardWaitMs) {
            $waitMicroseconds = self::boundedNanosecondDeadlineSleepMicroseconds(
                $hardDeadlineNs,
                \hrtime(true),
                $waitStepMs * 1000,
            );
            if ($waitMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($waitMicroseconds);
            $waited = (int)\max(
                0,
                \round((\hrtime(true) - $waitStartedNs) / 1_000_000)
            );
            $lastData = $this->readBackgroundStartupData($instanceFile);

            if ($this->isBackgroundStartupTerminalFailure($lastData)) {
                $lastStartupPhase = (string)($lastData['startup_phase'] ?? 'failed');
                break;
            }

            $masterPid = (int)($lastData['master_pid'] ?? 0);
            $controlPort = (int)($lastData['control_port'] ?? 0);
            $startupPhase = (string)($lastData['startup_phase'] ?? '');
            if ($startupPhase !== '') {
                $lastStartupPhase = $startupPhase;
            }
            if ($this->isPublishedBackgroundMasterLeaseAccepted(
                $instanceName,
                $lastData,
                $spawnedMasterPid,
            )) {
                $masterStarted = true;
                $lastMasterPid = $masterPid;
                $lastControlPort = $controlPort;
                break;
            }

            if ($waited >= 200 && $waited - $lastLivenessCheckMs >= 250) {
                $lastLivenessCheckMs = $waited;
                if (!Processer::isRunningByPid($spawnedMasterPid)) {
                    $lastData = $this->readBackgroundStartupData($instanceFile);
                    if (!$this->isBackgroundStartupTerminalFailure($lastData)) {
                        $this->recordMasterOnlyStartupFailure(
                            $instanceName,
                            new \RuntimeException(
                                'Master process exited before publishing its control endpoint (pid='
                                . $spawnedMasterPid . ').'
                            )
                        );
                        $lastData = $this->readBackgroundStartupData($instanceFile);
                    }
                    $lastStartupPhase = (string)($lastData['startup_phase'] ?? 'failed');
                    break;
                }
            }

            if (!$softDeadlineReported && $waited >= $softWaitMs) {
                $softDeadlineReported = true;
                $applicationReceiptPid = (int)Processer::getData($processIdentity, 'pid');
                $this->traceStartupPhase(
                    $instanceName,
                    'master-control-wait:process-alive',
                    [
                        'waited_ms' => $waited,
                        'spawned_pid' => $spawnedMasterPid,
                        'application_receipt_pid' => $applicationReceiptPid,
                        'stage' => $applicationReceiptPid === $spawnedMasterPid
                            ? 'master_run'
                            : 'php_bootstrap',
                    ]
                );
            }
        }

        $this->traceStartupPhase($instanceName, 'master-control-wait:after', [
            'waited_ms' => $waited,
            'started' => $masterStarted,
            'spawned_pid' => $spawnedMasterPid,
            'master_pid' => $lastMasterPid,
            'control_port' => $lastControlPort,
            'phase' => $lastStartupPhase,
        ]);

        if ($masterStarted) {
            if ($lastStartupPhase !== 'running') {
                $this->printer->note(__('Master 已启动，等待所有服务就绪...'));
                $backgroundStartupData = $this->readBackgroundStartupData($instanceFile);
                // Master publishes the endpoint by atomic replacement. On
                // Windows the CLI can observe the control PID/port from the
                // new envelope while runtime_selection is still available
                // only in the last complete snapshot. Re-read briefly and
                // merge that immutable selection instead of failing a healthy
                // startup on this publication boundary.
                $metadataDeadline = self::monotonicSeconds() + 0.5;
                while (!\is_array($backgroundStartupData['runtime_selection'] ?? null)
                    && self::monotonicSeconds() < $metadataDeadline
                ) {
                    $waitMicroseconds = self::boundedMonotonicDeadlineSleepMicroseconds(
                        $metadataDeadline,
                        self::monotonicSeconds(),
                        25_000,
                    );
                    if ($waitMicroseconds < 1) {
                        break;
                    }
                    SchedulerSystem::usleep($waitMicroseconds);
                    $backgroundStartupData = $this->readBackgroundStartupData($instanceFile);
                }
                if (!\is_array($backgroundStartupData['runtime_selection'] ?? null)
                    && \is_array($lastData['runtime_selection'] ?? null)
                ) {
                    $backgroundStartupData['runtime_selection'] = $lastData['runtime_selection'];
                }

                $readyWaitMs = $this->resolveBackgroundStartupReadyWaitMs($backgroundStartupData);
                $this->traceStartupPhase($instanceName, 'background-ready-wait:before', [
                    'wait_ms' => $readyWaitMs,
                ]);
                $readyResult = $this->waitForBackgroundStartupReady(
                    $instanceFile,
                    $readyWaitMs,
                    $waitStepMs,
                    $this->resolveBackgroundStartupReadyHardWaitMs($backgroundStartupData)
                );
                $startupCompleted = (bool)($readyResult['ready'] ?? false);
                $lastStartupPhase = (string)($readyResult['data']['startup_phase'] ?? $lastStartupPhase);
                $this->traceStartupPhase($instanceName, 'background-ready-wait:after', [
                    'waited_ms' => (int)($readyResult['waited_ms'] ?? 0),
                    'ready' => $startupCompleted,
                    'phase' => $lastStartupPhase,
                ]);
            } else {
                $startupCompleted = true;
            }

            if ($startupCompleted) {
                // During a full restart Nginx is already stopped, so restoring
                // the pre-restart maintenance state here cannot expose WLS
                // publicly. It must happen before the Nginx health/protocol
                // transaction, otherwise Dispatcher still routes the probes to
                // the temporary maintenance Worker instead of the READY
                // business generation.
                $this->finalizeMaintenanceModeAfterStartup(
                    $instanceName,
                    $maintenanceEnabledByUs,
                    $maintenanceResetAfterForceSwitch,
                    true,
                );
                $readyEndpoint = \is_array($readyResult['data'] ?? null)
                    ? $readyResult['data']
                    : $this->readBackgroundStartupData($instanceFile);
                $readyEdgeView = \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::resolve(
                    $readyEndpoint,
                    isset($args['no-nginx']) || isset($args['no_nginx']),
                );
                $readyEdgeAction = (string)($readyEdgeView['ready_action'] ?? 'reject');
                if ($readyEdgeAction
                    === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::READY_ACTION_NONE
                ) {
                    $this->printer->success(__(
                        '服务器已在后台运行（Master PID: %{1}, 控制端口: %{2}）',
                        [$lastMasterPid, $lastControlPort]
                    ));
                    $readySource = (string)($readyEdgeView['source'] ?? 'unknown');
                    if ($readySource
                        === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY
                    ) {
                        $this->printer->success(__('WLS 与宿主级 WLS 2.0 Gateway 已就绪，启动完成。'));
                    } elseif (\in_array($readySource, [
                        \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS,
                        \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS,
                    ], true)) {
                        $this->printer->success(__('WLS Worker 已就绪；当前由项目级 WLS 入口提供服务。'));
                        $this->printer->note(__('auto 模式会继续在后台发现并加入可信 WLS 2.0 Gateway。'));
                    } else {
                        $this->printer->success(__('纯 WLS Worker 已就绪；TLS/HTTP 由 WLS 直接提供。'));
                    }
                    $this->printer->note(__(
                        '使用 php bin/w server:status 查看状态，php bin/w server:stop 停止服务。'
                    ));
                } elseif (\in_array($readyEdgeAction, [
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::READY_ACTION_REGISTER_GATEWAY,
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::READY_ACTION_START_MANAGED_NGINX,
                ], true)) {
                    $managedNginxReady = $this->maybeStartManagedNginxAfterReady(
                        (int)$port,
                        is_array($args) ? $args : [],
                        $instanceName,
                        $activeCertificate,
                    );
                    if (!$managedNginxReady) {
                        $this->printer->warning($readyEdgeAction
                            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::READY_ACTION_REGISTER_GATEWAY
                                ? __('WLS Worker 已 READY，但 Gateway 注册/主域路由门禁失败；将回收本次实例。')
                                : __('WLS Worker 已 READY，但托管 Nginx 未通过公网协议门禁；将回收本次实例。'));
                        $startupCompleted = false;
                    } else {
                        $this->printer->success(__(
                            '服务器已在后台运行（Master PID: %{1}, 控制端口: %{2}）',
                            [$lastMasterPid, $lastControlPort]
                        ));
                        if ($readyEdgeAction
                            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::READY_ACTION_REGISTER_GATEWAY
                        ) {
                            $publishedEndpoint = $this->readBackgroundStartupData($instanceFile);
                            $publishedView = \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::resolve(
                                $publishedEndpoint,
                            );
                            if (($publishedView['source'] ?? '')
                                === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY
                            ) {
                                $this->printer->success(__('WLS 与宿主级 WLS 2.0 Gateway 均已通过运行时服务投影，启动完成。'));
                            } elseif (($publishedEndpoint['gateway']['certificate_pending'] ?? false) === true) {
                                $this->printer->success(__('WLS 已就绪并已注册 Gateway；当前仅开放 ACME challenge，等待证书激活。'));
                            } else {
                                $this->printer->success(__('WLS 已就绪且 Gateway 已接受注册；Agent 将继续完成公网 SNI/Host/TLS 实际探测。'));
                            }
                        } else {
                            $this->printer->success(__('WLS 与项目托管 Nginx 均已就绪，启动完成。'));
                        }
                        $this->printer->note(__(
                            '使用 php bin/w server:status 查看状态，php bin/w server:stop 停止服务。'
                        ));
                    }
                } else {
                    $this->printer->warning(__(
                        'WLS Worker 已 READY，但运行态边缘投影与启动意图不一致；已拒绝推测或发布公网入口。'
                    ));
                    $startupCompleted = false;
                }
            } else {
                $readyData = \is_array($readyResult['data'] ?? null)
                    ? $readyResult['data']
                    : $this->readBackgroundStartupData($instanceFile);
                $phaseLabel = $this->normalizeBackgroundStartupPhase(
                    $lastStartupPhase !== '' ? $lastStartupPhase : 'bootstrapping'
                );
                $readyWaitSec = \max(
                    1,
                    (int)\ceil(((int)($readyResult['waited_ms'] ?? 0)) / 1000)
                );
                if ($this->isBackgroundStartupTerminalFailure($readyData)) {
                    $this->printer->error(
                        'WLS background startup failed (phase: '
                        . $phaseLabel . ', waited: ' . $readyWaitSec . 's).'
                    );
                } else {
                    $this->printer->warning(__(
                        'Master 已运行，但未在 %{1} 秒内等到所有服务 READY（当前阶段：%{2}）。',
                        [$readyWaitSec, $phaseLabel]
                    ));
                }
                $failureReason = $this->readStartupFailureReason($readyData);
                $this->printStartupFailureDiagnostics($readyData);
                if ($failureReason !== '') {
                    $this->printer->warning(__('启动失败原因：%{1}', [$failureReason]));
                }
            }
        } else {
            $lastData = $lastData !== []
                ? $lastData
                : $this->readBackgroundStartupData($instanceFile);
            if (!$this->isBackgroundStartupTerminalFailure($lastData)) {
                $retired = $this->terminateSpawnedMasterProcess(
                    $instanceName,
                    $spawnedMasterPid,
                );
                $this->recordMasterOnlyStartupFailure(
                    $instanceName,
                    new \RuntimeException(
                        'Master did not publish its control endpoint within '
                        . \number_format($hardWaitMs / 1000, 2)
                        . ' seconds; exact retirement '
                        . ($retired ? 'was verified.' : 'could not be verified.')
                    )
                );
                $lastData = $this->readBackgroundStartupData($instanceFile);
            }

            $this->printer->error(__('WLS Master 控制面启动失败。'));
            $failureReason = $this->readStartupFailureReason($lastData);
            $this->printStartupFailureDiagnostics($lastData);
            if ($failureReason !== '') {
                $this->printer->warning(__('启动失败原因：%{1}', [$failureReason]));
            }
            $this->printer->note(__(
                '日志目录：%{1}',
                [WlsLogService::getLogDir($instanceName)]
            ));
        }

        if (\function_exists('flush')) {
            @\flush();
        }


        // The POSIX Master inherited FD 3 before its bootstrap ACK. Keep the
        // launcher copy until ACK/failure so an early child exit never opens a
        // numeric-port race during diagnostics and rollback.
        $this->closeStartupListenerCopies();
        return $startupCompleted;
    }

    /**
     * Retire only the detached Master generation created by this launcher.
     * The stable PID birth is captured before handoff; a protected Master lease
     * may recover the same tuple after publication. No name/port fallback may
     * authorize a signal.
     */
    protected function terminateSpawnedMasterProcess(
        string $instanceName,
        int $spawnedMasterPid,
    ): bool {
        if ($spawnedMasterPid <= 0) {
            return true;
        }
        $candidate = $this->spawnedMasterTerminationLease;
        if (!\is_array($candidate)
            || (int)($candidate['pid'] ?? 0) !== $spawnedMasterPid
        ) {
            $candidate = null;
            $lease = $this->getMasterLeaseManager()->read(
                $this->getMasterLeasePathForInstance($instanceName),
            );
            if (\is_array($lease)
                && \hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                && (int)($lease['master_pid'] ?? 0) === $spawnedMasterPid
            ) {
                $candidate = [
                    'pid' => $spawnedMasterPid,
                    'process_birth' => (string)($lease['master_process_birth'] ?? ''),
                    'pid_namespace_id' => (string)($lease['pid_namespace_id'] ?? ''),
                    'process_name' => MasterProcess::getMasterProcessName($instanceName),
                    'launch_id' => '',
                    'pname' => '--name=' . MasterProcess::getMasterProcessName($instanceName),
                ];
            }
        }
        if (!\is_array($candidate)) {
            return false;
        }

        $result = $this->getMasterLeaseRuntimeIdentity()->terminateExactProcessIdentity(
            $spawnedMasterPid,
            (string)($candidate['process_birth'] ?? ''),
            (string)($candidate['pid_namespace_id'] ?? ''),
            0.5,
        );
        if (!(bool)($result['released'] ?? false)) {
            $this->printer->warning(__(
                '已拒绝使用裸 PID 终止 Master %{1}：reason=%{2}',
                [
                    $spawnedMasterPid,
                    (string)($result['reason'] ?? 'stable termination result missing'),
                ],
            ));

            return false;
        }

        Processer::removePidFile((string)($candidate['pname'] ?? ''));
        if (\is_array($this->spawnedMasterTerminationLease)
            && (int)($this->spawnedMasterTerminationLease['pid'] ?? 0) === $spawnedMasterPid
        ) {
            $this->spawnedMasterTerminationLease = null;
        }

        return true;
    }

    /** @param array<string,mixed> $endpoint */
    protected function isPublishedBackgroundMasterLeaseAccepted(
        string $instanceName,
        array $endpoint,
        int $spawnedMasterPid,
    ): bool {
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $controlPort = (int)($endpoint['control_port'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        if ($masterPid <= 0 || $controlPort <= 0 || $masterEpoch <= 0 || $spawnedMasterPid <= 0) {
            return false;
        }

        $validation = $this->getMasterLeaseManager()->validateRunningLease(
            $this->getMasterLeasePathForInstance($instanceName),
            expectedInstance: $instanceName,
            expectedMasterPid: $masterPid,
            expectedEpoch: $masterEpoch,
            expectedControlPort: $controlPort,
            requireManagedName: true,
        );
        if (($validation['authorized'] ?? false) === true) {
            // Inside one PID namespace the launcher PID and published Master
            // PID must be identical; otherwise an unrelated generation could
            // satisfy an endpoint left behind by the attempted launch.
            return $masterPid === $spawnedMasterPid;
        }

        // A container-local PID cannot be compared with the host launcher
        // PID. A fresh schema-2 veto plus positive foreign-namespace proof may
        // reach authenticated IPC readiness, but never PID signalling.
        return ($validation['veto'] ?? false) === true
            && ($validation['foreign_pid_namespace'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    protected function buildMasterBackgroundArgv(
        string $phpBinary,
        string $script,
        string $instanceName,
        string $masterName,
        string $launchId,
        bool $foregroundMode = false,
        bool $windowMode = false
    ): array {
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1) {
            throw new \InvalidArgumentException('Master launch ID must be 32 lowercase hex characters.');
        }
        $argv = [
            $phpBinary,
            ...\Weline\Server\Service\LongRunningPhpRuntime::startupCliArguments(),
            $script,
            'server:start',
            $instanceName,
            '--master-only',
        ];

        if ($foregroundMode) {
            $argv[] = '--foreground';
        }

        if ($windowMode) {
            $argv[] = '--win';
        }

        $argv[] = '--name=' . $masterName;
        $argv[] = '--launch-id=' . $launchId;

        if ($windowMode) {
            $argv[] = '--window-title=' . MasterProcess::getMasterProcessDisplayName($instanceName, true);
        }

        return $argv;
    }

    protected function resolveBackgroundMasterConfirmWaitMs(int $spawnedMasterPid = 0): int
    {
        $configuredSec = (float)($this->getEnvironmentValue(
            'wls.orchestrator.background_master_confirm_wait_sec',
            0.0
        ) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int)\round(\max(0.5, \min(120.0, $configuredSec)) * 1000);
        }

        $configuredStartupSec = (float)($this->getEnvironmentValue(
            'wls.orchestrator.startup_timeout_sec',
            0.0
        ) ?? 0.0);
        if ($configuredStartupSec > 0.0) {
            return (int)\round(\max(2.0, \min(120.0, $configuredStartupSec)) * 1000);
        }

        // The argv launcher already returned the actual child PID. The control
        // endpoint should therefore be published during normal PHP bootstrap,
        // not after an opaque shell/nohup grace period.
        return IS_WIN ? 30000 : 10000;
    }

    /**
     * 控制面确认的硬上限，保留给显式配置和 trace 展示。
     * 默认启动确认不再做 PID 存活反查；实际等待以控制面上报窗口为准。
     */
    protected function resolveBackgroundMasterControlHardWaitMs(int $spawnedMasterPid = 0): int
    {
        $configuredSec = (float) ($this->getEnvironmentValue('wls.orchestrator.background_master_control_hard_wait_sec', 0.0) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int) \round(\max(0.5, \min(120.0, $configuredSec)) * 1000);
        }
        $softMs = $this->resolveBackgroundMasterConfirmWaitMs($spawnedMasterPid);
        // Keep POSIX at its existing bounded default. A Windows Master launched
        // from a Parallels UNC share can legitimately spend more than 60 seconds
        // in the cold PHP/bootstrap path before publishing its control endpoint;
        // x64 PHP emulation on ARM64 is the observed worst case. The spawned PID
        // remains known and polled throughout this window, so extend only the
        // Windows default while retaining the configured 120-second hard ceiling.
        $defaultHardCapMs = IS_WIN ? 120000 : 60000;
        return (int) \max($softMs, \min($defaultHardCapMs, $softMs * 4));
    }

    protected function resolveBackgroundStartupReadyWaitMs(array $instanceData = []): int
    {
        $configuredSec = (float)($this->getEnvironmentValue(
            'wls.orchestrator.background_ready_wait_sec',
            0.0
        ) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int)\round(\max(5.0, \min(900.0, $configuredSec)) * 1000);
        }

        $selectionData = $instanceData['runtime_selection'] ?? null;
        if (!\is_array($selectionData)) {
            throw new \RuntimeException('WLS startup readiness requires endpoint runtime_selection.');
        }
        $runtimeSelection = RuntimeSelection::fromArray($selectionData);
        $workerCount = \max(1, (int)($instanceData['count'] ?? $instanceData['worker_count'] ?? 1));
        $dispatcherEnabled = $runtimeSelection->isDispatcher();
        $sslEnabled = (bool)($instanceData['ssl_enabled'] ?? false);
        $startupTimeout = $this->getEnvironmentValue('wls.orchestrator.startup_timeout_sec', null);
        if ($startupTimeout !== null && (float)$startupTimeout > 0.0) {
            $startupTimeoutSec = \max(5.0, \min(300.0, (float)$startupTimeout));
            $timeoutSec = $startupTimeoutSec
                + \max(0, $workerCount - 1) * 4.0
                + ($dispatcherEnabled ? 8.0 : 0.0)
                + ($sslEnabled ? 5.0 : 0.0);

            return (int)\round(\max(5.0, \min(180.0, $timeoutSec)) * 1000);
        }

        $softSec = 12.0
            + \max(0, $workerCount - 1) * 2.5
            + ($dispatcherEnabled ? 4.0 : 0.0)
            + ($sslEnabled ? 3.0 : 0.0);

        return (int)\round(\max(15.0, \min(90.0, $softSec)) * 1000);
    }

    protected function resolveBackgroundStartupReadyHardWaitMs(array $instanceData = []): int
    {
        $configuredSec = (float) ($this->getEnvironmentValue('wls.orchestrator.background_ready_max_wait_sec', 0.0) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int) \round(\max(10.0, \min(1800.0, $configuredSec)) * 1000);
        }

        $idleWaitMs = $this->resolveBackgroundStartupReadyWaitMs($instanceData);
        $idleWaitSec = \max(1.0, $idleWaitMs / 1000.0);
        $workerCount = \max(1, (int) ($instanceData['count'] ?? $instanceData['worker_count'] ?? 1));
        $configuredReadySec = (float) ($this->getEnvironmentValue('wls.orchestrator.background_ready_wait_sec', 0.0) ?? 0.0);
        $configuredStartupSec = (float) ($this->getEnvironmentValue('wls.orchestrator.startup_timeout_sec', 0.0) ?? 0.0);
        if ($configuredReadySec <= 0.0 && $configuredStartupSec <= 0.0) {
            // 硬超时必须 >= 软超时；并发启动时子进程 READY 可能长时间停在同一 phase，
            // 仅靠「有进展则续期」不够，需要绝对上限覆盖整批子服务拉起。
            $hardWaitSec = \max(600.0, $idleWaitSec * 2.5, 30.0 + \max(0, $workerCount - 1) * 4.0);

            return (int) \round(\min(600.0, $hardWaitSec) * 1000);
        }

        $hardWaitSec = \max(
            90.0 + \max(0, $workerCount - 1) * 15.0,
            $idleWaitSec * 2.0
        );

        return (int) \round(\max($idleWaitSec, \min(600.0, $hardWaitSec)) * 1000);
    }

    protected function getEnvironmentValue(string $path, mixed $default = null): mixed
    {
        return Env::get($path, $default);
    }

    private function isTruthyCliFlagValue(mixed $value): bool
    {
        if ($value === false || $value === null) {
            return false;
        }

        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if ($normalized === '' || \in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }

            return true;
        }

        if (\is_int($value) || \is_float($value)) {
            return $value != 0;
        }

        return (bool)$value;
    }

    protected function resolveDaemonMode(array $config, bool $foregroundMode): bool
    {
        if ($foregroundMode) {
            return false;
        }

        return (bool) ($config['daemon'] ?? true);
    }

    /**
     * @param list<string> $tokens
     */
    protected function hasCliArgvToken(array $tokens): bool
    {
        $rawArgv = $_SERVER['argv'] ?? [];
        if (!\is_array($rawArgv)) {
            return false;
        }

        foreach ($rawArgv as $raw) {
            if (\is_string($raw) && \in_array($raw, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveForegroundOnlyFlag(array $args): bool
    {
        foreach (['foreground'] as $name) {
            if (\array_key_exists($name, $args) && $this->isTruthyCliFlagValue($args[$name])) {
                return true;
            }
        }

        foreach ($args as $key => $value) {
            if (\is_int($key) && \is_string($value) && \in_array($value, ['--foreground', '-foreground'], true)) {
                return true;
            }
        }

        return $this->hasCliArgvToken(['--foreground', '-foreground']);
    }

    protected function resolveWindowModeFlag(array $args): bool
    {
        if (\array_key_exists('win', $args) && $this->isTruthyCliFlagValue($args['win'])) {
            return true;
        }

        foreach ($args as $key => $value) {
            if (\is_int($key) && $value === '--win') {
                return true;
            }
        }

        return $this->hasCliArgvToken(['--win']);
    }

    /**
     * @return array{ready: bool, data: array<string, mixed>, waited_ms: int}
     */
    protected function waitForBackgroundStartupReady(string $instanceFile, int $maxWaitMs, int $waitStepMs = 200, ?int $hardMaxWaitMs = null): array
    {
        $waitStepMs = \max(50, $waitStepMs);
        $maxWaitMs = \max($waitStepMs, $maxWaitMs);
        $hardMaxWaitMs = $hardMaxWaitMs === null
            ? \min(600000, \max($maxWaitMs, $maxWaitMs * 3))
            : \max($maxWaitMs, $hardMaxWaitMs);
        $waited = 0;
        $lastData = $this->readBackgroundStartupData($instanceFile);
        $lastProgressToken = $this->buildBackgroundStartupProgressToken($lastData);
        $lastProgress = $this->formatBackgroundStartupProgress($lastData, $waited);
        $lastStartupEventSeq = 0;

        if ($this->isBackgroundStartupReady($lastData)) {
            return ['ready' => true, 'data' => $lastData, 'waited_ms' => 0];
        }
        if ($this->isBackgroundStartupTerminalFailure($lastData)) {
            return ['ready' => false, 'data' => $lastData, 'waited_ms' => 0];
        }

        [$lastStartupEventSeq, $lastProgress] = $this->emitBackgroundStartupEvents($lastData, $lastStartupEventSeq, $lastProgress);
        if ($lastProgress !== '') {
            $this->emitBackgroundStartupProgress($lastProgress, '');
        }

        while ($waited < $hardMaxWaitMs) {
            $remainingWaitMs = $hardMaxWaitMs - $waited;
            $sleepMilliseconds = \min($waitStepMs, $remainingWaitMs);
            if ($sleepMilliseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($sleepMilliseconds * 1000);
            $waited += $sleepMilliseconds;
            $lastData = $this->readBackgroundStartupData($instanceFile);
            [$lastStartupEventSeq, $lastProgress] = $this->emitBackgroundStartupEvents($lastData, $lastStartupEventSeq, $lastProgress);
            $progress = $this->formatBackgroundStartupProgress($lastData, $waited);
            if ($progress !== '') {
                $this->emitBackgroundStartupProgress($progress, $lastProgress);
                $lastProgress = $progress;
            }

            $progressToken = $this->buildBackgroundStartupProgressToken($lastData);
            if ($progressToken !== $lastProgressToken) {
                $lastProgressToken = $progressToken;
            }

            if ($this->isBackgroundStartupReady($lastData)) {
                [$lastStartupEventSeq, $lastProgress] = $this->drainBackgroundStartupEventsAfterReady(
                    $instanceFile,
                    $lastStartupEventSeq,
                    $lastProgress,
                    $waitStepMs
                );
                $this->finishBackgroundStartupProgress($lastProgress);
                return ['ready' => true, 'data' => $lastData, 'waited_ms' => $waited];
            }
            if ($this->isBackgroundStartupTerminalFailure($lastData)) {
                $this->finishBackgroundStartupProgress($lastProgress);
                return ['ready' => false, 'data' => $lastData, 'waited_ms' => $waited];
            }
        }

        $this->finishBackgroundStartupProgress($lastProgress);

        return ['ready' => false, 'data' => $lastData, 'waited_ms' => $waited];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readBackgroundStartupData(string $instanceFile): array
    {
        try {
            $content = GatewayProjectStateFilesystem::readOptional(
                $instanceFile,
                2_097_152,
                'WLS background startup endpoint',
            );
        } catch (\Throwable) {
            return [];
        }
        if ($content === null) {
            return [];
        }

        $data = \json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function isBackgroundStartupReady(array $instanceData): bool
    {
        if (\trim((string) ($instanceData['startup_phase'] ?? '')) === 'running') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function isBackgroundStartupTerminalFailure(array $instanceData): bool
    {
        if ($this->isBackgroundStartupReady($instanceData)) {
            return false;
        }

        $reason = $this->readStartupFailureReason($instanceData);
        if ($reason === '') {
            return false;
        }

        $failureTs = (int)($instanceData['startup_failure_timestamp'] ?? 0);
        $startedTs = (int)($instanceData['started_timestamp'] ?? 0);
        if ($startedTs <= 0) {
            $startedAt = \trim((string)($instanceData['started_at'] ?? $instanceData['master_started_at'] ?? ''));
            if ($startedAt !== '') {
                $parsed = \strtotime($startedAt);
                $startedTs = $parsed !== false ? (int)$parsed : 0;
            }
        }
        if ($failureTs > 0 && $startedTs > 0 && $failureTs < $startedTs) {
            return false;
        }

        $phase = \trim((string)($instanceData['startup_phase'] ?? ''));
        if (\in_array($phase, ['master_exited', 'stopped', 'stopping', 'failed'], true)) {
            return true;
        }

        return $failureTs > 0;
    }

    /**
     * @param array<string, mixed> $statusData
     */
    protected function isBackgroundStartupIpcReady(array $statusData): bool
    {
        if (!(bool) ($statusData['running'] ?? false) || (bool) ($statusData['shutting_down'] ?? false)) {
            return false;
        }

        $services = $statusData['services'] ?? [];
        if (!\is_array($services) || $services === []) {
            return false;
        }

        foreach ($services as $roleData) {
            if (!\is_array($roleData)) {
                continue;
            }
            $instances = $roleData['instances'] ?? [];
            if (!\is_array($instances) || $instances === []) {
                return false;
            }
            foreach ($instances as $instance) {
                if (!\is_array($instance)) {
                    return false;
                }
                $state = \strtolower(\trim((string) ($instance['state'] ?? '')));
                if ($state !== 'ready' && $state !== 'running') {
                    return false;
                }
            }
        }

        return true;
    }

    protected function normalizeBackgroundStartupPhase(string $phase): string
    {
        $phase = \trim($phase);

        return match ($phase) {
            'bootstrapping' => (string) __('启动准备'),
            'starting' => (string) __('启动服务'),
            'waiting_ready' => (string) __('等待就绪'),
            'running' => (string) __('运行中'),
            'stopping' => (string) __('停止中'),
            'stopped' => (string) __('已停止'),
            'master_exited' => (string) __('Master 已退出'),
            '' => 'bootstrapping',
            default => $phase,
        };
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function readStartupFailureReason(array $instanceData): string
    {
        $reason = \trim((string) ($instanceData['startup_failure_reason'] ?? ''));
        $code = $this->readStartupFailureCode($instanceData);
        if ($reason !== '') {
            if ($code !== '' && !\str_starts_with($reason, '[' . $code . ']')) {
                return '[' . $code . '] ' . $reason;
            }
            return $reason;
        }

        if ($code !== '') {
            return '[' . $code . ']';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function readStartupFailureCode(array $instanceData): string
    {
        return \trim((string) ($instanceData['startup_failure_code'] ?? ''));
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function readStartupFailureClass(array $instanceData): string
    {
        return \trim((string) ($instanceData['startup_failure_class'] ?? ''));
    }

    /**
     * @param array<string, mixed> $instanceData
     * @return list<string>
     */
    protected function readStartupFailureDiagnostics(array $instanceData): array
    {
        $diagnostics = $instanceData['startup_failure_diagnostics'] ?? [];
        if (!\is_array($diagnostics)) {
            return [];
        }

        $result = [];
        foreach ($diagnostics as $diagnostic) {
            $diagnostic = \trim((string)$diagnostic);
            if ($diagnostic !== '') {
                $result[] = $diagnostic;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    protected function printStartupFailureDiagnostics(array $instanceData): void
    {
        $code = $this->readStartupFailureCode($instanceData);
        $class = $this->readStartupFailureClass($instanceData);
        if ($code !== '') {
            $this->printer->warning('WLS failure code: ' . $code);
        }
        if ($class !== '') {
            $this->printer->note('WLS failure class: ' . $class);
        }

        $contextSummary = $this->formatStartupFailureContextSummary(
            $instanceData['startup_failure_context'] ?? []
        );
        if ($contextSummary !== '') {
            $this->printer->note('WLS failure context: ' . $contextSummary);
        }

        foreach ($this->readStartupFailureDiagnostics($instanceData) as $diagnostic) {
            $this->printer->note('WLS failure diagnostic: ' . $diagnostic);
        }
    }

    protected function formatStartupFailureContextSummary(mixed $context): string
    {
        if (!\is_array($context)) {
            return '';
        }

        $parts = [];
        foreach ([
            'instance',
            'main_port',
            'control_port',
            'worker_count',
            'effective_topology',
            'ssl_enabled',
            'startup_timeout_sec',
            'elapsed_sec',
        ] as $key) {
            if (!\array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (\is_array($value)) {
                continue;
            }
            $parts[] = $key . '=' . (string)$value;
        }

        return \implode(', ', $parts);
    }

    protected function formatBackgroundStartupProgress(array $instanceData, int $waitedMs): string
    {
        $rawPhase = \trim((string) ($instanceData['startup_phase'] ?? ''));
        $phase = $this->normalizeBackgroundStartupPhase($rawPhase !== '' ? $rawPhase : 'bootstrapping');
        $failureReason = $this->readStartupFailureReason($instanceData);
        $includeFullPending = $failureReason !== '' || $rawPhase === 'stopping';
        $summary = $this->summarizeBackgroundStartupServices($instanceData, $includeFullPending);
        $parts = [
            (string) __('启动中'),
            '阶段：' . $phase,
        ];

        if ($summary['total'] > 0) {
            $parts[] = '服务就绪：' . $summary['ready'] . '/' . $summary['total'];
            if ($summary['pending_detail'] !== '') {
                $parts[] = '待完成：' . $summary['pending_detail'];
            }
        }

        if ($failureReason !== '') {
            $parts[] = '原因：' . $failureReason;
        }

        $parts[] = '已等待 ' . \max(0, (int) \ceil($waitedMs / 1000)) . ' 秒';

        return \implode(' | ', $parts);
    }

    /**
     * @return array{ready:int,total:int,pending_detail:string}
     */
    protected function summarizeBackgroundStartupServices(array $instanceData, bool $includeFullPending = false): array
    {
        unset($instanceData, $includeFullPending);

        return [
            'ready' => 0,
            'total' => 0,
            'pending_detail' => '',
        ];
    }

    protected function buildBackgroundStartupProgressToken(array $instanceData): string
    {
        $summary = $this->summarizeBackgroundStartupServices($instanceData);

        return \json_encode([
            'phase' => \trim((string) ($instanceData['startup_phase'] ?? '')),
            'ready' => $summary['ready'],
            'total' => $summary['total'],
            'pending' => $summary['pending_detail'],
            'event_seq' => (int)($instanceData['startup_event_seq'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    protected function emitBackgroundStartupProgress(string $progress, string $lastProgress): void
    {
        if ($progress === $lastProgress) {
            return;
        }

        $clearLen = \max(\strlen($lastProgress), \strlen($progress)) + 10;
        echo "\r" . \str_repeat(' ', $clearLen) . "\r";
        echo '  ' . $progress;
    }

    protected function finishBackgroundStartupProgress(string $lastProgress): void
    {
        if ($lastProgress !== '') {
            echo "\n";
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function emitBackgroundStartupEvents(array $instanceData, int $lastSeq, string $lastProgress): array
    {
        $events = $instanceData['startup_events'] ?? [];
        if (!\is_array($events) || $events === []) {
            return [$lastSeq, $lastProgress];
        }

        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $seq = (int)($event['seq'] ?? 0);
            if ($seq <= $lastSeq) {
                continue;
            }
            $message = $this->formatBackgroundStartupEventMessage($event);
            if ($message === '') {
                $lastSeq = \max($lastSeq, $seq);
                continue;
            }
            if ($lastProgress !== '') {
                $this->finishBackgroundStartupProgress($lastProgress);
                $lastProgress = '';
            }

            echo '  ' . $message . "\n";
            $lastSeq = \max($lastSeq, $seq);
        }

        return [$lastSeq, $lastProgress];
    }

    protected function formatBackgroundStartupEventMessage(array $event): string
    {
        $workerId = (int)($event['worker_id'] ?? $event['instance_id'] ?? 0);
        $label = 'Worker' . ($workerId > 0 ? $workerId : '');
        return match ((string)($event['kind'] ?? '')) {
            'worker_ready' => $label . ' 已就绪',
            'worker_warmup_started' => $label . ' 已就绪，正在预热...',
            'worker_warmup_success' => $label . ' 预热成功',
            'worker_warmup_failed' => $label . ' 预热失败',
            default => \trim(\str_replace(["\r", "\n"], ' ', (string)($event['message'] ?? ''))),
        };
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function drainBackgroundStartupEventsAfterReady(
        string $instanceFile,
        int $lastSeq,
        string $lastProgress,
        int $waitStepMs
    ): array {
        unset($waitStepMs);

        // `startup_phase=running` is written only after every required child
        // has reached READY and its startup event has been persisted. A fixed
        // post-READY quiet window delayed a successful CLI start by at least
        // 400ms without adding correctness. Re-read once to cover the atomic
        // file replacement boundary, then return immediately.
        return $this->emitBackgroundStartupEvents(
            $this->readBackgroundStartupData($instanceFile),
            $lastSeq,
            $lastProgress
        );
    }
    
    /**
     * 运行 Master 进程（监控并自动重启 Worker；HTTPS 启用时可自动启动 HTTP 重定向进程）
     */
    protected function runMasterProcess(string $instanceName, array $config, string $workerScript, string $sslCert = '', string $sslKey = '', bool $sslEnabled = false, int $httpRedirectPort = 0, bool $windowMode = false): void
    {
        $masterPid = \getmypid();
        $this->updateInstanceMasterInfo($instanceName, $masterPid, true);

        $this->printer->note(__(''));
        $this->printer->success(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->success(__('║  Master 进程将监控并自动重启异常退出的 Worker                              ║'));
        $this->printer->success(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
        $this->printer->note(__(''));
        $this->printer->note(__('Master PID: %{1}', [$masterPid]));
        if ($sslEnabled && $httpRedirectPort > 0) {
            $this->printer->note(__('HTTP 重定向: 端口 %{1} → HTTPS（不计入 Worker 数）', [$httpRedirectPort]));
        }
        $this->printer->note(__('健康检查间隔: 5 秒'));
        $this->printer->note(__('按 Ctrl+C 停止服务'));
        $this->printer->note(__(''));

        $runtimeSelection = $config['runtime_selection'] ?? null;
        if (\is_array($runtimeSelection)) {
            $runtimeSelection = RuntimeSelection::fromArray($runtimeSelection);
        }
        if (!$runtimeSelection instanceof RuntimeSelection) {
            throw new \RuntimeException('WLS Master requires a complete RuntimeSelection.');
        }

        $workerCount = $config['worker_count'] ?? null;
        $workerBasePort = isset($config['worker_base_port']) ? (int)$config['worker_base_port'] : null;
        $workerPort = isset($config['worker_port']) ? (int)$config['worker_port'] : null;

        /** @var MasterProcess $master */
        $master = ObjectManager::getInstance(MasterProcess::class);
        try {
            $this->configureMasterRuntime(
                $master,
                $runtimeSelection,
                $workerCount,
                $workerBasePort,
                $workerPort,
                (int)($config['port'] ?? 0)
            )->setPrinter($this->printer)
                ->setOnStartedCallback(function () {
                    $this->wlsStartupProcessHandoffDone = true;
                    $this->sharedStateConsumerHandoffDone = true;
                    $this->releaseStartLock();
                })
                ->init(
                    $instanceName,
                    $config,
                    $workerScript,
                    $sslCert,
                    $sslKey,
                    $sslEnabled,
                    $httpRedirectPort,
                    $windowMode
                )
                ->run();
        } catch (\Throwable $e) {
            $this->retirePossibleGatewayRegistrationBeforeFailedStartupCleanup(
                $instanceName,
            );
            $childrenExited = $this->cleanupFailedStartupProcesses(
                $instanceName,
                (int)($config['worker_count'] ?? 0),
            );
            if ($childrenExited) {
                // Release the inherited-listener source only after every
                // identity-bound startup child is proven exited. Otherwise the
                // host lease must remain RESERVED for stale-lease recovery.
                DirectSharedListener::discardStartupListener();
                $this->wlsChildProcessesMayExist = false;
            }
            $this->printer->error(__('服务器启动失败'));
            $this->printer->error($e->getMessage());
            $this->printer->note(__(''));
            $this->printer->note(__('解决方案：'));
            $this->printer->note(__('  1. 停止占用端口的进程'));
            $this->printer->note(__('  2. 或改用非 443 主端口启动（将不启用独立 HTTP 重定向 Worker）'));
            $this->printer->note(__(''));
            throw $e;
        }
    }

    /**
     * 启动失败后清理当前实例的残留进程与索引。
     */
    protected function cleanupFailedStartupProcesses(
        string $instanceName,
        int $workerCount = 0,
    ): bool
    {
        $masterLease = $this->getMasterLeaseManager()->read(
            $this->getMasterLeasePathForInstance($instanceName),
        );
        $masterPid = \is_array($masterLease)
            ? (int)($masterLease['master_pid'] ?? 0)
            : 0;
        if ($masterPid > 0 && $masterPid !== (int)\getmypid()) {
            // Authenticated control stop is the preferred cross-platform path;
            // the stable-handle retirement below is only its bounded fallback.
            MasterProcess::sendStopCommand($instanceName, false);
        }
        $workerCount = $workerCount > 0 ? $workerCount : 16;
        $scopedWorkerPrefix = MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName) . '-';
        $scopedMaintenancePrefix = MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName) . '-';
        $processNames = [
            MasterProcess::getMasterProcessName($instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-session', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-memory', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-redirect', $instanceName),
            MasterProcess::buildScopedProcessName(GatewayProvider::PROCESS_NAME_PREFIX, $instanceName),
            MasterProcess::buildScopedProcessName(GatewayFallbackProvider::PROCESS_NAME_PREFIX, $instanceName),
            MasterProcess::buildScopedProcessName(RuntimeTaskWatchdogProvider::PROCESS_NAME_PREFIX, $instanceName),
        ];
        $prefixes = [
            ...$processNames,
            $scopedWorkerPrefix,
            $scopedMaintenancePrefix,
        ];
        for ($i = 1; $i <= $workerCount; $i++) {
            $processNames[] = MasterProcess::buildScopedProcessName(
                'weline-wls-worker',
                $instanceName,
                $i,
            );
            $processNames[] = MasterProcess::buildScopedProcessName(
                'weline-wls-maintenance',
                $instanceName,
                $i,
            );
            $processNames[] = MasterProcess::buildScopedProcessName(
                GatewayJoinBackendProvider::PROCESS_NAME_PREFIX . '-' . $i,
                $instanceName,
            );
        }
        // Join-backend names put the slot before the scoped instance token,
        // so the ordinary Worker prefix cannot cover them. Include every
        // exact name in the kill/recheck prefix set as well.
        $prefixes = [...$prefixes, ...$processNames];
        $prefixes = \array_values(\array_unique($prefixes));
        $processNames = \array_values(\array_unique($processNames));
        $knownPids = [];
        $enumerationComplete = true;
        try {
            foreach ($processNames as $processName) {
                foreach (Processer::getProcessIdsByName($processName) as $pid) {
                    if ((int)$pid > 0 && (int)$pid !== (int)\getmypid()) {
                        $knownPids[(int)$pid] = true;
                    }
                }
            }
            foreach ($prefixes as $prefix) {
                foreach (Processer::getProcessIdsByPrefix($prefix) as $pid) {
                    if ((int)$pid > 0 && (int)$pid !== (int)\getmypid()) {
                        $knownPids[(int)$pid] = true;
                    }
                }
            }
        } catch (\Throwable) {
            $enumerationComplete = false;
        }

        $retirementComplete = true;
        if ($masterPid > 0 && $masterPid !== (int)\getmypid()) {
            $retirementComplete = $this->terminateSpawnedMasterProcess(
                $instanceName,
                $masterPid,
            );
        }
        try {
            $childOutcomes = (new MasterChildCredentialStore())
                ->retireGenerationProcesses($instanceName);
            foreach ($childOutcomes as $outcome) {
                if (!(bool)($outcome['released'] ?? false)) {
                    $retirementComplete = false;
                }
            }
        } catch (\Throwable $throwable) {
            $retirementComplete = false;
            $this->printer->warning(__(
                '子进程凭据退役失败：%{1}',
                [\substr($throwable->getMessage(), 0, 512)],
            ));
        }
        $remaining = [];
        if ($knownPids !== []) {
            $exit = Processer::waitForExit(\array_keys($knownPids), 2.0);
            $remaining = \is_array($exit['remaining'] ?? null)
                ? $exit['remaining']
                : \array_keys($knownPids);
        }
        try {
            foreach ($processNames as $processName) {
                foreach (Processer::getProcessIdsByName($processName) as $pid) {
                    if ((int)$pid > 0 && (int)$pid !== (int)\getmypid()) {
                        $remaining[(int)$pid] = (int)$pid;
                    }
                }
            }
            foreach ($prefixes as $prefix) {
                foreach (Processer::getProcessIdsByPrefix($prefix) as $pid) {
                    if ((int)$pid > 0 && (int)$pid !== (int)\getmypid()) {
                        $remaining[(int)$pid] = (int)$pid;
                    }
                }
            }
        } catch (\Throwable) {
            $enumerationComplete = false;
        }
        if (!$retirementComplete || !$enumerationComplete || $remaining !== []) {
            // Do not release shared-state ownership, erase identity indexes, or
            // release a listener lease while a startup child may still execute.
            return false;
        }

        if (!$this->releaseFailedStartupSharedStateConsumers($instanceName)) {
            return false;
        }
        foreach ($processNames as $processName) {
            Processer::removePidFile('--name=' . $processName);
        }

        Processer::cleanupStalePidFiles();
        return true;
    }

    protected function releaseFailedStartupSharedStateConsumers(string $instanceName): bool
    {
        try {
            $this->createSharedStateServiceManager()->releaseInstanceConsumers($instanceName);
            $this->sharedStateConsumerAcquired = false;
            return true;
        } catch (\Throwable $throwable) {
            \w_log_warning(__(
                'WLS 启动失败后释放共享服务 consumer token 异常：%{1}',
                [$throwable->getMessage()],
            ));
            return false;
        }
    }

    protected function configureMasterRuntime(
        MasterProcess $master,
        RuntimeSelection $runtimeSelection,
        int|string|null $workerCount,
        ?int $workerBasePort,
        ?int $workerPort,
        int $mainPort
    ): MasterProcess {
        $runtimeWorkerBasePort = $workerBasePort;
        if ($runtimeSelection->isDispatcher() && $workerPort !== null && $workerPort > 0) {
            $runtimeWorkerBasePort = $workerPort - 1;
        }

        return $master
            ->setRuntimeSelection($runtimeSelection)
            ->setMainPort($mainPort)
            ->setWorkerCount($workerCount)
            ->setWorkerBasePort($runtimeWorkerBasePort)
            ->setWorkerPort($workerPort);
    }

    protected function createSharedStateRuntimeResolver(): SharedStateRuntimeResolver
    {
        return ObjectManager::getInstance(SharedStateRuntimeResolver::class);
    }

    protected function createSharedStateServiceManager(): SharedStateServiceManager
    {
        return ObjectManager::getInstance(SharedStateServiceManager::class);
    }

    protected function resolveSharedStateRuntimeConfig(string $instanceName, array $config, bool $forceRestart = false, bool $windowMode = false): array
    {
        $envConfig = $this->getEnvConfig();
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }

        $resolvedRuntime = $this->createSharedStateRuntimeResolver()->resolve($config, $envConfig, $instanceName);
        if (\is_array($resolvedRuntime['session'] ?? null) && \is_array($resolvedRuntime['memory'] ?? null)) {
            $managerConfig = $config;
            $managerConfig['session_server_port'] = (int)($resolvedRuntime['session']['port'] ?? 0);
            $managerConfig['session_server_token_file_name'] = (string)($resolvedRuntime['session']['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'session_server',
                    $managerConfig['session_server_port']
                ));
            $managerConfig['memory_server_port'] = (int)($resolvedRuntime['memory']['port'] ?? 0);
            $managerConfig['memory_server_token_file_name'] = (string)($resolvedRuntime['memory']['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'memory_server',
                    $managerConfig['memory_server_port']
                ));

            $ensuredRuntime = $this->createSharedStateServiceManager()->ensureRuntime(
                $instanceName,
                $managerConfig,
                $envConfig,
                SharedStateServiceManager::resolveEnsureFrontendFlag($managerConfig),
                $forceRestart
            );
            if (\is_array($ensuredRuntime['session'] ?? null) && \is_array($ensuredRuntime['memory'] ?? null)) {
                return $ensuredRuntime;
            }

            return $resolvedRuntime;
        }

        // 提供默认端口和 token，Providers 会使用这些配置
        $projectOffset = MasterProcess::getProjectPortOffset();
        $sessionPort = (int) ($config['session_server_port'] ?? 0);
        if ($sessionPort <= 0) {
            $sessionPort = 19970 + $projectOffset;
        }
        $memoryPort = (int) ($config['memory_server_port'] ?? 0);
        if ($memoryPort <= 0) {
            $memoryPort = 19971 + $projectOffset;
        }

        $sessionToken = (string) ($config['session_server_token_file_name'] ?? '');
        if ($sessionToken === '') {
            $sessionToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $sessionPort);
        }
        $memoryToken = (string) ($config['memory_server_token_file_name'] ?? '');
        if ($memoryToken === '') {
            $memoryToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $memoryPort);
        }

        // 返回配置供 Providers 使用（不再调用 SharedStateServiceManager）
        return [
            'session' => [
                'host' => '127.0.0.1',
                'port' => $sessionPort,
                'token_file_name' => $sessionToken,
            ],
            'memory' => [
                'host' => '127.0.0.1',
                'port' => $memoryPort,
                'token_file_name' => $memoryToken,
            ],
        ];
    }

    /**
     * @param array{
     *   session?: array<string, mixed>,
     *   memory?: array<string, mixed>
     * } $sharedStateRuntime
     */
    protected function printSharedStateRuntimeSummary(string $instanceName, array $sharedStateRuntime): void
    {
        foreach ([
            'session' => 'Session Server',
            'memory' => 'Memory Service',
        ] as $key => $label) {
            $runtime = \is_array($sharedStateRuntime[$key] ?? null) ? $sharedStateRuntime[$key] : [];
            if ($runtime === []) {
                continue;
            }

            $serviceInstanceName = (string) ($runtime['instance_name'] ?? $runtime['service_instance_name'] ?? '');
            $port = (int) ($runtime['port'] ?? 0);
            $processName = (string) ($runtime['process_name'] ?? '');
            $pid = (int) ($runtime['pid'] ?? 0);
            $reused = (bool) ($runtime['reuse_existing'] ?? false);
            $createdNow = (bool) ($runtime['created_now'] ?? false);

            if ($createdNow) {
                $this->printer->note(
                    __('实例 [%{1}] 已创建共享 %{2}: %{3} (port=%{4}, pid=%{5}, process=%{6})', [
                        $instanceName,
                        $label,
                        $serviceInstanceName !== '' ? $serviceInstanceName : 'shared-service',
                        $port,
                        $pid,
                        $processName !== '' ? $processName : 'unknown',
                    ])
                );
                continue;
            }

            if ($reused) {
                $this->printer->note(
                    __('实例 [%{1}] 复用共享 %{2}: %{3} (port=%{4}, pid=%{5}, process=%{6})', [
                        $instanceName,
                        $label,
                        $serviceInstanceName !== '' ? $serviceInstanceName : 'shared-service',
                        $port,
                        $pid,
                        $processName !== '' ? $processName : 'unknown',
                    ])
                );
            }
        }
    }

    protected function getSharedStateTokenFileName(int $port, string $defaultFileName, int $defaultPort): string
    {
        if ($port <= 0 || $port === $defaultPort) {
            return $defaultFileName;
        }

        $extension = \pathinfo($defaultFileName, \PATHINFO_EXTENSION);
        $filename = \pathinfo($defaultFileName, \PATHINFO_FILENAME);
        if ($filename === '') {
            return $defaultFileName;
        }

        return $extension !== ''
            ? $filename . '.' . $port . '.' . $extension
            : $filename . '.' . $port;
    }

    protected function resolveSharedStateTokenFileName(
        int $port,
        string $tokenFileName,
        string $defaultFileName,
        bool $explicit = false,
        int $defaultPort = 0
    ): string {
        $tokenFileName = \trim($tokenFileName);
        if ($tokenFileName === '') {
            return $this->getSharedStateTokenFileName($port, $defaultFileName, $defaultPort);
        }

        if (!$explicit && $this->isRuntimeGeneratedSharedStateTokenFileName($tokenFileName, $defaultFileName)) {
            return $this->getSharedStateTokenFileName($port, $defaultFileName, $defaultPort);
        }

        return $tokenFileName;
    }

    protected function isRuntimeGeneratedSharedStateTokenFileName(string $tokenFileName, string $defaultFileName): bool
    {
        $tokenFileName = \trim($tokenFileName);
        if ($tokenFileName === '') {
            return false;
        }

        $extension = \pathinfo($defaultFileName, \PATHINFO_EXTENSION);
        $filename = \pathinfo($defaultFileName, \PATHINFO_FILENAME);
        if ($filename === '') {
            return false;
        }

        $pattern = $extension !== ''
            ? '/^' . \preg_quote($filename, '/') . '\.[a-z0-9_-]+\.' . \preg_quote($extension, '/') . '$/i'
            : '/^' . \preg_quote($filename, '/') . '\.[a-z0-9_-]+$/i';

        return (bool) \preg_match($pattern, $tokenFileName);
    }

    /**
     * 更新实例的 Master 信息（原子更新，带文件锁）
     */
    protected function updateInstanceMasterInfo(string $instanceName, int $masterPid, bool $enabled): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instanceFile = $instanceDir . $instanceName . '.json';
        
        if (!\file_exists($instanceFile)) {
            return;
        }
        
        $published = ServerInstanceManager::updateJsonFileAtomically($instanceFile, function (array $data) use ($masterPid, $enabled): array {
            $data['master_enabled'] = $enabled;
            $data['master_pid'] = $masterPid;
            $data['master_started_at'] = \date('Y-m-d H:i:s');
            return $data;
        });
        if (!$published) {
            throw new \RuntimeException(
                'Failed to atomically publish WLS launcher Master state.'
            );
        }
    }

    /**
     * 开启维护模式（平滑重启时先开启，避免新请求进入）
     * 
     * 使用框架的维护模式配置，框架会自动处理维护页面显示
     */
    protected function enableMaintenanceMode(string $instanceName): void
    {
        $this->setFrameworkMaintenanceMode(true);
        $this->invokeWlsMaintenanceModeSync($instanceName, true, true);
    }

    protected function beginRestartMaintenanceTransaction(string $instanceName): void
    {
        if ($this->restartMaintenanceSnapshot === null) {
            $this->restartMaintenanceSnapshot = [
                'instance_name' => $instanceName,
                'enabled' => (bool) (Env::get('system.maintenance', false) ?? false),
            ];
        }

        if (!$this->restartMaintenanceShutdownRegistered) {
            \register_shutdown_function([$this, 'rollbackRestartMaintenanceTransactionIfPending']);
            $this->restartMaintenanceShutdownRegistered = true;
        }
    }

    /**
     * 覆盖 execute() 内所有失败 return/fatal 的最终回滚保险。
     * 正常成功路径会先恢复并清空快照，因此 shutdown 时为 no-op。
     */
    public function rollbackRestartMaintenanceTransactionIfPending(bool $requireRuntimeSync = false): void
    {
        $snapshot = $this->restartMaintenanceSnapshot;
        if ($snapshot === null) {
            return;
        }

        try {
            $restored = Env::getInstance()->setConfig('system.maintenance', $snapshot['enabled']);
        } catch (\Throwable $throwable) {
            $this->printer->error(__('恢复重启前维护态失败：%{1}', [$throwable->getMessage()]));
            return;
        }
        if (!$restored) {
            $this->printer->error(__('恢复重启前维护态失败，请检查 app/etc/env.php 写入权限。'));
            return;
        }

        $this->invokeWlsMaintenanceModeSync(
            $snapshot['instance_name'],
            $snapshot['enabled'],
            $requireRuntimeSync
        );
        // 必须在持久态与运行态都恢复后才提交事务；若 required 同步抛错，
        // 保留快照让 shutdown 回调再做一次 best-effort 补偿。
        $this->restartMaintenanceSnapshot = null;
    }

    /**
     * 关闭维护模式（平滑重启完成后关闭）
     */
    protected function disableMaintenanceMode(string $instanceName, bool $requireRuntimeSync = false): void
    {
        if ($this->restartMaintenanceSnapshot !== null
            && $this->restartMaintenanceSnapshot['instance_name'] === $instanceName) {
            $this->rollbackRestartMaintenanceTransactionIfPending($requireRuntimeSync);
            return;
        }

        $this->setFrameworkMaintenanceMode(false);
        $this->invokeWlsMaintenanceModeSync($instanceName, false, $requireRuntimeSync);
    }

    protected function setFrameworkMaintenanceMode(bool $enabled): void
    {
        Env::getInstance()->setConfig('system.maintenance', $enabled);
    }

    private function invokeWlsMaintenanceModeSync(
        ?string $instanceName,
        bool $enabled,
        bool $required
    ): void
    {
        $previous = $this->wlsMaintenanceSyncRequired;
        $this->wlsMaintenanceSyncRequired = $previous || $required;
        try {
            $this->syncWlsMaintenanceMode($instanceName, $enabled);
        } finally {
            $this->wlsMaintenanceSyncRequired = $previous;
        }
    }

    protected function syncWlsMaintenanceMode(?string $instanceName, bool $enabled): void
    {
        $required = $this->wlsMaintenanceSyncRequired;
        $maintenanceSyncTimeoutSec = \PHP_OS_FAMILY === 'Windows'
            ? self::WINDOWS_MAINTENANCE_SYNC_TIMEOUT_SEC
            : self::MAINTENANCE_SYNC_TIMEOUT_SEC;
        $startedAtNs = \hrtime(true);
        $deadlineNs = $startedAtNs
            + (int)($maintenanceSyncTimeoutSec * 1_000_000_000);
        $deadlineMonotonic = $deadlineNs / 1_000_000_000;
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            if ($instanceName !== null && $instanceName !== '') {
                // 启动刚完成时实例管理器的运行态缓存可能尚未收敛；显式实例必须
                // 直接使用 Master endpoint，不能让广播层 attempted=[] 伪装成功。
                $commandResult = $gateway->setMaintenanceModeBeforeDeadline(
                    $instanceName,
                    $enabled,
                    6.0,
                    $deadlineMonotonic,
                );
                $result = [
                    'success' => !empty($commandResult['success']),
                    'attempted' => [$instanceName],
                    'results_by_instance' => [$instanceName => $commandResult],
                    'message' => (string)($commandResult['message'] ?? 'unknown'),
                ];
            } else {
                /** @var BroadcastControlDispatchService $dispatchService */
                $dispatchService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
                $result = $dispatchService->setMaintenanceModeBeforeDeadline(
                    $enabled,
                    null,
                    6.0,
                    $deadlineMonotonic,
                );
            }

            $attempted = \array_values(\array_filter(
                (array)($result['attempted'] ?? []),
                static fn(mixed $name): bool => \is_string($name) && $name !== ''
            ));
            if ($attempted === []) {
                if ($required) {
                    throw new \RuntimeException('no controllable WLS instance accepted the maintenance command');
                }
                return;
            }

            if (empty($result['success'])) {
                throw new \RuntimeException((string)($result['message'] ?? 'control command rejected'));
            }

            /** @var array<string, string|null> $pending */
            $pending = [];
            foreach ($attempted as $targetInstance) {
                $commandResult = (array)(($result['results_by_instance'] ?? [])[$targetInstance] ?? []);
                $commandData = (array)($commandResult['data'] ?? []);
                $operationId = (string)($commandData['operation_id'] ?? '');
                $pending[$targetInstance] = $operationId !== '' ? $operationId : null;
            }

            $lastObserved = [];

            while ($pending !== [] && \hrtime(true) < $deadlineNs) {
                foreach ($pending as $targetInstance => $operationId) {
                    $remainingSec = ($deadlineNs - \hrtime(true)) / 1_000_000_000;
                    if ($remainingSec <= 0) {
                        break 2;
                    }

                    $status = $gateway->getStatusBeforeDeadline(
                        $targetInstance,
                        \max(0.1, \min(0.75, $remainingSec)),
                        $deadlineMonotonic,
                    );
                    if (empty($status['success'])) {
                        $lastObserved[$targetInstance] = (string)($status['message'] ?? 'status unavailable');
                        continue;
                    }

                    $statusData = (array)($status['data'] ?? []);
                    if (!\array_key_exists('maintenance_mode', $statusData)) {
                        $lastObserved[$targetInstance] = 'malformed_status:maintenance_mode_missing';
                        continue;
                    }
                    $actualEnabled = (bool)$statusData['maintenance_mode'];
                    $operationActive = false;
                    if ($operationId !== null) {
                        $controlOperation = $statusData['control_operation'] ?? null;
                        if (!\is_array($controlOperation)
                            || !\array_key_exists('active', $controlOperation)
                            || !\array_key_exists('queued', $controlOperation)
                            || !\is_array($controlOperation['queued'])
                        ) {
                            $lastObserved[$targetInstance] = 'malformed_status:control_operation_missing';
                            continue;
                        }
                        $active = \is_array($controlOperation['active'])
                            ? $controlOperation['active']
                            : [];
                        $operationActive = (string)($active['id'] ?? '') === $operationId;
                        if (!$operationActive) {
                            foreach ($controlOperation['queued'] as $queued) {
                                if ((string)($queued['id'] ?? '') === $operationId) {
                                    $operationActive = true;
                                    break;
                                }
                            }
                        }
                    }

                    $lastObserved[$targetInstance] = 'maintenance_mode='
                        . ($actualEnabled ? 'true' : 'false')
                        . ($operationId !== null
                            ? ', operation=' . $operationId . ($operationActive ? ':pending' : ':terminal')
                            : ', operation=immediate');
                    if (!$operationActive && $actualEnabled === $enabled) {
                        unset($pending[$targetInstance]);
                    }
                }

                if ($pending !== []) {
                    $remainingUsec = (int)\max(0, ($deadlineNs - \hrtime(true)) / 1_000);
                    SchedulerSystem::usleep(\min(self::MAINTENANCE_SYNC_POLL_INTERVAL_USEC, $remainingUsec));
                }
            }

            if ($pending !== []) {
                $details = [];
                foreach (\array_keys($pending) as $targetInstance) {
                    $details[] = $targetInstance . ': ' . ($lastObserved[$targetInstance] ?? 'not observed');
                }
                throw new \RuntimeException(
                    'maintenance control did not reach the requested terminal state within '
                    . $maintenanceSyncTimeoutSec . 's (' . \implode('; ', $details) . ')'
                );
            }

            $this->printer->note(__('WLS 维护模式已确认落地：%{1}', [
                ($enabled ? 'enabled' : 'disabled') . ', instances=' . \count($attempted),
            ]));
        } catch (\Throwable $throwable) {
            $message = (string)__('WLS 维护模式同步失败：%{1}', [$throwable->getMessage()]);
            if ($required) {
                $this->printer->error($message);
                throw new \RuntimeException($message, 0, $throwable);
            }
            $this->printer->warning($message);
        }
    }
    
    /**
     * 获取服务器配置
     * 优先级：命令行参数 > wls.servers[实例名] > wls（默认实例）> 默认值
     */
    protected function getServerConfig(string $instanceName, array $args): array
    {
        $this->traceStartupPhase($instanceName, 'getServerConfig:start');
        // 默认配置（文件监听默认关闭，避免频繁触发热重载导致 Worker 不断重启）
        $defaults = [
            'host' => $this->getDefaultHost(),  // 使用项目唯一域名，避免多项目 SSL 证书冲突
            'port' => self::DEFAULT_PORT,
            'https' => true,
            'worker_count' => 'auto',
            'mode' => 'io',
            'daemon' => true,
            'hot_reload' => false,  // 默认关闭，可通过 wls.hot_reload=true 或 --hot-reload 启用
            'ssl_cert' => '',  // SSL 证书路径
            'ssl_key' => '',   // SSL 私钥路径
            // WLS 2.0 never infers test trust from localhost, an IP, or a
            // development-looking suffix. Test certificates require an exact
            // explicit profile at the instance/config/CLI boundary.
            'certificate_profile' => ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION,
            'worker_base_port' => 10000 + MasterProcess::getProjectPortOffset(),  // Dispatcher 模式下 Worker 内网端口基数 + 项目偏移
            'worker_memory_limit' => '256M',
            'runtime_strategy' => 'auto',
            'runtime' => ['strategy' => 'auto', 'topology' => 'auto', 'listener_mode' => 'auto'],
            'event_loop' => 'auto',
            'loop' => ['driver' => 'auto'],
            'edge' => [
                'mode' => \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                'adapter' => \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
            ],
            'http' => [
                // Keep the public pure-WLS default here. HttpProtocolSelection
                // canonicalizes a managed Nginx/private gateway backend to H1,
                // while auto fallback and --edge=wls must retain real ALPN H2
                // with H1 fallback.
                'protocols' => HttpProtocolSelection::DEFAULT_PROTOCOLS,
                'preferred' => HttpProtocolSelection::HTTP_2,
                'tls_session_resumption' => true,
                'alt_svc' => false,
            ],
            'supervisor' => ['enabled' => 'auto'],
            'source' => __('默认值'),
        ];
        
        $config = $defaults;
        
        // 1. 加载已保存的实例配置（配置记忆）
        // 历史配置继续保持 CLI > env > saved > defaults。WLS 2.0 edge intent
        // 是实例级持久选择，单独遵循 CLI > saved > env > auto。
        $savedConfig = $this->loadSavedInstanceConfig($instanceName);
        $savedCertificateProfile = \is_array($savedConfig)
            && \array_key_exists('certificate_profile', $savedConfig)
                ? ProjectCertificateGenerationStore::normalizeTrustProfile(
                    (string)$savedConfig['certificate_profile'],
                )
                : null;
        $savedEdgeMode = \is_array($savedConfig)
            ? $this->resolveConfiguredEdgeIntent($savedConfig)
            : null;
        $savedPortExplicit = false;
        $savedRequestedPort = 0;
        if (\is_array($savedConfig)
            && ($savedConfig['port_explicit'] ?? false) === true
        ) {
            $savedRequestedPort = (int)($savedConfig['requested_port']
                ?? ($savedConfig['port'] ?? 0));
            if ($savedRequestedPort < 1 || $savedRequestedPort > 65535) {
                throw new \RuntimeException(
                    'Saved explicit WLS port intent is invalid.',
                );
            }
            $savedPortExplicit = true;
        }
        if (\is_array($savedConfig)
            && !$savedPortExplicit
            && \in_array($savedEdgeMode, [
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY,
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS,
            ], true)
            && (int)($savedConfig['port'] ?? 0) >= 20000
            && (int)($savedConfig['port'] ?? 0) <= 29999
        ) {
            // Pre-provenance WLS 2.0 builds persisted a host lease as if it
            // were desired configuration. The reserved range is not trusted
            // as user intent without port_explicit=true.
            unset($savedConfig['port'], $savedConfig['requested_port']);
        }
        $savedWorkerCountExplicit = \is_array($savedConfig)
            && \array_key_exists('worker_count', $savedConfig);
        if ($savedConfig) {
            // 移除已保存配置中的 worker_base_port，强制使用带项目偏移的默认值
            // 这确保了多项目部署时端口不会冲突（旧配置文件可能包含不带偏移的端口）
            unset($savedConfig['worker_base_port']);
            $config = \array_merge($config, $savedConfig);
            $config['source'] = __('已保存实例配置 (%{1})', [$instanceName]);
        }

        // 读取 env 配置
        $envConfig = $this->getEnvConfig();
        
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $wlsServers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
        $baseWlsEdgeMode = $this->resolveConfiguredEdgeIntent($wls);
        $instanceWlsEdgeMode = null;
        $instanceTopologyExplicit = false;
        if ($wls !== []) {
            $baseWls = $wls;
            unset($baseWls['servers'], $baseWls['log'], $baseWls['session'], $baseWls['worker_base_port']);
            $config = $this->mergeWlsConfigLayer($config, $baseWls);
            $config['source'] = __('env.wls');
        }
        if ($instanceName !== 'default'
            && isset($wlsServers[$instanceName])
            && \is_array($wlsServers[$instanceName])
        ) {
            $instanceConfig = $wlsServers[$instanceName];
            $instanceWlsEdgeMode = $this->resolveConfiguredEdgeIntent($instanceConfig);
            $instanceRuntime = \is_array($instanceConfig['runtime'] ?? null) ? $instanceConfig['runtime'] : [];
            $instanceTopologyExplicit = \array_key_exists('topology', $instanceRuntime);
            unset($instanceConfig['worker_base_port']);
            $config = $this->mergeWlsConfigLayer($config, $instanceConfig);
            $config['source'] = __('env.wls.servers.%{1}', [$instanceName]);
        }

        // Legacy adapter values are intent only when explicitly present in a
        // source layer. Never infer legacy mode from the default adapter.
        $envEdgeMode = $instanceWlsEdgeMode ?? $baseWlsEdgeMode;
        if ($envEdgeMode !== null) {
            $config = $this->applyConfiguredEdgeIntent($config, $envEdgeMode);
        }

        // Reapply only the persisted WLS 2.0 edge intent after env merging.
        // Other saved fields deliberately retain their historical lower priority.
        // CLI --edge is applied below and therefore remains authoritative.
        if ($savedEdgeMode !== null) {
            $config = $this->applyConfiguredEdgeIntent($config, $savedEdgeMode);
        }
        if ($savedCertificateProfile !== null) {
            $config['certificate_profile'] = $savedCertificateProfile;
        }
        $restartRequested = isset($args['r']) || isset($args['restart']);
        $savedRestartHost = \is_array($savedConfig)
            ? \trim((string)($savedConfig['host'] ?? ''))
            : '';
        if ($restartRequested
            && $savedRestartHost !== ''
            && !$this->shouldUseDefaultHostFallback($savedRestartHost)
        ) {
            // A rolling restart must keep the serving identity of the running
            // instance unless the CLI explicitly replaces it below. Letting a
            // global env default replace these fields makes the active native
            // manifest and the restart candidate describe different routes.
            foreach (['host', 'public_host', 'ssl_domain', 'ssl_cert', 'ssl_key'] as $identityKey) {
                if (\array_key_exists($identityKey, $savedConfig)
                    && \is_string($savedConfig[$identityKey])
                ) {
                    $config[$identityKey] = $savedConfig[$identityKey];
                }
            }
        }
        if ($savedPortExplicit) {
            $config['port'] = $savedRequestedPort;
        }
        $config['port_explicit'] = $savedPortExplicit;
        $config['requested_port'] = $savedPortExplicit
            ? $savedRequestedPort
            : (int)($config['port'] ?? self::DEFAULT_PORT);

        // Flat per-instance/default shared-state settings are user intent,
        // just like nested env settings. Mark them before runtime resolution
        // so the manager does not rewrite an explicit token after a port scan.
        if ($this->hasEnvWlsConfigKey($envConfig, $instanceName, 'session_server_port')) {
            $config['_session_server_port_explicit'] = true;
        }
        if ($this->hasEnvWlsConfigKey($envConfig, $instanceName, 'memory_server_port')) {
            $config['_memory_server_port_explicit'] = true;
        }
        if ($this->hasEnvWlsConfigKey($envConfig, $instanceName, 'session_server_token_file_name')
            && \trim((string)($config['session_server_token_file_name'] ?? '')) !== ''
        ) {
            $config['_session_server_token_file_name_explicit'] = true;
        }
        if ($this->hasEnvWlsConfigKey($envConfig, $instanceName, 'memory_server_token_file_name')
            && \trim((string)($config['memory_server_token_file_name'] ?? '')) !== ''
        ) {
            $config['_memory_server_token_file_name_explicit'] = true;
        }

        // 实例显式拓扑高于全局 wls.runtime.topology。普通 host/port 仍保持原有合并规则。
        $savedRuntime = \is_array($savedConfig['runtime'] ?? null) ? $savedConfig['runtime'] : [];
        if (\array_key_exists('topology', $savedRuntime)) {
            if (!\is_array($config['runtime'] ?? null)) {
                $config['runtime'] = [];
            }
            $config['runtime']['topology'] = (string)$savedRuntime['topology'];
            $config['_instance_topology_explicit'] = true;
        } elseif ($instanceTopologyExplicit) {
            $config['_instance_topology_explicit'] = true;
        }
        if ($savedWorkerCountExplicit) {
            // A count remembered from an explicit -c/--count belongs to this
            // instance and therefore outranks the global wls.worker_count
            // default. Runtime-resolved auto counts are never persisted here.
            // D12: if worker_count_requested=auto, ignore the stale integer and recompute.
            $savedRequested = $savedConfig['worker_count_requested'] ?? null;
            if (\is_string($savedRequested) && \strtolower(\trim($savedRequested)) === 'auto') {
                $config['worker_count'] = 'auto';
                $config['worker_count_requested'] = 'auto';
                unset($config['_instance_worker_count_explicit']);
            } else {
                $config['worker_count'] = \max(1, (int)$savedConfig['worker_count']);
                $config['_instance_worker_count_explicit'] = true;
                if ($savedRequested !== null && $savedRequested !== '') {
                    $config['worker_count_requested'] = $savedRequested;
                }
            }
        }

        // 如果 env 配置中的 host 是 127.0.0.1 或旧格式域名，恢复为项目唯一域名（避免多项目 SSL 证书冲突）
        $envHost = $config['host'] ?? '';
        if ($this->shouldUseDefaultHostFallback((string)$envHost)) {
            $config['host'] = $this->getDefaultHost();
            // 同时清理 ssl_domain，让它使用新的 host
            if ($this->shouldUseDefaultHostFallback((string)($config['ssl_domain'] ?? ''))) {
                unset($config['ssl_domain']);
            }
        }

        // wls.https = false 时也禁用 HTTPS（与 --no-ssl 一致，供生成地址等使用）
        if (isset($config['https']) && $config['https'] === false) {
            $config['no_ssl'] = true;
        }
        
        // 4. 命令行参数覆盖（最高优先级）
        $hasCliOverride = false;
        if (isset($args['host'])) {
            $normalizedHost = \trim((string)($config['host'] ?? ''));
            $savedHost = \is_array($savedConfig)
                ? \trim((string)($savedConfig['host'] ?? ''))
                : '';
            $previousHost = $savedHost !== '' ? $savedHost : $normalizedHost;
            $previousPublicHost = \trim((string)($config['public_host'] ?? ''));
            $previousPublicOrigin = \trim((string)($config['public_origin'] ?? ''));
            $publicHostWasDerived = $previousPublicHost === ''
                || ($previousHost !== '' && \strcasecmp($previousPublicHost, $previousHost) === 0);
            $publicOriginParts = $previousPublicOrigin !== '' ? \parse_url($previousPublicOrigin) : false;
            $previousPublicOriginHost = \is_array($publicOriginParts)
                ? \trim((string)($publicOriginParts['host'] ?? ''))
                : '';
            $publicOriginWasDerived = $previousPublicOrigin === ''
                || ($previousPublicOriginHost !== ''
                    && (($previousHost !== '' && \strcasecmp($previousPublicOriginHost, $previousHost) === 0)
                        || ($previousPublicHost !== ''
                            && \strcasecmp($previousPublicOriginHost, $previousPublicHost) === 0)));

            $newHost = \trim((string)$args['host']);
            $config['host'] = $newHost;
            if ($publicHostWasDerived) {
                $config['public_host'] = $newHost;
            }
            if ($publicHostWasDerived && $publicOriginWasDerived) {
                $publicScheme = (($config['no_ssl'] ?? false) === true
                    || (($config['https'] ?? true) === false))
                    ? 'http'
                    : 'https';
                $publicAuthority = \filter_var(
                    $newHost,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV6,
                ) !== false
                    ? '[' . $newHost . ']'
                    : $newHost;
                $publicPort = \is_array($publicOriginParts)
                    ? (int)($publicOriginParts['port'] ?? 0)
                    : 0;
                $defaultPublicPort = $publicScheme === 'https' ? 443 : 80;
                $config['public_origin'] = $publicScheme . '://' . $publicAuthority
                    . ($publicPort > 0 && $publicPort !== $defaultPublicPort ? ':' . $publicPort : '');
            }
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        if (isset($args['port']) || isset($args['p'])) {
            $config['port'] = (int) ($args['port'] ?? $args['p']);
            $config['requested_port'] = $config['port'];
            $config['port_explicit'] = true;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $edgeCliMode = $this->resolveEdgeCliMode($args);
        if ($edgeCliMode !== null) {
            $config['edge'] = \array_merge(
                \is_array($config['edge'] ?? null) ? $config['edge'] : [],
                [
                    'mode' => $edgeCliMode,
                    'adapter' => $edgeCliMode === 'wls'
                        ? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                        : \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                ],
            );
            $config['edge_mode'] = $edgeCliMode;
            $config['source'] = __('命令行参数 --edge');
            $hasCliOverride = true;
        }
        $certificateProfileArg = $args['certificate-profile']
            ?? $args['certificate_profile']
            ?? null;
        if ($certificateProfileArg !== null) {
            $config['certificate_profile'] = ProjectCertificateGenerationStore::normalizeTrustProfile(
                (string)$certificateProfileArg,
            );
            $config['source'] = __('命令行参数 --certificate-profile');
            $hasCliOverride = true;
        } else {
            $config['certificate_profile'] = ProjectCertificateGenerationStore::normalizeTrustProfile(
                (string)($config['certificate_profile']
                    ?? ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION),
            );
        }
        if (isset($args['count']) || isset($args['c'])) {
            $config['worker_count'] = (int) ($args['count'] ?? $args['c']);
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        if (isset($args['runtime-strategy']) || isset($args['runtime_strategy'])) {
            $config['runtime_strategy'] = (string)($args['runtime-strategy'] ?? $args['runtime_strategy']);
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $eventLoopArg = $args['event-loop']
            ?? $args['event_loop']
            ?? $args['loop-driver']
            ?? $args['loop_driver']
            ?? null;
        if ($eventLoopArg !== null) {
            $config['event_loop'] = (string)$eventLoopArg;
            $config['loop']['driver'] = (string)$eventLoopArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $supervisorArg = $args['supervisor']
            ?? $args['supervisor-enabled']
            ?? $args['supervisor_enabled']
            ?? null;
        if ($supervisorArg !== null) {
            $config['supervisor']['enabled'] = (string)$supervisorArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $workerMemoryLimitArg = $args['worker-memory-limit']
            ?? $args['worker_memory_limit']
            ?? $args['worker-memory']
            ?? $args['worker_memory']
            ?? null;
        if ($workerMemoryLimitArg !== null) {
            $config['worker_memory_limit'] = $workerMemoryLimitArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $dispatcherMemoryLimitArg = $args['dispatcher-memory-limit']
            ?? $args['dispatcher_memory_limit']
            ?? $args['dispatcher-memory']
            ?? $args['dispatcher_memory']
            ?? null;
        if ($dispatcherMemoryLimitArg !== null) {
            $config['dispatcher_memory_limit'] = $dispatcherMemoryLimitArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $sessionPortArg = $args['session-port']
            ?? $args['session_port']
            ?? null;
        if ($sessionPortArg !== null) {
            $config['session_server_port'] = (int)$sessionPortArg;
            $config['_session_server_port_explicit'] = true;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $sessionTokenArg = $args['session-token-file-name']
            ?? $args['session_token_file_name']
            ?? null;
        if ($sessionTokenArg !== null) {
            $config['session_server_token_file_name'] = (string)$sessionTokenArg;
            $config['_session_server_token_file_name_explicit'] = true;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $memoryPortArg = $args['memory-port']
            ?? $args['memory_port']
            ?? null;
        if ($memoryPortArg !== null) {
            $config['memory_server_port'] = (int)$memoryPortArg;
            $config['_memory_server_port_explicit'] = true;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $memoryTokenArg = $args['memory-token-file-name']
            ?? $args['memory_token_file_name']
            ?? null;
        if ($memoryTokenArg !== null) {
            $config['memory_server_token_file_name'] = (string)$memoryTokenArg;
            $config['_memory_server_token_file_name_explicit'] = true;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        // 默认一律后台运行；仅显式传入 --no-daemon 时前台运行（忽略 env 中的 daemon 配置）
        // 带 -r/--restart 时强制后台，避免被框架或 env 误判为前台
        $requestNoDaemon = (isset($args['no-daemon']) || isset($args['no_daemon']))
            && !(isset($args['r']) || isset($args['restart']));
        $config['daemon'] = !$requestNoDaemon;

        $gatewayEnabledEnv = \getenv('WLS_GATEWAY_ENABLED');
        $gatewayListenEnv = \getenv('WLS_GATEWAY_LISTEN');
        if (($gatewayEnabledEnv !== false && \trim((string)$gatewayEnabledEnv) !== '')
            || ($gatewayListenEnv !== false && \trim((string)$gatewayListenEnv) !== '')
        ) {
            $config['gateway'] = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
            if ($gatewayEnabledEnv !== false && \trim((string)$gatewayEnabledEnv) !== '') {
                $config['gateway']['enabled'] = \in_array(
                    \strtolower(\trim((string)$gatewayEnabledEnv)),
                    ['1', 'true', 'yes', 'on'],
                    true
                );
            }
            if ($gatewayListenEnv !== false && \trim((string)$gatewayListenEnv) !== '') {
                $config['gateway']['listen'] = \trim((string)$gatewayListenEnv);
            }
            $config['source'] = __('环境变量');
            $hasCliOverride = true;
        }
        
        $config = $this->applyPanelModeMemoryPolicy(
            $config,
            $envConfig,
            $instanceName,
            $workerMemoryLimitArg !== null,
            $dispatcherMemoryLimitArg !== null
        );

        // --no-ssl / --http-only：仅 HTTP，不启用 HTTPS（Windows 下可不装 event）
        if (isset($args['no-ssl']) || isset($args['no_ssl']) || isset($args['http-only'])) {
            $config['no_ssl'] = true;
        }
        $noNginx = isset($args['no-nginx'])
            || isset($args['no_nginx'])
            || $this->hasCliArgvToken(['--no-nginx']);
        if ($noNginx) {
            $config['edge'] = \array_merge(
                \is_array($config['edge'] ?? null) ? $config['edge'] : [],
                ['adapter' => \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS],
            );
            $config['edge_adapter'] = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
            $config['edge']['mode'] = \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS;
            $config['edge_mode'] = \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS;
            $config['ssl'] = \array_merge(
                \is_array($config['ssl'] ?? null) ? $config['ssl'] : [],
                ['engine' => 'stream'],
            );
            $config['http'] = \array_merge(
                \is_array($config['http'] ?? null) ? $config['http'] : [],
                [
                    'protocols' => [HttpProtocolSelection::HTTP_2, HttpProtocolSelection::HTTP_1],
                    'preferred' => HttpProtocolSelection::HTTP_2,
                    'tls_session_resumption' => true,
                    'alt_svc' => false,
                ],
            );
            $config['source'] = __('命令行参数 --no-nginx');
            $hasCliOverride = true;
        }
        // SSL 证书配置（命令行参数优先）
        if (isset($args['ssl-cert'])) {
            $config['ssl_cert'] = $args['ssl-cert'];
        }
        if (isset($args['ssl-key'])) {
            $config['ssl_key'] = $args['ssl-key'];
        }
        
        // SSL 域名配置（用于证书生成）
        if (isset($args['ssl-domain']) || isset($args['domain'])) {
            $config['ssl_domain'] = $args['ssl-domain'] ?? $args['domain'] ?? '';
        }
        
        if (isset($args['http-redirect-port']) || isset($args['redirect-port'])) {
            $this->printer->warning(__('参数 --http-redirect-port/--redirect-port 已弃用并忽略。HTTP 重定向规则固定为：仅 HTTPS=443 时使用 80。'));
        }
        
        // 配置解析阶段只探测项目证书文件。持久层恢复必须等 edge 决策完成：
        // gateway 首次缺证书要能先启动 challenge-only backend，不能在此连接项目数据库。
        $sslService = $this->createSslCertificateService(true);
        $legacyCertificateRuntime = $this->isLegacyEdgeRuntimeConfig($config);
        if (empty($config['no_ssl'])
            && empty($config['ssl_cert'])
            && empty($config['ssl_key'])
            && $legacyCertificateRuntime
        ) {
            $this->traceStartupPhase($instanceName, 'ssl:auto-detect:before');
            $autoSsl = $this->autoDetectSslCertificates();
            $this->traceStartupPhase($instanceName, 'ssl:auto-detect:after', [
                'found' => (bool)$autoSsl,
            ]);
            if ($autoSsl) {
                $config['ssl_cert'] = $autoSsl['cert'];
                $config['ssl_key'] = $autoSsl['key'];
                $autoDomain = $autoSsl['domain'] ?? '';
                // 如果自动检测的域名是 127.0.0.1 或 localhost，不使用它（让后续逻辑使用项目唯一域名）
                if ($autoDomain !== '127.0.0.1' && $autoDomain !== 'localhost') {
                    $config['ssl_domain'] = $autoDomain;
                }
            }
        } elseif (empty($config['no_ssl'])
            && empty($config['ssl_cert'])
            && empty($config['ssl_key'])
        ) {
            $this->traceStartupPhase(
                $instanceName,
                'ssl:auto-detect:skipped-wls2-serving-manifest',
            );
        }

        $startupCertPath = (string)($config['ssl_cert'] ?? '');
        $startupKeyPath = (string)($config['ssl_key'] ?? '');
        $startupCertificateHost = $this->resolveCertificateHost(
            $config,
            (string)($config['host'] ?? '127.0.0.1')
        );
        $startupCertificateFilesReady = empty($config['no_ssl'])
            && $sslService->canReuseConfiguredCertificate($startupCertPath, $startupKeyPath)
            && $sslService->certificateMatchesHost($startupCertPath, $startupCertificateHost);
        $startupSniDomains = [];
        if ($startupCertificateFilesReady) {
            $startupSniDomains = $this->collectAdditionalCertificateDomains(
                $instanceName,
                $config,
                $startupCertificateHost
            );
            foreach ($startupSniDomains as $startupSniDomain) {
                if (!$sslService->certificateMatchesHost($startupCertPath, $startupSniDomain)) {
                    $startupCertificateFilesReady = false;
                    break;
                }
            }
        }
        $certificatePreparationMode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? '')
        )));
        // WLS 2.0 serves only immutable ProjectServingManifest generations.
        // Directory scans, legacy SNI-map publication and trust-store mutation
        // remain available solely to the explicit migration/legacy edge.
        $deferCertificatePreparation = $certificatePreparationMode
            !== \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY;
        if ($deferCertificatePreparation) {
            $config['_certificate_preparation_deferred'] = true;
        }
        $this->traceStartupPhase($instanceName, 'ssl:file-gate', [
            'ready' => $startupCertificateFilesReady,
            'host' => $startupCertificateHost,
            'sni_count' => \count($startupSniDomains),
        ]);
        
        // 确保本地域名（0.0.0.0/127.0.0.1/localhost）有自签证书
        if (!empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'local-certificates:skipped-http-only');
        } elseif ($deferCertificatePreparation) {
            $this->traceStartupPhase($instanceName, 'local-certificates:deferred-edge-decision');
        } elseif ($startupCertificateFilesReady) {
            $this->traceStartupPhase($instanceName, 'local-certificates:deferred-valid-files');
        } else {
            $this->traceStartupPhase($instanceName, 'local-certificates:before');
            $this->ensureLocalSelfSignedCertificates($config);
            $this->traceStartupPhase($instanceName, 'local-certificates:after');
        }

        // 自动配置 hosts 文件（将项目域名映射到 127.0.0.1）
        $this->traceStartupPhase($instanceName, 'hosts:before', [
            'host' => (string)($config['host'] ?? '127.0.0.1'),
        ]);
        $this->ensureHostsFileConfigured($config['host'] ?? '127.0.0.1');
        $this->traceStartupPhase($instanceName, 'hosts:after');

        // 开发环境：确保 *.weline.test 泛域名证书存在，避免 hosts 中其他子域 TLS 主机名不匹配
        if (!empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:skipped-http-only');
        } elseif ($deferCertificatePreparation) {
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:deferred-edge-decision');
        } else {
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:before');
            $this->ensureManagedLocalWildcardCertificate();
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:after');
        }

        // 生成多域名证书映射文件（用于 SNI 支持）
        if (!empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'certificate-map:skipped-http-only');
        } elseif ($deferCertificatePreparation) {
            $this->traceStartupPhase($instanceName, 'certificate-map:deferred-edge-decision');
        } elseif ($startupCertificateFilesReady) {
            $this->traceStartupPhase($instanceName, 'certificate-map:deferred-valid-files');
        } else {
            $this->traceStartupPhase($instanceName, 'certificate-map:before');
            $this->generateCertificateMap();
            $this->traceStartupPhase($instanceName, 'certificate-map:after');
        }
        
        // 4. 计算实际 Worker 数量（runtime auto strategy + worker_budget）
        $profile = $this->detectRuntimeProfile(
            $this->resolveServerListenHost((string)($config['host'] ?? '127.0.0.1'))
        );
        $strategyName = (string)($config['runtime_strategy'] ?? ($config['runtime']['strategy'] ?? 'auto'));
        $this->latestRuntimeStrategy = $strategyName;
        // Preserve the user/config intent for RuntimeStrategy diagnostics.
        // getServerConfig resolves the effective integer before the final
        // RuntimeSelection pass; without this field an automatic choice is
        // incorrectly reported as an explicit Worker count.
        $cliWorkerCountExplicit = isset($args['count']) || isset($args['c']);
        $requestedBeforeResolve = $config['worker_count_requested'] ?? $config['worker_count'];
        $config['worker_count_requested'] = $requestedBeforeResolve;

        $workerMemoryLimitMb = \Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator::memoryLimitToMb(
            $config['worker_memory_limit'] ?? '256M'
        );
        $resolver = new RuntimeStrategyResolver();
        $limitMb = $profile->memoryMb() ?? 0;

        // Historical integer without requested provenance: low-mem hardCap clamp (D12).
        if (!$cliWorkerCountExplicit
            && !empty($config['_instance_worker_count_explicit'])
            && !\array_key_exists('worker_count_requested', $savedConfig ?? [])
            && \is_int($config['worker_count'] ?? null)
        ) {
            $calculator = new \Weline\Server\Service\Memory\WorkerMemoryBudgetCalculator();
            $clamp = $calculator->clampHistoricalCount((int)$config['worker_count'], $limitMb);
            if ($clamp['clamped']) {
                $config['worker_count'] = $clamp['count'];
                $config['_worker_count_clamped_by_budget'] = true;
                $this->printer->warning(
                    'worker_count_clamped_by_budget: saved=' . (int)($savedConfig['worker_count'] ?? 0)
                    . ' hard_cap=' . $clamp['hard_cap']
                    . ' limit_mb=' . $limitMb
                );
            }
        }

        $detailed = $resolver->resolveWorkerCountDetailed(
            $config['worker_count'],
            (string)($config['mode'] ?? 'io'),
            $strategyName,
            $profile,
            $workerMemoryLimitMb
        );
        $config['worker_count'] = $detailed['count'];
        $config['budget_ceiling'] = $detailed['budget_ceiling'];
        $config['worker_count_reason'] = $detailed['reason'] !== ''
            ? $detailed['reason']
            : (string)($config['worker_count_reason'] ?? '');
        if (\is_array($detailed['budget'])) {
            $config['worker_budget_detail'] = $detailed['budget'];
        }
        if ($cliWorkerCountExplicit
            && $limitMb > 0
            && $limitMb <= 2300
            && (int)$config['worker_count'] > 2
        ) {
            $this->printer->warning(
                'worker_count CLI exceeds low-mem hardCap advice: count='
                . (int)$config['worker_count']
                . ' limit_mb=' . $limitMb
                . ' (startup keeps explicit -c; Critical may still scale down)'
            );
        }

        $config['worker_memory_limit'] = ServiceContext::normalizeMemoryLimit($config['worker_memory_limit'] ?? '256M');
        if (isset($config['dispatcher_memory_limit'])) {
            $config['dispatcher_memory_limit'] = ServiceContext::normalizeMemoryLimit(
                $config['dispatcher_memory_limit'],
                $config['worker_memory_limit']
            );
        }

        $gatewayConfig = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        if (\array_key_exists('enabled', $gatewayConfig)
            && $this->isTruthyCliFlagValue($gatewayConfig['enabled'])
        ) {
            throw new \RuntimeException(
                'wls.gateway.enabled=true: ' . (string)__('Nginx 是唯一公网边缘，不能跳过其启动。')
            );
        }

        $this->traceStartupPhase($instanceName, 'getServerConfig:done');

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    private function applyPanelModeMemoryPolicy(
        array $config,
        array $envConfig,
        string $instanceName,
        bool $workerCliExplicit,
        bool $dispatcherCliExplicit
    ): array {
        if (!$this->isPanelModeEnabled($config)) {
            return $config;
        }

        $panelConfig = \is_array($config['panel'] ?? null) ? $config['panel'] : [];
        $panelWorkerMemoryLimit = ServiceContext::normalizeMemoryLimit(
            $panelConfig['worker_memory_limit'] ?? self::PANEL_MODE_DEFAULT_MEMORY_LIMIT,
            self::PANEL_MODE_DEFAULT_MEMORY_LIMIT
        );
        $workerMemoryExplicit = $workerCliExplicit
            || $this->hasEnvWlsConfigKey($envConfig, $instanceName, 'worker_memory_limit');
        $currentWorkerMemoryLimit = ServiceContext::normalizeMemoryLimit(
            $config['worker_memory_limit'] ?? '256M'
        );

        if (!$workerMemoryExplicit && $currentWorkerMemoryLimit === '256M') {
            $config['worker_memory_limit'] = $panelWorkerMemoryLimit;
            $currentWorkerMemoryLimit = $panelWorkerMemoryLimit;
        }

        $dispatcherMemoryExplicit = $dispatcherCliExplicit
            || $this->hasEnvWlsConfigKey($envConfig, $instanceName, 'dispatcher_memory_limit');
        $currentDispatcherMemoryLimit = isset($config['dispatcher_memory_limit'])
            ? ServiceContext::normalizeMemoryLimit($config['dispatcher_memory_limit'], $currentWorkerMemoryLimit)
            : '';
        if (!$dispatcherMemoryExplicit
            && ($currentDispatcherMemoryLimit === '' || $currentDispatcherMemoryLimit === '256M')
        ) {
            $config['dispatcher_memory_limit'] = $config['worker_memory_limit'] ?? $currentWorkerMemoryLimit;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isPanelModeEnabled(array $config): bool
    {
        $panelEnabledEnv = \getenv('WLS_PANEL_ENABLED');
        if ($panelEnabledEnv !== false && \trim((string)$panelEnabledEnv) !== '') {
            return $this->isTruthyCliFlagValue($panelEnabledEnv);
        }

        $panelModeEnv = \getenv('WLS_PANEL_MODE');
        if ($panelModeEnv !== false && \trim((string)$panelModeEnv) !== '') {
            return $this->isTruthyCliFlagValue($panelModeEnv);
        }

        $panelConfig = \is_array($config['panel'] ?? null) ? $config['panel'] : [];
        if (\array_key_exists('enabled', $panelConfig)) {
            return $this->isTruthyCliFlagValue($panelConfig['enabled']);
        }
        if (\array_key_exists('mode', $panelConfig)) {
            return $this->isTruthyCliFlagValue($panelConfig['mode']);
        }
        if (\array_key_exists('panel_mode', $config)) {
            return $this->isTruthyCliFlagValue($config['panel_mode']);
        }

        return false;
    }

    /**
     * Merge associative WLS sections recursively while replacing lists and
     * scalar values atomically at the higher-priority layer.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function mergeWlsConfigLayer(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;
            if (\is_array($current)
                && \is_array($value)
                && !\array_is_list($current)
                && !\array_is_list($value)
            ) {
                $base[$key] = $this->mergeWlsConfigLayer($current, $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Resolve an explicitly configured WLS 2.0 mode, including WLS 1.x
     * adapter-only records. A mode in the same layer always outranks its
     * adapter compatibility field.
     *
     * @param array<string,mixed> $layer
     */
    protected function resolveConfiguredEdgeIntent(array $layer): ?string
    {
        $nested = \is_array($layer['edge'] ?? null) ? $layer['edge'] : [];
        $mode = $layer['edge_mode'] ?? ($nested['mode'] ?? null);
        if (\is_scalar($mode) && \trim((string)$mode) !== '') {
            return \strtolower(\trim((string)$mode));
        }

        $adapter = $layer['edge_adapter'] ?? ($nested['adapter'] ?? null);
        if (!\is_scalar($adapter) || \trim((string)$adapter) === '') {
            return null;
        }
        return match (\strtolower(\trim((string)$adapter))) {
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS =>
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS,
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX =>
                \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY,
            default => null,
        };
    }

    /** @param array<string,mixed> $config */
    private function applyConfiguredEdgeIntent(array $config, string $mode): array
    {
        $mode = \strtolower(\trim($mode));
        $adapter = $mode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
                ? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                : \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX;
        $config['edge'] = \array_merge(
            \is_array($config['edge'] ?? null) ? $config['edge'] : [],
            ['mode' => $mode, 'adapter' => $adapter],
        );
        $config['edge_mode'] = $mode;
        $config['edge_adapter'] = $adapter;
        return $config;
    }

    /** @param array<string,mixed> $config */
    protected function isLegacyEdgeRuntimeConfig(array $config): bool
    {
        $mode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? ($config['gateway']['mode'] ?? ''))
        )));

        return $mode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_LEGACY;
    }

    /** @param array<string,mixed> $config */
    private function resolveCertificateTrustProfile(array $config): string
    {
        return ProjectCertificateGenerationStore::normalizeTrustProfile((string)(
            $config['certificate_profile']
                ?? ($config['gateway']['certificate_profile']
                    ?? ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION)
        ));
    }

    /** @param array<string,mixed> $certificateResult */
    private function resolveCertificateProvider(array $certificateResult): string
    {
        $provider = \strtolower(\trim((string)(
            $certificateResult['provider'] ?? ''
        )));
        if ($provider !== '') {
            return ProjectCertificateGenerationStore::normalizeProvider($provider);
        }
        return ProjectCertificateGenerationStore::PROVIDER_EXTERNAL;
    }

    /**
     * @param array<string, mixed> $envConfig
     */
    private function hasEnvWlsConfigKey(array $envConfig, string $instanceName, string $key): bool
    {
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $servers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
        $instanceConfig = $instanceName !== 'default' && \is_array($servers[$instanceName] ?? null)
            ? $servers[$instanceName]
            : [];

        return \array_key_exists($key, $instanceConfig)
            || \array_key_exists($key, $wls);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */

    
    private function beginStartupCertificateStateDeadline(): float
    {
        $deadline = (\hrtime(true) / 1_000_000_000)
            + self::STARTUP_CERTIFICATE_STATE_BUDGET_SECONDS;
        $this->startupCertificateStateDeadlineMonotonic = $deadline;
        return $deadline;
    }

    private function startupCertificateStateDeadline(): float
    {
        $deadline = $this->startupCertificateStateDeadlineMonotonic;
        if ($deadline === null) {
            // Protected certificate helpers are exercised independently by a
            // few extension/test call sites; they still receive one bounded
            // phase deadline rather than falling back to 300 seconds.
            return $this->beginStartupCertificateStateDeadline();
        }
        if (!\is_finite($deadline)
            || (\hrtime(true) / 1_000_000_000) >= $deadline
        ) {
            throw new \RuntimeException(
                'WLS startup certificate-state deadline was exhausted.',
            );
        }
        return $deadline;
    }

    private static function startupListenerStateBudgetSeconds(
        bool $requiresEmulatedWindowsIsolation,
    ): float {
        return $requiresEmulatedWindowsIsolation
            ? self::STARTUP_LISTENER_STATE_EMULATED_WINDOWS_BUDGET_SECONDS
            : self::STARTUP_LISTENER_STATE_BUDGET_SECONDS;
    }

    private static function startupListenerStateTotalBudgetSeconds(
        bool $requiresEmulatedWindowsIsolation,
    ): float {
        return $requiresEmulatedWindowsIsolation
            ? self::STARTUP_LISTENER_STATE_EMULATED_WINDOWS_TOTAL_BUDGET_SECONDS
            : self::STARTUP_LISTENER_STATE_BUDGET_SECONDS;
    }

    private function beginStartupListenerStateDeadline(): float
    {
        $requiresEmulatedWindowsIsolation =
            PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation();
        $now = self::monotonicSeconds();
        if ($this->startupListenerStateTotalDeadlineMonotonic === null) {
            $this->startupListenerStateTotalDeadlineMonotonic = $now
                + self::startupListenerStateTotalBudgetSeconds(
                    $requiresEmulatedWindowsIsolation,
                );
        }
        $totalDeadline = $this->startupListenerStateTotalDeadlineMonotonic;
        if (!\is_finite($totalDeadline) || $now >= $totalDeadline) {
            throw new \RuntimeException(
                'WLS startup listener-state total deadline was exhausted.',
            );
        }
        $deadline = \min(
            $totalDeadline,
            $now + self::startupListenerStateBudgetSeconds(
                $requiresEmulatedWindowsIsolation,
            ),
        );
        $this->startupListenerStateDeadlineMonotonic = $deadline;
        return $deadline;
    }

    private function renewStartupListenerStateDeadlineAfterColdPreflight(): void
    {
        if (!PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation()) {
            return;
        }
        $this->beginStartupListenerStateDeadline();
    }

    private function startupListenerStateDeadline(): float
    {
        $deadline = $this->startupListenerStateDeadlineMonotonic;
        if ($deadline === null) {
            // Preserve protected helper compatibility while guaranteeing that
            // direct extension/test calls never inherit the allocator's legacy
            // 300-second lock wait. The first call owns the phase deadline.
            return $this->beginStartupListenerStateDeadline();
        }
        if (!\is_finite($deadline) || self::monotonicSeconds() >= $deadline) {
            throw new \RuntimeException(
                'WLS startup listener-state deadline was exhausted.',
            );
        }
        return $deadline;
    }

    private function createGatewayStartupDecisionForListenerPhase():
        \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision
    {
        $deadline = $this->startupListenerStateDeadline();
        return new \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision(
            new \Weline\Server\Service\Edge\Gateway\GatewayHostManager(),
            new GatewayPortLeaseAllocator(
                operationDeadlineMonotonic: $deadline,
            ),
        );
    }

    /**
     * 确保 SSL 证书可用
     * 
     * 逻辑：
     * 1. 如果已有有效证书，直接使用
     * 2. 开发环境/本地域名：自动生成自签证书
     * 3. 生产环境/公网域名：自动申请 Let's Encrypt 证书
     * 
     * @param string $instanceName 实例名称
     * @param array $config 服务器配置
     * @return array ['success' => bool, 'cert_path' => string, 'key_path' => string, ...]
     */
    protected function ensureSslCertificate(string $instanceName, array $config): array
    {
        /** @var SslCertificateService $sslService */
        $sslService = $this->createSslCertificateService(true);
        $sslService->setOperationDeadlineMonotonic(
            $this->startupCertificateStateDeadline(),
        );
        $host = $config['host'] ?? '127.0.0.1';
        $certificateHost = $this->resolveCertificateHost($config, (string)$host);
        $legacyCertificateRuntime = $this->isLegacyEdgeRuntimeConfig($config);
        $effectiveEdgeMode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? ($config['gateway']['mode'] ?? ''))
        )));
        $trustProfile = $this->resolveCertificateTrustProfile($config);
        if (!empty($config['no_ssl'])) {
            return [
                'success' => true,
                'message' => 'TLS is disabled by explicit startup intent.',
                'cert_path' => '',
                'key_path' => '',
                'domain' => $certificateHost,
                'ssl_enabled' => false,
                'pending_certificate' => false,
                'is_new' => false,
            ];
        }
        // Startup consumes only the project-local tombstone and immutable
        // certificate generation. Full retirement replay can reach
        // PostgreSQL and therefore belongs to the bounded Gateway Agent /
        // maintenance child; even standalone WLS with valid project PEM must
        // remain independently bootable while project storage is unavailable.
        $generationStore = new ProjectCertificateGenerationStore();
        $deadlineMonotonic = $this->startupCertificateStateDeadline();
        try {
            $disabledGeneration = $generationStore->disabled(
                $certificateHost,
                $deadlineMonotonic,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => 'TLS_CERTIFICATE_RETIREMENT_STATE_INVALID: '
                    . $throwable->getMessage(),
                'code' => 'TLS_CERTIFICATE_RETIREMENT_STATE_INVALID',
                'cert_path' => '',
                'key_path' => '',
                'domain' => $certificateHost,
                'ssl_enabled' => false,
                'pending_certificate' => false,
                'is_new' => false,
            ];
        }
        try {
            $activeGeneration = $generationStore->active(
                $certificateHost,
                $deadlineMonotonic,
                $trustProfile,
            );
        } catch (CertificateTrustProvenanceException $throwable) {
            if ($effectiveEdgeMode
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY
                || $this->hasExplicitCertificatePairForLegacySelectorMigration($config)
            ) {
                // A shared gateway can retain the exact HTTP-01 route while the
                // project republishes a schema-2 production certificate. The
                // untrusted/legacy generation is never exposed on 443. A
                // complete explicit PEM pair may also proceed only as an
                // activation candidate: the normal source, Host and trust
                // profile validation below must replace schema 1 before use.
                $activeGeneration = null;
            } else {
                return [
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'code' => 'TLS_CERTIFICATE_PROVENANCE_UNAVAILABLE',
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $certificateHost,
                    'ssl_enabled' => false,
                    'pending_certificate' => false,
                    'is_new' => false,
                ];
            }
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => 'TLS_CERTIFICATE_RETIREMENT_STATE_INVALID: '
                    . $throwable->getMessage(),
                'code' => 'TLS_CERTIFICATE_RETIREMENT_STATE_INVALID',
                'cert_path' => '',
                'key_path' => '',
                'domain' => $certificateHost,
                'ssl_enabled' => false,
                'pending_certificate' => false,
                'is_new' => false,
            ];
        }
        if ($this->nativeServingManifestRebuildRequired) {
            $rebuildDomains = [$certificateHost => true];
            foreach ($this->nativeServingManifestRebuildActiveDomains as $candidateDomain) {
                $candidateDomain = $this->normalizeCertificateDomainCandidate(
                    (string)$candidateDomain,
                );
                if ($candidateDomain !== '') {
                    $rebuildDomains[$candidateDomain] = true;
                }
            }
            \ksort($rebuildDomains, SORT_STRING);
            try {
                $authorities = $generationStore->authoritySnapshot(
                    \array_keys($rebuildDomains),
                    $deadlineMonotonic,
                    $trustProfile,
                );
            } catch (\Throwable $throwable) {
                return [
                    'success' => false,
                    'message' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID: unable to read the '
                        . 'current whole-project certificate authority: '
                        . $throwable->getMessage(),
                    'code' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID',
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $certificateHost,
                    'ssl_enabled' => false,
                    'pending_certificate' => false,
                    'is_new' => false,
                ];
            }
            $selectedDomain = null;
            if ((string)($authorities[$certificateHost]['effective_state'] ?? '')
                    === 'active'
            ) {
                $selectedDomain = $certificateHost;
            } else {
                foreach ($this->nativeServingManifestRebuildActiveDomains as $candidateDomain) {
                    $candidateDomain = $this->normalizeCertificateDomainCandidate(
                        (string)$candidateDomain,
                    );
                    if ($candidateDomain !== ''
                        && (string)($authorities[$candidateDomain]['effective_state'] ?? '')
                            === 'active'
                    ) {
                        $selectedDomain = $candidateDomain;
                        break;
                    }
                }
            }
            if ($selectedDomain !== null) {
                $currentActive = $authorities[$selectedDomain]['active'] ?? null;
                $recovered = $this->activeProjectCertificateResult(
                    $selectedDomain,
                    $sslService,
                    \is_array($currentActive) ? $currentActive : null,
                    true,
                    $trustProfile,
                );
                if ($recovered !== null) {
                    $recovered['message'] = 'Rebuilt native WLS TLS bootstrap from the current '
                        . 'project certificate authority after a monotonic manifest transition.';
                    $recovered['code'] = 'TLS_SERVING_MANIFEST_REBUILT';
                    return $recovered;
                }
                return [
                    'success' => false,
                    'message' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID: current active '
                        . 'certificate authority has no usable immutable material.',
                    'code' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID',
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $selectedDomain,
                    'ssl_enabled' => false,
                    'pending_certificate' => false,
                    'is_new' => false,
                ];
            }
            $hostAuthority = \is_array($authorities[$certificateHost] ?? null)
                ? $authorities[$certificateHost]
                : [];
            $activeGeneration = null;
            $disabledGeneration = (string)($hostAuthority['effective_state'] ?? '')
                    === 'disabled'
                && \is_array($hostAuthority['disabled'] ?? null)
                    ? $hostAuthority['disabled']
                    : null;
            if (!\is_array($disabledGeneration)) {
                return [
                    'success' => false,
                    'message' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID: the stale manifest '
                        . 'has neither a current certificate selector nor a tombstone for '
                        . $certificateHost . '.',
                    'code' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID',
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $certificateHost,
                    'ssl_enabled' => false,
                    'pending_certificate' => false,
                    'is_new' => false,
                ];
            }
            // The ordinary tombstone branch immediately below is the only
            // authorized outcome. It starts HTTP-only and cannot fall through
            // to configured PEM, source files, ACME, or self-signed material.
        }
        if (\is_array($disabledGeneration) && !\is_array($activeGeneration)) {
            return [
                'success' => true,
                'message' => 'TLS_CERTIFICATE_RETIRED_HTTP_ONLY: the project tombstone forbids serving '
                    . $certificateHost
                    . (\is_array($disabledGeneration['retirement_intent'] ?? null)
                        ? '; retirement recovery remains pending in the background.'
                        : '; starting an HTTP-only runtime without PEM material.'),
                'code' => 'TLS_CERTIFICATE_RETIRED_HTTP_ONLY',
                'cert_path' => '',
                'key_path' => '',
                'domain' => $certificateHost,
                'ssl_enabled' => false,
                'pending_certificate' => false,
                'is_new' => false,
            ];
        }
        $manifestRecoveryProof = \is_array(
            $config['gateway'][
                \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
            ] ?? null
        ) ? $config['gateway'][
            \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
        ] : null;
        if ($manifestRecoveryProof !== null) {
            try {
                $manifestDecision = \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::validate(
                    $manifestRecoveryProof,
                    $trustProfile,
                    $deadlineMonotonic,
                    $certificateHost,
                );
            } catch (\Weline\Server\Service\Edge\NativeServingManifestRebuildRequiredException $exception) {
                $this->nativeServingManifestRebuildRequired = true;
                $this->nativeServingManifestRebuildActiveDomains = $exception->activeDomains;
                unset($config['gateway'][
                    \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::CONFIG_KEY
                ]);
                return $this->ensureSslCertificate($instanceName, $config);
            } catch (\Throwable $throwable) {
                return [
                    'success' => false,
                    'message' => 'TLS_SERVING_MANIFEST_RECOVERY_INVALID: '
                        . $throwable->getMessage(),
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $certificateHost,
                    'ssl_enabled' => false,
                    'pending_certificate' => false,
                    'is_new' => false,
                ];
            }
            if (!\hash_equals('active', (string)$manifestDecision['state'])) {
                return [
                    'success' => false,
                    'message' => 'TLS_CERTIFICATE_UNAVAILABLE: authoritative WLS 2.0 desired state has no active certificate ('
                        . (string)$manifestDecision['reason'] . ').',
                    'code' => 'TLS_CERTIFICATE_UNAVAILABLE',
                    'cert_path' => '',
                    'key_path' => '',
                    'domain' => $certificateHost,
                    'ssl_enabled' => false,
                    'pending_certificate' => \hash_equals(
                        'certificate_pending',
                        (string)$manifestDecision['reason'],
                    ),
                    'is_new' => false,
                ];
            }
            return [
                'success' => true,
                'message' => 'TLS bootstrap selected from the current exact whole-project serving manifest.',
                'code' => 'TLS_SERVING_MANIFEST_RECOVERY',
                'cert_path' => (string)$manifestDecision['cert_path'],
                'key_path' => (string)$manifestDecision['key_path'],
                'domain' => (string)$manifestDecision['domain'],
                'ssl_enabled' => true,
                'pending_certificate' => false,
                'serving_manifest_recovery' => true,
                'certificate_generation' => (int)$manifestDecision[
                    'certificate_generation'
                ],
                'certificate_source_digest' => (string)$manifestDecision[
                    'certificate_source_digest'
                ],
                'trust_profile' => (string)$manifestDecision['trust_profile'],
                'provider' => (string)$manifestDecision['provider'],
                'material_class' => (string)$manifestDecision['material_class'],
                'certificate_provenance_digest' => (string)$manifestDecision[
                    'certificate_provenance_digest'
                ],
                'leaf_fingerprint_sha256' => (string)$manifestDecision[
                    'leaf_fingerprint_sha256'
                ],
                'is_new' => false,
                'storage_sync_deferred' => true,
            ];
        }
        $syncDomain = $this->resolveSslDomainForSync($certificateHost, (string)($config['ssl_domain'] ?? ''));
        $hostResolution = $this->validatePublicHostResolvesToCurrentServer($certificateHost, $sslService);
        if (($hostResolution['success'] ?? false) !== true) {
            return [
                'success' => false,
                'message' => (string)($hostResolution['message'] ?? __('真实 Host 解析校验失败')),
                'ssl_enabled' => false,
            ];
        }
        
        // 智能判断是否为本地/内网环境（127.x, 10.x, 172.16-31.x, 192.168.x, localhost, *.local 等）
        $needsLocalCert = $sslService->needsSelfSignedCertificate($certificateHost);
        
        // 1. 如果命令行或配置中已指定证书，验证并使用
        $configuredCertPath = \trim((string)($config['ssl_cert'] ?? ''));
        $configuredKeyPath = \trim((string)($config['ssl_key'] ?? ''));
        $configuredPairDamaged = ($configuredCertPath === '') !== ($configuredKeyPath === '');
        if ($configuredCertPath !== '' && $configuredKeyPath !== '') {
            $certPath = $configuredCertPath;
            $keyPath = $configuredKeyPath;
            
            if (!\is_file($certPath) || !\is_file($keyPath)) {
                // 先降为“无文件”继续纯文件探针。是否允许访问项目证书库，必须由
                // 已确定的 gateway/wls/legacy 模式在下方决定。
                $config['ssl_cert'] = '';
                $config['ssl_key'] = '';
                $certPath = '';
                $keyPath = '';
            }
            if (\is_file($certPath) && \is_file($keyPath)) {
                if (!$sslService->canReuseConfiguredCertificate($certPath, $keyPath)) {
                    $config['ssl_cert'] = '';
                    $config['ssl_key'] = '';
                // 已配置证书必须覆盖当前 Host；公网证书也不能因“文件有效”而误复用到其它域名。
                } elseif (!$sslService->certificateMatchesHost($certPath, $certificateHost)) {
                    $config['ssl_cert'] = '';
                    $config['ssl_key'] = '';
                } else {
                // READY 只依赖已验证的不可变证书文件；数据库对账属于启动后的控制面任务。
                $certInfo = $sslService->parseCertificate($certPath);
                $this->ensureAdditionalSslCertificates(
                    $instanceName,
                    $config,
                    $certificateHost,
                    $sslService,
                    $certPath,
                    $keyPath
                );
                return [
                    'success' => true,
                    'cert_path' => $certPath,
                    'key_path' => $keyPath,
                    'domain' => $certificateHost,
                    'issuer' => $certInfo['issuer'] ?? __('手动配置'),
                    'expires_at' => $certInfo['expires_at'] ?? '',
                    'is_new' => false,
                    'storage_sync_deferred' => true,
                ];
                }
            }
        }

        // A validated immutable generation is the first recovery source after
        // a configured PEM pair becomes absent/invalid. It is project-owned and
        // independent of PostgreSQL or a late certificate-source mount, so a
        // restart must not replace it with self-signed material or downgrade a
        // gateway route to PENDING_CERTIFICATE.
        $activeGeneration = $this->activeProjectCertificateResult(
            $certificateHost,
            $sslService,
            $activeGeneration,
            true,
            $trustProfile,
        );
        if ($activeGeneration !== null) {
            return $activeGeneration;
        }
        if ($configuredPairDamaged) {
            return [
                'success' => false,
                'message' => 'TLS certificate configuration must provide both certificate and private key.',
                'cert_path' => '',
                'key_path' => '',
                'domain' => $certificateHost,
                'ssl_enabled' => false,
                'pending_certificate' => false,
            ];
        }
        
        // 2. 确定域名
        // 优先使用 host（项目唯一域名），忽略可能来自旧配置的 ssl_domain
        $domain = $certificateHost;
        $startupCertResult = $this->tryUseStartupCertificateFiles(
            $sslService,
            $domain,
            $syncDomain,
            $legacyCertificateRuntime,
        );
        if ($startupCertResult !== null) {
            $this->ensureAdditionalSslCertificates(
                $instanceName,
                $config,
                $domain,
                $sslService,
                (string)($startupCertResult['cert_path'] ?? ''),
                (string)($startupCertResult['key_path'] ?? '')
            );
            return $startupCertResult;
        }

        $gatewayCertificatePendingCandidate = $effectiveEdgeMode
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY
            && ($trustProfile === ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION
                || !$needsLocalCert);
        $certificateStorageReady = false;

        if (!$legacyCertificateRuntime) {
            $missing = $this->resolveWls2MissingCertificateResult(
                $config,
                $domain,
                $needsLocalCert,
                false,
            );
            if ($missing !== null) {
                return $missing;
            }
            if (!$needsLocalCert) {
                throw new \RuntimeException(
                    'WLS 2.0 certificate resolution did not produce an immutable generation.',
                );
            }
            // Local/development domains keep the existing self-signed cold-start
            // path below, then activate the resulting PEM under project_ssl.
        }

        // Standalone/legacy starts may restore project-owned PEM from PostgreSQL before
        // failing or signing. A public gateway start deliberately skips this dependency:
        // its challenge-only backend must become available even while project storage is down.
        if (!$gatewayCertificatePendingCandidate) {
            $sslService->ensureCertificateStorageReady();
            $certificateStorageReady = true;
            if ($this->restoreManagedCertificateForConfig($config, $sslService, (string)$host)) {
                $restoredCertificate = $this->tryUseStartupCertificateFiles(
                    $sslService,
                    $domain,
                    $syncDomain,
                );
                if ($restoredCertificate !== null) {
                    $this->ensureAdditionalSslCertificates(
                        $instanceName,
                        $config,
                        $domain,
                        $sslService,
                        (string)($restoredCertificate['cert_path'] ?? ''),
                        (string)($restoredCertificate['key_path'] ?? ''),
                    );
                    return $restoredCertificate;
                }
            }
        }

        if (!$legacyCertificateRuntime) {
            $missing = $this->resolveWls2MissingCertificateResult(
                $config,
                $domain,
                $needsLocalCert,
                false,
            );
            if ($missing !== null) {
                return $missing;
            }
            if (!$needsLocalCert) {
                throw new \RuntimeException(
                    'WLS 2.0 certificate resolution did not produce an immutable generation.',
                );
            }
        }

        // 纯文件探针不启动 ORM；WLS 2.0 gateway 缺证书时必须能先启动
        // challenge-only loopback backend，不得被项目数据库短暂不可用阻断。
        $webroot = $this->resolveAcmeWebrootForStartup($instanceName, $config);
        $email = Env::get('admin_email', 'admin@' . $domain);

        // 3. 先快速探测本地是否已有可复用证书，避免「明明复用却先喊『正在准备...』」的误导性输出。
        //    hasValidLocalCertificate 校验证书有效期、域名覆盖、私钥匹配和本地 CA 复用能力，
        //    但不触发签发；它也是 WLS 2.0 missing-certificate 门禁的事实输入。
        $willReuse = $sslService->hasValidLocalCertificate($domain);
        if (!$willReuse) {
            // 真正要走签发/续签路径：本地 CA + CSR + 可能的 Windows 信任库操作存在长尾风险，
            // 提前提示并在结束后连同耗时、签发方一起打印，配合 SslCertificateService 内部分阶段
            // w_log_info 可在事故时快速定位瓶颈。
            $this->printer->note(__('正在为 %{1} 准备 SSL 证书...', [$domain]));
        }

        $wls2MissingCertificate = $this->resolveWls2MissingCertificateResult(
            $config,
            $domain,
            $needsLocalCert,
            $willReuse,
        );
        if ($wls2MissingCertificate !== null) {
            if (($wls2MissingCertificate['pending_certificate'] ?? false) === true) {
                $this->printer->note((string)$wls2MissingCertificate['message']);
            } else {
                $this->printer->warning((string)$wls2MissingCertificate['message']);
            }
            return $wls2MissingCertificate;
        }

        // 只有确实进入本地签发、legacy 签发或入库路径时才启用 ORM。
        // 此时损坏或不可达的默认 PostgreSQL 仍精确 fail-closed，不回退 SQLite。
        if (!$certificateStorageReady) {
            $sslService->ensureCertificateStorageReady();
        }

        // 冷启动阶段：如果此时无法保证 ACME HTTP-01 校验入口已经可用（例如 dispatcher/worker 尚未就绪），
        // 则不要直接进入公网 ACME 申请流程，否则会出现必然 404（/.well-known/acme-challenge/* 未响应）。
        // 这里先生成自签证书让服务尽快就绪，后续 Master 子服务就绪后统一再补做正式证书申请。
        $deferAcmeForColdStartup = !$needsLocalCert
            && !$willReuse;
        if ($deferAcmeForColdStartup) {
            $this->printer->warning(__('检测到冷启动阶段 ACME HTTP-01 校验入口尚未就绪：已先用自签证书启动（%{1}）。', [$domain]));

            $primaryResult = $sslService->generateSelfSignedCertificate($domain);
            if (($primaryResult['success'] ?? false) === true) {
                $additionalDomains = $this->collectAdditionalCertificateDomains($instanceName, $config, $domain);
                foreach ($additionalDomains as $addDomain) {
                    $sslService->generateSelfSignedCertificate($addDomain);
                }
                // 启动阶段只更新映射文件，不广播 reload（避免启动窗口内触发不必要的热重载）。
                $sslService->regenerateCertificateMap(false);
                return $primaryResult;
            }
            // 自签生成失败：退回原先 ACME 路径让错误信息更明确。
            $this->printer->warning(__('自签证书生成失败，继续走公网 ACME 申请：%{1}', [(string) ($primaryResult['message'] ?? '未知错误')]));
        }

        $tStart = \hrtime(true);
        $result = $sslService->ensureCertificate($domain, $webroot, $email);
        if (($result['success'] ?? false) !== true
            && \str_contains((string)($result['message'] ?? ''), '正在申请证书中')) {
            // 自愈：上次流程异常退出残留锁时，释放后重试一次，避免“永远卡在申请中”。
            $sslService->forceReleaseSslIssuanceLock($domain);
            SchedulerSystem::sleep(1);
            $result = $sslService->ensureCertificate($domain, $webroot, $email);
        }
        $elapsedMs = (int) \round((\hrtime(true) - $tStart) / 1_000_000.0);

        if (($result['success'] ?? false) === true) {
            $issuer = (string) ($result['issuer'] ?? '');
            $isNew = (bool) ($result['is_new'] ?? false);
            if ($isNew) {
                $this->printer->success(__('SSL 证书已签发：%{1}（签发方：%{2}，耗时 %{3}ms）', [
                    $domain,
                    $issuer !== '' ? $issuer : __('本地 CA'),
                    (string) $elapsedMs,
                ]));
            } else {
                // 复用路径：正常毫秒级，不强显耗时避免噪声；仅当不常见地慢（>200ms）才追加耗时。
                if ($elapsedMs > 200) {
                    $this->printer->success(__('使用已有证书：%{1}（签发方：%{2}，耗时 %{3}ms）', [
                        $domain,
                        $issuer !== '' ? $issuer : __('未知'),
                        (string) $elapsedMs,
                    ]));
                } else {
                    $this->printer->success(__('使用已有证书：%{1}（签发方：%{2}）', [
                        $domain,
                        $issuer !== '' ? $issuer : __('未知'),
                    ]));
                }
            }
            $this->ensureAdditionalSslCertificates(
                $instanceName,
                $config,
                $domain,
                $sslService,
                (string)($result['cert_path'] ?? ''),
                (string)($result['key_path'] ?? '')
            );
        } else {
            $deferredFallback = $this->tryBuildDeferredStartupSslFallback(
                $sslService,
                $domain,
                $email,
                $needsLocalCert,
                $webroot,
                $result
            );
            if ($deferredFallback !== null) {
                return $deferredFallback;
            }
            $this->printer->warning(__('SSL 证书准备失败：%{1}（耗时 %{2}ms）— %{3}', [
                $domain,
                (string) $elapsedMs,
                (string) ($result['message'] ?? ''),
            ]));
        }
        return $result;
    }

    /** @param array<string,mixed> $config */
    protected function hasExplicitCertificatePairForLegacySelectorMigration(
        array $config,
    ): bool {
        return \trim((string)($config['ssl_cert'] ?? '')) !== ''
            && \trim((string)($config['ssl_key'] ?? '')) !== '';
    }

    /**
     * ProjectCertificateGenerationStore is the stable serving projection shared
     * by gateway and native WLS. `active()` validates the domain, expiry, key
     * match, immutable bytes and digest before any path is returned.
     *
     * @return array<string,mixed>|null
     */
    protected function activeProjectCertificateResult(
        string $certificateHost,
        SslCertificateService $sslService,
        ?array $resolvedActiveGeneration = null,
        bool $activeGenerationWasResolved = false,
        string $requiredTrustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION,
    ): ?array {
        $requiredTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
            $requiredTrustProfile,
        );
        if (!$activeGenerationWasResolved) {
            try {
                $resolvedActiveGeneration = (new ProjectCertificateGenerationStore())->active(
                    $certificateHost,
                    $this->startupCertificateStateDeadline(),
                    $requiredTrustProfile,
                );
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'TLS_CERTIFICATE_STATE_UNAVAILABLE: unable to read the active '
                        . 'project certificate generation.',
                    0,
                    $throwable,
                );
            }
        }
        $active = $resolvedActiveGeneration;
        if (!\is_array($active)) {
            return null;
        }
        $certPath = (string)($active['cert_path'] ?? '');
        $keyPath = (string)($active['key_path'] ?? '');
        if ($certPath === '' || $keyPath === '') {
            return null;
        }
        $sourceDigest = \strtolower(\trim((string)($active['source_digest'] ?? '')));
        $provider = (string)($active['provider'] ?? '');
        $materialClass = \strtolower(\trim((string)(
            $active['material_class'] ?? ''
        )));
        $provenanceDigest = \strtolower(\trim((string)(
            $active['provenance_digest'] ?? ''
        )));
        if (!\hash_equals($requiredTrustProfile, (string)($active['trust_profile'] ?? ''))
            || !\hash_equals(
                $provenanceDigest,
                ProjectCertificateGenerationStore::provenanceDigest(
                    $certificateHost,
                    $sourceDigest,
                    $requiredTrustProfile,
                    $provider,
                    $materialClass,
                ),
            )
        ) {
            throw new CertificateTrustProvenanceException(
                'TLS_CERTIFICATE_PROVENANCE_UNAVAILABLE: active project certificate '
                    . 'does not match the requested serving profile.',
            );
        }
        $certificate = $sslService->parseCertificate($certPath);
        return [
            'success' => true,
            'message' => 'Using the current immutable project certificate generation.',
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'chain_path' => (string)($active['chain_path'] ?? ''),
            'domain' => $certificateHost,
            'issuer' => (string)($certificate['issuer'] ?? ''),
            'expires_at' => (string)($certificate['expires_at'] ?? ''),
            'generation' => (int)($active['generation'] ?? 0),
            'source_digest' => $sourceDigest,
            'trust_profile' => $requiredTrustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => $provenanceDigest,
            'leaf_fingerprint_sha256' => (string)(
                $active['leaf_fingerprint_sha256'] ?? ''
            ),
            'cert_sha256' => (string)($active['cert_sha256'] ?? ''),
            'key_sha256' => (string)($active['key_sha256'] ?? ''),
            'chain_sha256' => (string)($active['chain_sha256'] ?? ''),
            'ssl_enabled' => true,
            'pending_certificate' => false,
            'is_new' => false,
            'storage_sync_deferred' => true,
            'project_generation_reused' => true,
        ];
    }

    /**
     * Keep certificate-service construction behind a narrow seam so the
     * challenge-only startup boundary can be verified without initializing
     * project persistence.
     */
    protected function createSslCertificateService(bool $deferCertificateStorage = false): SslCertificateService
    {
        $service = new SslCertificateService($deferCertificateStorage);
        $service->setAdministratorAuthorizationSession(
            $this->administratorAuthorizationSession
                ??= new \Weline\Server\Service\AdministratorAuthorizationSession(),
        );

        return $service;
    }

    /**
     * Finish legacy local-certificate conveniences only after auto/gateway
     * discovery has selected the effective edge. A public gateway consumes
     * project certificate files and must not initialize unrelated local
     * certificate storage before its challenge-only backend is ready.
     *
     * @param array<string,mixed> $config
     */
    protected function completeDeferredCertificatePreparation(
        string $instanceName,
        array $config,
        bool $gatewayMode,
        string $publicHost,
    ): void {
        if (!empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'certificate-preparation:skipped-http-only');
            return;
        }
        if (!$this->isLegacyEdgeRuntimeConfig($config)) {
            $this->traceStartupPhase(
                $instanceName,
                'certificate-preparation:skipped-wls2-serving-manifest',
            );
            $this->syncLocalDevelopmentCaTrustForManagedHost($publicHost);
            return;
        }

        $sslService = $this->createSslCertificateService(true);
        $sslService->setOperationDeadlineMonotonic(
            $this->startupCertificateStateDeadline(),
        );
        if ($gatewayMode && !$sslService->needsSelfSignedCertificate($publicHost)) {
            $this->traceStartupPhase($instanceName, 'certificate-preparation:skipped-public-gateway');
            return;
        }

        $certPath = (string)($config['ssl_cert'] ?? '');
        $keyPath = (string)($config['ssl_key'] ?? '');
        $filesReady = $sslService->canReuseConfiguredCertificate($certPath, $keyPath)
            && $sslService->certificateMatchesHost($certPath, $publicHost);
        if ($filesReady) {
            foreach ($this->collectAdditionalCertificateDomains($instanceName, $config, $publicHost) as $domain) {
                if (!$sslService->certificateMatchesHost($certPath, $domain)) {
                    $filesReady = false;
                    break;
                }
            }
        }

        $previousService = $this->deferredCertificatePreparationService;
        $this->deferredCertificatePreparationService = $sslService;
        try {
            if (!$filesReady) {
                $this->ensureLocalSelfSignedCertificates($config);
            }
            $this->ensureManagedLocalWildcardCertificate();
            if (!$filesReady) {
                $this->generateCertificateMap();
            }
        } finally {
            $this->deferredCertificatePreparationService = $previousService;
        }
        $this->traceStartupPhase($instanceName, 'certificate-preparation:completed-after-edge-decision', [
            'files_ready' => $filesReady,
            'gateway_mode' => $gatewayMode,
        ]);
    }

    /**
     * Enforce the WLS 2.0 no-certificate boundary before the legacy cold-start
     * self-signed fallback can run.
     *
     * A gateway project may start only its private HTTP backend so the exact
     * HTTP-01 challenge can be published. Pure WLS (including auto fallback)
     * owns its TLS listener and therefore cannot start without an already
     * valid project certificate. Only an explicit certificate_profile=test may
     * use local/self-signed material; a domain suffix is never authorization.
     *
     * @return array<string,mixed>|null
     */
    protected function resolveWls2MissingCertificateResult(
        array $config,
        string $domain,
        bool $needsLocalCertificate,
        bool $willReuseCertificate,
    ): ?array {
        $trustProfile = $this->resolveCertificateTrustProfile($config);
        if ($trustProfile === ProjectCertificateGenerationStore::TRUST_PROFILE_TEST
            && ($willReuseCertificate || $needsLocalCertificate)
        ) {
            return null;
        }

        $mode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? ($config['gateway']['mode'] ?? ''))
        )));
        if ($mode === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY) {
            return [
                'success' => true,
                'message' => (string)__(
                    'PENDING_CERTIFICATE：%{1} 尚无可用证书；当前只允许精确 ACME HTTP-01 challenge，证书激活前不发布普通 443 路由。',
                    [$domain],
                ),
                'code' => 'PENDING_CERTIFICATE',
                'cert_path' => '',
                'key_path' => '',
                'issuer' => '',
                'expires_at' => '',
                'is_new' => false,
                'ssl_enabled' => false,
                'pending_certificate' => true,
                'storage_sync_deferred' => true,
            ];
        }
        if ($mode === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS) {
            // Local/development domains (*.weline.test, loopback, etc.) still use
            // the project self-signed cold-start path. Public pure-WLS hosts remain
            // fail-closed until an enrolled project certificate exists.
            if ($needsLocalCertificate) {
                return null;
            }
            return [
                'success' => false,
                'message' => (string)__(
                    'TLS_CERTIFICATE_UNAVAILABLE：纯 WLS/备用入口没有 %{1} 的有效证书，已拒绝生成隐式自签名证书。',
                    [$domain],
                ),
                'code' => 'TLS_CERTIFICATE_UNAVAILABLE',
                'cert_path' => '',
                'key_path' => '',
                'issuer' => '',
                'expires_at' => '',
                'is_new' => false,
                'ssl_enabled' => false,
                'pending_certificate' => false,
            ];
        }
        return null;
    }

    /**
     * Every project joined to a host gateway needs a distinct loopback
     * backend. The historical fixed 9981 fallback makes the second default
     * project collide with the first, so fresh/default gateway starts use the
     * same host-coordinated stable lease range as recovery listeners.
     * Explicit ports remain authoritative.
     */
    protected function resolveGatewayInitialBackendPort(
        string $instanceName,
        int $configuredPort,
        bool $portExplicit,
        bool $gatewayMode,
    ): int {
        if (!$gatewayMode) {
            return $configuredPort;
        }

        $automatic = !$portExplicit && \in_array($configuredPort, [
            self::DEFAULT_PORT,
            self::DEFAULT_PORT_FALLBACK,
        ], true);
        $port = $this->allocateGatewayInitialBackendPort(
            $instanceName,
            $automatic ? null : $configuredPort,
        );
        if ($automatic && ($port < 20000 || $port > 29999)) {
            throw new \RuntimeException(
                'Gateway backend allocator returned a port outside 20000-29999.',
            );
        }
        return $port;
    }

    protected function allocateGatewayInitialBackendPort(
        string $instanceName,
        ?int $exactPort = null,
    ): int
    {
        $allocator = new GatewayPortLeaseAllocator(
            operationDeadlineMonotonic: $this->startupListenerStateDeadline(),
        );
        $leaseInstance = \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::forRole(
            $instanceName,
            \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::ROLE_INITIAL_BACKEND,
        );
        $reservation = $allocator->reserveBound(
            $leaseInstance,
            static function (int $port): mixed {
                return @\stream_socket_server(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
            },
            '127.0.0.1',
            true,
            $exactPort,
        );
        $this->startupGatewayBackendLease = $reservation;
        $this->startupGatewayBackendListener = $allocator->takeRetainedBoundSocket(
            (string)$reservation['lease_id'],
        );
        if (!\is_resource($this->startupGatewayBackendListener)) {
            throw new \RuntimeException(
                'Reserved gateway backend port did not retain its listening socket.'
            );
        }
        return (int)$reservation['port'];
    }

    /** @param array<string,mixed> $config */
    private function publishStartupListenerHandoffIntent(array &$config): void
    {
        $publicLease = \is_array($this->startupPublicEdgeLease)
            ? $this->startupPublicEdgeLease
            : [];
        $backendLease = \is_array($this->startupGatewayBackendLease)
            ? $this->startupGatewayBackendLease
            : [];
        if ($publicLease !== [] && $backendLease !== []) {
            throw new \RuntimeException(
                'One Master startup cannot inherit two different initial listeners.',
            );
        }
        $lease = $publicLease !== [] ? $publicLease : $backendLease;
        if ($lease === []) {
            unset($config['gateway']['startup_listener_handoff']);
            return;
        }

        $listener = $publicLease !== []
            ? $this->startupPublicEdgeListener
            : $this->startupGatewayBackendListener;
        if (!\is_resource($listener)) {
            throw new \RuntimeException(
                'A schema-6 startup lease has no retained listener.',
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $intent = WindowsListenerHandoff::createIntent(
                (string)($config['gateway']['instance_id'] ?? ''),
                (string)($config['gateway']['launch_id'] ?? ''),
                $lease,
            );
            $this->startupWindowsListenerHandoffIntent = $intent;
            $config['gateway']['startup_listener_handoff'] = $intent;
            return;
        }
        $config['gateway']['startup_listener_handoff'] = [
            'schema_version' => 1,
            'transport' => 'posix_inherited_fd',
            'continuous_ownership' => true,
            'fd' => DirectSharedListener::INHERITED_FD,
            'lease_id' => (string)($lease['lease_id'] ?? ''),
            'instance' => (string)($lease['instance'] ?? ''),
            'bind_host' => (string)($lease['bind_host'] ?? ''),
            'port' => (int)($lease['port'] ?? 0),
            'launch_id' => (string)($config['gateway']['launch_id'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $config */
    private function prepareStartupListenerTransfer(array &$config): void
    {
        $port = (int)($config['port'] ?? 0);
        $proof = $this->currentStartupListenerProofForPort($port);
        if ($proof === null) {
            return;
        }
        $launchId = \strtolower(\trim((string)(
            $config['gateway']['launch_id'] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1) {
            throw new \RuntimeException(
                'Startup listener transfer requires the immutable Master launch identity.',
            );
        }
        $lease = $proof['lease'];
        $prepared = (new GatewayPortLeaseAllocator(
            operationDeadlineMonotonic: $this->startupListenerStateDeadline(),
        ))
            ->prepareTransfer(
                (string)$lease['instance'],
                (string)$lease['lease_id'],
                (string)$lease['bind_host'],
                (int)$lease['port'],
                $launchId,
            );
        if ((string)$proof['kind'] === 'public') {
            $this->startupPublicEdgeLease = $prepared;
            $config['gateway']['public_lease'] = $prepared;
            if (\is_array($config['gateway']['edge_decision'] ?? null)) {
                $config['gateway']['edge_decision']['port_lease'] = $prepared;
            }
        } else {
            $this->startupGatewayBackendLease = $prepared;
            $config['gateway']['backend_lease'] = $prepared;
        }
    }

    /** @return array<int,resource> */
    private function startupMasterInheritedDescriptors(): array
    {
        if (IS_WIN) {
            return [];
        }
        $listeners = \array_values(\array_filter([
            $this->startupPublicEdgeListener,
            $this->startupGatewayBackendListener,
        ], 'is_resource'));
        if (\count($listeners) > 1) {
            throw new \RuntimeException(
                'A Master startup cannot inherit multiple initial listener sockets.'
            );
        }
        return $listeners === []
            ? []
            : [DirectSharedListener::INHERITED_FD => $listeners[0]];
    }

    private function closeStartupListenerCopies(): void
    {
        foreach ([
            'startupPublicEdgeListener',
            'startupGatewayBackendListener',
        ] as $property) {
            $listener = $this->{$property};
            if (\is_resource($listener)) {
                @\fclose($listener);
            }
            $this->{$property} = null;
        }
        $this->startupWindowsListenerHandoffIntent = null;
    }

    /** @return resource */
    private function startupListenerForLeaseId(string $leaseId): mixed
    {
        if (\is_array($this->startupPublicEdgeLease)
            && \hash_equals(
                (string)($this->startupPublicEdgeLease['lease_id'] ?? ''),
                $leaseId,
            )
            && \is_resource($this->startupPublicEdgeListener)
        ) {
            return $this->startupPublicEdgeListener;
        }
        if (\is_array($this->startupGatewayBackendLease)
            && \hash_equals(
                (string)($this->startupGatewayBackendLease['lease_id'] ?? ''),
                $leaseId,
            )
            && \is_resource($this->startupGatewayBackendListener)
        ) {
            return $this->startupGatewayBackendListener;
        }
        throw new \RuntimeException(
            'Startup listener handoff has no retained socket for its exact lease.'
        );
    }

    /** @param array<string,mixed> $config */
    private function installStartupListenerInCurrentMaster(
        string $instanceName,
        array $config,
    ): void {
        $gateway = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        if (IS_WIN) {
            $intent = WindowsListenerHandoff::validatePersistedIntent(
                $instanceName,
                (int)($config['port'] ?? 0),
                $gateway,
            );
            if ($intent === null) {
                return;
            }
            $listener = $this->startupListenerForLeaseId(
                (string)$intent['lease_id'],
            );
            if (\is_array($this->startupPublicEdgeLease)
                && \hash_equals(
                    (string)($this->startupPublicEdgeLease['lease_id'] ?? ''),
                    (string)$intent['lease_id'],
                )
            ) {
                $this->startupPublicEdgeListener = null;
            } else {
                $this->startupGatewayBackendListener = null;
            }
            WindowsListenerHandoff::installCurrentProcessSource(
                $listener,
                $intent,
                $this->startupListenerStateDeadline(),
            );
            $this->startupWindowsListenerHandoffIntent = null;
            return;
        }
        $handoff = $this->validatedPosixStartupListenerHandoff(
            $instanceName,
            (int)($config['port'] ?? 0),
            $gateway,
        );
        if ($handoff === null) {
            return;
        }
        $leaseId = (string)$handoff['lease_id'];
        $listener = null;
        if (\is_array($this->startupPublicEdgeLease)
            && \hash_equals(
                (string)($this->startupPublicEdgeLease['lease_id'] ?? ''),
                $leaseId,
            )
        ) {
            $listener = $this->startupPublicEdgeListener;
            $this->startupPublicEdgeListener = null;
        } elseif (\is_array($this->startupGatewayBackendLease)
            && \hash_equals(
                (string)($this->startupGatewayBackendLease['lease_id'] ?? ''),
                $leaseId,
            )
        ) {
            $listener = $this->startupGatewayBackendListener;
            $this->startupGatewayBackendListener = null;
        }
        if (!\is_resource($listener)) {
            throw new \RuntimeException(
                'Foreground Master startup has no matching retained listener.'
            );
        }
        try {
            DirectSharedListener::installStartupListener(
                $listener,
                (string)$handoff['bind_host'],
                (int)$handoff['port'],
                $leaseId,
            );
        } catch (\Throwable $throwable) {
            @\fclose($listener);
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     */
    private function adoptWindowsStartupListenerFromEndpoint(
        string $instanceName,
        array $endpoint,
        array $gateway,
    ): bool {
        $intent = WindowsListenerHandoff::validatePersistedIntent(
            $instanceName,
            (int)($endpoint['port'] ?? 0),
            $gateway,
        );
        if ($intent === null) {
            return false;
        }
        if (!IS_WIN) {
            throw new \RuntimeException(
                'Windows listener handoff metadata cannot be consumed on POSIX.'
            );
        }
        $launchId = (string)$intent['launch_id'];
        $processName = MasterProcess::getMasterProcessName($instanceName);
        $processIdentity = '--name=' . $processName . ' --launch-id=' . $launchId;
        $masterPid = (int)\getmypid();

        // The parent must know the durable child PID before it can create the
        // target-bound WSAPROTOCOL_INFO envelope. Publish the exact child-owned
        // lease before waiting for that envelope; otherwise the parent waits
        // for this registration while this process waits for the parent.
        Processer::setPid($processIdentity, $masterPid);
        try {
            WindowsListenerHandoff::awaitInstallForMaster(
                $intent,
                $this->startupListenerStateDeadline(),
            );
        } catch (\Throwable $exception) {
            if (!Processer::removeManagedProcessLeaseRecord(
                $masterPid,
                $processName,
                $launchId,
            )) {
                throw new \RuntimeException(
                    'Windows listener handoff failed and its exact early Master lease could not be retired.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
        return true;
    }

    /**
     * Adopt FD 3 only after the immutable endpoint copy and the schema-6 lease
     * envelope agree. Merely having an open descriptor never authorizes it.
     *
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     */
    private function adoptPosixStartupListenerFromEndpoint(
        string $instanceName,
        array $endpoint,
        array $gateway,
    ): bool {
        $handoff = $this->validatedPosixStartupListenerHandoff(
            $instanceName,
            (int)($endpoint['port'] ?? 0),
            $gateway,
        );
        if ($handoff === null) {
            return false;
        }
        if (IS_WIN) {
            throw new \RuntimeException(
                'POSIX inherited-listener metadata cannot be consumed on Windows.'
            );
        }
        $fd = (int)$handoff['fd'];
        $listener = @\fopen('php://fd/' . $fd, 'r+');
        if (!\is_resource($listener)) {
            throw new \RuntimeException(
                'Master-only startup did not inherit the required listener FD.'
            );
        }
        try {
            DirectSharedListener::installStartupListener(
                $listener,
                (string)$handoff['bind_host'],
                (int)$handoff['port'],
                (string)$handoff['lease_id'],
            );
        } catch (\Throwable $throwable) {
            @\fclose($listener);
            throw $throwable;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $gateway
     * @return array<string,mixed>|null
     */
    private function validatedPosixStartupListenerHandoff(
        string $instanceName,
        int $port,
        array $gateway,
    ): ?array {
        $handoff = $gateway['startup_listener_handoff'] ?? null;
        if ($handoff === null) {
            return null;
        }
        if (!\is_array($handoff)
            || (int)($handoff['schema_version'] ?? 0) !== 1
            || !\hash_equals('posix_inherited_fd', (string)($handoff['transport'] ?? ''))
            || ($handoff['continuous_ownership'] ?? false) !== true
            || (int)($handoff['fd'] ?? 0) !== DirectSharedListener::INHERITED_FD
            || (int)($handoff['port'] ?? 0) !== $port
            || \filter_var((string)($handoff['bind_host'] ?? ''), FILTER_VALIDATE_IP) === false
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($handoff['lease_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($handoff['launch_id'] ?? '')) !== 1
            || !\hash_equals(
                (string)($gateway['launch_id'] ?? ''),
                (string)$handoff['launch_id'],
            )
        ) {
            throw new \RuntimeException('Persisted POSIX startup listener handoff is invalid.');
        }
        $matchingLeases = [];
        foreach (['public_lease', 'backend_lease'] as $field) {
            $lease = $gateway[$field] ?? null;
            if (!\is_array($lease) || $lease === []) {
                continue;
            }
            if ((int)($lease['schema_version'] ?? 0)
                    !== GatewayPortLeaseAllocator::SCHEMA_VERSION
                || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                || (int)($lease['port'] ?? 0) !== $port
                || !\hash_equals(
                    (string)($handoff['lease_id'] ?? ''),
                    (string)($lease['lease_id'] ?? ''),
                )
                || !\hash_equals(
                    (string)($handoff['instance'] ?? ''),
                    (string)($lease['instance'] ?? ''),
                )
                || !\hash_equals(
                    (string)($handoff['bind_host'] ?? ''),
                    (string)($lease['bind_host'] ?? ''),
                )
            ) {
                continue;
            }
            $matchingLeases[] = $lease;
        }
        if (\count($matchingLeases) !== 1) {
            throw new \RuntimeException(
                'POSIX startup listener handoff does not match exactly one schema-6 lease.'
            );
        }
        $leaseInstance = (string)($matchingLeases[0]['instance'] ?? '');
        if (!\in_array($leaseInstance, [
            $instanceName,
            \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::forRole(
                $instanceName,
                \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::ROLE_INITIAL_BACKEND,
            ),
        ], true)) {
            throw new \RuntimeException(
                'POSIX startup listener lease belongs to a different WLS instance.'
            );
        }
        return $handoff;
    }

    protected function tryUseStartupCertificateFiles(
        SslCertificateService $sslService,
        string $domain,
        string $syncDomain,
        bool $allowLegacyTrustMutation = true,
    ): ?array {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return null;
        }
        if ($domain === '0.0.0.0') {
            $domain = 'localhost';
        }

        $certDir = $sslService->getCertificateDir($domain);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        if (!\is_file($certPath) || !\is_file($keyPath)) {
            return null;
        }
        // 本地证书除有效期/密钥外还必须能复用当前本地 CA；否则仍交给 ensureCertificate 重签。
        if ($allowLegacyTrustMutation
            && $sslService->needsSelfSignedCertificate($domain)
            && !$sslService->hasValidLocalCertificate($domain)) {
            return null;
        }
        if (!$sslService->canReuseConfiguredCertificate($certPath, $keyPath)) {
            return null;
        }
        if (!$sslService->certificateMatchesHost($certPath, $domain)) {
            return null;
        }

        // 启动数据面只消费已验证文件；ORM 对账和 SNI 映射发布不再阻塞 READY。
        unset($syncDomain);
        $certInfo = $sslService->parseCertificate($certPath);
        return [
            'success' => true,
            'message' => __('使用已有证书'),
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'issuer' => $certInfo['issuer'] ?? __('已有证书'),
            'expires_at' => $certInfo['expires_at'] ?? '',
            'is_new' => false,
            'ssl_enabled' => true,
            'storage_sync_deferred' => true,
        ];
    }

    /**
     * 真实公网 Host 启动前门闸：DNS A/AAAA 必须已经指向当前服务器。
     *
     * 本地开发域名、IP、localhost 等由 SSL 服务现有本地策略处理，不做公网 DNS 校验。
     *
     * @return array{success: bool, skipped?: bool, message?: string, resolved_ips?: list<string>, server_ips?: list<string>}
     */
    protected function validatePublicHostResolvesToCurrentServer(
        string $host,
        SslCertificateService $sslService
    ): array {
        $host = $this->normalizeCertificateDomainCandidate($host);
        if ($host === '' || $this->isWildcardBindHost($host) || $sslService->isLocalDomain($host)) {
            return ['success' => true, 'skipped' => true];
        }

        $resolvedIps = $this->resolvePublicHostIps($host);
        if ($resolvedIps === []) {
            return [
                'success' => false,
                'resolved_ips' => [],
                'server_ips' => [],
                'message' => __('启动已阻止：真实 Host %{1} 尚未解析到 A/AAAA 记录。请先把域名解析到当前服务器 IP 后再启动 WLS。', [$host]),
            ];
        }

        $serverIps = $this->detectCurrentServerIps();
        if ($serverIps === []) {
            return [
                'success' => false,
                'resolved_ips' => $resolvedIps,
                'server_ips' => [],
                'message' => __('启动已阻止：无法确认当前服务器 IP，不能校验真实 Host %{1} 是否指向本机。请配置 app/etc/env.php -> wls.public_ip 后重试。', [$host]),
            ];
        }

        $serverIpSet = [];
        foreach ($serverIps as $serverIp) {
            $serverIpSet[$this->normalizeIpForComparison($serverIp)] = true;
        }
        foreach ($resolvedIps as $resolvedIp) {
            if (isset($serverIpSet[$this->normalizeIpForComparison($resolvedIp)])) {
                return [
                    'success' => true,
                    'resolved_ips' => $resolvedIps,
                    'server_ips' => $serverIps,
                ];
            }
        }

        return [
            'success' => false,
            'resolved_ips' => $resolvedIps,
            'server_ips' => $serverIps,
            'message' => __('启动已阻止：真实 Host %{1} 未解析到当前服务器 IP。当前解析：%{2}；本机 IP：%{3}。请先修正 DNS A/AAAA 后再启动 WLS。', [
                $host,
                \implode(', ', $resolvedIps),
                \implode(', ', $serverIps),
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    protected function resolvePublicHostIps(string $host): array
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return [];
        }

        $ips = [];
        try {
            $records = @\dns_get_record($host, \DNS_A | \DNS_AAAA);
            if (\is_array($records)) {
                foreach ($records as $record) {
                    $ip = \trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
                    if ($this->isValidComparisonIp($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        } catch (\Throwable) {
        }

        if ($ips === []) {
            $v4 = @\gethostbynamel($host);
            if (\is_array($v4)) {
                foreach ($v4 as $ip) {
                    if ($this->isValidComparisonIp((string)$ip)) {
                        $ips[] = (string)$ip;
                    }
                }
            }
        }

        return $this->uniqueIps($ips);
    }

    /**
     * @return list<string>
     */
    protected function detectCurrentServerIps(): array
    {
        $ips = [];
        foreach ([
            Env::get('wls.public_ip'),
            Env::get('wls.public_ipv6'),
            Env::get('server.public_ip'),
            Env::get('server.public_ipv6'),
        ] as $configuredIp) {
            if (\is_scalar($configuredIp) && $this->isValidComparisonIp((string)$configuredIp)) {
                $ips[] = (string)$configuredIp;
            }
        }

        if (\function_exists('swoole_get_local_ip')) {
            try {
                $localIps = \swoole_get_local_ip();
                if (\is_array($localIps)) {
                    foreach ($localIps as $ip) {
                        if ($this->isValidComparisonIp((string)$ip)) {
                            $ips[] = (string)$ip;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $hostname = @\gethostname();
        if (\is_string($hostname) && $hostname !== '') {
            $hostIps = @\gethostbynamel($hostname);
            if (\is_array($hostIps)) {
                foreach ($hostIps as $ip) {
                    if ($this->isValidComparisonIp((string)$ip)) {
                        $ips[] = (string)$ip;
                    }
                }
            }
        }

        if (!$this->hasPublicIp($ips)) {
            foreach ($this->fetchPublicProbeIps(self::PUBLIC_IPV4_PROBE_URLS) as $ip) {
                $ips[] = $ip;
            }
            foreach ($this->fetchPublicProbeIps(self::PUBLIC_IPV6_PROBE_URLS) as $ip) {
                $ips[] = $ip;
            }
        }

        return $this->uniqueIps($ips);
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    protected function fetchPublicProbeIps(array $urls): array
    {
        if (!\function_exists('curl_init')) {
            return [];
        }

        $ips = [];
        foreach ($urls as $url) {
            $ch = \curl_init($url);
            if ($ch === false) {
                continue;
            }
            \curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT_MS => self::PUBLIC_HOST_IP_PROBE_TIMEOUT_MS,
                \CURLOPT_CONNECTTIMEOUT_MS => self::PUBLIC_HOST_IP_PROBE_TIMEOUT_MS,
                \CURLOPT_FOLLOWLOCATION => true,
                \CURLOPT_SSL_VERIFYPEER => true,
                \CURLOPT_USERAGENT => 'Weline-WLS-HostGuard/1.0',
            ]);
            $raw = \curl_exec($ch);
            \curl_close($ch);
            $ip = \trim((string)$raw);
            if ($this->isValidComparisonIp($ip)) {
                $ips[] = $ip;
                break;
            }
        }

        return $this->uniqueIps($ips);
    }

    /**
     * @param list<string> $ips
     */
    private function hasPublicIp(array $ips): bool
    {
        foreach ($ips as $ip) {
            if ($this->isValidComparisonIp($ip)
                && \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isValidComparisonIp(string $ip): bool
    {
        return \filter_var(\trim($ip), \FILTER_VALIDATE_IP) !== false;
    }

    private function normalizeIpForComparison(string $ip): string
    {
        $ip = \trim($ip);
        $packed = @\inet_pton($ip);
        if ($packed !== false) {
            $normalized = @\inet_ntop($packed);
            if (\is_string($normalized) && $normalized !== '') {
                return \strtolower($normalized);
            }
        }

        return \strtolower($ip);
    }

    /**
     * @param list<string> $ips
     * @return list<string>
     */
    private function uniqueIps(array $ips): array
    {
        $out = [];
        foreach ($ips as $ip) {
            $ip = \trim($ip);
            if (!$this->isValidComparisonIp($ip)) {
                continue;
            }
            $out[$this->normalizeIpForComparison($ip)] = $ip;
        }

        return \array_values($out);
    }

    protected function normalizeDefaultPortForSslState(int $port, bool $sslEnabled, bool $portExplicit = false): int
    {
        if (!$portExplicit && $sslEnabled && $port === self::DEFAULT_PORT) {
            return self::DEFAULT_PORT_HTTPS;
        }

        return $port;
    }

    /**
     * 冷启动时若 ACME HTTP-01 因 challenge 404 失败，回退临时自签并记录「启动后重试」。
     *
     * @return array<string,mixed>|null
     */
    protected function tryBuildDeferredStartupSslFallback(
        SslCertificateService $sslService,
        string $domain,
        string $email,
        bool $needsLocalCert,
        string $webroot,
        array $result
    ): ?array {
        if ($needsLocalCert || $webroot === SslCertificateService::WEBROOT_WLS_VIRTUAL) {
            return null;
        }

        $message = (string) ($result['message'] ?? '');
        if (!$this->isAcmeHttp01Challenge404Failure($message)) {
            return null;
        }

        $fallback = $sslService->generateSelfSignedCertificate($domain);
        if (($fallback['success'] ?? false) !== true) {
            return null;
        }

        $this->printer->warning(__('检测到冷启动阶段 ACME HTTP-01 校验失败：%{1}', [$message]));
        $this->printer->note(__('已临时启用自签证书启动实例；待 Dispatcher/Worker 就绪后将自动重试正式证书申请。'));

        return $fallback;
    }

    protected function isAcmeHttp01Challenge404Failure(string $message): bool
    {
        $message = \strtolower(\trim($message));
        if ($message === '') {
            return false;
        }

        return \str_contains($message, '/.well-known/acme-challenge/')
            && \str_contains($message, 'invalid response from http://')
            && \str_contains($message, '404');
    }

    protected function resolveCertificateHost(array $config, string $host): string
    {
        $rawHost = \strtolower(\trim($host));
        if ($rawHost === '') {
            return '127.0.0.1';
        }
        $host = $this->normalizeCertificateDomainCandidate($rawHost);
        if ($host === '') {
            throw new \RuntimeException('Certificate host is invalid after IDNA normalization.');
        }
        if (!$this->isWildcardBindHost($host)) {
            return $host;
        }

        $publicHost = $this->normalizeCertificateDomainCandidate(
            (string)($config['public_host'] ?? ''),
        );
        if ($this->isUsablePublicHost($publicHost)) {
            return $publicHost;
        }

        $defaultProjectHost = $this->normalizeCertificateDomainCandidate($this->getDefaultHost());
        if ($this->isUsablePublicHost($defaultProjectHost)) {
            return $defaultProjectHost;
        }

        return 'localhost';
    }

    /**
     * 为实例配置中的附加 Host 自动准备证书（SaaS 多域名场景）。
     */
    protected function ensureAdditionalSslCertificates(
        string $instanceName,
        array $config,
        string $primaryDomain,
        SslCertificateService $sslService,
        string $selectedCertPath = '',
        string $selectedKeyPath = ''
    ): void {
        if (!$this->isLegacyEdgeRuntimeConfig($config)) {
            return;
        }
        $domains = $this->collectAdditionalCertificateDomains($instanceName, $config, $primaryDomain);
        if ($domains === []) {
            return;
        }

        $webroot = $this->resolveAcmeWebrootForStartup($instanceName, $config);
        $selectedCertificateReusable = $selectedCertPath !== ''
            && $selectedKeyPath !== ''
            && $sslService->canReuseConfiguredCertificate($selectedCertPath, $selectedKeyPath);
        foreach ($domains as $domain) {
            // 当前已选证书（尤其是 *.weline.test）覆盖该 Host 时无需再次 ensure，
            // 避免“复用证书”仍刷新映射并向全部历史实例广播 reload。
            if ($selectedCertificateReusable
                && $sslService->certificateMatchesHost($selectedCertPath, $domain)) {
                continue;
            }

            $startupCertResult = $this->tryUseStartupCertificateFiles($sslService, $domain, $domain);
            if ($startupCertResult !== null) {
                continue;
            }

            $sslService->ensureCertificateStorageReady();
            $email = Env::get('admin_email', 'admin@' . $domain);
            $result = $sslService->ensureCertificate($domain, $webroot, $email);
            if (($result['success'] ?? false) !== true
                && \str_contains((string)($result['message'] ?? ''), '正在申请证书中')) {
                $sslService->forceReleaseSslIssuanceLock($domain);
                SchedulerSystem::sleep(1);
                $result = $sslService->ensureCertificate($domain, $webroot, $email);
            }
            if (($result['success'] ?? false) === true) {
                $issuer = (string)($result['issuer'] ?? __('未知'));
                $this->printer->note(__('附加 Host 证书就绪：%{1}（签发方：%{2}）', [$domain, $issuer]));
            } else {
                $this->printer->warning(__('附加 Host 证书准备失败：%{1} - %{2}', [
                    $domain,
                    (string)($result['message'] ?? __('未知错误')),
                ]));
            }
        }
    }

    /**
     * 收集需要额外签发证书的域名（排除当前主域名）。
     *
     * @return array<int, string>
     */
    protected function collectAdditionalCertificateDomains(string $instanceName, array $config, string $primaryDomain): array
    {
        $envConfig = $this->getEnvConfig();
        $wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $servers = \is_array($wlsConfig['servers'] ?? null) ? $wlsConfig['servers'] : [];
        $instanceConfig = \is_array($servers[$instanceName] ?? null) ? $servers[$instanceName] : [];

        $candidates = [
            (string)($config['public_host'] ?? ''),
            (string)($config['ssl_domain'] ?? ''),
            (string)($instanceConfig['host'] ?? ''),
            (string)($instanceConfig['ssl_domain'] ?? ''),
        ];

        foreach ($instanceConfig as $key => $value) {
            if (\is_int($key) && \is_scalar($value)) {
                $candidates[] = (string)$value;
            }
        }

        $domains = [];
        $primaryKey = $this->normalizeCertificateDomainCandidate($primaryDomain);
        foreach ($candidates as $candidate) {
            $domain = $this->normalizeCertificateDomainCandidate($candidate);
            if ($domain === '' || $domain === $primaryKey || $this->isWildcardBindHost($domain)) {
                continue;
            }
            $domains[$domain] = $domain;
        }

        return \array_values($domains);
    }

    protected function normalizeCertificateDomainCandidate(string $candidate): string
    {
        $candidate = \strtolower(\trim($candidate));
        if ($candidate === '') {
            return '';
        }

        if (\str_starts_with($candidate, 'http://') || \str_starts_with($candidate, 'https://')) {
            $host = (string)\parse_url($candidate, \PHP_URL_HOST);
            $candidate = \strtolower(\trim($host));
        }

        if (\preg_match('/^\[([^\]]+)](?::\d+)?$/', $candidate, $matches)) {
            $candidate = \strtolower(\trim((string)$matches[1]));
        } elseif (\substr_count($candidate, ':') === 1 && !\str_contains($candidate, '::')) {
            $candidate = \strtolower(\trim((string)\explode(':', $candidate, 2)[0]));
        }

        $candidate = \rtrim($candidate, '.');
        $wildcard = \str_starts_with($candidate, '*.');
        $body = $wildcard ? \substr($candidate, 2) : $candidate;
        if ($body !== '' && \filter_var($body, FILTER_VALIDATE_IP) === false) {
            if (!\function_exists('idn_to_ascii')) {
                return \preg_match('/[^\x00-\x7F]/', $body) === 1 ? '' : $candidate;
            }
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, $variant);
            if (!\is_string($ascii) || $ascii === '') {
                return '';
            }
            $body = \strtolower($ascii);
        }

        return $wildcard ? '*.' . $body : $body;
    }

    /**
     * ACME 校验 webroot 选择：
     * - 运行中实例：使用 WLS 虚拟 challenge（不中断服务）
     * - 冷启动实例：回退 PUB webroot（避免没有运行中 WLS 时 challenge 必失败）
     */
    protected function resolveAcmeWebrootForStartup(string $instanceName, array $config): string
    {
        if ($this->isServerRunning($instanceName, (int)($config['port'] ?? self::DEFAULT_PORT_HTTPS))) {
            return SslCertificateService::WEBROOT_WLS_VIRTUAL;
        }

        return \defined('PUB') ? PUB : '';
    }

    /**
     * 统一 SSL 入库域名：回环地址固定归一为 localhost，其它按配置域名/host。
     */
    protected function resolveSslDomainForSync(string $host, string $configuredDomain = ''): string
    {
        $configuredDomain = \strtolower(\trim($configuredDomain));
        if ($configuredDomain !== '') {
            return $configuredDomain;
        }
        $host = \strtolower(\trim($host));
        if ($host === '127.0.0.1' || $host === '::1' || $host === '0.0.0.0') {
            return 'localhost';
        }
        return $host;
    }

    protected function restoreManagedCertificateForConfig(array &$config, SslCertificateService $sslService, string $host): bool
    {
        $candidates = [];
        $configuredDomain = \strtolower(\trim((string) ($config['ssl_domain'] ?? '')));
        if ($configuredDomain !== '') {
            $candidates[] = $configuredDomain;
        }

        $syncDomain = $this->resolveSslDomainForSync($host, $configuredDomain);
        if ($syncDomain !== '' && !\in_array($syncDomain, $candidates, true)) {
            $candidates[] = $syncDomain;
        }

        $sslCertPath = \strtolower(\trim((string) ($config['ssl_cert'] ?? '')));
        if ($sslCertPath !== '') {
            $pathDomain = \basename(\dirname($sslCertPath));
            if ($pathDomain !== '' && $pathDomain !== '.' && $pathDomain !== '..' && !\in_array($pathDomain, $candidates, true)) {
                $candidates[] = $pathDomain;
            }
        }

        foreach ($candidates as $candidate) {
            $reload = $sslService->reloadManagedCertificates($candidate);
            if (($reload['reloaded'] ?? 0) <= 0) {
                continue;
            }

            $certDir = $sslService->getCertificateDir($candidate);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            if (\is_file($certPath) && \is_file($keyPath)) {
                $config['ssl_cert'] = $certPath;
                $config['ssl_key'] = $keyPath;
                $config['ssl_domain'] = $candidate;
                return true;
            }
        }

        return false;
    }
    
    /**
     * 自动检测 app/etc/ssl/ 目录下的 SSL 证书
     * 
     * 目录结构：app/etc/ssl/{domain}/
     *   - fullchain.pem / privkey.pem (Let's Encrypt 格式)
     *   - cert.pem / key.pem
     *   - ssl.crt / ssl.key
     * 
     * 也兼容旧格式：app/etc/ 下直接放置的证书
     */
    protected function autoDetectSslCertificates(): ?array
    {
        $etcDir = \dirname(Env::path_ENV_FILE) . DS;
        $sslDir = $etcDir . 'ssl' . DS;
        
        // 支持的证书文件名格式（按优先级）
        $certFormats = [
            ['cert' => 'fullchain.pem', 'key' => 'privkey.pem'],  // Let's Encrypt 格式
            ['cert' => 'cert.pem', 'key' => 'key.pem'],
            ['cert' => 'ssl.crt', 'key' => 'ssl.key'],
            ['cert' => 'ssl.pem', 'key' => 'ssl.key'],
            ['cert' => 'server.crt', 'key' => 'server.key'],
            ['cert' => 'certificate.crt', 'key' => 'private.key'],
        ];
        
        // 1. 优先检查多域名目录结构：app/etc/ssl/{domain}/
        if (\is_dir($sslDir)) {
            foreach ($this->boundedCertificateDomainDirectories($sslDir) as $domain) {
                $domainDir = $sslDir . $domain . DS;
                foreach ($certFormats as $format) {
                    $certPath = $domainDir . $format['cert'];
                    $keyPath = $domainDir . $format['key'];

                    if (\is_file($certPath) && \is_file($keyPath)
                        && !\is_link($certPath) && !\is_link($keyPath)
                    ) {
                        return [
                            'cert' => $certPath,
                            'key' => $keyPath,
                            'domain' => $domain,
                            'format' => $format['cert'] . ' / ' . $format['key'],
                        ];
                    }
                }
            }
        }
        
        // 2. 兼容旧格式：app/etc/ 下直接放置的证书
        foreach ($certFormats as $format) {
            $certPath = $etcDir . $format['cert'];
            $keyPath = $etcDir . $format['key'];
            
            if (\is_file($certPath) && \is_file($keyPath)
                && !\is_link($certPath) && !\is_link($keyPath)
            ) {
                return [
                    'cert' => $certPath,
                    'key' => $keyPath,
                    'domain' => 'default',
                    'format' => $format['cert'] . ' / ' . $format['key'],
                ];
            }
        }
        
        return null;
    }

    /** @return list<string> */
    private function boundedCertificateDomainDirectories(string $sslDir): array
    {
        $root = @\lstat($sslDir);
        if (!\is_array($root)
            || \is_link($sslDir)
            || (((int)($root['mode'] ?? 0) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS certificate directory is linked or special.');
        }
        $handle = @\opendir($sslDir);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate WLS certificate directory.');
        }
        $domains = [];
        $rawEntries = 0;
        try {
            while (($domain = @\readdir($handle)) !== false) {
                if ($domain === '.' || $domain === '..') {
                    continue;
                }
                if (++$rawEntries > 256) {
                    throw new \RuntimeException(
                        'WLS certificate directory exceeds its bounded entry count.',
                    );
                }
                $directory = $sslDir . $domain;
                $status = @\lstat($directory);
                if (!\is_array($status)
                    || \is_link($directory)
                    || (((int)($status['mode'] ?? 0) & 0170000) !== 0040000)
                ) {
                    continue;
                }
                $domains[] = $domain;
            }
        } finally {
            @\closedir($handle);
        }
        \sort($domains, SORT_STRING);

        return $domains;
    }

    /**
     * 获取默认监听地址
     *
     * 为避免多项目 SSL 证书冲突，使用项目唯一的本地域名。
     * 格式：p{项目哈希前8位}.weline.test 或 p{项目哈希前8位}.weline.localhost
     *
     * @return string
     */
    protected function getDefaultHost(): string
    {
        // 与进程作用域和默认端口共用同一个稳定项目身份。
        $shortHash = \substr(MasterProcess::getProjectIdentityHash(), 0, 8);

        // 生成项目唯一域名（子域名格式更符合 DNS 规范）
        return LocalDomainPolicy::buildProjectHost($shortHash);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getEnvConfig(): array
    {
        $envConfig = Env::getInstance()->getConfig();

        return \is_array($envConfig) ? $envConfig : [];
    }

    protected function shouldUseDefaultHostFallback(string $host): bool
    {
        $host = LocalDomainPolicy::normalizeDomain($host);
        if ($host === '' || $host === '127.0.0.1' || $host === 'localhost') {
            return true;
        }

        return LocalDomainPolicy::isManagedLocalDomain($host)
            || (bool)\preg_match('/^p[0-9a-f]{8}\.weline\.local$/i', $host);
    }

    /**
     * 确保 hosts 文件已配置项目域名
     *
     * @param string $host 域名
     */
    protected function ensureHostsFileConfigured(string $host): void
    {
        if (!LocalDomainPolicy::requiresHostsEntry($host) || $host === 'localhost') {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && (string)\getenv('WLS_AUTO_WRITE_WINDOWS_HOSTS') !== '1') {
            $hostsFile = (string)(\getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\drivers\\etc\\hosts';
            try {
                $content = GatewayProjectStateFilesystem::readOptional(
                    $hostsFile,
                    1_048_576,
                    'Windows hosts file',
                    true,
                ) ?? '';
            } catch (\Throwable) {
                $content = '';
            }
            if ($content !== '' && \preg_match('/^\s*127\.0\.0\.1\s+.*\b' . \preg_quote($host, '/') . '\b/im', $content)) {
                return;
            }

            $this->printer->warning(__('Windows server:start 默认不自动写 hosts，避免 UAC/权限弹窗阻塞启动。'));
            $this->printer->note(__('请按需以管理员身份执行：'));
            $this->printer->note('  php bin/w server:hosts:add ' . $host);
            $this->printer->note(__('或手动添加到 hosts：127.0.0.1 %{1}', [$host]));
            $this->printer->note(__('如需恢复启动时自动写 hosts，可显式设置 WLS_AUTO_WRITE_WINDOWS_HOSTS=1。'));
            return;
        }

        $result = $this->addHostsDomain($host);
        if (!($result['success'] ?? false) && ($result['needs_admin'] ?? false)) {
            $this->printer->note(__(
                'hosts 记录缺失，WLS 将请求一次管理员授权；密码仅由操作系统 sudo 读取并由其会话票据复用。'
            ));
            $result = $this->configureHostsWithAdministratorAuthorization($host);
        }

        if ($result['success'] ?? false) {
            if (($result['repaired'] ?? false) === true) {
                $this->printer->note(__('已将 %{1} 的 hosts 记录纠正为 127.0.0.1', [$host]));
            } elseif (!($result['already_exists'] ?? false)) {
                $this->printer->note(__('已将 %{1} 添加到 hosts 文件', [$host]));
            }
            return;
        }

        if ($result['needs_admin'] ?? false) {
            $this->printer->warning(__('hosts 配置未完成：管理员授权被取消、不可用或未能安全写入。'));
            $this->printer->note(__('请在交互式终端重新执行当前 server:start 命令完成系统授权。'));
            return;
        }

        $this->printer->warning(__('配置 hosts 文件失败: %{1}', [$result['message'] ?? '未知错误']));
    }

    /**
     * @return array<string,mixed>
     */
    protected function addHostsDomain(string $host): array
    {
        return \Weline\Server\Service\HostsFileManager::addDomain($host);
    }

    /**
     * @return array<string,mixed>
     */
    protected function configureHostsWithAdministratorAuthorization(string $host): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'success' => false,
                'needs_admin' => true,
                'message' => 'Interactive POSIX administrator authorization is unavailable.',
            ];
        }

        $phpBinary = @\realpath(PHP_BINARY);
        $editorCandidate = \dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'bin'
            . DIRECTORY_SEPARATOR
            . 'wls_hosts_privileged_editor.php';
        $editor = @\realpath($editorCandidate);
        if (!\is_string($phpBinary)
            || $phpBinary === ''
            || !\is_file($phpBinary)
            || !\is_executable($phpBinary)
            || !\is_string($editor)
            || $editor === ''
            || !\is_file($editor)
            || \is_link($editorCandidate)
        ) {
            return [
                'success' => false,
                'needs_admin' => true,
                'message' => 'The bounded privileged hosts editor is unavailable.',
            ];
        }

        $session = $this->administratorAuthorizationSession
            ??= new \Weline\Server\Service\AdministratorAuthorizationSession();
        if (!$session->runPrivileged([
            $phpBinary,
            '-n',
            $editor,
            '--domain=' . $host,
        ])) {
            return [
                'success' => false,
                'needs_admin' => true,
                'message' => 'Administrator authorization or privileged hosts publication failed.',
            ];
        }

        return $this->addHostsDomain($host);
    }


    /**
     * 开发环境自动准备 *.weline.test 泛域名证书，并确保本地 CA 被系统信任。
     */
    protected function ensureManagedLocalWildcardCertificate(): void
    {
        if (!LocalDomainPolicy::isDevelopmentMode()) {
            return;
        }

        /** @var SslCertificateService $sslService */
        $sslService = $this->deferredCertificatePreparationService
            ?? ObjectManager::getInstance(SslCertificateService::class);
        $wildcard = LocalDomainPolicy::currentWildcardDomain();

        if ($sslService->hasValidLocalCertificate($wildcard)) {
            // ensureCertificate 的“已有证书”分支仍会重建映射并广播；启动阶段已有有效证书时直接复用。
            $this->ensureLocalDevelopmentCaTrusted($sslService);
            return;
        }

        $this->printer->note(__('正在为本地泛域名 %{1} 准备 SSL 证书...', [$wildcard]));
        $email = Env::get('admin_email', 'admin@localhost');
        $result = $sslService->ensureCertificate($wildcard, $this->resolveAcmeWebrootForStartup('default', []), $email);
        if (($result['success'] ?? false) === true) {
            if ($result['is_new'] ?? false) {
                $this->printer->note(__('本地泛域名证书已就绪：%{1}', [$wildcard]));
            }
        } elseif (!empty($result['message'])) {
            $this->printer->warning(__('本地泛域名证书准备失败：%{1}', [(string) $result['message']]));
        }

        $this->ensureLocalDevelopmentCaTrusted($sslService);
    }

    /**
     * 确保 0.0.0.0、127.0.0.1、localhost 以及本次启动的本地域名都有本地 CA 证书。
     * 仅在证书不可被当前本地 CA 复用或证书无效时才生成，避免旧 CA 漂移导致浏览器不信任。
     */
    protected function ensureLocalSelfSignedCertificates(array $config = []): void
    {
        /** @var SslCertificateService $sslService */
        $sslService = $this->deferredCertificatePreparationService
            ?? ObjectManager::getInstance(SslCertificateService::class);
        // 0.0.0.0 只是"监听所有网卡"的绑定地址，不是合法证书 CN，归一为 localhost
        $localDomains = [
            '127.0.0.1' => '127.0.0.1',
            'localhost' => 'localhost',
        ];
        foreach (['host', 'public_host', 'ssl_domain'] as $key) {
            $domain = $this->normalizeCertificateDomainCandidate((string)($config[$key] ?? ''));
            if ($domain === '' || $this->isWildcardBindHost($domain)) {
                continue;
            }
            if ($sslService->needsSelfSignedCertificate($domain)) {
                $localDomains[$domain] = $domain;
            }
        }

        foreach ($localDomains as $localDomain) {
            if ($sslService->hasValidLocalCertificate($localDomain)) {
                continue;
            }
            $result = $sslService->generateLocalCaSignedCertificate($localDomain);
            if (!(bool) ($result['success'] ?? false)) {
                $result = $sslService->generateSelfSignedCertificate($localDomain);
            }
            if ($result['success'] ?? false) {
                $this->printer->note(__('已为 %{1} 生成自签证书', [$localDomain]));
            }
        }

        $this->ensureLocalDevelopmentCaTrusted($sslService);
    }

    protected function ensureLocalDevelopmentCaTrusted(SslCertificateService $sslService): void
    {
        $sslService->setAdministratorAuthorizationSession(
            $this->administratorAuthorizationSession
                ??= new \Weline\Server\Service\AdministratorAuthorizationSession(),
        );
        $trust = $sslService->ensureLocalDevelopmentCaTrusted();
        if (($trust['trusted'] ?? false) !== true && !empty($trust['message'])) {
            $this->printer->warning((string) $trust['message']);
        }
    }

    protected function syncLocalDevelopmentCaTrustForManagedHost(string $publicHost): void
    {
        $publicHost = LocalDomainPolicy::normalizeDomain($publicHost);
        if ($publicHost === '' || !LocalDomainPolicy::isManagedLocalDomain($publicHost)) {
            return;
        }

        $sslService = $this->deferredCertificatePreparationService
            ?? $this->createSslCertificateService(true);
        $this->ensureLocalDevelopmentCaTrusted($sslService);
    }

    /**
     * 生成多域名证书映射文件
     *
     * 扫描 app/etc/ssl/{domain}/ 目录，生成 SNI 证书映射
     */
    protected function generateCertificateMap(): void
    {
        $mapFile = Env::VAR_DIR . 'server' . DS . 'ssl_certificate_map.json';
        
        // 确保目录存在
        $mapDir = \dirname($mapFile);
        if (!\is_dir($mapDir)) {
            @\mkdir($mapDir, 0755, true);
        }
        
        /** @var SslCertificateService $sslService */
        $sslService = $this->deferredCertificatePreparationService
            ?? ObjectManager::getInstance(SslCertificateService::class);
        $sslService->reconcileCertificateFiles();
        $map = $sslService->getCertificateMap();
        
        // 保存映射文件
        \file_put_contents($mapFile, \json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * 计算 Worker 数量（智能模式）
     */
    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        $profile = $this->latestRuntimeProfile ?? $this->detectRuntimeProfile();

        return (new RuntimeStrategyResolver())->resolveWorkerCount(
            $workerCount,
            $mode,
            $this->latestRuntimeStrategy,
            $profile
        );
    }
    
    /**
     * 获取 CPU 核心数
     */
    protected function getCpuCoreCount(): int
    {
        if (IS_WIN) {
            $cores = \trim((string)(\getenv('NUMBER_OF_PROCESSORS') ?: ''));
            return \preg_match('/\A[1-9][0-9]{0,5}\z/D', $cores) === 1
                ? (int)$cores
                : 4;
        }
        
        if (PHP_OS_FAMILY === 'Linux' && \is_executable('/usr/bin/nproc')) {
            try {
                $result = GatewayBoundedCommandRunner::run(['/usr/bin/nproc'], 0.5);
                $cores = \trim((string)($result['output'] ?? ''));
                if ((int)($result['code'] ?? 1) === 0
                    && \preg_match('/\A[1-9][0-9]{0,5}\z/D', $cores) === 1
                ) {
                    return (int)$cores;
                }
            } catch (\Throwable) {
                // CPU discovery is advisory; retain the deterministic fallback.
            }
        }
        if (PHP_OS_FAMILY === 'Darwin' && \is_executable('/usr/sbin/sysctl')) {
            try {
                $result = GatewayBoundedCommandRunner::run(
                    ['/usr/sbin/sysctl', '-n', 'hw.ncpu'],
                    0.5,
                );
                $cores = \trim((string)($result['output'] ?? ''));
                if ((int)($result['code'] ?? 1) === 0
                    && \preg_match('/\A[1-9][0-9]{0,5}\z/D', $cores) === 1
                ) {
                    return (int)$cores;
                }
            } catch (\Throwable) {
                // CPU discovery is advisory; retain the deterministic fallback.
            }
        }
        
        return 4; // 默认 4 核
    }

    protected function detectRuntimeProfile(?string $listenHost = null): WlsRuntimeProfile
    {
        $profileKey = \strtolower(\trim((string)$listenHost));
        if ($this->latestRuntimeProfile !== null && $this->latestRuntimeProfileListenHost === $profileKey) {
            return $this->latestRuntimeProfile;
        }

        $this->latestRuntimeProfileListenHost = $profileKey;
        return $this->latestRuntimeProfile = (new RuntimeCapabilityDetector())->detect($listenHost);
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        // 策略/后台启动 Master 时使用 --instance=name，优先识别
        if (isset($args['instance']) && (string) $args['instance'] !== '') {
            return (string) $args['instance'];
        }
        // 选项值（需要跳过的）
        $optionValues = [];
        $valueOptions = [
            'port',
            'p',
            'host',
            'count',
            'c',
            'worker-memory-limit',
            'worker_memory_limit',
            'worker-memory',
            'worker_memory',
            'dispatcher-memory-limit',
            'dispatcher_memory_limit',
            'dispatcher-memory',
            'dispatcher_memory',
            'session-port',
            'session_port',
            'session-token-file-name',
            'session_token_file_name',
            'memory-port',
            'memory_port',
            'memory-token-file-name',
            'memory_token_file_name',
            'runtime-strategy',
            'runtime_strategy',
            'topology',
            'event-loop',
            'event_loop',
            'loop-driver',
            'loop_driver',
            'supervisor',
            'supervisor-enabled',
            'supervisor_enabled',
        ];
        foreach ($valueOptions as $opt) {
            if (isset($args[$opt])) {
                $optionValues[] = (string) $args[$opt];
            }
        }
        
        // 收集位置参数（排除选项值）
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $strArg = (string) $arg;
                // 排除选项值
                if (!\in_array($strArg, $optionValues, true)) {
                    $positionalArgs[] = $strArg;
                }
            }
        }
        
        \array_shift($positionalArgs); // 移除命令名
        
        $instanceName = $positionalArgs[0] ?? 'default';
        
        // 验证实例名称（不能是纯数字，避免与选项值混淆）
        if (!\preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $instanceName)) {
            // 如果是纯数字，视为无效，使用默认值
            if (\preg_match('/^\d+$/', $instanceName)) {
                return 'default';
            }
            $this->printer->error(__('无效的实例名称：%{1}，只允许字母开头，包含字母、数字、下划线和横线', [$instanceName]));
            exit(1);
        }
        
        return $instanceName;
    }
    
    
    /**
     * 检测可用的进程控制函数
     */
    protected function detectAvailableFunctions(): void
    {
        $this->availableFunctions = [
            'proc_open' => \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open'),
            'proc_close' => \function_exists('proc_close') && !$this->isFunctionDisabled('proc_close'),
            'pcntl_fork' => \function_exists('pcntl_fork') && !$this->isFunctionDisabled('pcntl_fork'),
            'exec' => \function_exists('exec') && !$this->isFunctionDisabled('exec'),
            'popen' => \function_exists('popen') && !$this->isFunctionDisabled('popen'),
            'shell_exec' => \function_exists('shell_exec') && !$this->isFunctionDisabled('shell_exec'),
        ];
    }
    
    /**
     * 检查函数是否被禁用
     */
    protected function isFunctionDisabled(string $function): bool
    {
        $disabled = \explode(',', \ini_get('disable_functions') ?: '');
        $disabled = \array_map('trim', $disabled);
        return \in_array($function, $disabled, true);
    }
    
    /**
     * @param array<string, mixed> $nginx
     * @return list<int>
     */
    private function resolveManagedNginxUpstreamPorts(array $nginx, int $fallbackPort): array
    {
        $ports = $nginx['owner_upstream_ports'] ?? [];
        $ports = \is_array($ports) ? $ports : [];
        if ($ports === []) {
            $ownerPort = (int)($nginx['owner_upstream_port'] ?? 0);
            if ($ownerPort > 0) {
                $ports[] = $ownerPort;
            }
        }
        if ($ports === []) {
            $ports[] = $fallbackPort;
        }

        $resolved = [];
        foreach ($ports as $port) {
            $port = (int)$port;
            if ($port < 1 || $port > 65535 || isset($resolved[$port])) {
                continue;
            }
            $resolved[$port] = $port;
        }

        return \array_values($resolved);
    }

    /**
     * 显示启动信息
     */
    protected function showStartupInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, string $source = '', bool $sslEnabled = false, bool $dispatcherEnabled = false, int $workerPort = 0, int $httpRedirectPort = 0, string $directListenerMode = ''): void
    {
        $this->printer->setup(__('Weline Server'));
        echo "\n";

        $endpoint = $this->readBackgroundStartupData($this->getRuntimeInstanceFile($instanceName));
        $servingProjectionDeadline = self::monotonicSeconds() + 1.0;
        $edgeView = \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::resolve(
            $endpoint,
            false,
            $servingProjectionDeadline,
        );
        $edgeSource = (string)($edgeView['source'] ?? 'unknown');
        $pureWls = \in_array($edgeSource, [
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_PURE_WLS,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS,
        ], true);
        $gatewayRuntime = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $gatewayMode = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY;
        $gatewayPending = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY_PENDING;
        $gatewayCertificatePending = ($gatewayMode || $gatewayPending)
            && (($gatewayRuntime['certificate_pending'] ?? false) === true);
        if (($gatewayPending && !$gatewayCertificatePending) || $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_UNKNOWN
        ) {
            $this->printer->keyValue([
                __('实例名称') => $instanceName,
                __('WLS 私网回源') => 'http://127.0.0.1:' . $port,
                __('公网入口') => $gatewayPending
                    ? (string)__('Gateway 注册/运行时验证中')
                    : (string)__('当前无新鲜的服务投影证明'),
                __('Worker 数') => $count . ' (CPU: ' . $this->getCpuCoreCount() . ')',
                __('运行模式') => $daemon ? __('后台运行（默认）') : __('前台运行'),
                __('平台') => \PHP_OS_FAMILY,
                __('配置来源') => $source ?: __('智能模式'),
            ], '→', 20);
            $this->printer->warning(__(
                '启动意图不作为公网可达证明；请用 server:status 查看最新的 Gateway/WLS 服务投影。'
            ));
            echo "\n";
            $this->showFunctionStatus();
            return;
        }
        $fallbackObservation = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS
                ? \Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection::
                    fallbackServingObservation($endpoint, $servingProjectionDeadline)
                : null;
        if (\is_array($fallbackObservation)
            && $fallbackObservation['authority_host'] === null
        ) {
            $this->printer->keyValue([
                __('实例名称') => $instanceName,
                __('WLS 降级 TLS 监听') => (string)$fallbackObservation['bind_endpoint'],
                __('路由域名/SNI') => \implode(
                    ', ',
                    (array)$fallbackObservation['route_domains'],
                ),
                __('Worker 数') => $count . ' (CPU: ' . $this->getCpuCoreCount() . ')',
                __('公网入口') => (string)__('需要匹配通配符证书的具体 hostname/SNI'),
                __('运行模式') => $daemon ? __('后台运行（默认）') : __('前台运行'),
                __('平台') => \PHP_OS_FAMILY,
                __('配置来源') => $source ?: __('智能模式'),
            ], '→', 20);
            $this->printer->warning(__(
                '监听地址仅表示 bind 端点，不能作为 HTTPS URL；请先配置具体域名、DNS/负载均衡及 SNI。'
            ));
            echo "\n";
            $this->showFunctionStatus();
            return;
        }
        if ($pureWls) {
            if ($edgeSource
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS
            ) {
                $fallbackEndpoint = \Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection::fallbackServingEndpoint(
                    $endpoint,
                    $servingProjectionDeadline,
                );
                if (\is_array($fallbackEndpoint)) {
                    $port = (int)$fallbackEndpoint['port'];
                    $host = (string)$fallbackEndpoint['authority_host'];
                    $sslEnabled = (bool)$fallbackEndpoint['https'];
                }
            }
            $displayHost = $this->isUsablePublicHost($host) ? $host : '127.0.0.1';
            $scheme = $sslEnabled ? 'https' : 'http';
            $defaultPort = $sslEnabled ? 443 : 80;
            $portSuffix = $port === $defaultPort ? '' : ':' . $port;
            $listener = $dispatcherEnabled
                ? 'Dispatcher'
                : (\in_array($directListenerMode, ['shared_fd', 'reuseport'], true)
                    ? $directListenerMode
                    : 'Direct');
            $this->printer->keyValue([
                __('实例名称') => $instanceName,
                ($edgeSource
                    === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_PURE_WLS
                        ? __('纯 WLS 公网入口')
                        : __('WLS 降级/过渡入口'))
                    => $scheme . '://' . $displayHost . $portSuffix . '/',
                __('Worker 数') => $count . ' (CPU: ' . $this->getCpuCoreCount() . ')',
                __('公网协议') => $sslEnabled ? 'HTTP/2 → HTTP/1.1' : 'HTTP/1.1',
                __('内部拓扑') => 'Pure WLS → ' . $listener . ' → Worker',
                __('TLS') => $sslEnabled ? 'TLS 1.3 (PHP Stream SSL)' : (string)__('关闭'),
                __('TLS 会话恢复') => (string)__('跨连接/跨 Worker Reused pending'),
                __('HTTP/3') => (string)__('不可用；需使用托管 Nginx'),
                __('运行模式') => $daemon ? __('后台运行（默认）') : __('前台运行'),
                __('平台') => \PHP_OS_FAMILY,
                __('配置来源') => $source ?: __('智能模式'),
            ], '→', 20);
            echo "\n";
            $this->showFunctionStatus();
            return;
        }

        if ($gatewayMode || $gatewayCertificatePending) {
            $displayHost = $this->isUsablePublicHost($host) ? $host : '127.0.0.1';
            $httpsPort = (int)($gatewayRuntime['public_https'] ?? 0);
            $httpsSuffix = $httpsPort === 443 ? '' : ':' . $httpsPort;
            $gatewayStatus = (new \Weline\Server\Service\Edge\Gateway\GatewayHostManager())->status();
            $gatewayHealthy = (bool)($gatewayStatus['ok'] ?? false)
                && (bool)($gatewayStatus['ready'] ?? false);
            $gatewayProtocols = (bool)($gatewayStatus['h3_enabled'] ?? false)
                ? 'HTTP/3 → HTTP/2 → HTTP/1.1'
                : 'HTTP/2 → HTTP/1.1';
            $workerPort = $workerPort ?: $port;
            if ($dispatcherEnabled) {
                $topology = 'Weline Gateway → Dispatcher ' . $port . ' → Worker '
                    . $workerPort . '-' . ($workerPort + $count - 1);
            } else {
                $listener = \in_array($directListenerMode, ['shared_fd', 'reuseport', 'worker_ports'], true)
                    ? $directListenerMode
                    : 'direct';
                $topology = 'Weline Gateway → Direct ' . $listener . ' → Worker ' . $port;
            }
            $this->printer->keyValue([
                __('实例名称') => $instanceName,
                __('WLS 2.0 网关入口') => $gatewayCertificatePending
                    ? 'PENDING_CERTIFICATE (HTTP-01 challenge only)'
                    : ($httpsPort > 0
                    ? 'https://' . $displayHost . $httpsSuffix . '/'
                    : (string)__('网关公网端点未验证')),
                __('WLS 私网回源') => 'http://127.0.0.1:' . $port,
                __('Worker 数') => $count . ' (CPU: ' . $this->getCpuCoreCount() . ')',
                __('公网协议') => $gatewayCertificatePending
                    ? 'ACME HTTP-01 only'
                    : ($gatewayHealthy ? $gatewayProtocols : (string)__('未验证')),
                __('内部拓扑') => $topology,
                __('TLS') => $gatewayCertificatePending
                    ? 'PENDING_CERTIFICATE (ordinary 443 route unpublished)'
                    : ($gatewayHealthy
                    ? 'TLS 1.3 (Gateway certificate snapshot)'
                    : (string)__('未验证')),
                __('网关状态') => $gatewayCertificatePending
                    ? 'PENDING_CERTIFICATE'
                    : (string)($gatewayStatus['state'] ?? 'CONTROL_DEGRADED'),
                __('网关 epoch') => (string)($gatewayStatus['epoch'] ?? $gatewayRuntime['epoch'] ?? ''),
                __('运行模式') => $daemon ? __('后台运行（默认）') : __('前台运行'),
                __('平台') => \PHP_OS_FAMILY,
                __('配置来源') => $source ?: __('智能模式'),
            ], '→', 20);
            echo "\n";
            $this->showFunctionStatus();
            return;
        }

        $nginx = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv()->doctorSnapshot();
        $ownerActive = (bool)($nginx['runtime_owner_active'] ?? false)
            && \hash_equals(
                $instanceName,
                (string)($nginx['owner_instance'] ?? ''),
            );
        $httpsPort = (int)($nginx['listen_https'] ?? 0);
        $displayHost = $this->isUsablePublicHost($host) ? $host : '127.0.0.1';
        $httpsSuffix = $httpsPort === 443 ? '' : ':' . $httpsPort;
        $publicUrl = $ownerActive && $httpsPort > 0
            ? 'https://' . $displayHost . $httpsSuffix . '/'
            : (string)__('Nginx 公网端点未验证');

        $protocols = [];
        if ($ownerActive && (bool)($nginx['http3_runtime_verified'] ?? false)) {
            $protocols[] = 'HTTP/3';
        }
        if ($ownerActive && (bool)($nginx['http2_runtime_verified'] ?? false)) {
            $protocols[] = 'HTTP/2';
        }
        if ($ownerActive && (bool)($nginx['http1_runtime_verified'] ?? false)) {
            $protocols[] = 'HTTP/1.1';
        }
        $protocolLabel = $protocols !== []
            ? \implode(' → ', $protocols)
            : (string)__('未验证');
        if ($ownerActive
            && (bool)($nginx['http3_configured'] ?? false)
            && !(bool)($nginx['http3_runtime_verified'] ?? false)
        ) {
            $protocolLabel .= '；' . (string)__('HTTP/3 QUIC pending');
        }

        $workerPort = $workerPort ?: $port;
        $upstreamPorts = $this->resolveManagedNginxUpstreamPorts($nginx, $port);
        $upstreamEndpoints = \array_map(
            static fn(int $upstreamPort): string => 'http://127.0.0.1:' . $upstreamPort,
            $upstreamPorts,
        );
        if ($dispatcherEnabled) {
            $topology = 'Nginx → Dispatcher ' . $port . ' → Worker '
                . $workerPort . '-' . ($workerPort + $count - 1);
        } else {
            $listener = \in_array($directListenerMode, ['shared_fd', 'reuseport', 'worker_ports'], true)
                ? $directListenerMode
                : 'direct';
            $workerTargets = \implode(', ', $upstreamPorts);
            if (\count($upstreamPorts) > 1
                && ($upstreamPorts[\count($upstreamPorts) - 1] - $upstreamPorts[0] + 1) === \count($upstreamPorts)
            ) {
                $workerTargets = $upstreamPorts[0] . '-' . $upstreamPorts[\count($upstreamPorts) - 1];
            }
            $topology = 'Nginx → Direct ' . $listener . ' → Worker ' . $workerTargets;
        }
        $sessionParts = [];
        if ($ownerActive && (bool)($nginx['tls_session_resumption_same_worker_runtime_verified'] ?? false)) {
            $sessionParts[] = 'same Worker';
        }
        if ($ownerActive && (bool)($nginx['tls_session_resumption_cross_worker_runtime_verified'] ?? false)) {
            $sessionParts[] = 'cross Worker';
        }
        if ($ownerActive
            && (bool)($nginx['tls_session_resumption_runtime_verified'] ?? false)
            && $sessionParts !== []
        ) {
            $sessionLabel = (string)__('shared cache/tickets；live Reused 已验证：%{1}', [
                \implode(' + ', $sessionParts),
            ]);
        } elseif ($ownerActive
            && (bool)($nginx['tls_session_cache_shared'] ?? false)
            && (bool)($nginx['tls_session_tickets'] ?? false)
        ) {
            $sessionLabel = (string)__('shared cache/tickets 已配置；live Reused pending');
        } else {
            $sessionLabel = (string)__('未配置或未验证');
        }

        $this->printer->keyValue([
            __('实例名称') => $instanceName,
            __('Nginx 公网入口') => $publicUrl,
            __('WLS 私网回源') => \implode(', ', $upstreamEndpoints),
            __('Worker 数') => $count . ' (CPU: ' . $this->getCpuCoreCount() . ')',
            __('公网协议') => $protocolLabel,
            __('内部拓扑') => $topology,
            __('TLS') => $ownerActive && (bool)($nginx['tls13_runtime_verified'] ?? false)
                ? 'TLS 1.3 (Nginx, live verified)'
                : (string)__('未验证'),
            __('TLS 会话恢复') => $sessionLabel,
            __('运行模式') => $daemon ? __('后台运行（默认）') : __('前台运行'),
            __('平台') => \PHP_OS_FAMILY,
            __('配置来源') => $source ?: __('智能模式'),
        ], '→', 20);
        echo "\n";

        $this->showFunctionStatus();
    }
    
    /**
     * 检查特权端口权限，不足时自动使用 sudo 重新执行并触发密码输入。
     *
     * @return bool true=可继续执行；false=当前进程应终止（已交给 sudo 子进程或提示失败）
     */
    protected function preflightDeferredStartupListenerCapability(
        string $host,
        int $port,
        bool $allowLegacyPrivilegeMutation = false,
    ): bool {
        $normalizedHost = $this->normalizeStartupListenerHost($host);
        if ($normalizedHost === null || $port < 1 || $port > 65535) {
            $this->printer->error(__('WLS 延迟端口接管的绑定地址无效。'));
            return false;
        }

        // Binding an ephemeral port on the exact address proves address-family
        // and local-interface availability without contending with the live
        // old generation's endpoint.
        $authority = \str_contains($normalizedHost, ':')
            ? '[' . $normalizedHost . ']'
            : $normalizedHost;
        $probe = @\stream_socket_server(
            'tcp://' . $authority . ':0',
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        if (!\is_resource($probe)) {
            $this->printer->error(__('WLS 端口接管地址能力预检失败：%{1}', [
                $error !== '' ? $error : (string)$errno,
            ]));
            return false;
        }
        @\fclose($probe);

        if (IS_WIN
            || $port >= 1024
            || !\function_exists('posix_geteuid')
            || (int)\posix_geteuid() === 0
        ) {
            return true;
        }

        if (PHP_OS === 'Linux') {
            $unprivilegedStart = $this->readBoundedProcFile(
                '/proc/sys/net/ipv4/ip_unprivileged_port_start',
                64,
            );
            if (\is_string($unprivilegedStart)
                && \ctype_digit(\trim($unprivilegedStart))
                && $port >= (int)\trim($unprivilegedStart)
            ) {
                return true;
            }
            $status = $this->readBoundedProcFile('/proc/self/status', 65_536);
            if (\is_string($status)
                && \preg_match('/^CapEff:\s*([a-fA-F0-9]+)$/m', $status, $match) === 1
            ) {
                $capabilities = \strtolower((string)$match[1]);
                $nibbleOffset = 2; // CAP_NET_BIND_SERVICE is bit 10.
                $index = \strlen($capabilities) - 1 - $nibbleOffset;
                if ($index >= 0
                    && ((int)\hexdec($capabilities[$index]) & (1 << 2)) !== 0
                ) {
                    return true;
                }
            }
        } else {
            // BSD/macOS has no Linux CapEff bitmap. Prove low-port bind
            // authority on a bounded alternative while leaving the live
            // target untouched.
            foreach (\range(1023, 1008) as $probePort) {
                if ($probePort === $port) {
                    continue;
                }
                $privilegedProbe = @\stream_socket_server(
                    'tcp://' . $authority . ':' . $probePort,
                    $probeErrno,
                    $probeError,
                    \STREAM_SERVER_BIND,
                );
                if (\is_resource($privilegedProbe)) {
                    @\fclose($privilegedProbe);
                    return true;
                }
                if ($probeErrno === 13
                    || \stripos((string)$probeError, 'permission') !== false
                    || \stripos((string)$probeError, 'denied') !== false
                ) {
                    break;
                }
            }
        }

        $this->printer->warning(__(
            '旧 WLS 仍在服务；当前 CLI 尚未证明可绑定特权端口 %{1}，不会先停旧代。',
            [$port],
        ));
        if (!$allowLegacyPrivilegeMutation) {
            $this->printer->error(__(
                'WLS 2.0 不会在项目启动期间执行 sudo/setcap；请先由宿主管理员授予端口能力，或使用高位端口。'
            ));
            return false;
        }
        if ($this->trySetcapForPrivilegedPort([$port])) {
            return true;
        }
        return $this->fallbackSudoRelaunch([$port]);
    }

    private function readBoundedProcFile(string $path, int $maximumBytes): string
    {
        if ($maximumBytes < 1
            || !\str_starts_with($path, '/proc/')
            || \is_link($path)
        ) {
            return '';
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return '';
        }
        try {
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
        } finally {
            @\fclose($handle);
        }

        return \is_string($contents) && \strlen($contents) <= $maximumBytes
            ? $contents
            : '';
    }

    protected function ensurePrivilegedPortPermission(
        int $mainPort,
        int $httpRedirectPort,
        bool $sslEnabled,
        bool $allowLegacyPrivilegeMutation = true,
    ): bool
    {
        if (IS_WIN) {
            return true;
        }
        if (!\function_exists('posix_geteuid')) {
            return true;
        }
        if ((int)\posix_geteuid() === 0) {
            return true;
        }

        $privilegedPorts = [];
        if ($mainPort > 0 && $mainPort < 1024) {
            if ($this->currentStartupListenerProofForPort($mainPort) === null) {
                $privilegedPorts[] = $mainPort;
            }
        }
        if ($sslEnabled && $httpRedirectPort > 0 && $httpRedirectPort < 1024) {
            if ($this->currentStartupListenerProofForPort($httpRedirectPort) === null) {
                $privilegedPorts[] = $httpRedirectPort;
            }
        }
        $privilegedPorts = \array_values(\array_unique($privilegedPorts));
        if (empty($privilegedPorts)) {
            return true;
        }

        // 先尝试直接绑定（可能已有 setcap 或 sysctl 配置）
        $testSocket = @\stream_socket_server(
            "tcp://0.0.0.0:{$privilegedPorts[0]}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );
        if ($testSocket) {
            @\fclose($testSocket);
            return true;
        }

        $this->printer->warning(__('检测到特权端口 %{1}，当前用户无法直接绑定。', [\implode(', ', $privilegedPorts)]));

        if (!$allowLegacyPrivilegeMutation) {
            $this->printer->error(__(
                'WLS 2.0 拒绝在项目启动期间执行 sudo/setcap；请预先配置宿主端口权限或使用高位端口。'
            ));
            return false;
        }

        // Linux 优先 setcap（授权后以当前用户运行，避免 root 生成文件导致权限问题）
        if ($this->trySetcapForPrivilegedPort($privilegedPorts)) {
            return true;
        }

        // setcap 不可用/失败，回退到 sudo 重启
        return $this->fallbackSudoRelaunch($privilegedPorts);
    }

    /**
     * 尝试通过 setcap 给 PHP 赋予绑定特权端口的能力，成功后以当前用户继续运行。
     *
     * 优势：不切换到 root，所有生成文件属主保持当前用户，彻底避免 root 文件权限问题。
     */
    protected function trySetcapForPrivilegedPort(array $privilegedPorts): bool
    {
        if (PHP_OS === 'Darwin') {
            // macOS 不支持 setcap
            return false;
        }

        $phpBin = PHP_BINARY;
        $realPhpBin = @\readlink($phpBin) ?: $phpBin;
        if (!\is_file($realPhpBin)) {
            $realPhpBin = $phpBin;
        }

        $setcapBin = \trim((string) @\shell_exec('which setcap 2>/dev/null'));
        if ($setcapBin === '') {
            $this->printer->note(__('未找到 setcap 命令，跳过 setcap 方式。'));
            return false;
        }

        // 检查是否已有 cap_net_bind_service（getcap 检测）
        $getcapBin = \trim((string) @\shell_exec('which getcap 2>/dev/null'));
        if ($getcapBin !== '') {
            $currentCap = \trim((string) @\shell_exec(\escapeshellarg($getcapBin) . ' ' . \escapeshellarg($realPhpBin) . ' 2>/dev/null'));
            if (\stripos($currentCap, 'cap_net_bind_service') !== false) {
                // 已有 setcap，但绑定仍失败（可能 capability 被覆盖或内核限制）
                $this->printer->note(__('PHP 已有 cap_net_bind_service 但绑定仍失败，可能需要重新设置。'));
            }
        }

        $setcapCmd = 'sudo ' . \escapeshellarg($setcapBin) . ' \'cap_net_bind_service=+ep\' ' . \escapeshellarg($realPhpBin);

        $this->printer->note(__('推荐方案：通过 setcap 授权 PHP 绑定特权端口（以当前用户运行，避免 root 文件权限问题）'));
        $this->printer->note(__('  PHP 路径：%{1}', [$realPhpBin]));
        $this->printer->note(__('  将执行：%{1}', [$setcapCmd]));
        echo "\n";
        echo __('是否使用 setcap 授权？[Y/n] ');
        $input = \trim((string) @\fgets(STDIN));
        if ($input !== '' && !\in_array(\strtolower($input), ['y', 'yes', '是', ''], true)) {
            return false;
        }

        $exitCode = 0;
        @\passthru($setcapCmd, $exitCode);
        if ($exitCode !== 0) {
            $this->printer->warning(__('setcap 执行失败（退出码 %{1}），将回退到 sudo 方式。', [(string) $exitCode]));
            return false;
        }

        // 用 getcap 验证 capability 已写入
        if ($getcapBin !== '') {
            $verifyCap = \trim((string) @\shell_exec(\escapeshellarg($getcapBin) . ' ' . \escapeshellarg($realPhpBin) . ' 2>/dev/null'));
            if (\stripos($verifyCap, 'cap_net_bind_service') === false) {
                $this->printer->warning(__('setcap 执行成功但 getcap 未检测到 capability，可能被 SELinux/AppArmor 拦截。'));
                return false;
            }
        }

        // setcap 只对新进程生效，当前进程无法直接获得新 capability，需要以当前用户重新启动
        $this->printer->success(__('setcap 授权成功！capability 已写入 PHP 二进制，以当前用户重新启动服务...'));

        $this->releaseStartLock();

        $rawArgv = $_SERVER['argv'] ?? [];
        if (!\is_array($rawArgv) || empty($rawArgv)) {
            $this->printer->note(__('请手动重新执行：php bin/w server:start ...'));
            return false;
        }
        $parts = \array_merge([PHP_BINARY], $rawArgv);
        $escaped = \array_map('escapeshellarg', $parts);
        $relaunchCommand = \implode(' ', $escaped);

        $relaunchExitCode = 0;
        if (\function_exists('passthru')) {
            @\passthru($relaunchCommand, $relaunchExitCode);
        } elseif (\function_exists('proc_open')) {
            $proc = @\proc_open(
                $relaunchCommand,
                [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                $pipes,
                null,
                null
            );
            if (\is_resource($proc)) {
                $relaunchExitCode = (int) \proc_close($proc);
            } else {
                $relaunchExitCode = -1;
            }
        } else {
            $this->printer->note(__('请手动重新执行：%{1}', [$relaunchCommand]));
            return false;
        }
        if ($relaunchExitCode !== 0) {
            $this->printer->error(__('重启失败，退出码：%{1}', [(string) $relaunchExitCode]));
        }
        // 当前进程应终止，新进程已接管
        return false;
    }

    /**
     * setcap 不可用时，回退到 sudo 重启。
     * 
     * 注意：此方式会以 root 运行，生成的文件属主为 root，可能导致后续权限问题。
     */
    protected function fallbackSudoRelaunch(array $privilegedPorts): bool
    {
        $interactive = $this->isInteractiveTerminal();
        $canPassthru = \function_exists('passthru');
        $canProcOpen = \function_exists('proc_open');

        if (!$interactive && !$canPassthru && !$canProcOpen) {
            $this->printer->error(__('端口 %{1} 需要 root 权限，请使用 sudo 重新执行。', [\implode(', ', $privilegedPorts)]));
            return false;
        }

        $rawArgv = $_SERVER['argv'] ?? [];
        if (!\is_array($rawArgv) || empty($rawArgv)) {
            $this->printer->error(__('无法自动重启为 sudo，请手动执行：sudo php bin/w server:start ...'));
            return false;
        }
        $parts = \array_merge([PHP_BINARY], $rawArgv);
        $escaped = \array_map('escapeshellarg', $parts);
        $relaunchCommand = 'sudo ' . \implode(' ', $escaped);

        $this->printer->warning(__('回退方案：以 sudo (root) 启动。注意：root 进程生成的文件属主为 root，可能导致其他用户权限问题。'));
        $this->printer->note(__('将执行命令：%{1}', [$relaunchCommand]));

        echo __('是否使用 sudo 继续？[Y/n] ');
        $input = \trim((string) @\fgets(STDIN));
        if ($input !== '' && !\in_array(\strtolower($input), ['y', 'yes', '是', ''], true)) {
            $this->printer->note(__('已取消。你可以手动执行：%{1}', [$relaunchCommand]));
            return false;
        }

        $this->releaseStartLock();

        $exitCode = 0;
        if ($canPassthru) {
            @\passthru($relaunchCommand, $exitCode);
        } elseif ($canProcOpen) {
            $proc = @\proc_open(
                $relaunchCommand,
                [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                $pipes,
                null,
                null
            );
            if (\is_resource($proc)) {
                $exitCode = (int) \proc_close($proc);
            } else {
                $exitCode = -1;
            }
        } else {
            $this->printer->error(__('端口 %{1} 需要 root 权限；passthru/proc_open 均不可用，请使用 sudo 重新执行：', [\implode(', ', $privilegedPorts)]));
            $this->printer->note($relaunchCommand);
            return false;
        }
        if ($exitCode !== 0) {
            $this->printer->error(__('sudo 执行失败，退出码：%{1}', [(string) $exitCode]));
        }
        return false;
    }

    /**
     * 检测当前终端是否为交互式
     */
    protected function isInteractiveTerminal(): bool
    {
        if (\defined('STDIN') && \function_exists('posix_isatty') && @\posix_isatty(STDIN)) {
            return true;
        }
        if (@\is_readable('/dev/tty')) {
            return true;
        }
        if (\getenv('TERM')) {
            return true;
        }
        return false;
    }

    /**
     * Linux/macOS 下检测 socket 绑定权限。
     * 
     * 某些情况下即使高端口也可能需要权限：
     * - macOS：防火墙、沙盒、SIP 保护等
     * - Linux：SELinux、AppArmor、容器沙盒等
     * 
     * 此方法在启动前尝试绑定端口，失败时优先 setcap，回退 sudo。
     *
     * @return bool true=可继续执行；false=当前进程应终止
     */
    protected function ensureUnixSocketPermission(
        string $host,
        int $port,
        bool $allowLegacyPrivilegeMutation = true,
    ): bool
    {
        if (IS_WIN) {
            return true;
        }
        if (PHP_OS !== 'Darwin' && PHP_OS !== 'Linux') {
            return true;
        }
        if (\function_exists('posix_geteuid') && (int)\posix_geteuid() === 0) {
            return true;
        }
        if ($this->currentStartupListenerProofForPort($port, $host) !== null) {
            return true;
        }
        
        $normalizedHost = $this->normalizeStartupListenerHost($host);
        if ($normalizedHost === null) {
            $this->printer->error(__('Socket 绑定地址必须是字面 IPv4/IPv6 地址。'));
            return false;
        }
        $authority = \str_contains($normalizedHost, ':')
            ? '[' . $normalizedHost . ']'
            : $normalizedHost;
        $testSocket = @\stream_socket_server(
            "tcp://{$authority}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );
        
        if ($testSocket) {
            @\fclose($testSocket);
            return true;
        }
        
        $isPermissionError = \stripos($errstr, 'permission') !== false 
            || \stripos($errstr, 'denied') !== false
            || $errno === 13;
        
        if (!$isPermissionError) {
            return true;
        }
        
        $platform = PHP_OS === 'Darwin' ? 'macOS' : 'Linux';
        $this->printer->warning(__('%{1} 检测到 socket 权限问题：%{2}', [$platform, $errstr]));
        $this->printer->note(__('端口 %{1} 绑定需要更高权限（可能由防火墙或系统安全设置引起）。', [$port]));

        if (!$allowLegacyPrivilegeMutation) {
            $this->printer->error(__(
                'WLS 2.0 拒绝在项目启动期间执行 sudo/setcap；请由宿主管理员修复 socket 权限。'
            ));
            return false;
        }

        // Linux 优先 setcap
        if ($port < 1024 && $this->trySetcapForPrivilegedPort([$port])) {
            return true;
        }

        // 回退 sudo
        return $this->fallbackSudoRelaunch([$port]);
    }
    
    /**
     * 显示函数状态
     */
    protected function showFunctionStatus(): void
    {
        $status = [];
        $importantFuncs = ['proc_open', 'pcntl_fork', 'exec'];
        
        foreach ($importantFuncs as $func) {
            $available = $this->availableFunctions[$func] ?? false;
            $icon = $available ? '✓' : '✗';
            $status[] = "{$func}: {$icon}";
        }
        
        $this->printer->note(__('进程函数：%{1}', [\implode(' | ', $status)]));
        echo "\n";
    }
    
    /**
     * 检查服务器是否已运行
     * 
     * 检测优先级（快→慢）：
     * 1. Processer 文件映射获取 PID（毫秒级，最快！）
     * 2. 端口检测（服务是否可用，与 server:status 一致）
     * 3. 当前实例 scoped 进程名和端口占用
     * 
     * 注：进程名仅用于判断是否可以安全杀死，不用于存活检测
     */
    /**
     * Fast path for `server:start -r -f`: the user explicitly targets the current
     * instance, so the persisted endpoint record is enough to enter cleanup.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveFastRestartInstanceMetadata(
        string $instanceName,
        int $port,
        bool $forceRestart,
        bool $forceSwitch
    ): ?array {
        if (!$forceRestart || !$forceSwitch) {
            return null;
        }

        $instanceFile = $this->getRuntimeInstanceFile($instanceName);
        try {
            $raw = GatewayProjectStateFilesystem::readOptional(
                $instanceFile,
                2_097_152,
                'WLS fast-restart endpoint',
            );
        } catch (\Throwable) {
            return null;
        }
        if ($raw === null) {
            return null;
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return null;
        }

        $recordName = (string) ($data['instance_name'] ?? $data['name'] ?? '');
        if ($recordName !== '' && $recordName !== $instanceName) {
            return null;
        }

        $recordPort = (int) ($data['main_port'] ?? $data['port'] ?? 0);
        if ($recordPort !== $port) {
            return null;
        }

        if ((int) ($data['master_pid'] ?? $data['pid'] ?? 0) <= 0) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function resolveFastRestartRedirectOccupant(array $metadata, int $redirectPort, string $instanceName): ?string
    {
        return (int) ($metadata['http_redirect_port'] ?? 0) === $redirectPort
            ? $instanceName
            : null;
    }

    protected function isServerRunning(string $instanceName, int $port): bool
    {
        $status = (new IpcControlGateway())->getStatus($instanceName, 0.5);
        return !empty($status['success'])
            && \is_array($status['data'] ?? null)
            && (bool)($status['data']['running'] ?? false);
    }

    /**
     * 包装 {@see Processer::inspectPortOccupantWithHistory()}，便于子类（含测试桩）覆盖。
     *
     * @return array{in_use?:bool,pid?:int,pid_running?:bool,is_weline?:bool,state?:string,pname?:string,scope?:string}
     */
    protected function inspectPortOccupantWithHistory(int $port): array
    {
        return Processer::inspectPortOccupantWithHistory($port);
    }

    /**
     * Authenticate the unique listener retained by this exact Start process.
     *
     * Metadata cannot exempt a port on its own: the durable schema-6 lease,
     * current process birth, retained stream endpoint and kernel listener
     * state must all agree. Any partial/ambiguous claim fails closed before a
     * generic port-release path can kill the startup process itself.
     *
     * @return array{kind:string,lease:array<string,mixed>,listener:resource}|null
     */
    private function currentStartupListenerProofForPort(
        int $port,
        ?string $expectedHost = null,
    ): ?array {
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $instanceName = $this->startupListenerInstanceName;
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            return null;
        }
        $candidates = [
            [
                'kind' => 'public',
                'lease' => $this->startupPublicEdgeLease,
                'listener' => $this->startupPublicEdgeListener,
                'instance' => $instanceName,
            ],
            [
                'kind' => 'gateway_backend',
                'lease' => $this->startupGatewayBackendLease,
                'listener' => $this->startupGatewayBackendListener,
                'instance' =>
                    \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::forRole(
                        $instanceName,
                        \Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity::ROLE_INITIAL_BACKEND,
                    ),
            ],
        ];
        $claims = [];
        foreach ($candidates as $candidate) {
            $lease = $candidate['lease'];
            $listener = $candidate['listener'];
            $leasePort = \is_array($lease) ? (int)($lease['port'] ?? 0) : 0;
            $streamEndpoint = \is_resource($listener)
                ? $this->startupListenerStreamEndpoint($listener)
                : null;
            if ($leasePort === $port
                || (\is_array($streamEndpoint) && $streamEndpoint['port'] === $port)
            ) {
                $candidate['stream_endpoint'] = $streamEndpoint;
                $claims[] = $candidate;
            }
        }
        if ($claims === []) {
            return null;
        }
        if (\count($claims) !== 1) {
            throw new \RuntimeException(
                'Startup listener ownership is ambiguous for port ' . $port . '.',
            );
        }

        $claim = $claims[0];
        $lease = $claim['lease'];
        $listener = $claim['listener'];
        $streamEndpoint = $claim['stream_endpoint'];
        $expectedInstance = (string)$claim['instance'];
        if (!\is_array($lease)
            || !\is_resource($listener)
            || !\is_array($streamEndpoint)
            || (int)($lease['schema_version'] ?? 0)
                !== GatewayPortLeaseAllocator::SCHEMA_VERSION
            || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
            || !\hash_equals($expectedInstance, (string)($lease['instance'] ?? ''))
            || (int)($lease['master_pid'] ?? 0) !== \getmypid()
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $lease['master_process_birth'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($lease['lease_id'] ?? '')) !== 1
            || (int)($lease['port'] ?? 0) !== $port
        ) {
            throw new \RuntimeException(
                'Startup listener claim is missing its exact schema-6 reservation evidence.',
            );
        }
        $leaseHost = $this->normalizeStartupListenerHost((string)(
            $lease['bind_host'] ?? ''
        ));
        $requestedHost = $expectedHost !== null
            ? $this->normalizeStartupListenerHost($expectedHost)
            : null;
        if ($leaseHost === null
            || !\hash_equals($leaseHost, (string)$streamEndpoint['host'])
            || ($requestedHost !== null && !\hash_equals($leaseHost, $requestedHost))
        ) {
            throw new \RuntimeException(
                'Startup listener bind address does not match its reservation.',
            );
        }

        $allocator = new GatewayPortLeaseAllocator(
            operationDeadlineMonotonic: $this->startupListenerStateDeadline(),
        );
        $durable = $allocator->currentReservedLease(
            $expectedInstance,
            (string)$lease['lease_id'],
            $leaseHost,
            $port,
        );
        foreach ([
            'schema_version',
            'state',
            'project_uuid',
            'instance',
            'lease_id',
            'bind_host',
            'port',
            'master_pid',
            'master_process_name',
            'master_process_birth',
        ] as $field) {
            if ((string)($durable[$field] ?? '') !== (string)($lease[$field] ?? '')) {
                throw new \RuntimeException(
                    'Startup listener lease changed after the socket was retained.',
                );
            }
        }
        $this->assertStartupListenerKernelEndpoint(
            $listener,
            $leaseHost,
            $port,
        );

        return [
            'kind' => (string)$claim['kind'],
            'lease' => $durable,
            'listener' => $listener,
        ];
    }

    /** @return array{host:string,port:int}|null */
    private function startupListenerStreamEndpoint(mixed $listener): ?array
    {
        if (!\is_resource($listener)
            || \get_resource_type($listener) !== 'stream'
        ) {
            return null;
        }
        $metadata = @\stream_get_meta_data($listener);
        if (!\is_array($metadata)
            || \stripos((string)($metadata['stream_type'] ?? ''), 'tcp') === false
        ) {
            return null;
        }
        $name = @\stream_socket_get_name($listener, false);
        if (!\is_string($name) || $name === '') {
            return null;
        }
        $separator = \strrpos($name, ':');
        if ($separator === false) {
            return null;
        }
        $host = $this->normalizeStartupListenerHost(\substr($name, 0, $separator));
        $rawPort = \substr($name, $separator + 1);
        if ($host === null || !\ctype_digit($rawPort)) {
            return null;
        }
        $port = (int)$rawPort;
        return $port >= 1 && $port <= 65535
            ? ['host' => $host, 'port' => $port]
            : null;
    }

    private function normalizeStartupListenerHost(string $host): ?string
    {
        $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        $packed = @\inet_pton($host);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        return \is_string($normalized) && $normalized !== ''
            ? \strtolower($normalized)
            : null;
    }

    /** @param resource $listener */
    private function assertStartupListenerKernelEndpoint(
        mixed $listener,
        string $expectedHost,
        int $expectedPort,
    ): void {
        if (!\function_exists('socket_import_stream')) {
            return;
        }
        $socket = @\socket_import_stream($listener);
        if ($socket === false) {
            throw new \RuntimeException(
                'Retained startup stream cannot be authenticated as a socket.',
            );
        }
        $type = @\socket_get_option($socket, SOL_SOCKET, SO_TYPE);
        $accepting = \defined('SO_ACCEPTCONN')
            ? @\socket_get_option($socket, SOL_SOCKET, SO_ACCEPTCONN)
            : 1;
        $actualHost = '';
        $actualPort = 0;
        $named = @\socket_getsockname($socket, $actualHost, $actualPort);
        $actualHost = $this->normalizeStartupListenerHost((string)$actualHost) ?? '';
        if ($type !== SOCK_STREAM
            || (int)$accepting !== 1
            || !$named
            || !\hash_equals($expectedHost, $actualHost)
            || (int)$actualPort !== $expectedPort
        ) {
            throw new \RuntimeException(
                'Retained startup socket is not the expected TCP listening endpoint.',
            );
        }
    }

    /**
     * Startup can test if a port is occupied, but owner reverse lookup is only
     * allowed after occupation is confirmed and a conflict diagnostic is needed.
     *
     * @return array{in_use?:bool,pid?:int,pid_running?:bool,is_weline?:bool,state?:string,pname?:string,scope?:string,skipped?:bool}
     */
    protected function inspectStartupPortIfOccupied(int $port, bool $skipReverseLookup = false): array
    {
        if ($skipReverseLookup || $port <= 0) {
            return ['in_use' => false, 'skipped' => $skipReverseLookup];
        }

        if ($this->currentStartupListenerProofForPort($port) !== null) {
            return ['in_use' => false, 'startup_listener_owned' => true];
        }

        if (!Processer::isPortInUse($port)) {
            return ['in_use' => false];
        }

        return $this->inspectPortOccupantWithHistory($port);
    }

    /**
     * 当前项目的作用域 token（用于跨项目隔离判定）。
     *
     * 抽离为方法以便测试覆盖，正常运行时与 {@see MasterProcess::getProjectScopeToken()} 一致。
     */
    protected function getCurrentProjectScopeToken(): string
    {
        return MasterProcess::getProjectScopeToken();
    }

    /**
     * 显示服务器已运行的提示信息
     */
    protected function showAlreadyRunningInfo(string $instanceName, int $port): void
    {
        echo "\n";
        $this->printer->success(__('服务器实例 [%{1}] 已在运行中（端口 %{2}）', [$instanceName, $port]));

        // 已运行也输出各区域访问地址，避免 `s:start -f`（未带 -r）早退时看不到入口。
        $displayHost = '127.0.0.1';
        $sslEnabled = false;
        try {
            $endpoint = $this->readBackgroundStartupData($this->getRuntimeInstanceFile($instanceName));
            $publicHost = \trim((string)($endpoint['public_host'] ?? ''));
            if ($this->isUsablePublicHost($publicHost)) {
                $displayHost = $publicHost;
            }
            $sslEnabled = (bool)($endpoint['ssl_enabled'] ?? false)
                || \strtolower((string)($endpoint['edge_adapter'] ?? '')) === 'nginx';
            $endpointPort = (int)($endpoint['main_port'] ?? $endpoint['port'] ?? 0);
            if ($endpointPort > 0 && $endpointPort <= 65535) {
                $port = $endpointPort;
            }
        } catch (\Throwable) {
        }
        $this->showUsageInfo($displayHost, $port, $instanceName, $sslEnabled);

        echo "\n";
        $this->printer->setup(__('如需重启该实例：'));
        $this->printer->note('  php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r');
        $this->printer->note('  ' . __('或使用 -r -f 强制切换（不等待请求完成）：'));
        $this->printer->note('  php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r -f');
        echo "\n";

        $this->printer->setup(__('如需启动另一个实例（多实例并行）：'));
        $this->printer->note('  php bin/w server:start <name> -p <port>');
        $this->printer->note('  ' . __('示例：php bin/w server:start api -p %{1}', [$port + 1000]));
        $this->printer->note('  ' . __('首次指定端口后会自动记住，下次只需：php bin/w server:start api'));
        echo "\n";

        $this->printer->setup(__('其他操作：'));
        $this->printer->note('  ' . __('查看所有实例：php bin/w server:status --all'));
        $this->printer->note('  ' . __('停止该实例：php bin/w server:stop') . ($instanceName !== 'default' ? ' ' . $instanceName : ''));
        $this->printer->note('  ' . __('停止所有实例：php bin/w server:stop --all'));
        echo "\n";
    }
    
    /**
     * 停止现有服务器
     *
     * 委托给 server:stop 统一执行：先停 Master，再按进程名杀 Worker/Dispatcher 并清理 PID 文件，
     * 避免重复逻辑与 var/process/pid 残留。
     */
    protected function stopExistingServer(
        string $instanceName,
        int $port,
        int $count,
        bool $fastLocal = false,
        int $workerPort = 0,
        bool $restartCleanup = false
    ): bool
    {
        // endpoint/IPC 可能在 Stop 完成时被删除，因此必须先固化旧代实际监听集。
        // 不记录 Session/Memory 共享 sidecar，它们的生命周期不属于单实例重启交接。
        $this->restartHandoffPorts = $this->captureRestartHandoffPorts(
            $instanceName,
            $port,
            $count,
            $workerPort
        );
        $this->restartHandoffCaptured = true;
        $mainStop = ObjectManager::getInstance(MainStop::class);
        $mainStop->execute($this->buildStopExistingServerArgs($instanceName, $fastLocal, $restartCleanup), []);
        if ($this->waitForRestartCleanupComplete($instanceName, $port, $count, $workerPort, $fastLocal)) {
            return true;
        }

        $this->printer->error(__('旧实例 [%{1}] 未完全停止，已中止本次启动，避免启动第二个同名实例。', [$instanceName]));
        $this->printer->note(__('请先继续执行 `php bin/w server:stop %{1} -f` 或检查残留 WLS 进程后再启动。', [$instanceName]));
        return false;
    }

    /**
     * 在停止旧实例前捕获其实际正在 LISTEN 的主/控制/Dispatcher/Worker/Redirect 端口。
     * 候选值可来自 endpoint 或有界 IPC 服务快照，最终只保留当下真实占用的端口。
     *
     * @return list<int>
     */
    protected function captureRestartHandoffPorts(
        string $instanceName,
        int $mainPort,
        int $workerCount,
        int $workerPort = 0
    ): array {
        $candidates = [$mainPort];
        $rawData = null;
        $instanceManager = $this->getInstanceManager();

        try {
            $rawData = $instanceManager->getRawInstanceData($instanceName);
        } catch (\Throwable) {
            $rawData = null;
        }

        if (\is_array($rawData)) {
            $runtimeSelection = RuntimeSelection::fromEndpoint($rawData);
            foreach (['port', 'main_port', 'control_port', 'dispatcher_port', 'http_redirect_port'] as $field) {
                $candidate = (int)($rawData[$field] ?? 0);
                if ($candidate > 0) {
                    $candidates[] = $candidate;
                }
            }

            $recordedWorkerPort = (int)($rawData['worker_port'] ?? 0);
            $recordedWorkerCount = \max(1, (int)($rawData['count'] ?? $workerCount));
            if ($recordedWorkerPort > 0) {
                $lastOffset = $runtimeSelection->isDirect()
                    && $runtimeSelection->listenerMode !== 'worker_ports'
                        ? 0
                        : $recordedWorkerCount - 1;
                for ($offset = 0; $offset <= $lastOffset; $offset++) {
                    $candidates[] = $recordedWorkerPort + $offset;
                }
            }
        } elseif ($workerPort > 0) {
            for ($offset = 0; $offset < \max(1, $workerCount); $offset++) {
                $candidates[] = $workerPort + $offset;
            }
        }

        try {
            $info = $instanceManager->getInstanceInfoWithIpcTimeout($instanceName, false, 0.4);
        } catch (\Throwable) {
            $info = null;
        }
        if ($info !== null) {
            foreach ([$info->port, $info->controlPort, $info->httpRedirectPort] as $candidate) {
                $candidate = (int)$candidate;
                if ($candidate > 0) {
                    $candidates[] = $candidate;
                }
            }
            foreach ($info->services as $service) {
                $role = (string)($service->role ?? '');
                if (!\in_array($role, ['worker', 'dispatcher', 'redirect', 'http_redirect'], true)) {
                    continue;
                }
                $candidate = (int)($service->port ?? 0);
                if ($candidate > 0) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = \array_values(\array_unique(\array_filter(
            \array_map('intval', $candidates),
            static fn (int $candidate): bool => $candidate > 0
        )));
        \sort($candidates);

        Processer::clearPortCache();
        $listening = [];
        foreach ($candidates as $candidate) {
            if ($this->currentStartupListenerProofForPort($candidate) !== null) {
                continue;
            }
            if (Processer::isPortInUse($candidate)) {
                $listening[] = $candidate;
            }
        }

        return $listening;
    }

    protected function waitForRestartCleanupComplete(
        string $instanceName,
        int $mainPort,
        int $workerCount,
        int $workerPort = 0,
        bool $fastLocal = false
    ): bool
    {
        $timeoutSeconds = $fastLocal
            ? self::FAST_RESTART_CLEANUP_TIMEOUT_SECONDS
            : (IS_WIN ? self::WINDOWS_RESTART_CLEANUP_TIMEOUT_SECONDS : self::RESTART_CLEANUP_TIMEOUT_SECONDS);
        $timeoutNanoseconds = (int)($timeoutSeconds * 1_000_000_000);
        $deadline = \hrtime(true) + $timeoutNanoseconds;
        while (true) {
            Processer::clearPortCache();
            if (!$this->hasRestartCleanupResidue($instanceName, $mainPort, $workerCount, $workerPort, $fastLocal)) {
                return true;
            }

            $remainingNanoseconds = $deadline - \hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }
            $waitMicroseconds = self::boundedNanosecondDeadlineSleepMicroseconds(
                $deadline,
                \hrtime(true),
                100_000,
            );
            if ($waitMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($waitMicroseconds);
        }

        Processer::clearPortCache();
        if (!$this->hasRestartCleanupResidue($instanceName, $mainPort, $workerCount, $workerPort, $fastLocal)) {
            return true;
        }

        $this->reportRestartHandoffTimeout($instanceName);
        return false;
    }

    protected function hasRestartCleanupResidue(
        string $instanceName,
        int $mainPort,
        int $workerCount,
        int $workerPort = 0,
        bool $fastLocal = false
    ): bool
    {
        if (!$this->restartHandoffCaptured) {
            $this->restartHandoffPorts = $this->captureRestartHandoffPorts(
                $instanceName,
                $mainPort,
                $workerCount,
                $workerPort
            );
            $this->restartHandoffCaptured = true;
        }

        // 交接期间任一目标端口仍在 LISTEN 都必须 fail closed。
        // owner/scope 不参与放行判断；仅当 bind 探针证明端口仍可绑定时，
        // 才把 netstat/lsof 的 stale LISTEN 行视为已释放。
        foreach ($this->restartHandoffPorts as $handoffPort) {
            if ($this->isRestartHandoffPortBlocked($handoffPort)) {
                return true;
            }
        }

        // 即使端口已释放，旧 Master/Worker 仍可能在退出窗口内再拉子进程。
        // 只检查本项目+本实例 scoped 前缀，不杀 unknown/foreign 进程。
        foreach ($this->getRestartCleanupProcessPrefixes($instanceName) as $prefix) {
            foreach (Processer::getProcessIdsByPrefix($prefix) as $candidatePid) {
                if ($candidatePid > 0 && Processer::processExists((int)$candidatePid)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isRestartHandoffPortBlocked(int $port): bool
    {
        if ($this->currentStartupListenerProofForPort($port) !== null) {
            return false;
        }
        if (!Processer::isPortInUse($port)) {
            return false;
        }
        if (Processer::isPortFreeByBindProbe($port)) {
            Processer::clearPortCache($port);

            return false;
        }

        return true;
    }

    /**
     * 超时后才查 owner，仅用于诊断；此方法不杀进程、不切换端口、不修改交接结果。
     */
    protected function reportRestartHandoffTimeout(string $instanceName): void
    {
        foreach ($this->restartHandoffPorts as $handoffPort) {
            if ($this->currentStartupListenerProofForPort($handoffPort) !== null) {
                continue;
            }
            if (!Processer::isPortInUse($handoffPort)) {
                continue;
            }

            $inspect = $this->inspectPortOccupantWithHistory($handoffPort);
            $this->printer->warning(__(
                '重启交接超时：端口 %{1} 仍在监听（PID=%{2}，进程=%{3}，作用域=%{4}，状态=%{5}）',
                [
                    $handoffPort,
                    (int)($inspect['pid'] ?? 0),
                    (string)($inspect['process_name'] ?? 'unknown'),
                    (string)($inspect['scope'] ?? 'unknown'),
                    (string)($inspect['state'] ?? 'unknown'),
                ]
            ));
        }

        foreach ($this->getRestartCleanupProcessPrefixes($instanceName) as $prefix) {
            $livePids = [];
            foreach (Processer::getProcessIdsByPrefix($prefix) as $candidatePid) {
                $candidatePid = (int)$candidatePid;
                if ($candidatePid > 0 && Processer::processExists($candidatePid)) {
                    $livePids[] = $candidatePid;
                }
            }
            if ($livePids !== []) {
                $this->printer->warning(__('重启交接超时：scoped 进程前缀 %{1} 仍有 PID %{2}', [
                    $prefix,
                    \implode(',', $livePids),
                ]));
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function getRestartCleanupProcessPrefixes(string $instanceName): array
    {
        return [
            MasterProcess::buildScopedProcessName(MasterProcess::MASTER_PROCESS_NAME_PREFIX, $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName),
            MasterProcess::buildScopedProcessName(MasterProcess::HTTP_REDIRECT_PROCESS_NAME, $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-runtime-watchdog', $instanceName),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function buildStopExistingServerArgs(
        string $instanceName,
        bool $fastLocal = false,
        bool $restartCleanup = false
    ): array
    {
        $args = [0 => 'server:stop', 1 => $instanceName];
        if ($fastLocal) {
            $args['force'] = true;
            $args['f'] = true;
            $args['fast-local'] = true;
        }
        if ($restartCleanup) {
            $args['restart-cleanup'] = true;
        }

        return $args;
    }

    /**
     * Windows can briefly report a LISTENING port whose PID no longer exists.
     * If that port is still recorded in this instance runtime, treat it as a
     * recoverable WLS residue and let server:stop perform the instance cleanup.
     *
     * @param array<int> $ports
     */
    protected function releaseRuntimeRecordedOrphanPorts(string $instanceName, array $ports, string $label = 'Port'): bool
    {
        $recordedPorts = $this->filterRuntimeRecordedPortsForInstance($instanceName, $ports);
        if ($recordedPorts === []) {
            return false;
        }

        $this->printer->warning(__(
            '%{1} 端口 %{2} 与旧实例 [%{3}] 运行态匹配，执行本实例残留清理...',
            [$label, \implode(', ', $recordedPorts), $instanceName]
        ));

        $mainStop = ObjectManager::getInstance(MainStop::class);
        $mainStop->execute($this->buildStopExistingServerArgs($instanceName, true), []);

        return $this->waitForSpecificPortsReleased($recordedPorts, 15.0);
    }

    /**
     * @param array<int> $ports
     * @return list<int>
     */
    protected function filterRuntimeRecordedPortsForInstance(string $instanceName, array $ports): array
    {
        $recordedLookup = \array_fill_keys($this->collectRuntimeRecordedPortsForInstance($instanceName), true);
        if ($recordedLookup === []) {
            return [];
        }

        return \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0 && isset($recordedLookup[$port])
        )));
    }

    /**
     * @return list<int>
     */
    protected function collectRuntimeRecordedPortsForInstance(string $instanceName): array
    {
        $ports = [];

        try {
            $info = $this->getInstanceManager()->getInstanceInfo($instanceName, false);
        } catch (\Throwable) {
            $info = null;
        }

        if ($info !== null) {
            foreach ([$info->port, $info->controlPort, $info->httpRedirectPort, $info->workerBasePort] as $port) {
                $port = (int) $port;
                if ($port > 0) {
                    $ports[] = $port;
                }
            }

            $workerCount = \max(1, (int) $info->workerCount);
            if ($info->workerBasePort > 0 && $workerCount > 1) {
                for ($offset = 1; $offset < $workerCount; $offset++) {
                    $ports[] = $info->workerBasePort + $offset;
                }
            }

        }

        $ports = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            fn (int $port): bool => $port > 0
                && $this->currentStartupListenerProofForPort($port) === null
        )));
        \sort($ports);

        return $ports;
    }

    /**
     * @param array<int> $ports
     */
    protected function waitForSpecificPortsReleased(array $ports, float $timeoutSeconds = 12.0): bool
    {
        $ports = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ports),
            static fn (int $port): bool => $port > 0
        )));
        if ($ports === []) {
            return true;
        }

        $deadline = self::monotonicSeconds() + \max(0.1, $timeoutSeconds);
        do {
            Processer::clearPortCache();
            $remaining = [];
            foreach ($ports as $port) {
                if ($this->currentStartupListenerProofForPort($port) !== null) {
                    continue;
                }
                if (Processer::isPortInUse($port)) {
                    $remaining[] = $port;
                }
            }
            if ($remaining === []) {
                return true;
            }
            $waitMicroseconds = self::boundedMonotonicDeadlineSleepMicroseconds(
                $deadline,
                self::monotonicSeconds(),
                300_000,
            );
            if ($waitMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($waitMicroseconds);
        } while (self::monotonicSeconds() < $deadline);

        Processer::clearPortCache();
        foreach ($ports as $port) {
            if ($this->currentStartupListenerProofForPort($port) !== null) {
                continue;
            }
            if (Processer::isPortInUse($port)) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * 检查并释放端口
     * 
     * 注意：只杀框架进程（通过 --name=weline-xxx 识别），非框架进程不乱杀，提示用户手动处理。
     */
    /**
     * 检查并释放单个端口
     * 框架进程占用时最多尝试 3 次；仍杀不死则按 Master 前缀清理逃逸 Master 后再试一次。
     *
     * @param string $instanceName 实例名，用于按前缀清理逃逸 Master
     */
    protected function checkAndReleasePort(string $host, int $port, bool $forceRelease = false, string $label = 'Port', string $instanceName = 'default'): bool
    {
        $this->printer->note(__('检查 %{1} 端口 %{2} 可用性...', [$label, $port]));

        if ($this->currentStartupListenerProofForPort($port, $host) !== null) {
            $this->printer->success(__('%{1} 端口 %{2} 已由当前启动事务预留 ✓', [
                $label,
                $port,
            ]));
            return true;
        }
        
        if (!Processer::isPortInUse($port)) {
            $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
            return true;
        }

        $portInspect = Processer::inspectPortOccupantWithHistory($port);
        $isWelineProcess = (bool) ($portInspect['is_weline'] ?? false);
        if (!$forceRelease) {
            $this->printer->error(__('%{1} 端口 %{2} 已被占用', [$label, $port]));
            $this->printer->note(__('使用 -r 参数强制重启（仅杀框架进程），或手动停止占用该端口的进程'));
            $this->printer->note(__('或使用: php bin/w server:kill-port %{1} -f', [$port]));
            return false;
        }
        if (!$isWelineProcess) {
            $this->printer->error(__('%{1} 端口 %{2} 的 owner 无法由凭据出生身份证明，不予终止', [
                $label,
                $port,
            ]));
            $this->printer->note(__('请手动停止占用该端口的进程，或更换端口'));
            return false;
        }

        $this->printer->warning(__('%{1} 端口 %{2} 由 WLS 记录占用；正在通过实例 IPC 与凭据出生身份退役。', [
            $label,
            $port,
        ]));
        if (!$this->cleanupFailedStartupProcesses($instanceName, 16)) {
            $this->printer->error(__(
                '实例 [%{1}] 无法证明旧代进程全部退出；已拒绝按端口或前缀强杀。',
                [$instanceName],
            ));
            return false;
        }
        Processer::clearPortCache();
        if (!Processer::isPortInUse($port)) {
            $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
            return true;
        }

        $this->printer->error(__('无法释放 %{1} 端口 %{2}', [$label, $port]));
        $this->printer->note(__('请检查当前 owner 并手动处理；WLS 2.0 不会从端口反推终止权限。'));
        return false;
    }

    /**
     * 检查并释放多个端口（Worker 端口）
     * 框架进程占用时最多尝试 3 轮；仍杀不死则按 Master 前缀清理逃逸 Master 后再试一轮。
     *
     * @param string $instanceName 实例名，用于按前缀清理逃逸 Master
     */
    protected function checkAndReleasePorts(string $host, int $port, int $count, bool $forceRelease = false, string $instanceName = 'default'): bool
    {
        $this->printer->note(__('检查 Worker 端口可用性...'));
        
        $portsInUse = [];
        for ($i = 0; $i < $count; $i++) {
            $currentPort = $port + $i;
            if ($this->currentStartupListenerProofForPort($currentPort, $host) !== null) {
                continue;
            }
            if (Processer::isPortInUse($currentPort)) {
                $portsInUse[] = $currentPort;
            }
        }
        if (empty($portsInUse)) {
            $this->printer->success(__('端口检查通过'));
            echo "\n";
            return true;
        }

        if (!$forceRelease) {
            $this->printer->error(__('端口 %{1} 已被占用', [$portsInUse[0]]));
            $this->printer->note(__('使用 -r 参数强制重启（仅杀框架进程），或手动停止占用该端口的进程'));
            return false;
        }
        foreach ($portsInUse as $p) {
            $portInspect = Processer::inspectPortOccupantWithHistory($p);
            if (!($portInspect['is_weline'] ?? false)) {
                $this->printer->error(__('端口 %{1} 的 owner 无法由凭据出生身份证明，不予终止', [$p]));
                $this->printer->note(__('请手动停止占用该端口的进程，或更换端口'));
                return false;
            }
        }

        $portsInUse = \array_values(\array_filter(
            $portsInUse,
            static fn (int $p): bool => Processer::isPortInUse($p)
        ));
        if (empty($portsInUse)) {
            $this->printer->success(__('端口检查通过'));
            echo "\n";
            return true;
        }

        $this->printer->warning(__(
            '当前 Worker 端口由 WLS 记录占用；正在通过实例 IPC 与凭据出生身份退役。'
        ));
        if (!$this->cleanupFailedStartupProcesses($instanceName, $count)) {
            $this->printer->error(__(
                '实例 [%{1}] 无法证明旧代进程全部退出；已拒绝按端口或前缀强杀。',
                [$instanceName],
            ));
            return false;
        }
        Processer::clearPortCache();
        $stillInUse = [];
        foreach ($portsInUse as $p) {
            if (Processer::isPortInUse($p)) {
                $stillInUse[] = $p;
            }
        }
        if (empty($stillInUse)) {
            $this->printer->success(__('端口检查通过'));
            echo "\n";
            return true;
        }

        $this->printer->error(__('无法释放端口 %{1}', [$stillInUse[0]]));
        $this->printer->note(__('请尝试: php bin/w server:kill-port %{1} -f', [$stillInUse[0]]));
        return false;
    }

    /**
     * @param array<string, mixed> $portInspect
     */
    protected function shouldAutoReleaseHttpRedirectPortOccupant(array $portInspect): bool
    {
        return (bool) ($portInspect['is_weline'] ?? false);
    }

    /**
     * @param array<string, mixed> $portInspect
     */
    protected function isFrameworkOwnedHttpRedirectPortOccupant(array $portInspect, ?string $resolvedOwner = null): bool
    {
        return (bool) ($portInspect['is_weline'] ?? false)
            || ($resolvedOwner !== null && $resolvedOwner !== '');
    }

    protected function releaseFrameworkOwnedHttpRedirectPort(string $host, int $port, string $instanceName = 'default'): bool
    {
        return $this->checkAndReleasePort($host, $port, true, 'HTTP Redirect', $instanceName);
    }

    /**
     * 解析端口占用进程展示名，尽量避免提示“未知进程”。
     *
     * @param array<string, mixed> $portInspect
     */
    protected function resolvePortOccupantDisplayName(array $portInspect, string $instanceName = 'default'): string
    {
        $processName = \trim((string) ($portInspect['process_name'] ?? ''));
        if ($processName !== '' && $processName !== 'unknown') {
            return $processName;
        }

        $pid = (int) ($portInspect['pid'] ?? 0);
        if ($pid > 0) {
            $record = Processer::getProcessRecordByPid($pid);
            $recordName = \trim((string) ($record['process_name'] ?? $record['pname'] ?? ''));
            if ($recordName !== '' && $recordName !== 'unknown') {
                return $recordName;
            }
        }

        if ((bool) ($portInspect['is_weline'] ?? false)) {
            return (string) __('WLS 进程（实例 %{1}）', [$instanceName]);
        }

        if ($pid > 0) {
            $sysInfo = Processer::getProcessInfo($pid);
            $imageName = \trim((string) ($sysInfo['name'] ?? ''));
            if (($sysInfo['exists'] ?? false) && $imageName !== '') {
                return $imageName;
            }
            return (string) __('PID %{1}', [$pid]);
        }

        return (string) __('未知进程');
    }

    /**
     * 查找 Dispatcher 模式下可用的 Worker 连续端口段（仅跳过非框架占用）
     */
    protected function findAvailableWorkerPortBase(
        int $startPort,
        int $count,
        int $maxScan = 500,
        ?string $ignoreInstanceName = null,
        array $extraReservedPorts = [],
    ): int
    {
        $reservedPorts = $this->getReservedWorkerPortsFromOtherInstances($ignoreInstanceName);
        $reservedPortLookup = [];
        foreach ($reservedPorts as $reservedPort) {
            $reservedPortLookup[$reservedPort] = true;
        }
        foreach ($extraReservedPorts as $reservedPort) {
            $reservedPortLookup[(int) $reservedPort] = true;
        }

        $base = $this->normalizePrivateWorkerPortBase(
            \max($startPort, 1),
            0,
            $count,
        );
        for ($attempt = 0; $attempt < $maxScan; $attempt++, $base++) {
            $hasConflict = false;
            foreach ($this->buildWorkerAllocationCandidatePorts(
                $base,
                $count,
            ) as $port) {
                if ($this->isWorkerPortAllocated($port) || isset($reservedPortLookup[$port])) {
                    $hasConflict = true;
                    break;
                }
            }
            if (!$hasConflict) {
                return $base;
            }
        }
        throw new \RuntimeException(
            'Unable to allocate a private Worker port range outside the system ephemeral port space.'
        );
    }

    /**
     * @return list<int>
     */
    protected function buildWorkerAllocationCandidatePorts(
        int $workerPort,
        int $count,
    ): array
    {
        if ($workerPort <= 0) {
            return [];
        }

        $count = \max(1, $count);
        $ports = \range($workerPort, $workerPort + $count - 1);

        // Dispatcher topology uses one temporary maintenance responder during
        // startup/reload; reserve exactly the port the Provider can bind.
        $maintenanceCount = 1;
        $maintenancePort = $workerPort + $count + 99;
        for ($i = 0; $i < $maintenanceCount; $i++) {
            $ports[] = $maintenancePort + $i;
        }

        return \array_values(\array_filter(
            \array_unique($ports),
            static fn (int $port): bool => $port > 0 && $port <= 65535,
        ));
    }

    protected function resolveInitialWorkerPort(
        int $mainPort,
        int $workerBasePort,
        int $workerCount,
        bool $dispatcherEnabled,
        bool $useDirectMode,
        bool $usesWorkerPorts = false,
    ): int
    {
        if ($dispatcherEnabled || $usesWorkerPorts) {
            return $this->normalizePrivateWorkerPortBase(
                $workerBasePort + $mainPort,
                $mainPort,
                $workerCount,
            );
        }

        if ($workerCount <= 1 || $useDirectMode) {
            return $mainPort;
        }

        return $this->normalizePrivateWorkerPortBase(
            $workerBasePort + $mainPort,
            $mainPort,
            $workerCount,
        );
    }

    protected function normalizePrivateWorkerPortBase(
        int $candidate,
        int $mainPort,
        int $workerCount,
    ): int {
        if (!$this->workerPortPlanTouchesEphemeralRange(
            $candidate,
            $workerCount,
        )) {
            return $candidate;
        }

        // The raw candidate already contains the project offset, so it is a
        // stable cross-project seed without bootstrapping framework Env here.
        $hash = (int)\sprintf(
            '%u',
            \crc32($candidate . ':' . $mainPort . ':' . $workerCount),
        );
        $base = self::PRIVATE_WORKER_PORT_MIN + ($hash % self::PRIVATE_WORKER_PORT_SPAN);

        for ($attempt = 0; $attempt < self::PRIVATE_WORKER_PORT_SPAN; $attempt++, $base++) {
            if ($base >= self::PRIVATE_WORKER_PORT_MIN + self::PRIVATE_WORKER_PORT_SPAN) {
                $base = self::PRIVATE_WORKER_PORT_MIN;
            }
            $ports = $this->buildWorkerAllocationCandidatePorts(
                $base,
                $workerCount,
            );
            if (!$this->workerPortPlanTouchesEphemeralRange(
                $base,
                $workerCount,
            ) && ($mainPort <= 0 || !\in_array($mainPort, $ports, true))) {
                return $base;
            }
        }

        throw new \RuntimeException(
            'Unable to derive a deterministic private Worker port range outside the system ephemeral port space.'
        );
    }

    protected function workerPortPlanTouchesEphemeralRange(
        int $base,
        int $workerCount,
    ): bool {
        $ports = $this->buildWorkerAllocationCandidatePorts(
            $base,
            $workerCount,
        );
        if ($base < 1024 || $ports === []) {
            return true;
        }

        foreach ($ports as $port) {
            if ($port >= self::CONSERVATIVE_EPHEMERAL_PORT_START) {
                return true;
            }
        }

        return false;
    }

    protected function findAvailableMainPort(int $startPort, int $maxScan = 200): int
    {
        $port = \max($startPort, 1);
        for ($attempt = 0; $attempt < $maxScan; $attempt++, $port++) {
            if (!Processer::isPortInUse($port)) {
                return $port;
            }
        }

        return $startPort > 0 ? $startPort - 1 : 0;
    }
    
    /**
     * @return list<int>
     */
    protected function getWorkerAllocationReservedPorts(int $mainPort, bool $dispatcherEnabled): array
    {
        if (!$dispatcherEnabled) {
            return [];
        }

        $reserved = $mainPort > 0 ? [$mainPort] : [];
        $controlPort = $this->resolvePreferredControlPort($mainPort);
        if ($controlPort > 0) {
            $reserved[] = $controlPort;
        }

        return \array_values(\array_unique($reserved));
    }

    protected function resolvePreferredControlPort(int $mainPort): int
    {
        $configuredControlPort = (int) (Env::get('server.control_port', 0) ?? 0);
        if ($configuredControlPort > 0) {
            return $configuredControlPort;
        }

        $projectOffset = MasterProcess::getProjectPortOffset();
        $legacyCandidate = 20000 + $mainPort + $projectOffset;
        if ($legacyCandidate <= 65535 && $mainPort < 20000) {
            return $legacyCandidate;
        }

        // WLS 2.0 pure-edge fallback ports live in 20000-29999. Keep the
        // control plane in a disjoint, bounded range instead of overflowing
        // 65535 with the historical arithmetic formula.
        return 30000 + (($mainPort + $projectOffset) % 10000);
    }

    protected function isWorkerPortAllocated(int $port): bool
    {
        return $port > 0 && Processer::isPortInUse($port);
    }

    /**
     * @return list<int>
     */
    protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
    {
        $reservedPortLookup = [];
        foreach ((new GatewayProjectEndpointReader($this->getInstanceManager()))->all(
            $this->startupListenerStateDeadline(),
        ) as $instanceName => $data) {
            if ($ignoreInstanceName !== null && $instanceName === $ignoreInstanceName) {
                continue;
            }
            $instanceFile = $this->getRuntimeInstanceFile($instanceName);

            if (!$this->isWorkerPortReservationActive($data, $instanceFile)) {
                continue;
            }

            try {
                $instanceReservedPorts = $this->extractReservedWorkerPortsFromInstanceData($data);
            } catch (\Throwable $exception) {
                // A recent, interrupted legacy start may leave an endpoint
                // record that is not valid schema v4. Never reinterpret its
                // removed topology fields, but conservatively fence the raw
                // Worker range until the short reservation TTL expires.
                $instanceReservedPorts = $this->extractConservativeWorkerPortReservation($data);
                \w_log_warning(__(
                    'WLS 跳过无效实例拓扑元数据并保守预留 Worker 端口：%{1}（%{2}）',
                    [\basename($instanceFile), $exception->getMessage()],
                ));
            }

            foreach ($instanceReservedPorts as $reservedPort) {
                $reservedPortLookup[$reservedPort] = true;
            }
        }

        return \array_map('intval', \array_keys($reservedPortLookup));
    }

    protected function getInstanceRuntimeDir(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'instances' . DS;
    }

    protected function getRuntimeInstanceFile(string $instanceName): string
    {
        return $this->getInstanceRuntimeDir() . $instanceName . '.json';
    }

    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }

    protected function getMasterLeaseManager(): MasterLeaseManager
    {
        return $this->masterLeaseManager ??= new MasterLeaseManager(
            $this->getMasterLeaseRuntimeIdentity(),
        );
    }

    protected function getMasterLeaseRuntimeIdentity(): MasterLeaseRuntimeIdentity
    {
        return $this->masterLeaseRuntimeIdentity ??= new MasterLeaseRuntimeIdentity();
    }

    protected function getMasterLeasePathForInstance(string $instanceName): string
    {
        return MasterLeaseManager::pathForInstance($instanceName);
    }

    protected function isWorkerPortReservationActive(array $instanceData, string $instanceFile = ''): bool
    {
        if ((int)($instanceData['worker_port'] ?? 0) <= 0) {
            return false;
        }

        $instanceName = \trim((string)($instanceData['instance_name'] ?? $instanceData['name'] ?? ''));
        if ($instanceName === '' && $instanceFile !== '') {
            $instanceName = \basename($instanceFile, '.json');
        }
        if (\preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instanceName) !== 1
            || ($instanceFile !== ''
                && !\hash_equals(\basename($instanceFile, '.json'), $instanceName))
        ) {
            return false;
        }

        $masterPid = (int)($instanceData['master_pid'] ?? 0);
        if ($masterPid > 0) {
            $masterEpoch = (int)($instanceData['master_epoch'] ?? 0);
            $controlPort = (int)($instanceData['control_port'] ?? 0);
            if ($masterEpoch <= 0 || $controlPort <= 0) {
                return false;
            }
            $validation = $this->getMasterLeaseManager()->validateRunningLease(
                $this->getMasterLeasePathForInstance($instanceName),
                expectedInstance: $instanceName,
                expectedMasterPid: $masterPid,
                expectedEpoch: $masterEpoch,
                expectedControlPort: $controlPort,
                requireManagedName: true,
            );

            return ($validation['authorized'] ?? false) === true
                || (($validation['veto'] ?? false) === true
                    && ($validation['foreign_pid_namespace'] ?? false) === true);
        }

        return $this->isProtectedStartupWorkerPortReservationActive(
            $instanceName,
            $instanceData,
        );
    }

    /** @param array<string,mixed> $instanceData */
    protected function isProtectedStartupWorkerPortReservationActive(
        string $instanceName,
        array $instanceData,
    ): bool
    {
        if ((string)($instanceData['startup_phase'] ?? '') !== 'bootstrapping'
            || (string)($instanceData['lifecycle_state'] ?? '') !== 'starting'
        ) {
            return false;
        }
        $startupPid = (int)($instanceData['pid'] ?? 0);
        $startedMonotonic = $instanceData['started_monotonic'] ?? null;
        $hostBootId = (string)($instanceData['startup_host_boot_id'] ?? '');
        $processBirth = (string)($instanceData['startup_process_birth'] ?? '');
        $pidNamespaceId = (string)($instanceData['startup_pid_namespace_id'] ?? '');
        if ($startupPid <= 0
            || (!\is_int($startedMonotonic) && !\is_float($startedMonotonic))
            || !\is_finite((float)$startedMonotonic)
            || (float)$startedMonotonic <= 0.0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $processBirth) !== 1
        ) {
            return false;
        }
        $identity = $this->getMasterLeaseRuntimeIdentity();
        if (!\hash_equals($identity->hostBootId(), $hostBootId)) {
            return false;
        }
        $now = $identity->monotonicNow();
        $age = $now - (float)$startedMonotonic;
        if ($age < 0.0 || $age > self::WORKER_PORT_RESERVATION_TTL) {
            return false;
        }
        $owner = [
            'instance' => $instanceName,
            'master_pid' => $startupPid,
            'master_process_birth' => $processBirth,
            'pid_namespace_id' => $pidNamespaceId,
        ];
        $ownerStatus = $identity->observeOwner($owner, false);
        if ($ownerStatus !== MasterLeaseRuntimeIdentity::OWNER_MATCH
            && !($ownerStatus === MasterLeaseRuntimeIdentity::OWNER_UNKNOWN
                && $identity->ownerIsOutsideCurrentPidNamespace($owner))
        ) {
            return false;
        }

        return $this->isStartLockHeldBy($instanceName, $startupPid);
    }

    protected function getStartLockFileForInstance(string $instanceName): string
    {
        return Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $instanceName . '.lock';
    }

    protected function isStartLockHeldBy(string $instanceName, int $expectedPid): bool
    {
        $path = $this->getStartLockFileForInstance($instanceName);
        $before = @\lstat($path);
        if (!\is_array($before)
            || (((int)($before['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)($before['size'] ?? -1) > 4096
        ) {
            return false;
        }
        $handle = @\fopen($path, 'r+b');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened)
                || (int)($before['dev'] ?? -1) !== (int)($opened['dev'] ?? -2)
                || (int)($before['ino'] ?? -1) !== (int)($opened['ino'] ?? -2)
            ) {
                return false;
            }
            $raw = @\stream_get_contents($handle, 4097);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_string($raw)
                || \strlen($raw) > 4096
                || !\is_array($after)
                || !\is_array($pathAfter)
                || (int)($opened['dev'] ?? -1) !== (int)($after['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($after['ino'] ?? -2)
                || (int)($after['dev'] ?? -1) !== (int)($pathAfter['dev'] ?? -2)
                || (int)($after['ino'] ?? -1) !== (int)($pathAfter['ino'] ?? -2)
            ) {
                return false;
            }
            $lockData = \json_decode($raw, true);
            if (!\is_array($lockData)
                || (int)($lockData['pid'] ?? 0) !== $expectedPid
                || !\hash_equals((string)($lockData['instance'] ?? ''), $instanceName)
            ) {
                return false;
            }
            if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                @\flock($handle, LOCK_UN);
                return false;
            }

            return true;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @return list<int>
     */
    protected function extractReservedWorkerPortsFromInstanceData(array $instanceData): array
    {
        $runtimeSelection = RuntimeSelection::fromEndpoint($instanceData);
        if ($runtimeSelection->isDirect() && $runtimeSelection->listenerMode !== 'worker_ports') {
            return [];
        }

        $workerPort = (int)($instanceData['worker_port'] ?? 0);
        if ($workerPort <= 0) {
            return [];
        }

        $count = \max(1, (int)($instanceData['count'] ?? 1));
        return $this->buildWorkerAllocationCandidatePorts($workerPort, $count);
    }

    /**
     * Collision-only fallback for a recent non-canonical endpoint record.
     * It deliberately does not revive or infer any removed topology field.
     *
     * @return list<int>
     */
    protected function extractConservativeWorkerPortReservation(array $instanceData): array
    {
        $workerPort = (int)($instanceData['worker_port'] ?? 0);
        if ($workerPort <= 0) {
            return [];
        }

        $count = \min(1024, \max(1, (int)($instanceData['count'] ?? 1)));
        return $this->buildWorkerAllocationCandidatePorts($workerPort, $count);
    }

    /**
     * 保存实例信息
     */
    protected function saveInstanceInfo(
        string $instanceName,
        string $host,
        int $port,
        int $count,
        bool $daemon,
        bool $sslEnabled,
        string $sslCert,
        string $sslKey,
        RuntimeSelection $runtimeSelection,
        int $workerPort = 0,
        int $httpRedirectPort = 0,
        bool $windowMode = false,
        bool $enableLog = false,
        int $workerBasePort = 10000,
        array $sharedStateRuntime = [],
        array $orchestratorRuntimeOptions = [],
        string $workerMemoryLimit = '256M',
        string $dispatcherMemoryLimit = '',
        string $publicHost = '',
        array $gatewayRuntime = [],
        array $runtimeMetadata = []
    ): void {
        $containerRegistryDigest = \strtolower(\trim((string)($runtimeMetadata['container_registry_digest'] ?? '')));
        if (\preg_match('/^[a-f0-9]{64}$/D', $containerRegistryDigest) !== 1) {
            throw new \RuntimeException('WLS Start must persist a valid container_registry_digest.');
        }
        $startupIdentity = $this->getMasterLeaseRuntimeIdentity();
        $startupOwner = $startupIdentity->captureOwner((int)\getmypid());

        $instanceData = [
            'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
            'runtime_selection' => $runtimeSelection->toArray(),
            'name' => $instanceName,
            'instance_name' => $instanceName,
            'host' => $host,
            'public_host' => $publicHost !== '' ? $publicHost : $host,
            'public_origin' => (string)($runtimeMetadata['public_origin'] ?? ''),
            'port' => $port,
            'main_port' => $port,
            'count' => $count,
            'daemon' => $daemon,
            'ssl_enabled' => $sslEnabled,
            'ssl_cert' => $sslCert,
            'ssl_key' => $sslKey,
            'http3' => \is_array($runtimeMetadata['http3'] ?? null) ? $runtimeMetadata['http3'] : [],
            'edge_adapter' => (string)($runtimeMetadata['edge_adapter'] ?? ''),
            'http_protocol_selection' => \is_array($runtimeMetadata['http_protocol_selection'] ?? null)
                ? $runtimeMetadata['http_protocol_selection']
                : [],
            'policy_digest' => (string)($runtimeMetadata['policy_digest'] ?? ''),
            'container_registry_digest' => $containerRegistryDigest,
            'supervisor_enabled' => (bool)($runtimeMetadata['supervisor_enabled'] ?? false),
            'supervisor_reason' => (string)($runtimeMetadata['supervisor_reason'] ?? ''),
            'started_at' => \date('Y-m-d H:i:s'),
            'started_timestamp' => \time(),
            'started_monotonic' => $startupIdentity->monotonicNow(),
            'startup_host_boot_id' => $startupIdentity->hostBootId(),
            'startup_process_birth' => $startupOwner['birth'],
            'startup_pid_namespace_id' => $startupOwner['pid_namespace_id'],
            'pid' => \getmypid(),
            'launcher_pid' => 0,
            'master_enabled' => false,
            'master_pid' => 0,
            'startup_phase' => 'bootstrapping',
            'lifecycle_state' => 'starting',
            'server_ready_at' => null,
            'server_ready_service_count' => 0,
            'startup_event_seq' => 0,
            'startup_events' => [],
            'startup_failure_reason' => '',
            'startup_failure_at' => '',
            'startup_failure_timestamp' => 0,
            'startup_failure_pending' => [],
            'startup_failure_class' => '',
            'startup_failure_code' => '',
            'startup_failure_context' => [],
            'startup_failure_diagnostics' => [],
            'stopped_reason' => '',
            'stopped_at' => '',
            'stopped_timestamp' => 0,
            'dispatcher_port' => $runtimeSelection->isDispatcher() ? $port : 0,
            'worker_port' => $workerPort ?: $port,
            'worker_base_port' => $workerBasePort,
            'worker_memory_limit' => ServiceContext::normalizeMemoryLimit($workerMemoryLimit),
            'dispatcher_memory_limit' => ServiceContext::normalizeMemoryLimit(
                $dispatcherMemoryLimit !== '' ? $dispatcherMemoryLimit : $workerMemoryLimit,
                ServiceContext::normalizeMemoryLimit($workerMemoryLimit)
            ),
            'session_server_port' => (int)($sharedStateRuntime['session']['port'] ?? (19970 + MasterProcess::getProjectPortOffset())),
            'session_server_token_file_name' => (string)($sharedStateRuntime['session']['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'session_server',
                    (int)($sharedStateRuntime['session']['port'] ?? (19970 + MasterProcess::getProjectPortOffset()))
                )),
            'memory_server_port' => (int)($sharedStateRuntime['memory']['port'] ?? (19971 + MasterProcess::getProjectPortOffset())),
            'memory_server_token_file_name' => (string)($sharedStateRuntime['memory']['token_file_name']
                ?? SharedStateRuntimeScope::defaultTokenFileNameForRole(
                    'memory_server',
                    (int)($sharedStateRuntime['memory']['port'] ?? (19971 + MasterProcess::getProjectPortOffset()))
                )),
            'shared_state' => $sharedStateRuntime,
            'gateway' => $gatewayRuntime,
            'http_redirect_port' => $httpRedirectPort,
            'window_mode' => $windowMode,
            'runtime_state' => 'running',
            'last_verified_at' => \time(),
            'orchestrator_runtime_options' => $orchestratorRuntimeOptions,
            'enable_log' => $enableLog,
            'control_port' => 0,
        ];

        $runtimeSelection->assertCanonicalEndpoint($instanceData);
        $instanceManager = $this->getInstanceManager();
        $instanceManager->setEndpointPublicationDeadlineMonotonic(
            $this->startupListenerStateDeadline(),
        );
        $instanceManager->saveInstance($instanceName, $instanceData);
    }

    /**
     * Compile and validate a complete generation outside the live registry,
     * then promote it under one directory lock. No staging path is returned or
     * logged, and a failed promotion restores the exact previous byte set.
     *
     * @return array{
     *     container_registry_digest:string,
     *     policy_check:array{valid:bool,errors:list<string>,source:string,bundle:array<string,mixed>}
     * }
     */
    private function compileFrameworkRuntimeRegistries(
        string $policyTopology,
        string $instanceName,
        array $compileContext = [],
    ): array
    {
        $finalDirectory = BP . 'generated' . DS . 'framework';
        $hookRegistry = BP . 'generated' . DS . 'hooks.php';
        $modulesRoot = BP . 'app' . DS . 'code' . DS . 'Weline';
        /** @var FrameworkCompiler $compiler */
        $compiler = ObjectManager::getInstance(FrameworkCompiler::class);
        // A waiting starter must cover one Defender-cold compile, but the
        // deadline remains bounded so a dead compiler cannot stall startup.
        $publisher = new AtomicCompiledFilePublisher(60_000);

        $sharedLockAcquiredHere = false;
        try {
            $sharedLockAcquiredHere = $publisher->acquireDirectoryLock($finalDirectory, \LOCK_SH);
            $fastPathHookSnapshot = $this->snapshotCompiledArtifact($hookRegistry);
            $cachedRuntime = $this->loadPublishedFrameworkRuntimeRegistries(
                $compiler,
                $modulesRoot,
                $finalDirectory,
                $hookRegistry,
                $fastPathHookSnapshot,
                $policyTopology,
                $instanceName,
                $compileContext,
            );
            if ($cachedRuntime !== null) {
                return $cachedRuntime;
            }
        } finally {
            if ($sharedLockAcquiredHere) {
                AtomicCompiledFilePublisher::releaseDirectoryLock($finalDirectory);
            }
        }

        $stagingRoot = BP . 'var' . DS . 'tmp' . DS . 'framework-start-stage-'
            . (string)(\getmypid() ?: 0)
            . '-'
            . \bin2hex(\random_bytes(8));
        $stagingDirectory = $stagingRoot . DS . 'framework';
        $stagingHookRegistry = $stagingRoot . DS . 'hooks.php';
        $finalSnapshots = [];
        $runtimeContainerInstalled = false;
        $exclusiveLockAcquiredHere = false;

        try {
            $exclusiveLockAcquiredHere = $publisher->acquireDirectoryLock($finalDirectory, \LOCK_EX);
            $hookSnapshot = $this->snapshotCompiledArtifact($hookRegistry);
            $cachedRuntime = $this->loadPublishedFrameworkRuntimeRegistries(
                $compiler,
                $modulesRoot,
                $finalDirectory,
                $hookRegistry,
                $hookSnapshot,
                $policyTopology,
                $instanceName,
                $compileContext,
            );
            if ($cachedRuntime !== null) {
                return $cachedRuntime;
            }

            if (!@\mkdir($stagingRoot, 0700, true) && !\is_dir($stagingRoot)) {
                throw new \RuntimeException('Unable to create private framework registry staging directory.');
            }

            // Template policies consume hooks.php but framework:compile does
            // not own it. Compile against one immutable copy, then fence the
            // live hook digest both before and after final promotion.
            if ($hookSnapshot['exists']) {
                $publisher->publish($stagingHookRegistry, (string)$hookSnapshot['content']);
                AtomicCompiledFilePublisher::releaseDirectoryLock($stagingRoot);
            }

            $compiler->compile(
                $modulesRoot,
                $stagingDirectory,
            );

            $stagedSnapshots = [];
            foreach (self::FRAMEWORK_RUNTIME_REGISTRY_FILES as $fileName) {
                $stagedPath = $stagingDirectory . DS . $fileName;
                $snapshot = $this->snapshotCompiledArtifact($stagedPath);
                if (!$snapshot['exists']) {
                    throw new \RuntimeException("Framework registry staging output is missing: {$fileName}.");
                }
                $stagedSnapshots[$fileName] = $snapshot;
            }

            $stagedContainer = new CompiledContainer(
                $stagingDirectory . DS . 'container.php',
                false,
            );
            $containerRegistryDigest = $stagedContainer->registryDigest();
            if (\preg_match('/^[a-f0-9]{64}$/D', $containerRegistryDigest) !== 1) {
                throw new \RuntimeException('Staged compiled container registry digest is invalid.');
            }

            $stagedPolicyControl = new RuntimePolicyControlService(
                new RuntimePolicyCompiler($stagingDirectory . DS . 'runtime_policy_providers.php'),
            );
            $policyCheck = $stagedPolicyControl->check(
                $policyTopology,
                $instanceName,
                $compileContext,
            );
            if (empty($policyCheck['valid'])) {
                // Invalid policy is a normal preflight result. The final files
                // have not been touched at this point.
                return [
                    'container_registry_digest' => $containerRegistryDigest,
                    'policy_check' => $policyCheck,
                    'cache_hit' => false,
                ];
            }

            $this->assertCompiledArtifactSnapshot($hookRegistry, $hookSnapshot, 'hook registry');
            foreach (self::FRAMEWORK_RUNTIME_REGISTRY_FILES as $fileName) {
                $finalSnapshots[$fileName] = $this->snapshotCompiledArtifact(
                    $finalDirectory . DS . $fileName,
                );
            }
            $this->assertCompiledArtifactSnapshot($hookRegistry, $hookSnapshot, 'hook registry');

            try {
                foreach (self::FRAMEWORK_RUNTIME_REGISTRY_FILES as $fileName) {
                    $publisher->publish(
                        $finalDirectory . DS . $fileName,
                        (string)$stagedSnapshots[$fileName]['content'],
                    );
                }
                foreach (self::FRAMEWORK_RUNTIME_REGISTRY_FILES as $fileName) {
                    $this->assertCompiledArtifactSnapshot(
                        $finalDirectory . DS . $fileName,
                        $stagedSnapshots[$fileName],
                        $fileName,
                    );
                }
                ContainerRuntime::preflight($containerRegistryDigest);
                $runtimeContainerInstalled = true;
                $this->assertCompiledArtifactSnapshot($hookRegistry, $hookSnapshot, 'hook registry');
            } catch (\Throwable $promotionException) {
                $rollbackErrors = $this->restoreCompiledArtifactSnapshots(
                    $finalDirectory,
                    $finalSnapshots,
                    $publisher,
                );
                if ($runtimeContainerInstalled) {
                    try {
                        if (($finalSnapshots['container.php']['exists'] ?? false) === true) {
                            ContainerRuntime::preflight();
                        } else {
                            ContainerRuntime::set(null);
                        }
                    } catch (\Throwable $containerRestoreException) {
                        $rollbackErrors[] = 'container runtime restore: ' . $containerRestoreException->getMessage();
                    }
                }
                if ($rollbackErrors !== []) {
                    throw new \RuntimeException(
                        'Framework registry promotion failed and rollback verification failed: '
                        . \implode('; ', $rollbackErrors),
                        0,
                        $promotionException,
                    );
                }
                throw $promotionException;
            }

            return [
                'container_registry_digest' => $containerRegistryDigest,
                'policy_check' => $policyCheck,
                'cache_hit' => false,
            ];
        } catch (\Throwable $exception) {
            $message = \str_replace(
                [$stagingRoot, \str_replace('\\', '/', $stagingRoot)],
                '[private framework staging]',
                $exception->getMessage(),
            );
            throw new \RuntimeException($message, 0, $exception);
        } finally {
            AtomicCompiledFilePublisher::releaseDirectoryLock($stagingRoot);
            if ($exclusiveLockAcquiredHere) {
                AtomicCompiledFilePublisher::releaseDirectoryLock($finalDirectory);
            }
            $this->removePrivateStagingDirectory($stagingRoot);
        }
    }

    /**
     * Read one immutable published generation while its directory lock is held.
     *
     * @param array{exists:bool,content:?string,sha256:string} $hookSnapshot
     * @return array{
     *     container_registry_digest:string,
     *     policy_check:array{valid:bool,errors:list<string>,source:string,bundle:array<string,mixed>},
     *     cache_hit:bool
     * }|null
     */
    private function loadPublishedFrameworkRuntimeRegistries(
        FrameworkCompiler $compiler,
        string $modulesRoot,
        string $finalDirectory,
        string $hookRegistry,
        array $hookSnapshot,
        string $policyTopology,
        string $instanceName,
        array $compileContext,
    ): ?array {
        // Windows avoids recursive source-tree enumeration but still proves
        // the current source, hook registry and immutable artifacts belong to
        // the same generation. POSIX retains the full freshness scan.
        $publishedGenerationReady = \PHP_OS_FAMILY === 'Windows'
            ? $compiler->isPublishedGenerationValid(
                $modulesRoot,
                $finalDirectory,
                $hookRegistry,
            )
            : $compiler->isFresh($modulesRoot, $finalDirectory);
        if (!$publishedGenerationReady) {
            return null;
        }

        $finalContainer = new CompiledContainer(
            $finalDirectory . DS . 'container.php',
            false,
        );
        $containerRegistryDigest = $finalContainer->registryDigest();
        if (\preg_match('/^[a-f0-9]{64}$/D', $containerRegistryDigest) !== 1) {
            throw new \RuntimeException('Compiled container registry digest is invalid.');
        }

        $policyCheck = (new RuntimePolicyControlService(
            new RuntimePolicyCompiler($finalDirectory . DS . 'runtime_policy_providers.php'),
        ))->check($policyTopology, $instanceName, $compileContext);
        $this->assertCompiledArtifactSnapshot(
            $hookRegistry,
            $hookSnapshot,
            'hook registry',
        );
        if (!empty($policyCheck['valid'])) {
            ContainerRuntime::preflight($containerRegistryDigest);
        }

        return [
            'container_registry_digest' => $containerRegistryDigest,
            'policy_check' => $policyCheck,
            'cache_hit' => true,
        ];
    }

    /**
     * @return array{exists:bool,content:?string,sha256:string}
     */
    private function snapshotCompiledArtifact(string $path): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            \clearstatcache(true, $path);
            try {
                $content = GatewayProjectStateFilesystem::readOptional(
                    $path,
                    67_108_864,
                    'compiled WLS runtime artifact',
                    true,
                );
            } catch (\Throwable $throwable) {
                if (!\file_exists($path) && !\is_link($path)) {
                    return ['exists' => false, 'content' => null, 'sha256' => ''];
                }
                throw new \RuntimeException(
                    'Compiled registry path is unsafe or oversized.',
                    0,
                    $throwable,
                );
            }
            if ($content === null) {
                return ['exists' => false, 'content' => null, 'sha256' => ''];
            }
            return [
                'exists' => true,
                'content' => $content,
                'sha256' => \hash('sha256', $content),
            ];
        }

        throw new \RuntimeException('Compiled registry changed while it was being snapshotted.');
    }

    /**
     * @param array{exists:bool,content:?string,sha256:string} $snapshot
     */
    private function assertCompiledArtifactSnapshot(string $path, array $snapshot, string $label): void
    {
        $actual = $this->snapshotCompiledArtifact($path);
        if ($actual['exists'] !== $snapshot['exists']
            || ($snapshot['exists'] && !\hash_equals($snapshot['sha256'], $actual['sha256']))
        ) {
            throw new \RuntimeException("Compiled {$label} changed during startup transaction.");
        }
    }

    /**
     * @param array<string, array{exists:bool,content:?string,sha256:string}> $snapshots
     * @return list<string>
     */
    private function restoreCompiledArtifactSnapshots(
        string $directory,
        array $snapshots,
        AtomicCompiledFilePublisher $publisher,
    ): array {
        $errors = [];
        foreach (self::FRAMEWORK_RUNTIME_REGISTRY_FILES as $fileName) {
            $snapshot = $snapshots[$fileName] ?? null;
            if (!\is_array($snapshot)) {
                $errors[] = "{$fileName}: original snapshot missing";
                continue;
            }
            $path = $directory . DS . $fileName;
            try {
                if ($snapshot['exists']) {
                    $publisher->publish($path, (string)$snapshot['content']);
                } elseif ((\file_exists($path) || \is_link($path)) && !@\unlink($path)) {
                    throw new \RuntimeException('unable to remove newly published artifact');
                }
                $this->assertCompiledArtifactSnapshot($path, $snapshot, $fileName . ' rollback');
            } catch (\Throwable $exception) {
                $errors[] = $fileName . ': ' . $exception->getMessage();
            }
        }
        return $errors;
    }

    private function removePrivateStagingDirectory(string $directory): void
    {
        $visitedEntries = 0;
        try {
            $this->removePrivateStagingDirectoryBounded($directory, 0, $visitedEntries);
        } catch (\Throwable $throwable) {
            // Cleanup runs from a finally block and must not replace the
            // compile/promotion result. Abort traversal on any unsafe path and
            // leave the private staging root for a later bounded cleanup.
            $this->printer->warning(__('WLS 私有编译暂存目录未完全清理：%{1}', [
                \substr($throwable->getMessage(), 0, 512),
            ]));
        }
    }

    private function removePrivateStagingDirectoryBounded(
        string $directory,
        int $depth,
        int &$visitedEntries,
    ): void
    {
        if ($directory === '' || (!\is_dir($directory) && !\is_link($directory))) {
            return;
        }
        if (\is_link($directory)) {
            if (!@\unlink($directory) && \is_link($directory)) {
                throw new \RuntimeException('Unable to remove linked private compilation staging entry.');
            }
            return;
        }
        if ($depth > 16) {
            throw new \RuntimeException('Private compilation staging directory exceeds its depth limit.');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Private compilation staging path is linked or special.');
        }
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate private compilation staging directory.');
        }
        try {
            while (($entry = @\readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (++$visitedEntries > 4096) {
                    throw new \RuntimeException(
                        'Private compilation staging directory exceeds its entry limit.'
                    );
                }
                $path = $directory . DS . $entry;
                if (\is_dir($path) && !\is_link($path)) {
                    $this->removePrivateStagingDirectoryBounded(
                        $path,
                        $depth + 1,
                        $visitedEntries,
                    );
                } elseif (!@\unlink($path) && (\file_exists($path) || \is_link($path))) {
                    throw new \RuntimeException('Unable to remove private compilation staging entry.');
                }
            }
        } finally {
            @\closedir($handle);
        }
        if (!@\rmdir($directory) && (\file_exists($directory) || \is_link($directory))) {
            throw new \RuntimeException('Unable to remove private compilation staging directory.');
        }
    }

    protected function startForegroundManagedProcess(string $command): int
    {
        return Processer::create($command, true, true, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getManagedProcessMetadata(string $command): array
    {
        $data = Processer::getData($command);

        return \is_array($data) ? $data : [];
    }

    protected function persistForegroundLauncherPid(string $instanceName, string $command, int $fallbackPid = 0): int
    {
        $metadata = $this->getManagedProcessMetadata($command);
        $launcherPid = (int) ($metadata['launcher_pid'] ?? 0);
        if ($launcherPid <= 0 && $fallbackPid > 0) {
            $launcherPid = $fallbackPid;
        }

        if ($launcherPid > 0) {
            $instanceManager = $this->getInstanceManager();
            $instanceManager->setEndpointPublicationDeadlineMonotonic(
                $this->startupListenerStateDeadline(),
            );
            $instanceManager->saveInstance(
                $instanceName,
                ['launcher_pid' => $launcherPid],
            );
        }

        return $launcherPid;
    }

    /**
     * 将 server:start 的关键运行参数固化为实例级 orchestrator 选项。
     *
     * @return array<string, bool>
     */
    protected function buildOrchestratorRuntimeOptions(bool $windowMode): array
    {
        if (!$windowMode) {
            return [];
        }

        // Windows 窗口模式：显式允许 Worker/非 Worker 使用前台创建，确保可见全部子进程控制台。
        return [
            'allow_windows_frontend_child_process' => true,
            'frontend_worker_windows' => true,
            'frontend_non_worker_windows' => true,
        ];
    }
    
    /**
     * 将实际的 host 同步到 env.php 的 wls 配置
     *
     * http:req 等 CLI 工具依赖 wls.{host,port,https} 构建请求 URL。
     * 
     * 注意：
     * - 只同步 host，不同步 port 和 https
     * - port 是用户配置的偏好设置，不应被启动参数自动覆盖
     * - https 也是用户配置，不应被 --no-ssl 等临时参数覆盖
     */
    protected function syncServerConfigToEnv(string $host, int $port, bool $sslEnabled): void
    {
        $env = Env::getInstance();
        $wlsConfig = $env->get('wls') ?? [];
        if (!\is_array($wlsConfig)) {
            $wlsConfig = [];
        }
        
        // 只同步 host，不同步 port（port 是用户配置，不应被自动覆盖）
        if (($wlsConfig['host'] ?? null) !== $host) {
            $wlsConfig['host'] = $host;
            $env->setConfig('wls', $wlsConfig);
        }
    }
    
    /*----------------------------------------实例配置记忆（Config Shorthand）------------------------------------------*/
    
    /**
     * 获取实例配置文件目录
     */
    protected function getInstanceConfigDir(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'config' . DS;
    }
    
    /**
     * 获取实例配置文件路径
     */
    protected function getInstanceConfigFile(string $instanceName): string
    {
        return $this->getInstanceConfigDir() . $instanceName . '.json';
    }
    
    /**
     * 加载已保存的实例配置
     * 
     * 当用户首次使用 server:start api -p 8443 启动后，配置会被记住。
     * 下次运行 server:start api 时自动加载已保存的端口、地址、Worker 数等配置。
     * 
     * @param string $instanceName 实例名称
     * @return array|null 已保存的配置，未保存时返回 null
     */
    protected function loadSavedInstanceConfig(string $instanceName): ?array
    {
        $data = (new SavedInstanceConfigStore($this->getInstanceConfigDir()))
            ->load($instanceName, $this->startupListenerStateDeadline());
        if ($data === null) {
            return null;
        }
        return $this->stripRuntimeOnlySavedInstanceConfig($data);
    }

    /**
     * Instance config memory should only keep user intent, not runtime-resolved sidecar state.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function stripRuntimeOnlySavedInstanceConfig(array $data): array
    {
        unset(
            $data['session_server_port'],
            $data['session_server_token_file_name'],
            $data['memory_server_port'],
            $data['memory_server_token_file_name']
        );

        return $data;
    }
    
    /**
     * 保存实例配置（配置记忆）
     * 
     * 将命令行指定的参数（端口、地址、Worker 数等）保存到实例配置文件。
     * 下次用相同实例名启动时，无需再次指定这些参数。
     * 
     * 仅保存用户显式指定的配置项，不保存运行时状态（如 PID、Worker PIDs 等）。
     * 
     * @param string $instanceName 实例名称
     * @param array $args 命令行参数
     * @param array $config 最终合并后的配置
     */
    protected function saveInstanceConfig(string $instanceName, array $args, array $config): void
    {
        $configDir = $this->getInstanceConfigDir();
        if (!\is_dir($configDir)) {
            @\mkdir($configDir, 0755, true);
        }

        $savedConfig = [];
        $persistKeys = [
            'host',
            'public_host',
            'mode',
            'ssl_cert',
            'ssl_key',
            'ssl_domain',
            'certificate_profile',
            'worker_base_port',
            'worker_memory_limit',
            'dispatcher_memory_limit',
        ];
        foreach ($persistKeys as $key) {
            if (isset($config[$key])) {
                $savedConfig[$key] = $config[$key];
            }
        }
        $requestedPort = (int)($config['requested_port'] ?? 0);
        if ($requestedPort < 1 || $requestedPort > 65535) {
            // Compatibility for callers predating explicit desired-port
            // provenance. The normal start path always supplies requested_port
            // before any host lease can replace config.port.
            $requestedPort = (int)($config['port'] ?? 0);
        }
        if ($requestedPort < 1 || $requestedPort > 65535) {
            throw new \RuntimeException('Desired WLS instance port is invalid.');
        }
        $savedConfig['port'] = $requestedPort;
        $savedConfig['requested_port'] = $requestedPort;
        $savedConfig['port_explicit'] = ($config['port_explicit'] ?? false) === true;

        $effectiveEdgeMode = \strtolower(\trim((string)(
            $config['edge_mode']
            ?? ($config['edge']['mode'] ?? \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO)
        )));
        $savedConfig['edge_mode'] = \strtolower(\trim((string)(
            $config['gateway']['requested_mode'] ?? $effectiveEdgeMode
        )));
        $savedConfig['edge_adapter'] = $effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
                ? \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                : \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX;
        $savedConfig['ssl_enabled'] = $effectiveEdgeMode
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS
            && empty($config['no_ssl']);
        if (\hash_equals(
            'stateless',
            (string)($config['gateway']['backend_capability'] ?? ''),
        )) {
            $savedConfig['gateway']['backend_capability'] = 'stateless';
        }
        $runtimeSelection = $config['runtime_selection'] ?? null;
        if (\is_array($runtimeSelection)) {
            $runtimeSelection = RuntimeSelection::fromArray($runtimeSelection);
        }
        $topologyExplicit = isset($args['direct']) || isset($args['dispatcher']);
        if ($topologyExplicit) {
            if (!$runtimeSelection instanceof RuntimeSelection) {
                throw new \RuntimeException('Explicit WLS topology requires RuntimeSelection before saving instance config.');
            }
        }

        $savedAt = \date('Y-m-d H:i:s');
        (new SavedInstanceConfigStore($this->getInstanceConfigDir()))->update(
            $instanceName,
            static function (array $existingSavedConfig) use (
                $savedConfig,
                $config,
                $args,
                $runtimeSelection,
                $topologyExplicit,
                $savedAt,
            ): array {
                $next = $savedConfig;
                $existingRuntime = \is_array($existingSavedConfig['runtime'] ?? null)
                    ? $existingSavedConfig['runtime']
                    : [];
                if (\array_key_exists('topology', $existingRuntime)) {
                    $next['runtime']['topology'] = (string)$existingRuntime['topology'];
                }
                if ($topologyExplicit) {
                    /** @var RuntimeSelection $runtimeSelection */
                    $next['runtime']['topology'] = $runtimeSelection->requestedTopology->value;
                }

                if (isset($args['count']) || isset($args['c'])) {
                    $next['worker_count'] = \max(1, (int)($args['count'] ?? $args['c']));
                    $next['worker_count_requested'] = $next['worker_count'];
                } elseif (\array_key_exists('worker_count_requested', $config)) {
                    $requested = $config['worker_count_requested'];
                    if (\is_string($requested)
                        && \strtolower(\trim($requested)) === 'auto'
                    ) {
                        $next['worker_count_requested'] = 'auto';
                        unset($next['worker_count']);
                    } elseif ((\is_int($requested) && $requested > 0)
                        || (\is_string($requested)
                            && \ctype_digit($requested)
                            && (int)$requested > 0)
                    ) {
                        $next['worker_count_requested'] = (int)$requested;
                        $next['worker_count'] = (int)$requested;
                    } elseif (\array_key_exists('worker_count', $existingSavedConfig)) {
                        $next['worker_count'] = \max(
                            1,
                            (int)$existingSavedConfig['worker_count'],
                        );
                        if (\array_key_exists(
                            'worker_count_requested',
                            $existingSavedConfig,
                        )) {
                            $next['worker_count_requested'] =
                                $existingSavedConfig['worker_count_requested'];
                        }
                    }
                } elseif (\array_key_exists('worker_count', $existingSavedConfig)) {
                    $next['worker_count'] = \max(
                        1,
                        (int)$existingSavedConfig['worker_count'],
                    );
                    if (\array_key_exists('worker_count_requested', $existingSavedConfig)) {
                        $next['worker_count_requested'] =
                            $existingSavedConfig['worker_count_requested'];
                    }
                }
                $next['saved_at'] = $savedAt;
                return [$next, null];
            },
            deadlineMonotonic: $this->startupListenerStateDeadline(),
        );
    }
    
    /*----------------------------------------实例配置记忆结束------------------------------------------*/
    
    /**
     * 更新实例的 Worker PID 列表（原子更新，带文件锁）
     */
    /**
     * 确保 Worker 脚本存在
     * 
     * 注意：不覆盖已有的 bin/worker.php；WLS 后端只允许明文 HTTP/1.1。
     */
    protected function ensureWorkerScript(): string
    {
        $workerScript = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin' . DS . 'worker.php';
        $scriptDir = \dirname($workerScript);
        
        if (!\is_dir($scriptDir)) {
            @\mkdir($scriptDir, 0755, true);
        }
        
        // 只在文件不存在时创建（不覆盖已有的框架集成版本）
        if (!\file_exists($workerScript)) {
            $script = $this->getWorkerScriptContent();
            \file_put_contents($workerScript, $script);
        }
        
        return $workerScript;
    }
    
    /**
     * 获取 Worker 脚本内容
     */
    protected function getWorkerScriptContent(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程
 * 
 * 用法: php worker.php <host> <port> <worker_id> [instance_name]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';

// 静默模式，不输出到控制台
error_reporting(0);

// 创建 socket
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ]
]);

$socket = @stream_socket_server(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    exit(1);
}

stream_set_blocking($socket, false);

$connections = [];
$requestCount = 0;

// 事件循环
while (true) {
    $read = array_merge([$socket], $connections);
    $write = [];
    $except = [];
    
    $changed = @stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 新连接
    if (in_array($socket, $read)) {
        $conn = @stream_socket_accept($socket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $connections[(int)$conn] = $conn;
        }
        $key = array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $data = @fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @fclose($conn);
            unset($connections[(int)$conn]);
            continue;
        }
        
        $requestCount++;
        
        // 高性能响应
        $body = "Hello Weline Server! Instance: {$instanceName}, Worker: {$workerId}, Port: {$port}, Request: {$requestCount}";
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/plain; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: keep-alive\r\n";
        $response .= "\r\n";
        $response .= $body;
        
        @fwrite($conn, $response);
    }
}
PHP;
    }
    
    /**
     * 获取推荐的最佳性能配置
     */
    protected function getRecommendedConfig(): array
    {
        $profile = $this->latestRuntimeProfile ?? $this->detectRuntimeProfile();
        $resolver = new RuntimeStrategyResolver();
        
        return [
            // Worker 配置
            'worker_count' => [
                'io' => $resolver->resolveWorkerCount('auto', 'io', $this->latestRuntimeStrategy, $profile),
                'cpu' => $resolver->resolveWorkerCount('auto', 'cpu', $this->latestRuntimeStrategy, $profile),
            ],
            // PHP 扩展
            'extensions' => [
                'opcache' => __('字节码缓存，提升 PHP 执行速度 50%+'),
                'sockets' => __('原生 Socket 支持，提升网络性能'),
            ],
            // PHP 函数
            'functions' => [
                'proc_open' => __('进程控制核心函数，支持精确的 PID 管理'),
                'pcntl_fork' => __('真正的进程分叉，共享内存，性能最优（仅 Linux/Mac）'),
            ],
            // PHP 配置
            'ini_settings' => [
                'memory_limit' => ['recommended' => '256M', 'min' => 128, 'unit' => 'M', 'desc' => __('内存限制')],
                'max_execution_time' => ['recommended' => '0', 'desc' => __('执行时间限制（0=无限制）')],
                'opcache.enable_cli' => [
                    'recommended' => PhpRuntimeSafetyProfile::requiresJitIsolation() ? '0' : '1',
                    'desc' => __('CLI 模式开启 OPCache'),
                ],
                'opcache.jit' => [
                    'recommended' => PhpRuntimeSafetyProfile::requiresJitIsolation() ? 'off' : 'tracing',
                    'desc' => __('JIT 编译器（PHP 8+）'),
                ],
                'opcache.jit_buffer_size' => [
                    'recommended' => PhpRuntimeSafetyProfile::requiresJitIsolation() ? '0' : '128M',
                    'desc' => __('JIT 缓冲区大小'),
                ],
            ],
        ];
    }
    
    /**
     * 检测性能问题并收集建议
     */
    protected function detectPerformanceIssues(
        int $workerCount,
        string $mode,
        bool $dispatcherEnabled = true,
        bool $supportsReusePort = false,
        bool $directListenerEnabled = false
    ): array
    {
        $issues = [];
        $recommended = $this->getRecommendedConfig();
        
        // Windows Direct/Dispatcher both use the built-in select loops by design.
        // Native event/ev extensions are neither required nor recommended there.
        if (!IS_WIN) {
            $eventLoopIssues = $this->detectEventLoopIssues();
            $issues = \array_merge($issues, $eventLoopIssues);
        }
        
        // 1. 检查 Worker 数量
        $normalizedMode = \strtolower(\trim($mode)) === 'cpu' ? 'cpu' : 'io';
        $recommendedWorkers = (int)$recommended['worker_count'][$normalizedMode];
        
        if ($workerCount < $recommendedWorkers) {
            $platformNote = IS_WIN ? __('（Windows 建议不超过 CPU 核心数）') : '';
            $issues['worker_count'] = [
                'level' => 'info',
                'current' => $workerCount,
                'recommended' => $recommendedWorkers,
                'message' => __('当前 Worker 数：%{1}，推荐：%{2}', [$workerCount, $recommendedWorkers]) . $platformNote,
                'action' => __('使用 -c %{1} 参数或在 wls.worker_count 设置', [$recommendedWorkers]),
            ];
        }
        
        // 2. 检查 PHP 扩展
        foreach ($recommended['extensions'] as $ext => $benefit) {
            $loaded = $ext === 'opcache'
                ? (\extension_loaded('Zend OPcache') || \function_exists('opcache_get_status'))
                : \extension_loaded($ext);
            if (!$loaded) {
                $issues["ext_{$ext}"] = [
                    'level' => 'warning',
                    'message' => __('缺少扩展：%{1}', [$ext]),
                    'benefit' => $benefit,
                    'action' => __('在 php.ini 中启用：extension=%{1}', [$ext]),
                ];
            }
        }
        
        // 3. 检查 PHP 函数
        if (!$this->availableFunctions['proc_open']) {
            $issues['func_proc_open'] = [
                'level' => 'warning',
                'message' => __('函数被禁用：proc_open'),
                'benefit' => $recommended['functions']['proc_open'],
                'action' => __('从 disable_functions 中移除 proc_open'),
            ];
        }
        if (!IS_WIN && !$this->availableFunctions['pcntl_fork']) {
            $issues['func_pcntl_fork'] = [
                'level' => 'warning',
                'message' => __('函数被禁用：pcntl_fork'),
                'benefit' => $recommended['functions']['pcntl_fork'],
                'action' => __('从 disable_functions 中移除 pcntl_fork'),
            ];
        }

        $pureWlsWindowsDispatcher = IS_WIN
            && (new \Weline\Server\Service\Edge\EdgeAdapterResolver())->resolve()->name()
                === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS;
        if ($dispatcherEnabled && !$directListenerEnabled && !$pureWlsWindowsDispatcher) {
            $issues['direct_listener'] = [
                'level' => 'info',
                'message' => __('当前使用 Dispatcher；Nginx 模式 auto 与纯 WLS macOS/Linux auto 均使用 Direct'),
                'benefit' => __('Nginx 模式由边缘直接分流到 Worker；纯 WLS Windows 固定使用单一公网 Dispatcher，macOS/Linux 使用 Direct'),
                'action' => __('非 Windows 纯 WLS 场景可移除 --dispatcher，或配置 wls.runtime.topology = auto/direct'),
            ];
        }
        
        // 4. 检查 PHP 配置
        // 内存限制
        $memoryLimit = \ini_get('memory_limit');
        $memoryMb = $this->parseMemoryLimit($memoryLimit);
        if ($memoryMb > 0 && $memoryMb < 128) {
            $issues['memory_limit'] = [
                'level' => 'warning',
                'current' => $memoryLimit,
                'recommended' => '256M',
                'message' => __('内存限制较低：%{1}', [$memoryLimit]),
                'action' => __('在 php.ini 设置 memory_limit = 256M'),
            ];
        }
        
        // OPCache CLI
        if (\extension_loaded('Zend OPcache') || \function_exists('opcache_get_status')) {
            $opcacheCliEnabled = (string)\ini_get('opcache.enable_cli') === '1'
                || \in_array(
                    'opcache.enable_cli=1',
                    \Weline\Server\Service\LongRunningPhpRuntime::startupCliArguments(),
                    true,
                );
            if (!PhpRuntimeSafetyProfile::requiresJitIsolation()
                && !$opcacheCliEnabled
            ) {
                $issues['opcache_cli'] = [
                    'level' => 'info',
                    'message' => __('OPCache CLI 模式未启用'),
                    'benefit' => __('启用后可提升 CLI 脚本执行速度'),
                    'action' => __('在 php.ini 设置 opcache.enable_cli = 1'),
                ];
            }
            
            // JIT（PHP 8+）。Windows ARM64 上的 x64 PHP 仿真必须保持关闭，不能给出反向建议。
            if (\version_compare(PHP_VERSION, '8.0.0', '>=')
                && !PhpRuntimeSafetyProfile::requiresJitIsolation()
                && !PhpRuntimeSafetyProfile::isJitEnabled()
            ) {
                $issues['opcache_jit'] = [
                    'level' => 'info',
                    'message' => __('JIT 编译器未启用'),
                    'benefit' => __('PHP 8 JIT 可提升 CPU 密集型任务性能 2-3 倍'),
                    'action' => __('在 php.ini 设置 opcache.jit = tracing'),
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * 检测事件循环问题
     */
    protected function detectEventLoopIssues(): array
    {
        $issues = [];
        
        // 检查是否安装了 event 扩展
        $hasEvent = \extension_loaded('event');
        
        if (!$hasEvent) {
            $issues['event_loop'] = [
                'level' => 'critical', // 最高优先级
                'message' => __('未安装 event 扩展，使用 stream_select 回退方案'),
                'benefit' => __('安装后将使用 libevent 事件循环；实际收益以 server:benchmark 同机对比为准'),
                'action' => IS_WIN 
                    ? __('Windows: 下载 php_event.dll 并在 php.ini 中添加 extension=event')
                    : __('Linux/Mac: php bin/w server:start --install-deps（仅显式安装，并以独立 scan-dir ini 验证）'),
            ];
        }
        
        // 检查 ev 扩展（更高性能，可选）
        $hasEv = \extension_loaded('ev');
        if (!$hasEv && $hasEvent) {
            // 已有 event，ev 是可选优化
            $issues['ev_extension'] = [
                'level' => 'info',
                'message' => __('可选：安装 ev 扩展可获得更高性能'),
                'benefit' => __('基于 libev，比 libevent 更轻量'),
                'action' => __('pecl install ev'),
            ];
        }
        
        return $issues;
    }
    
    /**
     * 解析内存限制字符串为 MB
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = \trim($limit);
        if ($limit === '-1') {
            return -1; // 无限制
        }
        
        $unit = \strtolower(\substr($limit, -1));
        $value = (int) $limit;
        
        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }
    
    /**
     * 显示优化建议
     */
    protected function showOptimizationTips(
        int $workerCount,
        string $mode = 'io',
        bool $dispatcherEnabled = true,
        bool $supportsReusePort = false,
        bool $directListenerEnabled = false
    ): void
    {
        // 检测性能问题
        $issues = $this->detectPerformanceIssues(
            $workerCount,
            $mode,
            $dispatcherEnabled,
            $supportsReusePort,
            $directListenerEnabled
        );
        
        if (empty($issues)) {
            echo "\n";
            $this->printer->success(__('✅ 当前配置已达最佳性能！'));
            return;
        }
        
        echo "\n";
        $this->printer->warning(__('📊 性能优化建议'));
        echo "\n";
        
        // 按级别分组
        $criticals = [];
        $warnings = [];
        $infos = [];
        
        foreach ($issues as $key => $issue) {
            if ($issue['level'] === 'critical') {
                $criticals[$key] = $issue;
            } elseif ($issue['level'] === 'warning') {
                $warnings[$key] = $issue;
            } else {
                $infos[$key] = $issue;
            }
        }
        
        // 显示关键问题（严重影响性能）
        if (!empty($criticals)) {
            $this->printer->error(__('🚨 关键性能问题（强烈建议解决）：'));
            echo "\n";
            foreach ($criticals as $issue) {
                $this->printer->error("  ✖ {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->warning("    → {$issue['benefit']}");
                }
                if (isset($issue['current_performance']) && isset($issue['optimal_performance'])) {
                    $this->printer->note(__('    当前性能：%{1} → 优化后：%{2}', [$issue['current_performance'], $issue['optimal_performance']]));
                }
                $this->printer->success("    ✓ {$issue['action']}");
                echo "\n";
            }
        }
        
        // 显示警告级别的问题（影响性能）
        if (!empty($warnings)) {
            $this->printer->warning(__('⚠️ 影响性能的配置：'));
            echo "\n";
            foreach ($warnings as $issue) {
                $this->printer->warning("  • {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->note("    → {$issue['benefit']}");
                }
                $this->printer->note("    ✓ {$issue['action']}");
            }
            echo "\n";
        }
        
        // 显示信息级别的建议（可选优化）
        if (!empty($infos)) {
            $this->printer->note(__('💡 可选优化：'));
            echo "\n";
            foreach ($infos as $issue) {
                $this->printer->note("  • {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->note("    → {$issue['benefit']}");
                }
                $this->printer->note("    ✓ {$issue['action']}");
            }
            echo "\n";
        }
        
        // PHP 配置文件位置
        $this->printer->note(__('📁 PHP 配置文件：%{1}', [\php_ini_loaded_file() ?: 'php.ini']));
        echo "\n";
        
        // 总结
        if (!empty($criticals)) {
            $this->printer->setup(__('🔥 解决关键问题后，性能将提升 100-200%%！'));
        } else {
            $this->printer->success(__('💪 优化后，服务器性能将有质的飞跃！'));
        }
    }
    

    /**
     * 显示使用说明（含各区域入口地址）
     */
    protected function showUsageInfo(string $host, int $port, string $instanceName, bool $sslEnabled = false): void
    {
        $endpoint = $this->readBackgroundStartupData($this->getRuntimeInstanceFile($instanceName));
        $servingProjectionDeadline = self::monotonicSeconds() + 1.0;
        $edgeView = \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::resolve(
            $endpoint,
            false,
            $servingProjectionDeadline,
        );
        $edgeSource = (string)($edgeView['source'] ?? 'unknown');
        $pureWls = \in_array($edgeSource, [
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_PURE_WLS,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS,
        ], true);
        $gatewayRuntime = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $gatewayMode = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY;
        $gatewayPending = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_GATEWAY_PENDING;
        $gatewayCertificatePending = ($gatewayMode || $gatewayPending)
            && (($gatewayRuntime['certificate_pending'] ?? false) === true);
        if (($gatewayPending && !$gatewayCertificatePending) || $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_UNKNOWN
        ) {
            echo "\n";
            $this->printAreaAccessAddresses(
                'http://127.0.0.1:' . $port . '/',
                __('回源内部地址（当前无可验证公网入口）'),
            );
            $this->printer->title(__('使用说明'), '═');
            $this->printer->keyValue([
                __('公网状态') => $gatewayPending
                    ? (string)__('Gateway 注册/运行时验证中')
                    : (string)__('当前无新鲜的服务投影证明'),
                __('查看状态') => 'php bin/w server:status ' . $instanceName,
                __('停止服务') => 'php bin/w server:stop ' . $instanceName,
            ], '→', 18);
            $this->printer->note(__(
                '在精确 Gateway 或 WLS 服务投影发布前，不生成可能误导的公网访问地址。'
            ));
            $this->printer->separator('─');
            return;
        }
        if ($gatewayCertificatePending) {
            $displayHost = $this->isUsablePublicHost($host) ? $host : '127.0.0.1';
            $httpPort = (int)($gatewayRuntime['public_http'] ?? 0);
            $httpSuffix = $httpPort === 80 ? '' : ':' . $httpPort;
            echo "\n";
            $this->printAreaAccessAddresses(
                'http://127.0.0.1:' . $port . '/',
                __('回源内部地址'),
            );
            $this->printer->title(__('使用说明'), '═');
            $this->printer->keyValue([
                __('证书状态') => 'PENDING_CERTIFICATE',
                __('ACME HTTP-01 入口') => $httpPort > 0
                    ? 'http://' . $displayHost . $httpSuffix
                        . '/.well-known/acme-challenge/<token>'
                    : (string)__('网关 HTTP challenge 端点未验证'),
                __('查看状态') => 'php bin/w server:status ' . $instanceName,
                __('停止服务') => 'php bin/w server:stop ' . $instanceName,
            ], '→', 18);
            $this->printer->note(__(
                '普通 HTTPS、前后台页面与 REST 地址尚未发布；证书激活并由网关验证后才会开放 443 路由。'
            ));
            $this->printer->separator('─');
            return;
        }
        $fallbackObservation = $edgeSource
            === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS
                ? \Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection::
                    fallbackServingObservation($endpoint, $servingProjectionDeadline)
                : null;
        if (\is_array($fallbackObservation)
            && $fallbackObservation['authority_host'] === null
        ) {
            echo "\n";
            $this->printer->title(__('WLS 降级入口'), '═');
            $this->printer->keyValue([
                __('TLS 监听') => (string)$fallbackObservation['bind_endpoint'],
                __('路由域名/SNI') => \implode(
                    ', ',
                    (array)$fallbackObservation['route_domains'],
                ),
                __('公网地址') => (string)__('未生成：需要匹配通配符证书的具体 hostname'),
                __('查看状态') => 'php bin/w server:status ' . $instanceName,
                __('停止服务') => 'php bin/w server:stop ' . $instanceName,
            ], '→', 18);
            $this->printer->warning(__(
                '不得将 bind IP 当作 HTTPS 权威；请配置具体域名解析/负载均衡，并在请求中携带匹配的 Host 与 SNI。'
            ));
            $this->printer->separator('─');
            return;
        }
        if ($pureWls) {
            $fallbackEndpoint = $edgeSource
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS
                    ? \Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection::fallbackServingEndpoint(
                        $endpoint,
                        $servingProjectionDeadline,
                    )
                    : null;
            $publicPort = \is_array($fallbackEndpoint)
                ? (int)$fallbackEndpoint['port']
                : $port;
            if (\is_array($fallbackEndpoint)) {
                $host = (string)$fallbackEndpoint['authority_host'];
                $sslEnabled = (bool)$fallbackEndpoint['https'];
            }
            $backendPorts = [$port];
        } elseif ($gatewayMode) {
            $publicPort = (int)($gatewayRuntime['public_https'] ?? 0);
            $backendPorts = [$port];
            if ($publicPort < 1 || $publicPort > 65535) {
                $this->printer->error(__('无法生成访问地址：WLS 2.0 Gateway HTTPS 端点未验证。'));
                return;
            }
        } else {
            $nginx = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv()->doctorSnapshot();
            $ownerActive = (bool)($nginx['runtime_owner_active'] ?? false)
                && \hash_equals($instanceName, (string)($nginx['owner_instance'] ?? ''));
            $publicPort = (int)($nginx['listen_https'] ?? 0);
            $backendPorts = $this->resolveManagedNginxUpstreamPorts($nginx, $port);
            if (!$ownerActive
                || $publicPort < 1
                || $publicPort > 65535
                || $backendPorts === []
            ) {
                $this->printer->error(__('无法生成访问地址：实例绑定的 Nginx HTTPS 端点未验证。'));
                return;
            }
        }
        $host = $this->isUsablePublicHost($host) ? $host : '127.0.0.1';
        $scheme = $pureWls && !$sslEnabled ? 'http' : 'https';
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSuffix = $publicPort === $defaultPort ? '' : ':' . $publicPort;
        $baseUrl = $scheme . '://' . $host . $portSuffix . '/';
        $testUrl = $baseUrl;

        $originBases = [];
        foreach ($backendPorts as $backendPort) {
            $originBases[] = 'http://127.0.0.1:' . $backendPort;
        }
        if ($originBases === []) {
            $originBases[] = 'http://127.0.0.1:' . $port;
        }

        echo "\n";
        $this->printAreaAccessAddresses(
            $baseUrl,
            $this->isUsablePublicHost($host) ? __('服务访问地址') : __('回源内部地址'),
            $this->isUsablePublicHost($host) ? rtrim($baseUrl, '/') : null,
        );
        $primaryOrigin = $originBases[0];
        if (!$pureWls && !\hash_equals(\rtrim($baseUrl, '/'), \rtrim($primaryOrigin, '/'))) {
            $this->printAreaAccessAddresses(
                $primaryOrigin . '/',
                __('回源内部地址'),
            );
        }

        if ($pureWls) {
            $this->printer->note($edgeSource
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView::SOURCE_PURE_WLS
                    ? __(
                        '纯 WLS 直接提供公网 %{1}；HTTP/3 需要托管 Nginx。',
                        [$sslEnabled ? 'TLS 1.3 + HTTP/2/HTTP/1.1' : 'HTTP/1.1'],
                    )
                    : __(
                        '当前由项目级 WLS %{1} 入口提供服务；auto 模式会继续尝试加入宿主 Gateway。',
                        [$sslEnabled ? 'TLS' : 'HTTP'],
                    ));
        } elseif ($gatewayMode) {
            $this->printer->note(__(
                '公网访问由宿主级 WLS 2.0 Gateway 提供。',
            ));
        } else {
            $this->printer->note(__(
                '公网访问必须经过项目托管 Nginx。',
            ));
        }
        $this->printer->separator('─');

        echo "\n";
        $this->printer->title(__('使用说明'), '═');
        $this->printer->keyValue([
            __('测试请求') => 'curl ' . $testUrl,
            __('查看状态') => 'php bin/w server:status ' . $instanceName,
            __('停止服务') => 'php bin/w server:stop ' . $instanceName,
            __('压力测试') => 'php bin/w server:benchmark',
            __('优化指南') => 'php bin/w server:doc',
        ], '→', 18);
    }

    /**
     * 启动收尾与状态共用：按区域打印可复制的访问地址。
     *
     * @param string|null $defaultPublicHost 非空时追加「默认外网地址」行
     */
    private function printAreaAccessAddresses(
        string $baseUrl,
        string $title,
        ?string $defaultPublicHost = null,
    ): void {
        $urlRows = $this->buildAreaAccessUrlRows($baseUrl);
        if ($defaultPublicHost !== null && $defaultPublicHost !== '') {
            $urlRows[__('默认外网地址')] = $defaultPublicHost;
        }
        $this->printer->title($title, '═');
        $this->printer->keyValue($urlRows, '→', 18);
        $this->printer->separator('─');
    }

    /**
     * @return array<string,string>
     */
    private function buildAreaAccessUrlRows(string $baseUrl): array
    {
        $base = \rtrim($baseUrl, '/');
        $backendPrefix = \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        $restBackendPrefix = \trim((string)(Env::getAreaRoutePrefix('rest_backend') ?? ''), '/');
        $restFrontendPrefix = \trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?? 'api'), '/');

        return [
            __('前台/首页') => $base . '/',
            __('后台入口') => $base . '/'
                . ($backendPrefix !== '' ? $backendPrefix . '/' : '')
                . 'admin',
            __('后台 REST 接口') => $restBackendPrefix !== ''
                ? $base . '/' . $restBackendPrefix . '/'
                : (string)__('未配置（请在 env.php 中设置 area_routes.rest_backend.prefix）'),
            __('前台 REST 接口') => $base . '/'
                . ($restFrontendPrefix !== '' ? $restFrontendPrefix : 'api')
                . '/',
        ];
    }

    protected function showServerInfoAfterStartupComplete(
        string $instanceName,
        string $host,
        int $port,
        int $count,
        bool $daemon,
        string $source = '',
        bool $sslEnabled = false,
        bool $dispatcherEnabled = false,
        int $workerPort = 0,
        int $httpRedirectPort = 0,
        string $directListenerMode = ''
    ): void {
        $this->showStartupInfo(
            $instanceName,
            $host,
            $port,
            $count,
            $daemon,
            $source,
            $sslEnabled,
            $dispatcherEnabled,
            $workerPort,
            $httpRedirectPort,
            $directListenerMode
        );
        $this->showUsageInfo($host, $port, $instanceName, $sslEnabled);
    }

    protected function finalizeBackgroundStartupOutput(
        bool $startupCompleted,
        string $instanceName,
        string $host,
        int $port,
        int $count,
        string $source = '',
        bool $sslEnabled = false,
        bool $dispatcherEnabled = false,
        int $workerPort = 0,
        int $httpRedirectPort = 0,
        string $directListenerMode = ''
    ): void {
        if (!$startupCompleted) {
            return;
        }

        $this->showServerInfoAfterStartupComplete(
            $instanceName,
            $host,
            $port,
            $count,
            true,
            $source,
            $sslEnabled,
            $dispatcherEnabled,
            $workerPort,
            $httpRedirectPort,
            $directListenerMode
        );
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('启动 Weline 常驻内存 HTTP 服务器');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:start [name]',
            __('启动 Weline 高性能常驻内存服务器'),
            [
                '[name]' => __('实例名称（默认：default）'),
                '--host <host>' => __('公网域名或展示主机；gateway/legacy 模式回源监听 127.0.0.1，wls 模式直接监听'),
                '-p, --port <port>' => __('gateway/legacy 模式的 WLS 回源端口；wls 模式为纯 WLS HTTPS 端口'),
                '-c, --count <n>' => __('Worker 进程数（默认：auto 智能模式）'),
                '--win' => __('Windows 子进程使用可见控制台窗口'),
                '-m, --mode <mode>' => __('运行模式：io（I/O密集）或 cpu（CPU密集）'),
                '-r, --restart' => __('滚动排水重启：Master 保持运行，Orchestrator 分批次排水替换 Worker（默认三批）'),
                '-f' => __('与 -r 同用时强制完整重启（停 Master，跳过排水等待）'),
                '--ssl-cert <path>' => __('公网 TLS 证书文件路径（默认交给 Nginx；--no-nginx 时交给纯 WLS）'),
                '--ssl-key <path>' => __('公网 TLS 私钥文件路径（默认交给 Nginx；--no-nginx 时交给纯 WLS）'),
                '--certificate-profile <profile>' => __('WLS 2.0 证书信任范围：production（默认）或显式 test；域名后缀不会自动启用 test'),
                '--worker-memory-limit <size>' => __('Worker 进程 PHP memory_limit（如 512M，数字按 MB 处理，-1 为不限）'),
                '--dispatcher-memory-limit <size>' => __('Dispatcher 进程 PHP memory_limit（默认跟随 Worker）'),
                '--runtime-strategy <mode>' => __('运行策略：auto/performance/stability（默认 auto）'),
                '--event-loop <driver>' => __('事件循环：auto/event/select（默认 auto）'),
                '--install-deps' => __('仅为 POSIX Direct 显式安装 ext-event/reuseport 依赖；Windows worker_ports 不需要编译扩展'),
                '--install-nginx' => __('已退役：请先单独执行 server:nginx:install；启动路径不会下载或编译'),
                '--edge <mode>' => __('WLS 2.0 边缘模式：auto/gateway/wls（默认 auto）'),
                '--no-nginx' => __('兼容别名，等价于 --edge=wls'),
                '--no-auto-deps' => __('兼容旧脚本：明确禁止依赖安装；当前普通启动默认已等价，不能与 --install-deps 同用'),
                '--supervisor <value>' => __('Supervisor：auto/true/false（默认 auto）'),
                '--direct' => __('直连模式：Nginx 下 Windows 使用独立 Worker 端口；纯 WLS 仅 macOS/Linux 可用'),
                '--dispatcher' => __('Dispatcher 模式：纯 WLS Windows auto 默认使用；其他场景可显式用于兼容/诊断'),
                '--help' => __('显示帮助信息'),
            ],
            [
                __('配置优先级') => __('命令行参数 > 已保存实例配置 > wls.servers.[name] > wls > 默认值'),
                __('拓扑优先级') => __('--direct/--dispatcher > 当前实例 wls.runtime.topology > 全局 wls.runtime.topology > auto'),
                __('默认边缘') => __('auto 加入可信 wls-edge/2 网关；无法建立时不接管未知端口 owner，自动分配 20000–29999 纯 WLS 地址'),
                __('启动副作用') => __('auto/gateway 仅在 virgin host 上从最终项目发行物自带的签名包首装宿主网关；不下载或编译 legacy Nginx，复制到宿主 A/B 槽后不依赖引导项目'),
                __('多项目支持') => __('多个项目共享宿主 80/443，并通过项目 UUID、域名冲突检查、generation 和租约隔离'),
                __('配置记忆') => __('首次 server:start api -p 9981 会保存回源端口，之后 server:start api 自动复用'),
                __('智能模式') => __('worker_count 设为 "auto" 时由运行时策略按 OS/CPU/内存自动计算'),
                __('事件循环') => __('Windows Direct 使用内置 stream_select；Linux reuseport/shared_fd 与 macOS shared_fd Direct 使用预装 ext-event，缺失时停止并提示显式 --install-deps'),
                __('HTTP/3') => __('仅当 nginx -V 证明包含 ngx_http_v3_module 时配置 QUIC/Alt-Svc；可用 verifier 必须通过 owner-bound 真实 QUIC，否则明确 pending'),
                __('默认拓扑') => __('Nginx 模式所有平台 auto 均为 Direct；纯 WLS 的 macOS/Linux 为 Direct，Windows 固定 Dispatcher'),
                __('多进程') => __('优先级：proc_open > pcntl_fork > exec'),
                __('HTTPS') => __('gateway/legacy 由 Nginx 终结；wls 模式由纯 WLS 启用 TLS 1.3'),
                __('HTTP 协议') => __('Nginx 公网支持 HTTP/3（能力可用时）、HTTP/2、HTTP/1.1；纯 WLS 默认 HTTP/2 并自动回退 HTTP/1.1'),
                __('连接复用') => __('两种模式均支持 Keep-Alive；HTTP/2 使用多路复用；Nginx 模式额外提供回源连接池'),
                __('TLS 会话') => __('Nginx 会话缓存与 Ticket 需 live Reused 验收；纯 WLS 跨连接/跨 Worker Session Ticket 仍为 pending'),
                __('Master 进程') => __('持续监控 Worker 状态并自动恢复；Nginx 模式的公网 HTTP→HTTPS 重定向由 Nginx 负责'),
                __('端口') => __('Nginx 模式下 -p 指定 loopback 回源端口；--no-nginx 时 -p 指定纯 WLS 公网端口'),
                __('Worker 内存') => __('可通过 wls.worker_memory_limit 或 --worker-memory-limit 设置；wls.dispatcher_memory_limit 未设置时跟随 Worker'),
            ],
            [
                __('显式安装 Nginx') => 'php bin/w server:nginx:install',
                __('启动默认实例') => 'php bin/w server:start',
                __('强制加入共享网关') => 'php bin/w server:start gateway --edge=gateway',
                __('纯 WLS HTTPS') => 'php bin/w server:start pure -p 9986 --edge=wls',
                __('启动命名实例（首次指定回源端口）') => 'php bin/w server:start api -p 9981',
                __('再次启动已配置实例') => 'php bin/w server:start api',
                __('Direct 模式') => 'php bin/w server:start direct -p 9982 --direct',
                __('Dispatcher 模式') => 'php bin/w server:start dispatcher -p 9983 --dispatcher',
                __('显式安装依赖后启动') => 'php bin/w server:start deps -p 9984 --install-deps',
                __('Windows 可见窗口') => 'php bin/w server:start win -p 9985 --win',
                __('滚动排水重启') => 'php bin/w server:start api -r',
                __('强制完整重启') => 'php bin/w server:start api -r -f',
                __('指定 Nginx TLS 证书') => 'php bin/w server:start api --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
                __('设置 Worker 内存') => 'php bin/w server:start api --worker-memory-limit=512M',
                __('查看所有实例状态') => 'php bin/w server:status --all',
                __('停止指定实例') => 'php bin/w server:stop api',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('压力测试') => 'php bin/w server:benchmark --instance=api',
            ]
        );
    }
    
    
    // ========== 热重载支持方法 ==========
    
    /**
     * 根据配置启动热重载监控
     * 
     * 开发模式下默认启用热重载，生产模式默认关闭
     * 文件变更时触发 code 级别重载（Worker 重启加载新代码）
     */
    protected function startHotReloadIfEnabled(array $config, string $instanceName): void
    {
        // 热重载默认关闭，需要显式启用
        // 可通过 wls.hot_reload=true 或命令行 --hot-reload 启用
        $hotReload = $config['hot_reload'] ?? false;
        if (!$hotReload) {
            return;
        }
        
        // 仅非守护进程模式支持热重载（前台运行时）
        if ($config['daemon'] ?? true) {
            $this->printer->note(__('热重载仅在前台模式 (--no-daemon) 下生效'));
            $this->printer->note(__('使用 "php bin/w s:up --hot" 手动触发热更新'));
            return;
        }
        
        $this->printer->note(__('启动热重载监控...'));
        
        // 获取监控配置
        $serverEnv = Env::getInstance()->getConfig('wls') ?? [];
        $watchDirs = $serverEnv['watch_dirs'] ?? ['app/code', 'app/etc'];
        $watchInterval = (float) ($serverEnv['watch_interval'] ?? 1);
        
        // 转换为绝对路径
        $absoluteDirs = [];
        foreach ($watchDirs as $dir) {
            $absoluteDirs[] = BP . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
        }
        
        $this->printer->success(__('热重载已启用'));
        $this->printer->note(__('监控目录：%{1}', [\implode(', ', $watchDirs)]));
        $this->printer->note(__('检查间隔：%{1} 秒', [$watchInterval]));
        echo "\n";
        
        // 启动文件监控（变更时通知所有 WLS Worker 重载，与 CLI 命令重载机制一致）
        $this->runFileWatcher($absoluteDirs, $watchInterval, $instanceName);
    }
    
    /**
     * 文件监控进程名前缀（真实名称按项目实例隔离）
     */
    protected const FILE_WATCHER_PROCESS_NAME = 'weline-wls-watcher';

    /**
     * 运行文件监控器（子进程模式）
     *
     * 子进程通过受保护配置文件和父进程出生身份自行退出；
     * 只在协作退出超时时使用稳定内核句柄精确终止。
     */
    protected function runFileWatcher(array $watchDirs, float $interval, string $instanceName): void
    {
        $configDir = Env::VAR_DIR . 'tmp' . DS;
        $watchDirs = \array_values(\array_filter(
            \array_map(static fn(mixed $path): string => \trim((string)$path), $watchDirs),
            static fn(string $path): bool => $path !== '' && \strlen($path) <= 4096,
        ));
        if ($watchDirs === [] || \count($watchDirs) > 64) {
            $this->printer->error(__('文件监控目录配置无效'));
            return;
        }
        $interval = \max(0.1, \min(60.0, $interval));

        try {
            $parentPid = (int)\getmypid();
            $parentRuntimeIdentity = $this->getMasterLeaseRuntimeIdentity();
            $parentIdentity = $parentRuntimeIdentity->captureProcessIdentity($parentPid);
            $parentHostBootId = $parentRuntimeIdentity->hostBootId();
            $watcherLaunchId = \bin2hex(\random_bytes(16));
            $watcherTaskName = MasterProcess::buildScopedProcessName(
                self::FILE_WATCHER_PROCESS_NAME,
                $instanceName,
            );
        } catch (\Throwable $throwable) {
            $this->printer->error(__('无法建立文件监控父进程身份：%{1}', [$throwable->getMessage()]));
            return;
        }

        $configFile = $this->createFileWatcherConfig($configDir, [
            'watch_dirs' => $watchDirs,
            'check_interval' => $interval,
            'parent_pid' => $parentPid,
            'parent_process_birth' => (string)$parentIdentity['birth'],
            'parent_pid_namespace_id' => (string)$parentIdentity['pid_namespace_id'],
            'parent_host_boot_id' => $parentHostBootId,
        ]);
        if ($configFile === null) {
            $this->printer->error(__('无法安全创建文件监控配置'));
            return;
        }

        $watcherScript = \dirname(__DIR__, 2) . DS . 'bin' . DS . 'file_watcher.php';
        $phpBinary = \defined('PHP_BINARY') && \PHP_BINARY ? \PHP_BINARY : 'php';
        $processName = '--name=' . $watcherTaskName
            . ' --launch-id=' . \rawurlencode($watcherLaunchId);

        $this->printer->note(__('按 Ctrl+C 停止监控...'));
        echo "\n";

        // 方案1：pcntl_fork（Linux/Mac），主进程可正确处理信号
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $this->runFileWatcherWithFork(
                $phpBinary,
                $watcherScript,
                $configFile,
                $processName,
                $watcherTaskName,
                $watcherLaunchId,
            );
            return;
        }

        // 方案2：proc_open（Windows 或 pcntl 不可用）
        $this->runFileWatcherWithProcOpen(
            $phpBinary,
            $watcherScript,
            $configFile,
            $processName,
            $watcherTaskName,
            $watcherLaunchId,
        );
    }

    /**
     * 使用 pcntl_fork 运行文件监控子进程
     */
    protected function runFileWatcherWithFork(
        string $phpBinary,
        string $watcherScript,
        string $configFile,
        string $processName,
        string $watcherTaskName,
        string $watcherLaunchId,
    ): void {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->printer->error(__('创建文件监控子进程失败'));
            @\unlink($configFile);
            return;
        }
        if ($pid === 0) {
            if (\function_exists('posix_setsid')) {
                \posix_setsid();
            }
            \pcntl_exec($phpBinary, [
                $watcherScript,
                $configFile,
                '--name=' . $watcherTaskName,
                '--launch-id=' . $watcherLaunchId,
            ]);
            exit(1);
        }

        try {
            $childIdentity = $this->getMasterLeaseRuntimeIdentity()->captureProcessIdentity($pid);
        } catch (\Throwable $throwable) {
            @\unlink($configFile);
            $this->waitForForkedFileWatcherExit($pid, 2.0);
            $this->printer->error(__('无法冻结文件监控子进程身份：%{1}', [$throwable->getMessage()]));
            return;
        }
        Processer::setPid($processName, $pid, false);

        $shutdown = false;
        \pcntl_async_signals(true);
        \pcntl_signal(\SIGINT, function () use (&$shutdown) {
            $shutdown = true;
        });
        \pcntl_signal(\SIGTERM, function () use (&$shutdown) {
            $shutdown = true;
        });

        $released = false;
        while (!$shutdown) {
            $result = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($result === $pid || $result === -1) {
                $released = true;
                break;
            }
            \pcntl_signal_dispatch();
            SchedulerSystem::usleep(200000);
        }

        @\unlink($configFile);
        if (!$released) {
            $released = $this->waitForForkedFileWatcherExit($pid, 2.0);
        }
        if (!$released) {
            $result = $this->getMasterLeaseRuntimeIdentity()->terminateExactProcessIdentity(
                $pid,
                (string)$childIdentity['birth'],
                (string)$childIdentity['pid_namespace_id'],
                0.5,
            );
            $released = (bool)($result['released'] ?? false);
            if ($released) {
                @\pcntl_waitpid($pid, $status, \WNOHANG);
            } else {
                $this->printer->warning(__('文件监控进程未确认退出；已保留精确出生 lease，且未向裸 PID 发送信号。'));
            }
        }
        if ($released) {
            Processer::removeManagedProcessLeaseRecord($pid, $watcherTaskName, $watcherLaunchId);
        }
    }

    /**
     * 使用 proc_open 运行文件监控子进程
     */
    protected function runFileWatcherWithProcOpen(
        string $phpBinary,
        string $watcherScript,
        string $configFile,
        string $processName,
        string $watcherTaskName,
        string $watcherLaunchId,
    ): void {
        $descriptorspec = [
            0 => ['pipe', 'r'],
        ];
        $command = [
            $phpBinary,
            $watcherScript,
            $configFile,
            '--name=' . $watcherTaskName,
            '--launch-id=' . $watcherLaunchId,
        ];
        $proc = @\proc_open($command, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($proc)) {
            $this->printer->error(__('创建文件监控子进程失败'));
            @\unlink($configFile);
            return;
        }
        if (isset($pipes[0])) {
            \fclose($pipes[0]);
        }

        $status = \proc_get_status($proc);
        $pid = (int)($status['pid'] ?? 0);
        try {
            $childIdentity = $this->getMasterLeaseRuntimeIdentity()->captureProcessIdentity($pid);
        } catch (\Throwable $throwable) {
            @\unlink($configFile);
            $this->waitForProcFileWatcherExit($proc, 2.0);
            $this->printer->error(__('无法冻结文件监控子进程身份：%{1}', [$throwable->getMessage()]));
            return;
        }
        Processer::setPid($processName, $pid, false);

        $shutdown = false;
        if (!IS_WIN && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGINT, function () use (&$shutdown) {
                $shutdown = true;
            });
            \pcntl_signal(\SIGTERM, function () use (&$shutdown) {
                $shutdown = true;
            });
        }

        while (true) {
            $status = \proc_get_status($proc);
            if (!$status || !$status['running']) {
                break;
            }
            if ($shutdown) {
                break;
            }
            if (!IS_WIN) {
                \pcntl_signal_dispatch();
            }
            SchedulerSystem::usleep(200000);
        }
        @\unlink($configFile);
        $released = !\is_array($status) || !($status['running'] ?? false);
        if (!$released) {
            $released = $this->waitForProcFileWatcherExit($proc, 2.0);
        }
        if (!$released) {
            $result = $this->getMasterLeaseRuntimeIdentity()->terminateExactProcessIdentity(
                $pid,
                (string)$childIdentity['birth'],
                (string)$childIdentity['pid_namespace_id'],
                0.5,
            );
            $released = (bool)($result['released'] ?? false);
        }
        if ($released) {
            @\proc_close($proc);
            Processer::removeManagedProcessLeaseRecord($pid, $watcherTaskName, $watcherLaunchId);
        } else {
            $this->printer->warning(__('文件监控进程未确认退出；已保留精确出生 lease，且未向裸 PID 发送信号。'));
        }
    }

    /** @param array<string,mixed> $payload */
    protected function createFileWatcherConfig(string $configDir, array $payload): ?string
    {
        if (\is_link($configDir)
            || (!\is_dir($configDir) && !@\mkdir($configDir, 0700, true))
            || !\is_dir($configDir)
        ) {
            return null;
        }
        @\chmod($configDir, 0700);
        try {
            $json = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_string($json) || $json === '' || \strlen($json) > 262144) {
            return null;
        }

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            try {
                $path = $configDir . 'file_watcher_' . \bin2hex(\random_bytes(16)) . '.json';
            } catch (\Throwable) {
                return null;
            }
            $handle = @\fopen($path, 'xb');
            if (!\is_resource($handle)) {
                continue;
            }
            $written = 0;
            try {
                @\chmod($path, 0600);
                $length = \strlen($json);
                while ($written < $length) {
                    $result = @\fwrite($handle, \substr($json, $written));
                    if (!\is_int($result) || $result <= 0) {
                        throw new \RuntimeException('file watcher config write failed');
                    }
                    $written += $result;
                }
                if (!@\fflush($handle)
                    || (\function_exists('fsync') && !@\fsync($handle))
                ) {
                    throw new \RuntimeException('file watcher config flush failed');
                }
                @\fclose($handle);
                return $path;
            } catch (\Throwable) {
                @\fclose($handle);
                @\unlink($path);
                return null;
            }
        }

        return null;
    }

    protected function waitForForkedFileWatcherExit(int $pid, float $timeoutSeconds): bool
    {
        $deadline = \hrtime(true) + (int)(\max(0.01, $timeoutSeconds) * 1_000_000_000);
        do {
            $result = @\pcntl_waitpid($pid, $status, \WNOHANG);
            if ($result === $pid || $result === -1) {
                return true;
            }
            $waitMicroseconds = self::boundedNanosecondDeadlineSleepMicroseconds(
                $deadline,
                \hrtime(true),
                20_000,
            );
            if ($waitMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($waitMicroseconds);
        } while (\hrtime(true) < $deadline);

        return false;
    }

    /** @param resource $process */
    protected function waitForProcFileWatcherExit($process, float $timeoutSeconds): bool
    {
        if (!\is_resource($process)) {
            return true;
        }
        $deadline = \hrtime(true) + (int)(\max(0.01, $timeoutSeconds) * 1_000_000_000);
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status) || !($status['running'] ?? false)) {
                return true;
            }
            $waitMicroseconds = self::boundedNanosecondDeadlineSleepMicroseconds(
                $deadline,
                \hrtime(true),
                20_000,
            );
            if ($waitMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($waitMicroseconds);
        } while (\hrtime(true) < $deadline);

        return false;
    }
    /**
     * 获取启动锁，防止并发启动同一实例
     * 
     * 使用文件锁（flock）实现：
     * - 进程崩溃时操作系统自动释放锁
     * - 非阻塞模式，立即返回结果
     * 
     * @param string $instanceName 实例名称
     * @param int $timeout 获取锁超时（秒）
     * @return bool 是否成功获取锁
     */
    protected function traceStartupPhase(string $instanceName, string $phase, array $context = []): void
    {
        if ((string)\getenv('WLS_STARTUP_TRACE') !== '1') {
            return;
        }

        static $traceStartNs = null;
        static $tracePreviousNs = null;
        static $traceSequence = 0;

        $nowNs = \hrtime(true);
        $traceStartNs ??= $nowNs;
        $tracePreviousNs ??= $nowNs;
        $context['sequence'] = ++$traceSequence;
        $context['mono_ns'] = $nowNs;
        $context['total_ms'] = \round(($nowNs - $traceStartNs) / 1_000_000, 3);
        $context['delta_ms'] = \round(($nowNs - $tracePreviousNs) / 1_000_000, 3);
        $wallNow = self::diagnosticWallSeconds();
        $requestStartedAt = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? $wallNow);
        $context['process_elapsed_ms'] = \round(\max(0.0, $wallNow - $requestStartedAt) * 1000, 3);
        $context['memory_mb'] = \round(\memory_get_usage(true) / 1048576, 2);
        $tracePreviousNs = $nowNs;

        $dir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $path = $dir . 'wls-startup-trace.log';
        $contextJson = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $line = \sprintf("[%s] pid=%d instance=%s phase=%s context=%s%s", \date('Y-m-d H:i:s'), \getmypid(), $instanceName, $phase, $contextJson, PHP_EOL);
        self::appendStartupTraceLine($path, $line);
    }

    /**
     * Diagnostic tracing must never become a startup mutex. Open only a
     * single-link regular inode, verify the path/handle identity before and
     * after the non-blocking lock, and drop the line on any contention/race.
     */
    private static function appendStartupTraceLine(string $path, string $line): void
    {
        if ($path === '' || \str_contains($path, "\0") || $line === '') {
            return;
        }
        $directory = \dirname($path);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)($directoryBefore['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            return;
        }
        $safeFile = static fn (array $status): bool =>
            ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
            && (int)($status['nlink'] ?? 0) === 1;
        $sameIdentity = static function (array $before, array $after): bool {
            foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
                if ((int)($before[$field] ?? -1) !== (int)($after[$field] ?? -2)) {
                    return false;
                }
            }
            return true;
        };
        $sameDirectoryIdentity = static function (array $before, array $after): bool {
            foreach (['dev', 'ino', 'mode'] as $field) {
                if ((int)($before[$field] ?? -1) !== (int)($after[$field] ?? -2)) {
                    return false;
                }
            }
            return true;
        };

        $handle = false;
        $before = false;
        $created = false;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            \clearstatcache(true, $path);
            $before = @\lstat($path);
            $created = false;
            if (\is_array($before)) {
                if (!$safeFile($before) || \is_link($path)) {
                    return;
                }
                $handle = @\fopen($path, 'r+b');
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    return;
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }
            if (\is_resource($handle)) {
                break;
            }
        }
        if (!\is_resource($handle)) {
            return;
        }

        $locked = false;
        try {
            \clearstatcache(true, $path);
            $opened = @\fstat($handle);
            $pathAfterOpen = @\lstat($path);
            $directoryAfterOpen = @\lstat($directory);
            if (!\is_array($opened)
                || !\is_array($pathAfterOpen)
                || !\is_array($directoryAfterOpen)
                || !$safeFile($opened)
                || !$safeFile($pathAfterOpen)
                || (!$created && (!\is_array($before) || !$sameIdentity($before, $opened)))
                || !$sameIdentity($opened, $pathAfterOpen)
                || !$sameDirectoryIdentity($directoryBefore, $directoryAfterOpen)
                || \is_link($path)
            ) {
                return;
            }
            $locked = @\flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) {
                return;
            }
            $lockedStatus = @\fstat($handle);
            $lockedPathStatus = @\lstat($path);
            $lockedDirectoryStatus = @\lstat($directory);
            if (!\is_array($lockedStatus)
                || !\is_array($lockedPathStatus)
                || !\is_array($lockedDirectoryStatus)
                || !$safeFile($lockedStatus)
                || !$sameIdentity($opened, $lockedStatus)
                || !$sameIdentity($lockedStatus, $lockedPathStatus)
                || !$sameDirectoryIdentity($directoryBefore, $lockedDirectoryStatus)
                || @\fseek($handle, 0, SEEK_END) !== 0
            ) {
                return;
            }
            $offset = 0;
            $length = \strlen($line);
            while ($offset < $length) {
                $written = @\fwrite($handle, \substr($line, $offset));
                if (!\is_int($written) || $written < 1) {
                    return;
                }
                $offset += $written;
            }
            @\fflush($handle);
        } finally {
            if ($locked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    protected function acquireStartLock(string $instanceName, int $timeout = 3): bool
    {
        $this->startLockBlockedByLifecycle = false;
        if ($this->lifecycleOperationLock !== null) {
            return false;
        }
        $lifecycleLock = new ServerLifecycleOperationLock();
        if (!$lifecycleLock->acquire($instanceName, 'start', (float)$timeout)) {
            $this->startLockBlockedByLifecycle = true;
            return false;
        }
        $this->startLockFile = $this->getStartLockFileForInstance($instanceName);
        $handle = $this->acquireVerifiedPersistentLock(
            $this->startLockFile,
            (float)$timeout,
            function () use ($instanceName): array {
                $processIdentity = [];
                try {
                    $processIdentity = (
                        new \Weline\Server\Service\MasterLeaseRuntimeIdentity()
                    )->captureProcessIdentity((int)\getmypid());
                } catch (\Throwable) {
                    // The lock remains valid, but a future force takeover must
                    // wait for flock instead of killing a PID without birth.
                }
                return [
                    'pid' => \getmypid(),
                    'process_birth' => (string)($processIdentity['birth'] ?? ''),
                    'pid_namespace_id' => (string)(
                        $processIdentity['pid_namespace_id'] ?? ''
                    ),
                    'instance' => $instanceName,
                    'started_at' => \date('Y-m-d H:i:s'),
                    'command' => \substr(
                        \implode(' ', \array_map(
                            static fn(mixed $argument): string => (string)$argument,
                            (array)($_SERVER['argv'] ?? []),
                        )),
                        0,
                        4096,
                    ),
                ];
            },
        );
        if (!\is_resource($handle)) {
            $lifecycleLock->release();
            return false;
        }
        $this->lifecycleOperationLock = $lifecycleLock;
        $this->startLockHandle = $handle;

        return true;
    }

    /**
     * @param \Closure():array<string,mixed> $payloadBuilder
     * @return resource|false
     */
    private function acquireVerifiedPersistentLock(
        string $path,
        float $timeout,
        \Closure $payloadBuilder,
    ) {
        return VerifiedPersistentFileLock::acquire($path, $timeout, $payloadBuilder);
    }

    /**
     * A diagnostic PID in a persistent lock file may have been recycled. A
     * force takeover may terminate it only after the live command line still
     * proves that it is a WLS start launcher, never merely because PID matches.
     */
    protected function isVerifiedStartLockOwnerProcess(
        int $pid,
        string $processBirth,
        string $pidNamespaceId,
    ): bool {
        if ($pid <= 0 || \preg_match('/\A[a-f0-9]{64}\z/D', $processBirth) !== 1) {
            return false;
        }
        $runtimeIdentity = new MasterLeaseRuntimeIdentity();
        $processInfo = $runtimeIdentity->inspectProcess($pid);
        if (($processInfo['exists'] ?? null) !== true) {
            return false;
        }
        try {
            $ownerState = $runtimeIdentity->observeProcessIdentity(
                $pid,
                $processBirth,
                $pidNamespaceId,
            );
        } catch (\Throwable) {
            return false;
        }
        if ($ownerState
            !== \Weline\Server\Service\MasterLeaseRuntimeIdentity::OWNER_MATCH
        ) {
            return false;
        }
        $commandLine = \strtolower(\trim((string)($processInfo['command'] ?? '')));
        if ($commandLine === '') {
            return false;
        }

        return \str_contains($commandLine, 'server:start')
            && (\str_contains($commandLine, 'bin/w')
                || \str_contains($commandLine, 'bin\\w'));
    }

    private static function monotonicSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS Start monotonic clock is invalid.');
        }

        return $now;
    }

    /**
     * PHP 进程异常结束（fatal / 未捕获错误）时：若曾尝试拉起本实例 WLS 且未完成交接，则清理残留子进程。
     */
    public function shutdownCleanupOrphanWlsProcessesIfNeeded(): void
    {
        if ($this->wlsStartupProcessHandoffDone || !$this->wlsChildProcessesMayExist) {
            if ($this->sharedStateConsumerAcquired && !$this->sharedStateConsumerHandoffDone) {
                $instanceName = $this->startLockInstanceName;
                if ($instanceName !== '') {
                    $this->releaseFailedStartupSharedStateConsumers($instanceName);
                }
            }
            $this->cancelUntransferredPublicEdgeLease();
            return;
        }
        $instanceName = $this->startLockInstanceName;
        if ($instanceName === '') {
            return;
        }
        // The ordinary daemon failure return retires before cleanup. Keep the
        // same ordering for an exception/fatal that escapes the background
        // READY/public-edge transaction: killing Agent first would remove the
        // strongest exact-generation unregister path and leave a committed
        // host route alive until lease expiry. Retirement failure must not
        // suppress the subsequent exact-process cleanup.
        try {
            $this->retirePossibleGatewayRegistrationBeforeFailedStartupCleanup(
                $instanceName,
            );
        } catch (\Throwable) {
            // The helper normally contains its own warning path. Shutdown must
            // still continue if diagnostic output itself is unavailable.
        }
        try {
            if ($this->cleanupFailedStartupProcesses($instanceName, 16)) {
                $this->wlsChildProcessesMayExist = false;
                $this->cancelUntransferredPublicEdgeLease();
            }
        } catch (\Throwable) {
            // Exit was not proven. Retain the host lease for bounded allocator
            // recovery rather than publishing RELEASED beside a live inheritor.
        }
    }

    private function cancelUntransferredPublicEdgeLease(): void
    {
        $this->closeStartupListenerCopies();
        DirectSharedListener::discardStartupListener();
        $leases = [
            $this->startupPublicEdgeLease,
            $this->startupGatewayBackendLease,
        ];
        $this->startupPublicEdgeLease = null;
        $this->startupGatewayBackendLease = null;
        $cleanupDeadline = self::monotonicSeconds()
            + self::STARTUP_LISTENER_CLEANUP_BUDGET_SECONDS;
        $allocator = new GatewayPortLeaseAllocator(
            operationDeadlineMonotonic: $cleanupDeadline,
        );
        foreach ($leases as $lease) {
            if (!\is_array($lease) || $lease === []) {
                continue;
            }
            try {
                $allocator->cancelReservation(
                    (string)($lease['instance'] ?? ''),
                    (int)($lease['port'] ?? 0),
                    (string)($lease['lease_id'] ?? ''),
                );
            } catch (\Throwable) {
                // ACTIVE means the Master already adopted the exact lease. Any
                // other mismatch is left intact for bounded stale-lease recovery.
            }
        }
    }

    /**
     * 释放启动锁
     */
    public function releaseStartLock(): void
    {
        if ($this->startLockHandle !== null) {
            @\flock($this->startLockHandle, \LOCK_UN);
            @\fclose($this->startLockHandle);
            $this->startLockHandle = null;
        }

        $this->lifecycleOperationLock?->release();
        $this->lifecycleOperationLock = null;

        // Keep the inode persistent. Unlinking a flock file after unlock can
        // delete a newer owner's path and let a third process lock a different
        // inode. The next owner truncates and rewrites the diagnostic payload.
        $this->startLockFile = '';
    }

    protected function acquireWorkerPortAllocationLock(int $timeout = self::WORKER_PORT_ALLOCATION_LOCK_TIMEOUT): bool
    {
        $this->workerPortAllocationLockFile = $this->getWorkerPortAllocationLockFilePath();
        $handle = $this->acquireVerifiedPersistentLock(
            $this->workerPortAllocationLockFile,
            (float)$timeout,
            static fn(): array => [
                'pid' => \getmypid(),
                'started_at' => \date('Y-m-d H:i:s'),
                'command' => \substr(
                    \implode(' ', \array_map(
                        static fn(mixed $argument): string => (string)$argument,
                        (array)($_SERVER['argv'] ?? []),
                    )),
                    0,
                    4096,
                ),
            ],
        );
        if (!\is_resource($handle)) {
            return false;
        }
        $this->workerPortAllocationLockHandle = $handle;

        return true;
    }

    public function releaseWorkerPortAllocationLock(): void
    {
        if ($this->workerPortAllocationLockHandle !== null) {
            @\flock($this->workerPortAllocationLockHandle, \LOCK_UN);
            @\fclose($this->workerPortAllocationLockHandle);
            $this->workerPortAllocationLockHandle = null;
        }
    }

    protected function getWorkerPortAllocationLockFilePath(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'worker_port_allocation.lock';
    }

    /**
     * 打印欢迎语
     */
    protected function printWelcome(): void
    {
        $width = 60;
        $title = 'Weline Framework Server';
        $version = 'v' . $this->getWelineVersion();
        $padding = ($width - \mb_strlen($title) - \mb_strlen($version) - 3) / 2;

        $this->printer->note('');
        $this->printer->note($this->colorize(str_repeat('═', $width), 'Blue'));
        $this->printer->note(
            $this->colorize('║', 'Blue') .
            \str_repeat(' ', $width - 2) .
            $this->colorize('║', 'Blue')
        );
        $this->printer->note(
            $this->colorize('║', 'Blue') .
            \str_repeat(' ', (int)\floor($padding)) .
            $this->colorize($title, 'Green') .
            ' ' .
            $this->colorize($version, 'Yellow') .
            \str_repeat(' ', (int)\ceil($padding)) .
            $this->colorize('║', 'Blue')
        );
        $this->printer->note(
            $this->colorize('║', 'Blue') .
            \str_repeat(' ', $width - 2) .
            $this->colorize('║', 'Blue')
        );
        $this->printer->note($this->colorize(str_repeat('═', $width), 'Blue'));
        $this->printer->note('');
    }

    /**
     * After WLS is READY, optionally start the per-project managed nginx edge.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed>|null $activeCertificate
     */
    private function maybeStartManagedNginxAfterReady(
        int $upstreamPort,
        array $args,
        string $instanceName,
        ?array $activeCertificate,
    ): bool
    {
        if (isset($args['no-nginx']) || isset($args['no_nginx'])) {
            $this->printer->error(__('纯 WLS 模式不应进入 Nginx/网关发布阶段。'));
            return false;
        }
        $gatewayRetirementRequired = false;
        $gatewayRuntimeAttempted = false;
        $autoStartupFallback = false;
        $endpoint = [];
        try {
            $endpoint = $this->getInstanceManager()->getRawInstanceData($instanceName);
            $gatewayRuntime = \is_array($endpoint['gateway'] ?? null)
                ? $endpoint['gateway']
                : [];
            if ((string)($gatewayRuntime['mode'] ?? '')
                === \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY
            ) {
                $gatewayRuntimeAttempted = true;
                $autoStartupFallback = \hash_equals(
                    \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
                    \strtolower(\trim((string)(
                        $gatewayRuntime['requested_mode'] ?? ''
                    ))),
                );
                // From this point every failure path owns a possible host-side
                // registration outcome. Even register() throwing can mean the
                // Controller committed before receipt/lifecycle publication
                // failed, so rollback must inspect authenticated own-status
                // under the same retirement fence rather than guessing from
                // the PHP return value.
                // Explicit gateway remains fail-closed and retires immediately.
                // Auto first attempts a same-Master Agent fallback; only the
                // outer failed-startup cleanup may retire it if that request
                // is rejected or never reaches a verified serving projection.
                $gatewayRetirementRequired = !$autoStartupFallback;
                $registration = (new \Weline\Server\Service\Edge\Gateway\GatewayHostManager())
                    ->register($instanceName);
                $routes = \is_array($registration['routes'] ?? null)
                    ? $registration['routes']
                    : [];
                $publicHost = \is_array($endpoint)
                    ? (string)($endpoint['public_host'] ?? $endpoint['host'] ?? '')
                    : '';
                $routeGate = $this->resolveGatewayPrimaryRouteGate(
                    $routes,
                    $publicHost,
                    ($gatewayRuntime['certificate_pending'] ?? false) === true,
                );
                $publicHost = $routeGate['public_host'];
                $primaryActive = $routeGate['active'];
                $primaryChallengeOnly = $routeGate['challenge_only'];
                if (!$routeGate['accepted']) {
                    throw new \RuntimeException((string)__(
                        'WLS 2.0 网关注册后主域 %{1} 未达到 ACTIVE 或受限 challenge-only：%{2}',
                        [
                            $publicHost !== '' ? $publicHost : '(missing)',
                            $routeGate['states'] !== []
                                ? \implode(', ', $routeGate['states'])
                                : 'NO_ROUTE',
                        ],
                    ));
                }
                $edgePort = (int)($gatewayRuntime['public_https'] ?? 0);
                if ($primaryActive && $publicHost !== '' && $edgePort > 0) {
                    $this->syncServerConfigToEnv($publicHost, $edgePort, true);
                }
                if ($primaryChallengeOnly) {
                    $this->printer->success(__('项目已以 PENDING_CERTIFICATE 注册到宿主级 WLS 2.0 Gateway'));
                    $this->printer->note(__(
                        '主域 %{1} 当前仅开放精确 ACME HTTP-01 challenge；普通 443 路由仍关闭。',
                        [$publicHost],
                    ));
                    return true;
                }
                $this->printer->success(__('项目已注册到宿主级 WLS 2.0 Gateway'));
                $this->printer->note(__('协议：%{1}，epoch：%{2}，活动路由：%{3}', [
                    \Weline\Server\Service\Edge\Gateway\GatewayPaths::PROTOCOL,
                    (string)($registration['epoch'] ?? $gatewayRuntime['epoch'] ?? ''),
                    (string)$routeGate['active_count'],
                ]));
                return true;
            }

            $service = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
            $edgeAdapterName = \is_array($endpoint)
                ? \trim((string)($endpoint['edge_adapter'] ?? ''))
                : '';
            if ($edgeAdapterName === '') {
                // Master endpoint snapshots may omit edge_adapter before the
                // Nginx publication boundary; fall back to the env-selected
                // Nginx-only adapter instead of recycling a READY WLS fleet.
                $edgeAdapterName = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
                    ->resolve()
                    ->name();
            }
            $publicHost = \is_array($endpoint)
                ? \trim((string)($endpoint['public_host'] ?? ''))
                : '';
            if ($publicHost === '' && \is_array($endpoint)) {
                $publicHost = \trim((string)($endpoint['host'] ?? ''));
            }
            $serverNames = $publicHost !== '' ? [$publicHost] : [];
            if ($edgeAdapterName !== \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX) {
                $this->printer->error(__('运行态实例不是 Nginx-only 边缘，已拒绝发布。'));
                return false;
            }
            if (!$service->paths()->managedEnabled()) {
                $this->printer->error(__('Nginx-only 默认启动要求 wls.edge.nginx.managed=true。'));
                return false;
            }
            if (!$service->paths()->autoStartEnabled()) {
                $this->printer->error(__('Nginx-only 默认启动要求 wls.edge.nginx.auto_start=true。'));
                return false;
            }
            if (!$service->paths()->isInstalled()) {
                $this->printer->warning(__(
                    '托管 Nginx 未安装：普通 server:start 不下载、不编译；请先显式执行 php bin/w server:nginx:install。'
                ));
                return false;
            }
            $result = $service->prepareAndStart(
                $upstreamPort,
                '127.0.0.1',
                $serverNames,
                $instanceName,
                $edgeAdapterName,
                $activeCertificate,
            );
            if (!($result['ok'] ?? false)) {
                $this->printer->warning(__('托管 Nginx 启动失败：%{1}', [(string)$result['message']]));
                return false;
            }
            $details = \is_array($result['details'] ?? null) ? $result['details'] : [];
            if ($details === []) {
                $this->printer->warning(__('托管 Nginx 启动成功但缺少已验证的公网端点详情。'));
                return false;
            }
            $edgeSsl = (bool)($details['ssl'] ?? false);
            if (!$edgeSsl
                || !(bool)($details['tls13_runtime_verified'] ?? false)
                || !(bool)($details['http2_runtime_verified'] ?? false)
                || !(bool)($details['http1_runtime_verified'] ?? false)
            ) {
                $this->printer->warning(__('托管 Nginx 未同时通过 TLS 1.3、HTTP/2 与 HTTP/1.1 真实门禁。'));
                return false;
            }
            $edgePort = (int)($details['listen_https'] ?? 0);
            if ($edgePort < 1 || $edgePort > 65535) {
                $this->printer->warning(__('托管 Nginx 返回了无效的公网监听端口。'));
                return false;
            }
            $this->syncServerConfigToEnv(
                $publicHost !== '' ? $publicHost : '127.0.0.1',
                $edgePort,
                true,
            );
            $verifiedProtocols = [];
            if ((bool)($details['http2_runtime_verified'] ?? false)) {
                $verifiedProtocols[] = 'HTTP/2';
            }
            if ((bool)($details['http1_runtime_verified'] ?? false)) {
                $verifiedProtocols[] = 'HTTP/1.1';
            }
            $this->printer->success(__('托管 Nginx 已启动'));
            $this->printer->note(__('边缘 HTTP %{1} → HTTPS 重定向', [
                (string)($details['listen_http'] ?? ''),
            ]));
            $this->printer->note(__('边缘 HTTPS %{1} → WLS %{2}', [
                (string)($details['listen_https'] ?? ''),
                (string)($details['upstream'] ?? ''),
            ]));
            if ($verifiedProtocols !== []) {
                $this->printer->note(__('公网已验证协议：%{1}', [\implode(' → ', $verifiedProtocols)]));
            }
            if ((bool)($details['http3_capable'] ?? false)
                && !(bool)($details['http3_runtime_verified'] ?? false)
            ) {
                $this->printer->warning(__('当前环境未通过 HTTP/3 真实 QUIC 门禁；本代未发布 QUIC 监听或 Alt-Svc，继续使用 HTTP/2/HTTP/1.1。'));
            }
            return true;
        } catch (\Throwable $e) {
            if ($gatewayRuntimeAttempted
                && $autoStartupFallback
                && \is_array($activeCertificate)
            ) {
                try {
                    $currentEndpoint = $this->getInstanceManager()
                        ->getRawInstanceData($instanceName);
                } catch (\Throwable) {
                    $currentEndpoint = null;
                }
                if (\is_array($currentEndpoint)
                    && $this->requestAutoGatewayStartupFallback(
                        $instanceName,
                        $currentEndpoint,
                        $activeCertificate,
                        $e->getMessage(),
                    )
                ) {
                    $this->printer->warning(__(
                        'Gateway 首次注册/路由门禁失败；当前 Master 已切换到项目级纯 WLS TLS 高端口入口。'
                    ));
                    $this->printer->note(__(
                        'Gateway Agent 将继续后台重试注册；恢复稳定后按既有排空协议切回 80/443。'
                    ));
                    return true;
                }
            }
            if ($gatewayRetirementRequired) {
                try {
                    $retirement = (
                        new \Weline\Server\Service\Edge\Gateway\GatewayRegistrationRetirementCoordinator()
                    )->retire(
                        $instanceName,
                        1,
                        true,
                        // This startup transaction is already doomed. Never
                        // restore REGISTERED/UNCERTAIN and let Agent resurrect
                        // a route while the local backend is being recycled.
                        false,
                    );
                    $retirementMessage = ($retirement['action'] ?? '')
                        === \Weline\Server\Service\Edge\Gateway\GatewayStopRegistrationPolicy::ACTION_DRAIN
                            ? __('Gateway 启动事务已在本地回收前排空并注销。')
                            : __('Gateway 启动事务已确认无宿主路由并完成退休。');
                    $this->printer->note($retirementMessage);
                } catch (\Throwable $retirementFailure) {
                    // retire(..., restoreOnFailure=false) deliberately keeps
                    // RETIRING/UNCERTAIN. Local WLS will be recycled while the
                    // bounded host lease expires to STALE/503; never issue a
                    // naked unregister or reopen Agent registration here.
                    $this->printer->warning(__(
                        'Gateway 启动失败后无法确认已注销路由：%{1}；'
                        . '本地退休栅栏保持，宿主租约将过期为 STALE/503。',
                        [\substr($retirementFailure->getMessage(), 0, 512)],
                    ));
                }
                $this->printer->warning(__('Gateway 启动/注册异常：%{1}', [$e->getMessage()]));
                return false;
            }
            if ($gatewayRuntimeAttempted) {
                $this->printer->warning(__('Gateway 启动/注册异常：%{1}', [$e->getMessage()]));
                return false;
            }
            $this->printer->warning(__('托管 Nginx 启动异常：%{1}', [$e->getMessage()]));
            return false;
        }
    }

    /**
     * Request, but never create, the project fallback listener. Success is
     * reported only after the persisted runtime projection proves either the
     * exact fallback lease or a concurrently recovered gateway is serving.
     *
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $activeCertificate
     */
    protected function requestAutoGatewayStartupFallback(
        string $instanceName,
        array $endpoint,
        array $activeCertificate,
        string $failure,
    ): bool {
        try {
            // Registration may fail before any gateway route is publishable
            // (for example, while the loopback join backend is still absent).
            // Publish only project-owned TLS serving truth first. This does not
            // create or claim a host Gateway ACTIVE route; it gives the Agent
            // one immutable launch/certificate after-image for local fallback.
            $servingManifest = $this->buildAutoGatewayStartupFallbackServingManifest(
                $instanceName,
                (\hrtime(true) / 1_000_000_000) + 8.0,
            );
            $gateway = \is_array($endpoint['gateway'] ?? null)
                ? $endpoint['gateway']
                : [];
            $source = \is_array($gateway['certificate_source'] ?? null)
                ? $gateway['certificate_source']
                : [];
            $certificateFence = \Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore::
                activeCertificateFenceForDomain(
                    $servingManifest,
                    (string)($source['domain'] ?? $activeCertificate['domain'] ?? ''),
                );
            if ((int)($certificateFence['generation'] ?? 0)
                    !== (int)($activeCertificate['generation'] ?? 0)
            ) {
                throw new \RuntimeException(
                    'Startup fallback serving manifest does not contain the exact active certificate generation.',
                );
            }
            foreach ([
                'source_digest',
                'trust_profile',
                'provider',
                'material_class',
                'provenance_digest',
                'cert_path',
                'key_path',
                'leaf_fingerprint_sha256',
            ] as $certificateField) {
                if (!\hash_equals(
                    (string)($certificateFence[$certificateField] ?? ''),
                    (string)($activeCertificate[$certificateField] ?? ''),
                )) {
                    throw new \RuntimeException(
                        'Startup fallback serving manifest certificate provenance changed.',
                    );
                }
            }
            $request = GatewayStartupFallbackRequest::issue(
                $instanceName,
                $endpoint,
                $activeCertificate,
                $failure,
            );
        } catch (\Throwable $throwable) {
            $this->printer->warning(__(
                'auto 纯 WLS 降级请求未通过本地身份/证书栅栏：%{1}',
                [$throwable->getMessage()],
            ));
            return false;
        }

        $accepted = false;
        $lastRequestDispatchAt = 0.0;
        $requestDeadline = \hrtime(true) / 1_000_000_000 + 5.0;
        do {
            $lastRequestDispatchAt = \hrtime(true) / 1_000_000_000;
            try {
                $result = $this->sendAutoGatewayStartupFallbackRequest(
                    $instanceName,
                    $request,
                    $requestDeadline,
                );
            } catch (\Throwable) {
                $result = [];
            }
            if (($result['success'] ?? false) === true
                && (($result['data']['accepted'] ?? false) === true)
            ) {
                $accepted = true;
                break;
            }
            try {
                $latest = $this->getInstanceManager()
                    ->getRawInstanceData($instanceName);
            } catch (\Throwable) {
                $latest = null;
            }
            if (\is_array($latest)
                && (GatewayRuntimeServingProjection::gatewayIsServing(
                    $latest,
                    $requestDeadline,
                )
                    || GatewayRuntimeServingProjection::fallbackServingEndpoint(
                        $latest,
                        $requestDeadline,
                    )
                        !== null
                    || GatewayRuntimeServingProjection::fallbackServingObservation(
                        $latest,
                        $requestDeadline,
                    )
                        !== null)
            ) {
                $accepted = true;
                break;
            }
            $this->sleepWithinStartupFallbackDeadline($requestDeadline);
        } while ((\hrtime(true) / 1_000_000_000) < $requestDeadline);
        if (!$accepted) {
            $this->printer->warning(__(
                '当前实例 Gateway Agent 未接受 auto 纯 WLS 降级请求。'
            ));
            return false;
        }

        $projectionDeadline = \hrtime(true) / 1_000_000_000
            + $this->startupFallbackProjectionTimeoutSeconds();
        do {
            try {
                $latest = $this->getInstanceManager()
                    ->getRawInstanceData($instanceName);
            } catch (\Throwable) {
                $latest = null;
            }
            if (\is_array($latest)) {
                $fallback = GatewayRuntimeServingProjection::fallbackServingEndpoint(
                    $latest,
                    $projectionDeadline,
                );
                if (\is_array($fallback)) {
                    try {
                        $this->syncServerConfigToEnv(
                            (string)($fallback['authority_host'] ?? '127.0.0.1'),
                            (int)$fallback['port'],
                            true,
                        );
                    } catch (\Throwable $throwable) {
                        $this->printer->warning(__(
                            '备用入口已验证，但无法同步显示配置：%{1}',
                            [$throwable->getMessage()],
                        ));
                    }
                    $this->printer->note(__(
                        '备用入口：%{1}（高端口不是 80/443 的透明替代，请同步检查防火墙、DNS 或负载均衡）。',
                        [(string)$fallback['origin']],
                    ));
                    return true;
                }
                $fallbackObservation = GatewayRuntimeServingProjection::
                    fallbackServingObservation($latest, $projectionDeadline);
                if (\is_array($fallbackObservation)) {
                    $this->printer->note(__(
                        '备用 TLS 监听：%{1}；路由域名：%{2}。',
                        [
                            (string)$fallbackObservation['bind_endpoint'],
                            \implode(', ', (array)$fallbackObservation['route_domains']),
                        ],
                    ));
                    $this->printer->warning(__(
                        '当前只有通配符路由，必须使用匹配证书的具体 hostname/SNI；监听 IP 不是可用 HTTPS 地址。'
                    ));
                    return true;
                }
                if (GatewayRuntimeServingProjection::gatewayIsServing(
                    $latest,
                    $projectionDeadline,
                )) {
                    return true;
                }
            }
            $now = \hrtime(true) / 1_000_000_000;
            if ($now - $lastRequestDispatchAt
                >= $this->startupFallbackRequestRedispatchSeconds()
            ) {
                // Master confirms only that the request was forwarded to the
                // current READY Agent. Re-send the same idempotent envelope so
                // an Agent reconnect between enqueue and processing cannot
                // strand startup until this projection deadline expires.
                $lastRequestDispatchAt = $now;
                try {
                    $this->sendAutoGatewayStartupFallbackRequest(
                        $instanceName,
                        $request,
                        $projectionDeadline,
                    );
                } catch (\Throwable) {
                    // The runtime projection remains authoritative. A newly
                    // READY Agent may accept the next bounded re-dispatch.
                }
            }
            $this->sleepWithinStartupFallbackDeadline($projectionDeadline);
        } while ((\hrtime(true) / 1_000_000_000) < $projectionDeadline);

        $this->printer->warning(__(
            'auto 纯 WLS 降级请求已接受，但未在期限内形成可验证的运行时服务投影。'
        ));
        return false;
    }

    private function sleepWithinStartupFallbackDeadline(
        float $deadlineMonotonic,
    ): void {
        $microseconds = self::boundedStartupFallbackSleepMicroseconds(
            $deadlineMonotonic,
            \hrtime(true) / 1_000_000_000,
        );
        if ($microseconds > 0) {
            SchedulerSystem::usleep($microseconds);
        }
    }

    private static function boundedStartupFallbackSleepMicroseconds(
        float $deadlineMonotonic,
        float $monotonicNow,
        int $maximumMicroseconds = 100_000,
    ): int {
        return self::boundedMonotonicDeadlineSleepMicroseconds(
            $deadlineMonotonic,
            $monotonicNow,
            $maximumMicroseconds,
        );
    }

    private static function boundedMonotonicDeadlineSleepMicroseconds(
        float $deadlineMonotonic,
        float $monotonicNow,
        int $maximumMicroseconds,
    ): int {
        if (!\is_finite($deadlineMonotonic)
            || !\is_finite($monotonicNow)
            || $maximumMicroseconds < 1
            || $deadlineMonotonic <= $monotonicNow
        ) {
            return 0;
        }
        $remainingSeconds = $deadlineMonotonic - $monotonicNow;
        if ($remainingSeconds >= $maximumMicroseconds / 1_000_000) {
            return $maximumMicroseconds;
        }
        $remainingMicroseconds = (int)\floor($remainingSeconds * 1_000_000);
        return \min($maximumMicroseconds, \max(0, $remainingMicroseconds));
    }

    private static function boundedNanosecondDeadlineSleepMicroseconds(
        int $deadlineNanoseconds,
        int $monotonicNowNanoseconds,
        int $maximumMicroseconds,
    ): int {
        if ($maximumMicroseconds < 1
            || $deadlineNanoseconds <= $monotonicNowNanoseconds
        ) {
            return 0;
        }
        $remainingNanoseconds = $deadlineNanoseconds - $monotonicNowNanoseconds;
        if ($remainingNanoseconds >= $maximumMicroseconds * 1_000) {
            return $maximumMicroseconds;
        }
        return \max(0, \intdiv($remainingNanoseconds, 1_000));
    }

    /** @return array<string,mixed> */
    protected function buildAutoGatewayStartupFallbackServingManifest(
        string $instanceName,
        float $deadlineMonotonic,
    ): array {
        return (new \Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder())
            ->buildServingManifest($instanceName, $deadlineMonotonic);
    }

    /**
     * @param array<string,int|string> $request
     * @return array<string,mixed>
     */
    protected function sendAutoGatewayStartupFallbackRequest(
        string $instanceName,
        array $request,
        float $deadlineMonotonic,
    ): array {
        return (new IpcControlGateway())->command(
            $instanceName,
            ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST,
            '',
            $request,
            1.0,
            $deadlineMonotonic,
        );
    }

    protected function startupFallbackProjectionTimeoutSeconds(): float
    {
        // Orchestrator's child READY budget is 30 seconds. Keep a bounded
        // publication margin so a healthy fallback is not retired at the exact
        // worker deadline while its endpoint CAS is still being persisted.
        return 45.0;
    }

    protected function startupFallbackRequestRedispatchSeconds(): float
    {
        // This is below the Agent's ten-second fallback command cadence and
        // above the launcher's projection poll cadence. Replays carry the same
        // request id/digest and therefore cannot allocate caller-chosen ports.
        return 2.0;
    }

    /**
     * A non-critical Gateway Agent may have committed registration before the
     * launcher's READY/public-edge gate fails. Fence and retire that possible
     * host mutation while the exact Master and Agent are still alive; local
     * process cleanup is not allowed to race an unfenced registration replay.
     */
    private function retirePossibleGatewayRegistrationBeforeFailedStartupCleanup(
        string $instanceName,
    ): void {
        try {
            $endpoint = $this->getInstanceManager()->getRawInstanceData($instanceName);
            if (!\is_array($endpoint)
                || !\Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection::participatesInGateway(
                    $endpoint,
                )
            ) {
                return;
            }
            $fact = \Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle::factForEndpoint(
                $endpoint,
            );
            if (\in_array((string)($fact['state'] ?? ''), [
                \Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle::STATE_RETIRING,
                \Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle::STATE_RETIRED,
            ], true)) {
                return;
            }
            (new \Weline\Server\Service\Edge\Gateway\GatewayRegistrationRetirementCoordinator())
                ->retire(
                    $instanceName,
                    1,
                    true,
                    false,
                );
        } catch (\Throwable $throwable) {
            // restoreOnFailure=false deliberately keeps the exact launch in
            // RETIRING/UNCERTAIN. The host lease expires to STALE/503 after
            // local cleanup; never issue a naked unregister here.
            $this->printer->warning(__(
                'WLS 启动失败前无法确认 Gateway 路由已注销：%{1}；'
                . '本地退休栅栏保持，宿主租约将过期为 STALE/503。',
                [\substr($throwable->getMessage(), 0, 512)],
            ));
        }
    }

    /**
     * Gate startup on the current instance's exact public host. An ACTIVE
     * route belonging to another project domain must never hide a missing or
     * failed primary route.
     *
     * @param list<mixed> $routes
     * @return array{
     *   public_host:string,
     *   primary_status:string,
     *   active:bool,
     *   challenge_only:bool,
     *   accepted:bool,
     *   active_count:int,
     *   states:list<string>
     * }
     */
    protected function resolveGatewayPrimaryRouteGate(
        array $routes,
        string $publicHost,
        bool $certificatePending,
    ): array {
        $publicHost = $this->normalizeCertificateDomainCandidate($publicHost);
        $primaryStatus = '';
        $activeCount = 0;
        $states = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                $states['UNKNOWN'] = 'UNKNOWN';
                continue;
            }
            $status = \strtoupper(\trim((string)($route['status'] ?? 'UNKNOWN')));
            $status = $status !== '' ? $status : 'UNKNOWN';
            $states[$status] = $status;
            if ($status === 'ACTIVE') {
                $activeCount++;
            }
            $routeDomain = $this->normalizeCertificateDomainCandidate(
                (string)($route['domain'] ?? ''),
            );
            if ($publicHost !== '' && \hash_equals($publicHost, $routeDomain)) {
                $primaryStatus = $status;
            }
        }
        $active = $primaryStatus === 'ACTIVE';
        $challengeOnly = $certificatePending
            && $primaryStatus === 'PENDING_CERTIFICATE';
        return [
            'public_host' => $publicHost,
            'primary_status' => $primaryStatus,
            'active' => $active,
            'challenge_only' => $challengeOnly,
            'accepted' => $active || $challengeOnly,
            'active_count' => $activeCount,
            'states' => \array_values($states),
        ];
    }

    /**
     * 打印结束语
     *
     * @param bool $success 是否成功
     * @param string $message 附加消息
     */
    protected function printGoodbye(bool $success = true, string $message = ''): void
    {
        $this->printer->note('');

        if ($success) {
            $this->printer->successIcon(__('Weline Server 启动完成！'));
        } else {
            $this->printer->errorIcon(__('Weline Server 启动失败'));
        }

        if ($message) {
            $this->printer->note('  ' . $message);
        }

        $this->printer->note('');
        $this->printer->note(__('使用 %{1}php bin/w server:status%{2} 查看服务器状态', ['<info>', '</info>']));
        $this->printer->note(__('使用 %{1}php bin/w server:stop%{2} 停止服务器', ['<info>', '</info>']));
        $this->printer->note('');
        $this->printer->note($this->colorize(str_repeat('─', 60), 'Blue'));
        $this->printer->note('');
    }

    private function resolveEdgeCliMode(array $args): ?string
    {
        $value = $args['edge'] ?? null;
        if (\is_array($value)) {
            $value = \end($value);
        }
        if (\is_scalar($value) && \trim((string)$value) !== '') {
            return $this->normalizePublicEdgeCliMode((string)$value);
        }
        foreach ($_SERVER['argv'] ?? [] as $argument) {
            $argument = (string)$argument;
            if (\str_starts_with($argument, '--edge=')) {
                return $this->normalizePublicEdgeCliMode(
                    \substr($argument, 7),
                );
            }
        }
        return null;
    }

    protected function normalizePublicEdgeCliMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));
        if (!\in_array($mode, [
            \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_AUTO,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_GATEWAY,
            \Weline\Server\Service\Edge\Gateway\GatewayStartupDecision::MODE_WLS,
        ], true)) {
            throw new \InvalidArgumentException((string)__(
                '--edge 仅允许 auto、gateway 或 wls；legacy 只保留给已保存的 WLS 1.x 实例。'
            ));
        }

        return $mode;
    }

    /**
     * 获取 Weline 版本
     */
    protected function getWelineVersion(): string
    {
        // 尝试从 composer.json 获取版本
        static $version = null;
        if ($version === null) {
            $composerFile = BP . 'composer.json';
            try {
                $composerRaw = GatewayProjectStateFilesystem::readOptional(
                    $composerFile,
                    1_048_576,
                    'Weline composer metadata',
                );
            } catch (\Throwable) {
                $composerRaw = null;
            }
            if ($composerRaw !== null) {
                $composer = \json_decode($composerRaw, true);
                $version = \is_array($composer)
                    ? (string)($composer['version'] ?? '3.0.0')
                    : '3.0.0';
            } else {
                $version = '3.0.0';
            }
        }
        return $version;
    }

    /**
     * 彩色化输出（内部使用 ANSI 颜色）
     *
     * @param string $text 文本
     * @param string $color 颜色
     * @return string
     */
    private function colorize(string $text, string $color): string
    {
        $colors = [
            'Black' => '30',
            'Red' => '31',
            'Green' => '32',
            'Yellow' => '33',
            'Blue' => '34',
            'Magenta' => '35',
            'Cyan' => '36',
            'White' => '37',
        ];

        $code = $colors[$color] ?? '34';
        return "\033[{$code}m{$text}\033[0m";
    }

    private static function diagnosticWallSeconds(): float
    {
        return (float)(new \DateTimeImmutable('now'))->format('U.u');
    }
}
