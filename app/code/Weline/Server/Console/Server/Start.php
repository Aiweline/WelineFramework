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
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Console\Server\Stop as MainStop;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\SharedSidecarInspector;
use Weline\Server\Service\SharedStateRuntimeResolver;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\WlsLogService;
use Weline\Server\Log\LogConfig;
use Weline\Server\Service\Policy\RuntimePolicyControlService;
use Weline\Server\Service\Policy\RuntimePolicyCompiler;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;
use Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper;
use Weline\Server\Service\Runtime\RuntimeDiagnosticsFormatter;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
use Weline\Server\Service\Runtime\ProtocolEdgeDependencyBootstrapper;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\TlsProcessProfileConfigurator;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;

/**
 * server:start - 启动常驻内存服务器
 */
class Start extends CommandAbstract
{
    /**
     * 默认 HTTP 端口（直连省去 Nginx）
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
     * 启动维护事务必须在一个总 deadline 内看到控制操作终态。
     * Direct Master 仅在全部 READY Worker ACK 后提交 maintenance_mode，
     * 因此“operation 已退出队列 + 状态相符”才允许启动命令报告成功。
     */
    private const MAINTENANCE_SYNC_TIMEOUT_SEC = 12.0;

    private const MAINTENANCE_SYNC_POLL_INTERVAL_USEC = 50_000;

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
    private const FRAMEWORK_RUNTIME_REGISTRY_FILES = FrameworkCompileManifest::GENERATION_FILES;

    /**
     * 启动中实例的 worker 端口预留 TTL（秒）
     */
    private const WORKER_PORT_RESERVATION_TTL = 120;
    
    /**
     * 可用的进程控制函数
     */
    protected array $availableFunctions = [];
    
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
     * 启动完成后尾部输出的延迟告警（用于确保提示位于最后且醒目）
     */
    private ?string $deferredStartupWarning = null;

    /**
     * Last startup preflight profile, reused by the post-start advisor so it
     * cannot drift from the resolver or rerun a listener capability probe.
     */
    private ?WlsRuntimeProfile $latestRuntimeProfile = null;
    private ?string $latestRuntimeProfileListenHost = null;

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
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
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
            $this->startCliServer($args, $data);
            return;
        }

        // 检测可用函数
        $this->detectAvailableFunctions();
        
        // Weline Server 不可用时自动回退到 CLI 服务器
        $cliService = ObjectManager::getInstance(CliServerService::class);
        if (!$cliService->isWelineServerAvailable()) {
            $this->printer->warning(__('Weline Server 不可用：%{1}', [$cliService->getUnavailableReason()]));
            $this->printer->note(__('自动回退到 PHP 内置 CLI 服务器...'));
            $this->printer->note(__(''));
            $this->startCliServer($args, $data);
            return;
        }
        
        // 解析实例名称
        $instanceName = $this->parseInstanceName($args);
        $this->traceStartupPhase($instanceName, 'execute:instance-parsed');
        
        // 仅运行 Master 进程（由 daemon 模式后台启动时调用，内部使用）
        // master-only 不需要启动锁，因为它是由已经获取锁的父进程启动的
        if (isset($args['master-only']) || getenv('WLS_MASTER_ONLY')) {
            $this->runMasterOnly($instanceName);
            return;
        }
        
        // 获取启动锁，防止并发启动同一实例
        if (!$this->acquireStartLock($instanceName)) {
            $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $instanceName . '.lock';
            $lockInfo = [];
            if (\is_file($lockFile)) {
                $lockData = \json_decode((string) @\file_get_contents($lockFile), true);
                $lockInfo = \is_array($lockData) ? $lockData : [];
            }
            $ownerPid = (int) ($lockInfo['pid'] ?? 0);

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
            if ($ownerPid > 0 && $ownerPid !== \getmypid()) {
                Processer::killProcessTreeByPid($ownerPid, true) || Processer::killByPid($ownerPid, true);
            }
            $this->cleanupFailedStartupProcesses($instanceName, 16);
            if (\is_file($lockFile)) {
                @\unlink($lockFile);
            }
            if ($this->acquireStartLock($instanceName, 2)) {
                $this->printer->success(__('已强制接管启动锁，继续启动实例 [%{1}]', [$instanceName]));
            } else {
                $this->printer->warning(__('强制清理后仍未获取启动锁，将按用户确认继续启动实例 [%{1}]。', [$instanceName]));
            }
        }
        
        // 注册关闭时释放锁；fatal / 未交接时按实例前缀清理可能残留的 WLS 子进程
        $this->startLockInstanceName = $instanceName;
        $this->wlsStartupProcessHandoffDone = false;
        $this->wlsChildProcessesMayExist = false;
        $this->traceStartupPhase($instanceName, 'start-lock:acquired');
        \register_shutdown_function([$this, 'releaseStartLock']);
        \register_shutdown_function([$this, 'shutdownCleanupOrphanWlsProcessesIfNeeded']);
        
        // --win：Windows 子进程可见窗口；--foreground：阻塞前台 Master（daemon=false）。
        // --frontend/-frontend 已弃用，等价于 --win（窗口），不再自动阻塞后台。
        if ($this->hasCliArgvToken(['--frontend', '-frontend'])) {
            $this->printer->warning(__('参数 --frontend/-frontend 已弃用：窗口可见请用 --win；阻塞前台 Master 请用 --foreground。'));
        }

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
        $this->traceStartupPhase($instanceName, 'config:before');
        $config = $this->getServerConfig($instanceName, $args);

        // 依赖决策必须基于与最终 RuntimeSelection 相同的 requested/effective
        // 拓扑事实。Direct 缺失依赖时 fail-closed；显式 Dispatcher 只把
        // ext-event 当作可选优化，安装失败也不允许改写拓扑。
        // master-only 是已经过父进程预检的内部重入路径，禁止重复安装。
        $runtimeResolver = new RuntimeStrategyResolver();
        try {
            $dependencyTopologyIntent = $runtimeResolver->resolveTopologyIntent($config, $args);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return;
        }
        if (!isset($args['master-only']) && !\getenv('WLS_MASTER_ONLY')) {
            /** @var RuntimeDependencyBootstrapper $dependencyBootstrapper */
            $dependencyBootstrapper = ObjectManager::getInstance(RuntimeDependencyBootstrapper::class);
            $dependencyResult = $dependencyBootstrapper->ensureOptimalRuntime(
                $args,
                $dependencyTopologyIntent['requested'],
                $dependencyTopologyIntent['effective'],
                empty($config['no_ssl']) && ($config['https'] ?? true) !== false,
            );
            $dependencyStatus = (string)($dependencyResult['status'] ?? 'failed');
            $dependencyMessage = (string)($dependencyResult['message'] ?? '');

            if ($dependencyStatus === 'failed') {
                $this->printer->error(__('WLS 最优运行时依赖自动安装失败：%{1}', [$dependencyMessage]));
                if (!empty($dependencyResult['output'])) {
                    $this->printer->note((string)$dependencyResult['output']);
                }
                if ($dependencyTopologyIntent['effective']->isDirect()) {
                    $this->printer->note(__('Direct 不会静默降级；请修复当前 PHP 的 sockets/OpenSSL/ext-event，或显式使用 --dispatcher。'));
                }
                return;
            }

            if ($dependencyStatus === 'platform_optimal' || $dependencyStatus === 'skipped') {
                $this->printer->note($dependencyMessage);
                if (!empty($dependencyResult['output'])) {
                    $this->printer->note((string)$dependencyResult['output']);
                }
            } elseif ($dependencyStatus === 'installed') {
                $this->printer->success(__('WLS 运行时依赖已自动安装并验证。'));
                if (!empty($dependencyResult['restart_required'])) {
                    $this->printer->note(__('正在使用已加载新扩展的 PHP 进程继续启动...'));
                    // 此时尚未创建任何 WLS 子进程；先释放实例启动锁，
                    // 否则重入的 server:start 会被父进程自己阻塞。
                    $this->releaseStartLock();
                    $exitCode = $dependencyBootstrapper->relaunchCurrentStartCommand();
                    if ($exitCode !== 0) {
                        $this->printer->error(__('依赖安装后的 WLS 重新启动失败，退出码：%{1}', [$exitCode]));
                    }
                    return;
                }
            }
        }
        $this->traceStartupPhase($instanceName, 'config:after', [
            'host' => (string)($config['host'] ?? ''),
            'port' => (int)($config['port'] ?? 0),
            'workers' => (int)($config['worker_count'] ?? 0),
            'no_ssl' => !empty($config['no_ssl']),
        ]);
        
        // 提示配置来源（已保存的实例配置时特别提示，让用户知道为什么不用指定端口）
        $source = $config['source'] ?? '';
        if (\is_string($source) && \str_contains($source, $instanceName) && $this->loadSavedInstanceConfig($instanceName) !== null) {
            $savedConfig = $this->loadSavedInstanceConfig($instanceName);
            $savedPort = $savedConfig['port'] ?? '?';
            $savedHost = $savedConfig['host'] ?? '?';
            $this->printer->note(__('使用已保存的实例配置：%{1} (%{2}:%{3})', [$instanceName, $savedHost, $savedPort]));
        }
        
        $host = $config['host'];
        $port = $config['port'];
        $count = $config['worker_count'];
        $this->traceStartupPhase($instanceName, 'host-allowlist:before', [
            'host' => (string)$host,
        ]);
        if (!$this->validateExternalHostAllowlist($instanceName, $host, $config)) {
            return;
        }
        $this->traceStartupPhase($instanceName, 'host-allowlist:after', [
            'public_host' => (string)($config['public_host'] ?? $host),
        ]);
        $publicHost = (string)($config['public_host'] ?? $host);
        $config['public_host'] = $publicHost;
        $daemon = $this->resolveDaemonMode($config, $foregroundMode);
        
        // --no-ssl 时仅 HTTP（端口保持 80）；否则默认启用 HTTPS
        $noSsl = !empty($config['no_ssl']);
        $portExplicit = isset($args['port']) || isset($args['p']);
        
        if ($noSsl) {
            $sslEnabled = false;
            $sslCert = '';
            $sslKey = '';
            $sslResult = ['success' => true, 'is_new' => false, 'issuer' => '', 'expires_at' => ''];
            if ($port === self::DEFAULT_PORT) {
                $port = self::DEFAULT_PORT;  // 保持 80
                $config['port'] = $port;
            }
            $this->printer->note(__('以 HTTP 运行（端口 %{1}）。由 wls.https=false 或 --no-ssl 生效。', [$port]));
        } else {
            // Windows 下未安装 event 时允许强制运行 SSL；提示延后到「服务器已在后台运行」之后输出（后台模式）或此处输出（前台模式）
            $isMasterOnly = isset($args['master-only']) || getenv('WLS_MASTER_ONLY');
            if (IS_WIN && !\extension_loaded('event') && !$isMasterOnly && !$daemon) {
                $this->printWindowsEventHttpsWarning();
            }

            $sslResult = $this->ensureSslCertificate($instanceName, $config);
            if (!$sslResult['success']) {
                $this->printer->error($sslResult['message']);
                return;
            }
            $sslCert = $sslResult['cert_path'] ?? '';
            $sslKey = $sslResult['key_path'] ?? '';
            $sslEnabled = (bool) ($sslResult['ssl_enabled'] ?? true);
            $config['ssl_cert'] = $sslCert;
            $config['ssl_key'] = $sslKey;
            $config['ssl_domain'] = (string)($config['public_host'] ?? $host);
            if (!$sslEnabled) {
                $disableReason = \trim((string)($sslResult['message'] ?? ''));
                $this->printer->warning(__('HTTPS 未启用：%{1}', [
                    $disableReason !== '' ? $disableReason : __('SSL 证书服务返回 HTTP 模式'),
                ]));
                $this->printer->note(__('本次将以 HTTP 运行；如需 HTTPS，请检查 wls.https 配置和证书管理中的域名 HTTPS 开关。'));
                $sslCert = '';
                $sslKey = '';
            } else {
                $port = $this->normalizeDefaultPortForSslState($port, $sslEnabled, $portExplicit);
                $config['port'] = $port;

                if ($sslResult['is_new'] ?? false) {
                    $this->printer->success(__('已生成新证书：%{1}', [$sslResult['issuer']]));
                } else {
                    $this->printer->note(__('使用已有证书：%{1}', [$sslResult['issuer']]));
                }
                if (!empty($sslResult['expires_at'])) {
                    $this->printer->note(__('证书有效期至：%{1}', [$sslResult['expires_at']]));
                }

                // 证书就绪后再生成 SNI 映射；启动/重启路径马上会拉起新实例，不能在这里同步等待旧 Master 的 SSL reload ACK。
                /** @var SslCertificateService $sslMapSync */
                $sslMapSync = ObjectManager::getInstance(SslCertificateService::class);
                $sslMapSync->regenerateCertificateMap(false);
            }
        }

        // 在停止旧实例前完成拓扑、事件循环与策略能力预检，避免预检失败造成停机。
        $this->traceStartupPhase($instanceName, 'runtime-strategy:before');
        $runtimeProfile = $this->detectRuntimeProfile($this->resolveServerListenHost((string)$host));
        try {
            $runtimeStrategy = $runtimeResolver->resolve($config, $args, $runtimeProfile);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return;
        }
        if ((string)$runtimeStrategy['requested_topology'] !== $dependencyTopologyIntent['requested']->value
            || (string)$runtimeStrategy['effective_topology'] !== $dependencyTopologyIntent['effective']->value
        ) {
            $this->printer->error(__('WLS 依赖预检与最终 RuntimeSelection 拓扑不一致；已拒绝启动。'));
            return;
        }
        $policyTopology = (string)$runtimeStrategy['topology'];
        if ($policyTopology === 'independent') {
            $policyTopology = 'direct';
        }
        $count = (int)$runtimeStrategy['worker_count'];
        $config['worker_count'] = $count;
        $config['runtime_strategy'] = (string)$runtimeStrategy['runtime_strategy'];
        $config['requested_topology'] = (string)$runtimeStrategy['requested_topology'];
        $config['topology'] = (string)$runtimeStrategy['topology'];
        if (!\is_array($config['runtime'] ?? null)) {
            $config['runtime'] = [];
        }
        $config['runtime']['topology'] = (string)$runtimeStrategy['topology'];
        $config['runtime']['listener_mode'] = (string)$runtimeStrategy['direct_listener_mode'];
        $config['direct_listener_mode'] = (string)$runtimeStrategy['direct_listener_mode'];
        $config['event_loop'] = (string)$runtimeStrategy['event_loop_driver'];
        $config['loop']['driver'] = (string)$runtimeStrategy['event_loop_driver'];
        $config['supervisor']['enabled'] = (bool)$runtimeStrategy['supervisor_enabled'];
        $dispatcherEnabled = (bool)$runtimeStrategy['dispatcher_enabled'];
        $supportsReusePort = $runtimeProfile->supportsReusePort();
        $useDirectMode = (string)$runtimeStrategy['topology'] === 'direct';
        try {
            $httpProtocolSelection = HttpProtocolSelection::fromConfig($config, $sslEnabled);
        } catch (\RuntimeException $exception) {
            $this->printer->error(__('WLS HTTP 协议配置无效：%{1}', [$exception->getMessage()]));
            return;
        }
        $protocolEdgeBinary = '';
        if ($httpProtocolSelection->isProtocolEdgeEnabled()) {
            $protocolEdgeDependency = (new ProtocolEdgeDependencyBootstrapper())->ensureAvailable(
                $args,
                $config,
                $httpProtocolSelection,
            );
            if ((string)($protocolEdgeDependency['status'] ?? 'failed') === 'failed') {
                $this->printer->error(__('WLS HTTP/3、HTTP/2 协商依赖预检失败：%{1}', [
                    (string)($protocolEdgeDependency['message'] ?? ''),
                ]));
                if (!empty($protocolEdgeDependency['output'])) {
                    $this->printer->note((string)$protocolEdgeDependency['output']);
                }
                return;
            }
            $protocolEdgeBinary = (string)($protocolEdgeDependency['binary'] ?? '');
            $this->printer->note((string)($protocolEdgeDependency['message'] ?? ''));
        }
        $config['http'] = \array_merge(
            \is_array($config['http'] ?? null) ? $config['http'] : [],
            $httpProtocolSelection->toConfig(),
            ['protocol_edge_binary' => $protocolEdgeBinary],
        );
        $protocolEdgeEnabled = $httpProtocolSelection->isProtocolEdgeEnabled();
        $runtimeStrategy['http_protocol_selection'] = $httpProtocolSelection->toArray();
        $runtimeStrategy['protocol_edge_enabled'] = $protocolEdgeEnabled;
        $runtimeStrategy['protocol_edge_binary'] = $protocolEdgeBinary;
        $this->printer->note(__('HTTP 自动协商：%{1}（优先 %{2}，TLS 会话复用：%{3}）', [
            \implode(' -> ', $httpProtocolSelection->protocols),
            $httpProtocolSelection->preferred,
            $httpProtocolSelection->tlsSessionResumption ? __('开启') : __('关闭'),
        ]));
        foreach ((new RuntimeDiagnosticsFormatter())->formatStartupSummary($runtimeProfile, $runtimeStrategy) as $runtimeLine) {
            if (\str_starts_with($runtimeLine, 'WARNING:') || \str_starts_with($runtimeLine, 'Warning:')) {
                $this->printer->warning($runtimeLine);
            } else {
                $this->printer->note($runtimeLine);
            }
        }
        try {
            $tlsProcessProfile = (new TlsProcessProfileConfigurator())->activate($config, $sslEnabled);
        } catch (\RuntimeException $exception) {
            $this->printer->error($exception->getMessage());
            return;
        }
        if (!\is_array($config['ssl'] ?? null)) {
            $config['ssl'] = [];
        }
        $config['ssl']['key_exchange_profile'] = $tlsProcessProfile['requested'];
        $config['ssl']['effective_key_exchange_profile'] = $tlsProcessProfile['effective'];
        $config['ssl']['process_openssl_conf'] = $tlsProcessProfile['openssl_conf'];
        $runtimeStrategy['tls_key_exchange_profile'] = $tlsProcessProfile['effective'];
        if ($sslEnabled) {
            $this->printer->note(
                'TLS key exchange: ' . $tlsProcessProfile['effective'] . ' - ' . $tlsProcessProfile['reason']
            );
        }
        $this->traceStartupPhase($instanceName, 'runtime-strategy:after', [
            'topology' => (string)$runtimeStrategy['topology'],
            'event_loop' => (string)$runtimeStrategy['event_loop_driver'],
            'workers' => (int)$count,
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
            return;
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
            return;
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
            ]);
        } catch (\Throwable $exception) {
            $this->printer->error(__('WLS 编译运行时预检失败：%{1}', [$exception->getMessage()]));
            return;
        }
        if (empty($policyCheck['valid'])) {
            $this->printer->error(__('WLS 运行时策略不支持当前拓扑：%{1}', [$runtimeStrategy['topology']]));
            foreach ((array)($policyCheck['errors'] ?? []) as $policyError) {
                $this->printer->note('  - ' . (string)$policyError);
            }
            if ((string)$runtimeStrategy['topology'] === 'direct') {
                $this->printer->note(__('Direct 不会静默忽略关键策略；请修复策略能力，或显式使用 --dispatcher。'));
            }
            return;
        }
        // The staging validator is intentionally discarded. Policy activation
        // later reloads the atomically promoted final registry.
        $policyControl = new RuntimePolicyControlService();
        $runtimeStrategy['policy_compatible'] = true;
        $runtimeStrategy['policy_digest'] = (string)($policyCheck['bundle']['digest'] ?? '');
        $runtimeStrategy['container_registry_digest'] = $containerRegistryDigest;
        if (!\is_array($config['runtime'] ?? null)) {
            $config['runtime'] = [];
        }
        $config['runtime']['container_registry_digest'] = $containerRegistryDigest;

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
                $forceSwitchStopStart = \microtime(true);
                $this->traceStartupPhase($instanceName, 'force-switch-stop:before');
                if (!$this->stopExistingServer($instanceName, $port, $count, true, 0, true)) {
                    $this->rollbackRestartMaintenanceTransactionIfPending();
                    return;
                }
                $this->traceStartupPhase($instanceName, 'force-switch-stop:after', [
                    'elapsed_ms' => (int) \round((\microtime(true) - $forceSwitchStopStart) * 1000),
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
                $this->printer->warning(__('检测到服务器已运行，平滑重启：先开启维护模式并等待全部 Worker 请求排空...'));
                $this->beginRestartMaintenanceTransaction($instanceName);
                $this->enableMaintenanceMode($instanceName);
                $maintenanceEnabledByUs = true;
                $this->printer->success(__('全部 READY Worker 已完成维护门禁与请求排水，开始切换...'));
                
                if (!$this->stopExistingServer($instanceName, $port, $count)) {
                    $this->disableMaintenanceMode($instanceName);
                    return;
                }
            }
        }

        // Worker 基础端口：默认 10000 + 项目偏移量，确保多项目不冲突
        $defaultWorkerBasePort = 10000 + MasterProcess::getProjectPortOffset();
        $workerBasePort = (int) ($config['worker_base_port'] ?? $defaultWorkerBasePort);
        $this->printer->note(__('Worker基础端口: %{1}', [$workerBasePort]));
        try {
            $this->traceStartupPhase($instanceName, 'shared-runtime:before');
            $sharedStateRuntime = $this->resolveSharedStateRuntimeConfig($instanceName, $config, $forceRestart, $windowMode);
            $this->printer->note(__('共享状态运行时: %{1}', [$sharedStateRuntime]));
            $this->traceStartupPhase($instanceName, 'shared-runtime:after', [
                'session_port' => (int)($sharedStateRuntime['session']['port'] ?? 0),
                'memory_port' => (int)($sharedStateRuntime['memory']['port'] ?? 0),
            ]);
        } catch (\RuntimeException $exception) {
            $this->printer->note(__('共享状态运行时解析失败: %{1}', [$exception->getMessage()]));
            $this->printer->error($exception->getMessage());
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return;
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
        $config['session_server_token_file_name'] = (string) ($sharedStateRuntime['session']['token_file_name'] ?? 'session_server.token');
        $config['memory_server_port'] = $memoryServerPort;
        $config['memory_server_token_file_name'] = (string) ($sharedStateRuntime['memory']['token_file_name'] ?? 'memory_server.token');
        $config['shared_state'] = $sharedStateRuntime;
        $this->printSharedStateRuntimeSummary($instanceName, $sharedStateRuntime);

        // Worker 端口计算移至端口冲突检测之后，避免重复计算
        // Dispatcher 只做 TCP 透传和流量控制，不做 SSL 握手
        // SSL 握手始终由 Worker 处理（无论是否使用 Dispatcher）
        $workerSslEnabled = $sslEnabled && !$protocolEdgeEnabled;
        
        // ========== HTTP Redirect：固定规则：仅 HTTPS=443 时启用 80；非 443 不启独立 Worker ==========
        $httpRedirectPort = 0;
        if ($sslEnabled) {
            $httpRedirectPort = ($port === 443) ? 80 : 0;
            if ($httpRedirectPort === 0) {
                $this->printer->note(__('HTTPS 非 443：未启用独立 HTTP 重定向 Worker；明文入口仍可由 Dispatcher 内联跳转 HTTPS'));
            }
        }
        
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
            return;
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
            return;
        }
        $this->traceStartupPhase($instanceName, 'main-port-preflight:after', [
            'port' => (int)$port,
            'in_use' => (bool)($mainPortInspect['in_use'] ?? false),
        ]);

        $reservedWorkerPorts = $this->getWorkerAllocationReservedPorts(
            $port,
            $dispatcherEnabled || $protocolEdgeEnabled,
        );
        if ($protocolEdgeEnabled) {
            $reservedWorkerPorts[] = ProtocolEdgeRuntime::adminPortForInstance($instanceName, $port);
            $reservedWorkerPorts = \array_values(\array_unique($reservedWorkerPorts));
        }
        $requiresWorkerPortAllocationLock = $protocolEdgeEnabled || (!$useDirectMode && $count > 1);
        $workerPortAllocationLocked = false;
        if ($requiresWorkerPortAllocationLock) {
            $this->traceStartupPhase($instanceName, 'worker-port-lock:before', [
                'workers' => (int)$count,
            ]);
            if (!$this->acquireWorkerPortAllocationLock()) {
                $this->printer->error(__('无法分配 Worker 端口：全局端口分配锁正被其他启动流程占用'));
                $this->printer->note(__('请稍后重试，或等待其他实例启动完成'));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return;
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
                $dispatcherEnabled || $protocolEdgeEnabled,
                $useDirectMode && !$protocolEdgeEnabled,
            );

            if ($forceRestart && !$forceSwitch && !$skipPostStopPortInspection && $this->hasRestartCleanupResidue($instanceName, $port, $count, $workerPort, $forceSwitch)) {
                $this->printer->error(__('强制重启前仍检测到旧实例 [%{1}] 的残留 WLS 进程或端口，已中止启动。', [$instanceName]));
                $this->printer->note(__('必须先完成旧实例清理，禁止自动切换主端口或 Worker 端口启动第二个同名实例。'));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return;
            }

        // Dispatcher 模式或独立端口模式：Worker 端口段需智能分配
        // - WLS 进程占用的端口：释放后分配给新进程
        // - 非 WLS 进程占用的端口：跳过，使用下一个可用端口
        if (!$forceRestart && ($dispatcherEnabled || $protocolEdgeEnabled || (!$useDirectMode && $count > 1))) {
            $nextWorkerPort = $this->findAvailableWorkerPortBase(
                $workerPort,
                $count,
                500,
                $instanceName,
                $reservedWorkerPorts,
                $protocolEdgeEnabled,
                $dispatcherEnabled,
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

        // HTTP Redirect 端口被非框架进程占用时：先展示占用进程详情（PID/名称/命令行），
        // 在交互式终端询问是否强制结束；用户同意则尝试释放，后续晚判会二次校验；
        // 用户拒绝或处于非交互模式则按既有方案给出指引并退出，避免误杀宝塔/Nginx 等关键进程。
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

            $confirmed = false;
            if ($this->isInteractiveTerminal() && \defined('STDIN')) {
                $this->printer->warning(__('是否强制结束该进程并释放 HTTP 重定向端口 %{1}？[y/N]: ', [$httpRedirectPort]));
                echo '  > ';
                $input = \trim((string) @\fgets(STDIN));
                $confirmed = \in_array(\strtolower($input), ['y', 'yes', '是'], true);
            }

            if (!$confirmed) {
                $this->printer->note('');
                $this->printer->setup(__('解决方案：'));
                $this->printer->note(__('  1. 手动停止占用端口 %{1} 的进程', [$httpRedirectPort]));
                if ($occupantPid > 0) {
                    $this->printer->note(__('     - Windows: taskkill /F /PID %{1}', [$occupantPid]));
                    $this->printer->note(__('     - Linux/macOS: kill -9 %{1}', [$occupantPid]));
                }
                $this->printer->note(__('  2. 或使用框架命令释放：php bin/w server:kill-port %{1} -f', [$httpRedirectPort]));
                $this->printer->note(__('  3. 或改用非 443 主端口启动（将不启用独立 HTTP 重定向 Worker）'));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return;
            }

            $this->printer->note(__('正在强制结束占用端口 %{1} 的进程 (PID: %{2})...', [
                $httpRedirectPort,
                $occupantPid > 0 ? (string) $occupantPid : '?',
            ]));
            $released = Processer::killProcessByPort($httpRedirectPort);
            if (!$released) {
                $released = Processer::forceReleasePort($httpRedirectPort);
            }
            if (Processer::isPortInUse($httpRedirectPort)) {
                $waited = 0;
                while ($waited < 3000 && Processer::isPortInUse($httpRedirectPort)) {
                    SchedulerSystem::usleep(300000);
                    $waited += 300;
                }
                $released = !Processer::isPortInUse($httpRedirectPort);
            }
            if (Processer::isPortInUse($httpRedirectPort)) {
                $this->printer->error(__('无法释放端口 %{1}', [$httpRedirectPort]));
                $this->printer->note(__('提示：可改用非 443 主端口启动（将不启用独立 HTTP 重定向 Worker），或手动结束 PID %{1} 后重试', [
                    $occupantPid > 0 ? (string) $occupantPid : '?',
                ]));
                $this->rollbackRestartMaintenanceTransactionIfPending();
                return;
            }

            $this->printer->success(__('端口 %{1} 已释放，HTTP 重定向 Worker 将正常启动', [$httpRedirectPort]));
        }

        // Linux/Mac 非 root 绑定特权端口时，自动触发 sudo 密码输入并重启当前命令
        $this->traceStartupPhase($instanceName, 'permission-check:before');
        if (!$this->ensurePrivilegedPortPermission($port, $httpRedirectPort, $sslEnabled)) {
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return;
        }
        
        // Linux/macOS 下检测 socket 权限（即使高端口也可能因系统安全设置需要 sudo）
        if (!$this->ensureUnixSocketPermission($host, $port)) {
            $this->rollbackRestartMaintenanceTransactionIfPending();
            return;
        }
        $this->traceStartupPhase($instanceName, 'permission-check:after');
        
        // 显示启动信息
        // 使用 $useDirectMode 而非重新计算，确保与架构选择逻辑一致

        // 80/443 端口自我处理提示（特权端口、单端口建议）
        if (!$dispatcherEnabled && !$useDirectMode && $count > 1) {
            // 独立端口模式
            $this->printer->note(__('提示：当前为独立端口模式，%{1} 个 Worker 分别监听端口 %{2}-%{3}。', [$count, $workerPort, $workerPort + $count - 1]));
        } elseif ($useDirectMode && $protocolEdgeEnabled) {
            $this->printer->note(__('提示：当前为 Direct + 协议边缘模式；公开端口 %{1} 自动协商 h3/h2/h1，Worker 使用本机端口 %{2}-%{3}。', [
                $port,
                $workerPort,
                $workerPort + $count - 1,
            ]));
        } elseif ($useDirectMode && $count > 1) {
            // 直连模式
            $listenerLabel = (string)$runtimeStrategy['direct_listener_mode'] === 'shared_fd'
                ? __('Master 共享监听 FD')
                : 'SO_REUSEPORT';
            $this->printer->note(__('提示：当前为 %{1} 直连模式，多 Worker 共用同一端口 %{2}。', [$listenerLabel, $port]));
        }

        // 检查端口是否被占用（框架进程占用时最多重试 3 次，仍占用则按 Master 前缀清理逃逸 Master 后再试）
        $this->traceStartupPhase($instanceName, 'port-release-check:before', [
            'main_port' => (int)$port,
            'worker_port' => (int)$workerPort,
            'workers' => (int)$count,
            'dispatcher' => $dispatcherEnabled,
            'skipped' => $skipPostStopPortInspection,
        ]);
        if (!$skipPostStopPortInspection && $protocolEdgeEnabled) {
            if (!$this->checkAndReleasePort($host, $port, $forceRestart, 'HTTP Protocol Edge', $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                }
                return;
            }
            if (!$this->checkAndReleasePorts('127.0.0.1', $workerPort, $count, $forceRestart, $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                }
                return;
            }
            if ($dispatcherEnabled) {
                $edgeDispatcherPort = ProtocolEdgeRuntime::dispatcherPortFromWorkerRange($workerPort, $count);
                if (!$this->checkAndReleasePort('127.0.0.1', $edgeDispatcherPort, $forceRestart, 'Internal Dispatcher', $instanceName)) {
                    if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                        $this->disableMaintenanceMode($instanceName);
                    }
                    return;
                }
            }
        } elseif (!$skipPostStopPortInspection && $dispatcherEnabled) {
            // Dispatcher 模式：检查主端口（Dispatcher 用）+ Worker 内网端口
            if (!$this->checkAndReleasePort($host, $port, $forceRestart, 'Dispatcher', $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return;
            }
            if (!$this->checkAndReleasePorts($host, $workerPort, $count, $forceRestart, $instanceName)) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return;
            }
        } elseif (!$skipPostStopPortInspection) {
            // 直连模式：
            // - SO_REUSEPORT: 多 Worker 复用同一端口，只检查主端口
            // - 非 SO_REUSEPORT: 仍按连续端口检查
            $checkResult = $useDirectMode
                ? $this->checkAndReleasePort($host, $port, $forceRestart, 'Worker(Main)', $instanceName)
                : $this->checkAndReleasePorts($host, $workerPort, $count, $forceRestart, $instanceName);
            if (!$checkResult) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                    $this->printer->note(__('维护状态已恢复到重启前配置（端口检查未通过）。'));
                }
                return;
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
                    return;
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

                // 深橙色警告提示
                $this->printer->warning(__('HTTP Redirect 端口 %{1} 被占用: %{2}', [$httpRedirectPort, $processName]));
                $this->printer->note(__('是否强制停用该进程以释放端口？[y/N]: ', []));
                echo '  > ';
                $input = \trim((string)@\fgets(STDIN));
                if (!\in_array(\strtolower($input), ['y', 'yes', '是'], true)) {
                    $this->printer->note(__('已取消。HTTP Redirect 端口 %{1} 无法使用，将不启用 HTTP→HTTPS 重定向。', [$httpRedirectPort]));
                    $this->printer->note(__('提示：可改用非 443 主端口启动（将不启用独立 HTTP 重定向 Worker）'));
                    // 禁用 HTTP Redirect
                    $httpRedirectPort = 0;
                } else {
                    // 用户确认，尝试强制释放
                    $this->printer->note(__('正在强制停用占用端口 %{1} 的进程...', [$httpRedirectPort]));
                    $released = Processer::killProcessByPort($httpRedirectPort);
                    if (!$released) {
                        $released = Processer::forceReleasePort($httpRedirectPort);
                    }
                    if (IS_WIN && !$released) {
                        $waited = 0;
                        while ($waited < 3000 && Processer::isPortInUse($httpRedirectPort)) {
                            SchedulerSystem::usleep(300000);
                            $waited += 300;
                        }
                        $released = !Processer::isPortInUse($httpRedirectPort);
                    }
                    if (!Processer::isPortInUse($httpRedirectPort)) {
                        $this->printer->success(__('端口 %{1} 已释放，HTTP Redirect 将正常启动', [$httpRedirectPort]));
                    } else {
                        $this->printer->error(__('无法释放端口 %{1}，HTTP Redirect 将不启用', [$httpRedirectPort]));
                        $this->printer->note(__('提示：可改用非 443 主端口启动（将不启用独立 HTTP 重定向 Worker）'));
                        $httpRedirectPort = 0;
                    }
                }
            }
        }
        
            // 创建 Worker 脚本路径（Dispatcher 模式下使用非 SSL 脚本）
            wls_http_redirect_conflict_done:
            // 旧代已退出、新 Master/Worker 尚未创建：在此激活启动预检选中的同一份策略。
            // Worker READY 会以该 digest 为准，禁止同一代出现编译参数不同的混合策略。
            try {
                $policyBundle = $policyControl->activateForStart(
                    $instanceName,
                    $policyTopology,
                    $config,
                );
                $runtimeStrategy['policy_digest'] = $policyBundle->digest;
            } catch (\Throwable $exception) {
                if (!empty($maintenanceEnabledByUs) || !empty($maintenanceResetAfterForceSwitch)) {
                    $this->disableMaintenanceMode($instanceName);
                }
                $this->printer->error(__('WLS 启动策略激活失败：%{1}', [$exception->getMessage()]));
                return;
            }
            // 保存实例信息（Master 将从这里读取配置并启动所有进程）
            $workerScript = $this->ensureWorkerScript($workerSslEnabled);
            $orchestratorRuntimeOptions = $this->buildOrchestratorRuntimeOptions($windowMode);
            $listenHost = $this->resolveServerListenHost((string)$host);
            $this->traceStartupPhase($instanceName, 'save-instance:before');
            $this->saveInstanceInfo($instanceName, $listenHost, $port, $count, $daemon, $sslEnabled, $sslCert, $sslKey, $dispatcherEnabled, $workerPort, $httpRedirectPort, $windowMode, $enableLog, $useDirectMode, $workerBasePort, $sharedStateRuntime, $orchestratorRuntimeOptions, (string) ($config['worker_memory_limit'] ?? '256M'), (string) ($config['dispatcher_memory_limit'] ?? ''), $publicHost, \is_array($config['gateway'] ?? null) ? $config['gateway'] : [], $runtimeStrategy);
            $this->traceStartupPhase($instanceName, 'save-instance:after');
        } finally {
            if ($workerPortAllocationLocked) {
                $this->releaseWorkerPortAllocationLock();
            }
        }
        
        // 保存实例配置（配置记忆：下次 server:start <name> 直接使用相同配置）
        // 同时将实际的 host/port/https 同步到 env.php，供 http:req 等 CLI 工具读取
        $this->traceStartupPhase($instanceName, 'save-config:before');
        $this->saveInstanceConfig($instanceName, $args, $config);
        $this->syncServerConfigToEnv($host, $port, $sslEnabled);
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
        // Master 统一管理：Dispatcher、Worker、HTTP Redirect
        $config['worker_port'] = $workerPort;
        $config['dispatcher_enabled'] = $dispatcherEnabled;
        $config['master_mode'] = $useDirectMode
            ? MasterProcess::MODE_DIRECT
            : ($dispatcherEnabled ? MasterProcess::MODE_DISPATCHER : MasterProcess::MODE_INDEPENDENT);
        $config['orchestrator_runtime_options'] = $this->buildOrchestratorRuntimeOptions($windowMode);
        // 同步 daemon 标志到 config（$daemon 已根据 --frontend 参数覆盖，
        // 但 $config['daemon'] 仍是 env 默认值 true，导致 MasterProcess::log() 跳过控制台输出）
        $config['daemon'] = $daemon;

        // 将 .local 域名转换为 127.0.0.1 用于实际监听
        // 域名仅用于 SSL 证书，实际监听使用 IP 避免 PHP DNS 解析问题
        $listenHost = $this->resolveServerListenHost((string)$host);

        if ($daemon) {
            $this->wlsChildProcessesMayExist = true;
            $this->traceStartupPhase($instanceName, 'master-background:before');
            $startupCompleted = $this->startMasterInBackground($instanceName, $sslEnabled, $listenHost, $port, $foregroundMode, $windowMode);
            $this->traceStartupPhase($instanceName, 'master-background:after', [
                'completed' => $startupCompleted,
            ]);
            $this->wlsStartupProcessHandoffDone = true;
            // 后台模式：Master 已独立启动，释放启动锁
            $this->releaseStartLock();
            // 关闭由本次启动流程引入的维护态：仅在新 Master 全部就绪后才关，避免空窗。
            $this->finalizeMaintenanceModeAfterStartup(
                $instanceName,
                $maintenanceEnabledByUs,
                $maintenanceResetAfterForceSwitch,
                $startupCompleted
            );
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
                $useDirectMode
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
        $this->runMasterProcess($instanceName, $config, $workerScript, $sslCert, $sslKey, $sslEnabled, $httpRedirectPort, $windowMode);
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
        $command = \sprintf('reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings" /v %s 2>NUL', $valueName);
        $output = @\shell_exec($command);
        if (!\is_string($output) || $output === '') {
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
     * 仅运行 Master 进程（由 startMasterInBackground 通过子进程调用，从实例文件恢复状态）
     * 非 Windows 下调用 posix_setsid() 脱离控制终端，避免 SSH 断开或父进程退出时收到 SIGHUP 导致 Master 退出。
     */
    protected function runMasterOnly(string $instanceName): void
    {
        if (!IS_WIN && \function_exists('posix_setsid')) {
            @\posix_setsid();
        }
        $instanceFile = $this->getRuntimeInstanceFile($instanceName);
        if (!\is_file($instanceFile)) {
            $this->printer->error(__('实例文件不存在：%{1}', [$instanceFile]));
            return;
        }
        $content = \file_get_contents($instanceFile);
        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            $this->printer->error(__('实例文件无效'));
            return;
        }
        $endpointSchemaVersion = (int)($data['schema_version'] ?? 2);
        if ($endpointSchemaVersion > RuntimeSelection::ENDPOINT_SCHEMA_VERSION) {
            $this->printer->error(__('master-only 启动已拒绝：不支持实例 endpoint schema v%{1}。', [$endpointSchemaVersion]));
            return;
        }

        $persistedRuntimeSelection = null;
        if ($endpointSchemaVersion === RuntimeSelection::ENDPOINT_SCHEMA_VERSION) {
            try {
                if (!\is_array($data['runtime_selection'] ?? null)) {
                    throw new \RuntimeException('WLS endpoint schema v3 requires runtime_selection.');
                }
                $persistedRuntimeSelection = RuntimeSelection::fromArray($data['runtime_selection']);
                $persistedRuntimeSelection->assertEndpointProjection($data);
                if (!$persistedRuntimeSelection->policyCompatible) {
                    throw new \RuntimeException('WLS endpoint policy compatibility is false.');
                }
                if (!\is_string($data['runtime_strategy'] ?? null) || \trim($data['runtime_strategy']) === '') {
                    throw new \RuntimeException('WLS endpoint schema v3 requires runtime_strategy.');
                }
                if (!\is_string($data['policy_digest'] ?? null) || \trim($data['policy_digest']) === '') {
                    throw new \RuntimeException('WLS endpoint schema v3 requires policy_digest.');
                }
                if (!\is_string($data['container_registry_digest'] ?? null)
                    || \preg_match('/^[a-f0-9]{64}$/D', \strtolower(\trim($data['container_registry_digest']))) !== 1
                ) {
                    throw new \RuntimeException('WLS endpoint schema v3 requires container_registry_digest.');
                }
                if (!\is_bool($data['supervisor_enabled'] ?? null)) {
                    throw new \RuntimeException('WLS endpoint schema v3 requires boolean supervisor_enabled.');
                }
                if (!\is_string($data['supervisor_reason'] ?? null) || \trim($data['supervisor_reason']) === '') {
                    throw new \RuntimeException('WLS endpoint schema v3 requires supervisor_reason.');
                }
            } catch (\RuntimeException $exception) {
                $this->printer->error(__('master-only 启动已拒绝：实例 endpoint schema v3 校验失败（%{1}）。', [
                    $exception->getMessage(),
                ]));
                return;
            }
        }
        $expectedContainerDigest = \strtolower(\trim((string)($data['container_registry_digest'] ?? '')));
        if (\preg_match('/^[a-f0-9]{64}$/D', $expectedContainerDigest) !== 1) {
            $this->printer->error(__('master-only 启动已拒绝：实例缺少有效的 container_registry_digest。'));
            return;
        }
        try {
            ContainerRuntime::preflight($expectedContainerDigest);
        } catch (\Throwable $exception) {
            $this->printer->error(__('master-only 启动已拒绝：编译容器预检失败（%{1}）。', [
                $exception->getMessage(),
            ]));
            return;
        }
        // master-only 权限门禁（Unix）：
        // 避免子进程/复活链路在非 root 下拉起 Master，导致后续子进程绑定 80/443 失败。
        // 注意：setcap cap_net_bind_service 授权后，非 root 用户也可绑定特权端口，
        // 因此通过实际 stream_socket_server 测试替代单纯的 euid 检查。
        if (!IS_WIN && \function_exists('posix_geteuid')) {
            $mainPort = (int)($data['port'] ?? 0);
            $redirectPort = ($mainPort === 443) ? 80 : 0;
            $sslEnabledFlag = (bool)($data['ssl_enabled'] ?? false);
            $needsPrivileged = ($mainPort > 0 && $mainPort < 1024)
                || ($sslEnabledFlag && $redirectPort > 0 && $redirectPort < 1024);
            if ($needsPrivileged && (int)\posix_geteuid() !== 0) {
                // 非 root 但可能有 setcap，尝试实际绑定测试
                $testPort = $mainPort > 0 && $mainPort < 1024 ? $mainPort : $redirectPort;
                $testSock = @\stream_socket_server("tcp://0.0.0.0:{$testPort}", $errno, $errstr, STREAM_SERVER_BIND);
                if ($testSock) {
                    @\fclose($testSock);
                    // setcap 生效，允许继续
                } else {
                    $this->printer->error(__('master-only 启动被拒绝：特权端口 %{1} 无法绑定（%{2}）。', [$testPort, $errstr]));
                    $this->printer->note(__('请执行 sudo setcap cap_net_bind_service=+ep $(which php) 授权，或使用 sudo。'));
                    return;
                }
            }
        }
        $sslEnabled = (bool)($data['ssl_enabled'] ?? false);
        $dispatcherEnabled = (bool)($data['dispatcher_enabled'] ?? false);
        try {
            $httpProtocolSelection = \is_array($data['http_protocol_selection'] ?? null)
                ? HttpProtocolSelection::fromArray($data['http_protocol_selection'])
                : HttpProtocolSelection::fromConfig([
                    'http' => [
                        'protocols' => [HttpProtocolSelection::HTTP_1],
                        'preferred' => HttpProtocolSelection::HTTP_1,
                        'protocol_edge' => HttpProtocolSelection::EDGE_DISABLED,
                    ],
                ], $sslEnabled);
        } catch (\RuntimeException $exception) {
            $this->printer->error(__('master-only 启动已拒绝：HTTP 协议选择无效（%{1}）。', [
                $exception->getMessage(),
            ]));
            return;
        }
        $protocolEdgeEnabled = $httpProtocolSelection->isProtocolEdgeEnabled();
        $protocolEdgeBinary = \trim((string)($data['protocol_edge_binary'] ?? ''));
        if ($protocolEdgeEnabled
            && !ProtocolEdgeRuntime::isRunnableBinary($protocolEdgeBinary)
        ) {
            $this->printer->error(__('master-only 启动已拒绝：预检通过的 HTTP 协议边缘二进制已不可用。'));
            return;
        }
        // 协议边缘启用时由 WLS Native Engine 终止 TLS/QUIC，Worker 保持私有 HTTP/1.1 长连接。
        $workerScript = $this->ensureWorkerScript($sslEnabled && !$protocolEdgeEnabled);
        $port = (int)($data['port'] ?? 443);
        $workerPort = (int)($data['worker_port'] ?? $port);
        // 默认端口 10000 + 项目偏移量，确保多项目不冲突
        $defaultWorkerBasePort = 10000 + MasterProcess::getProjectPortOffset();
        $workerBasePort = (int)($data['worker_base_port'] ?? $defaultWorkerBasePort);
        $workerCount = (int)($data['count'] ?? 1);
        $effectiveTopology = $persistedRuntimeSelection instanceof RuntimeSelection
            ? $persistedRuntimeSelection->effectiveTopology->value
            : (string)($data['topology'] ?? (
                !empty($data['master_mode']) && MasterProcess::isDirectMode((string)$data['master_mode'])
                    ? 'direct'
                    : ($dispatcherEnabled ? 'dispatcher' : 'independent')
            ));
        if ($effectiveTopology === 'independent') {
            $this->printer->error(__('master-only 启动已拒绝：independent 拓扑尚不具备完整 READY 和策略保证，请使用 direct 或 --dispatcher。'));
            return;
        }
        if (IS_WIN && $effectiveTopology !== 'dispatcher') {
            $this->printer->error(__('master-only 启动已拒绝：Windows 只支持 Dispatcher 透传拓扑。'));
            return;
        }
        $orchestratorRuntimeOptions = \is_array($data['orchestrator_runtime_options'] ?? null)
            ? $data['orchestrator_runtime_options']
            : [];
        $config = [
            'host' => (string)($data['host'] ?? '127.0.0.1'),
            'public_host' => (string)($data['public_host'] ?? ($data['host'] ?? '127.0.0.1')),
            'port' => $port,
            'worker_count' => $workerCount,
            'topology' => $effectiveTopology,
            'runtime_strategy' => (string)($data['runtime_strategy'] ?? 'auto'),
            'event_loop' => (string)($data['event_loop_driver'] ?? 'auto'),
            'loop' => ['driver' => (string)($data['event_loop_driver'] ?? 'auto')],
            'supervisor' => [
                'enabled' => (bool)($data['supervisor_enabled'] ?? false),
            ],
            'runtime' => [
                'topology' => $effectiveTopology,
                'listener_mode' => (string)($data['direct_listener_mode'] ?? ''),
                'container_registry_digest' => (string)($data['container_registry_digest'] ?? ''),
            ],
            'direct_listener_mode' => (string)($data['direct_listener_mode'] ?? ''),
            'ssl' => [
                'engine' => (string)($data['ssl_engine'] ?? 'stream'),
                'key_exchange_profile' => (string)($data['tls_key_exchange_profile'] ?? 'performance'),
            ],
            'http' => \array_merge($httpProtocolSelection->toConfig(), [
                'protocol_edge_binary' => $protocolEdgeBinary,
            ]),
            'dispatcher_enabled' => $dispatcherEnabled,
            'worker_port' => $workerPort,
            'worker_base_port' => $workerBasePort,
            'worker_memory_limit' => ServiceContext::normalizeMemoryLimit($data['worker_memory_limit'] ?? '256M'),
            'dispatcher_memory_limit' => ServiceContext::normalizeMemoryLimit(
                $data['dispatcher_memory_limit'] ?? ($data['worker_memory_limit'] ?? '256M'),
                ServiceContext::normalizeMemoryLimit($data['worker_memory_limit'] ?? '256M')
            ),
            'session_server_port' => (int) ($data['session_server_port'] ?? (19970 + MasterProcess::getProjectPortOffset())),
            'session_server_token_file_name' => (string) ($data['session_server_token_file_name'] ?? 'session_server.token'),
            'memory_server_port' => (int) ($data['memory_server_port'] ?? (19971 + MasterProcess::getProjectPortOffset())),
            'memory_server_token_file_name' => (string) ($data['memory_server_token_file_name'] ?? 'memory_server.token'),
            'shared_state' => \is_array($data['shared_state'] ?? null) ? $data['shared_state'] : [],
            'gateway' => \is_array($data['gateway'] ?? null) ? $data['gateway'] : [],
            'daemon' => (bool) ($data['daemon'] ?? true),
            'orchestrator_runtime_options' => $orchestratorRuntimeOptions,
        ];
        // HTTPS 模式固定规则：仅 443 启动 80 端口 Redirect Worker
        $httpRedirectPort = 0;
        if ($sslEnabled) {
            $httpRedirectPort = ($port === 443) ? 80 : 0;
        }
        // window_mode 优先；兼容旧 frontend 字段
        $windowMode = (bool) ($data['window_mode'] ?? $data['frontend'] ?? false);

        // 读取进程日志开关（-log / 阻塞前台 Master 写入的 enable_log）
        $daemonSaved = (bool) ($data['daemon'] ?? true);
        $enableLog = (bool) ($data['enable_log'] ?? false) || !$daemonSaved;
        if ($enableLog) {
            Processer::setLogEnabled(true);
        }
        LogConfig::bootstrapVerbose($enableLog);

        // 读取 Master 运行模式。
        $masterMode = (string)($data['master_mode'] ?? match ($effectiveTopology) {
            'direct' => MasterProcess::MODE_DIRECT,
            'dispatcher' => MasterProcess::MODE_DISPATCHER,
            'independent' => MasterProcess::MODE_INDEPENDENT,
            default => MasterProcess::MODE_LEGACY,
        });
        $mainPort = (int)($data['main_port'] ?? $port);
        
        /** @var MasterProcess $master */
        $master = ObjectManager::getInstance(MasterProcess::class);
        $this->configureMasterRuntime(
            $master,
            $dispatcherEnabled,
            $workerCount,
            $workerBasePort,
            $workerPort,
            $masterMode,
            $mainPort
        )->setPrinter($this->printer)
            // 恢复运行态配置（由 Start.php 保存）
            ->init($instanceName, $config, $workerScript, (string)($data['ssl_cert'] ?? ''), (string)($data['ssl_key'] ?? ''), $sslEnabled, $httpRedirectPort, $windowMode)
            ->run();
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
    protected function startMasterInBackground(
        string $instanceName,
        bool $sslEnabled = false,
        string $host = '127.0.0.1',
        int $port = 443,
        bool $foregroundMode = false,
        bool $windowMode = false
    ): bool {
        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = BP . 'bin' . DS . 'w';
        
        $masterName = MasterProcess::getMasterProcessName($instanceName);
        $cmd = $this->buildMasterBackgroundCommand($phpBinary, $script, $instanceName, $masterName, $foregroundMode, $windowMode);
        $spawnedMasterPid = 0;
        $this->traceStartupPhase($instanceName, 'master-spawn:before', [
            'windows' => IS_WIN,
            'foreground' => $foregroundMode,
            'window_mode' => $windowMode,
        ]);
        if (IS_WIN) {
            if ($foregroundMode) {
                $foregroundPid = $this->startForegroundManagedProcess($cmd);
                $this->persistForegroundLauncherPid($instanceName, $cmd, $foregroundPid);
            } elseif (\method_exists(Processer::class, 'createWindowsDetachedPhpArgv')) {
                $argv = $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $foregroundMode, $windowMode);
                $pid = Processer::createWindowsDetachedPhpArgv($argv, BP, $cmd);
                $spawnedMasterPid = \max(0, (int) $pid);
                if ($pid <= 0) {
                    $bp = \str_replace("'", "''", BP);
                    $phpBin = \str_replace("'", "''", $phpBinary);
                    $argList = $this->buildPowerShellArgumentListLiteral(\array_slice($argv, 1));
                    $psCmd = "Set-Location -LiteralPath '" . $bp . "'; Start-Process -FilePath '" . $phpBin . "' -ArgumentList " . $argList . " -WindowStyle Hidden -WorkingDirectory '" . $bp . "'";
                    $fullCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . \str_replace('"', '\"', $psCmd) . '"';
                    @\exec($fullCmd . ' 2>NUL');
                }
            } else {
                $bp = \str_replace("'", "''", BP);
                $phpBin = \str_replace("'", "''", $phpBinary);
                $argv = $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $foregroundMode, $windowMode);
                $argList = $this->buildPowerShellArgumentListLiteral(\array_slice($argv, 1));
                $psCmd = "Set-Location -LiteralPath '" . $bp . "'; Start-Process -FilePath '" . $phpBin . "' -ArgumentList " . $argList . " -WindowStyle Hidden -WorkingDirectory '" . $bp . "'";
                $fullCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . \str_replace('"', '\"', $psCmd) . '"';
                @\exec($fullCmd . ' 2>NUL');
            }
        } else {
            Processer::create($cmd, false);
        }
        $this->traceStartupPhase($instanceName, 'master-spawn:after', [
            'spawned_pid' => $spawnedMasterPid,
        ]);
        
        // ========== 启动确认机制 ==========
        // 轮询检查后台 Master 是否成功启动
        $instanceFile = $this->getRuntimeInstanceFile($instanceName);
        $maxWaitMs = $this->resolveBackgroundMasterConfirmWaitMs($spawnedMasterPid);
        // READY is persisted atomically by the Master, so startup confirmation
        // does not need a coarse 200ms polling cadence. Keep Windows slightly
        // more conservative while POSIX returns as soon as the control plane
        // has published its state.
        $waitStepMs = IS_WIN ? 100 : 50;
        $hardWaitMs = $this->resolveBackgroundMasterControlHardWaitMs($spawnedMasterPid);
        $waited = 0;
        $masterStarted = false;
        $startupCompleted = false;
        $lastMasterPid = 0;
        $lastControlPort = 0;
        $lastStartupPhase = '';
        $readyResult = null;
        
        // 阶段 A：等待 master_pid + control_port 写入实例文件。
        // 启动确认只相信 Master 控制面上报，不再用 tasklist/PID 反查判断存活。
        $this->traceStartupPhase($instanceName, 'master-control-wait:before', [
            'soft_wait_ms' => $maxWaitMs,
            'hard_wait_ms' => $hardWaitMs,
            'spawned_pid' => $spawnedMasterPid,
        ]);
        while ($waited < $maxWaitMs) {
            SchedulerSystem::usleep($waitStepMs * 1000);
            $waited += $waitStepMs;
            
            // 检查实例文件是否已更新
            if (\is_file($instanceFile)) {
                $content = @\file_get_contents($instanceFile);
                if ($content !== false) {
                    $data = \json_decode($content, true);
                    if (\is_array($data)) {
                        $masterPid = (int)($data['master_pid'] ?? 0);
                        $controlPort = (int)($data['control_port'] ?? 0);
                        $startupPhase = (string) ($data['startup_phase'] ?? '');
                        
                        // 检测到新的 master_pid 和 control_port
                        if ($masterPid > 0 && $controlPort > 0) {
                            // 验证进程是否存活
                            // The instance file is the Master control-plane handshake; do not probe PID here.
                            $masterStarted = true;
                            $lastMasterPid = $masterPid;
                            $lastControlPort = $controlPort;
                            $lastStartupPhase = $startupPhase;
                            break;
                        }
                    }
                }
            }

        }
        $this->traceStartupPhase($instanceName, 'master-control-wait:after', [
            'waited_ms' => $waited,
            'started' => $masterStarted,
            'master_pid' => $lastMasterPid,
            'control_port' => $lastControlPort,
            'phase' => $lastStartupPhase,
        ]);

        if ($masterStarted) {
            if ($lastStartupPhase !== 'running') {
                $this->printer->note(__('Master 已启动，等待所有服务就绪...'));
                $backgroundStartupData = $this->readBackgroundStartupData($instanceFile);
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
                $startupCompleted = $readyResult['ready'];
                $lastStartupPhase = (string) ($readyResult['data']['startup_phase'] ?? $lastStartupPhase);
                $this->traceStartupPhase($instanceName, 'background-ready-wait:after', [
                    'waited_ms' => (int)($readyResult['waited_ms'] ?? 0),
                    'ready' => $startupCompleted,
                    'phase' => $lastStartupPhase,
                ]);
            } else {
                $startupCompleted = true;
            }

            if ($startupCompleted) {
                $this->printer->success(__('服务器已在后台运行（Master PID: %{1}, 控制端口: %{2}）', [$lastMasterPid, $lastControlPort]));
                $this->printer->success(__('所有服务已就绪，启动完成。'));
                $this->printer->note(__('使用 php bin/w server:status 查看状态，php bin/w server:stop 停止服务。'));
            } else {
                $phaseLabel = $this->normalizeBackgroundStartupPhase($lastStartupPhase !== '' ? $lastStartupPhase : 'bootstrapping');
                $readyWaitSec = \max(1, (int) \ceil(((int) ($readyResult['waited_ms'] ?? 0)) / 1000));
                $startupTerminalFailure = $this->isBackgroundStartupTerminalFailure((array)($readyResult['data'] ?? []));
                if ($startupTerminalFailure) {
                    $this->printer->error('WLS background startup failed (phase: ' . $phaseLabel . ', waited: ' . $readyWaitSec . 's).');
                }
                if (!$startupTerminalFailure) {
                    $this->printer->warning(__('Master 已在后台运行（PID: %{1}, 控制端口: %{2}），但未在 %{3} 秒内等到所有服务就绪（当前阶段：%{4}）。', [$lastMasterPid, $lastControlPort, $readyWaitSec, $phaseLabel]));
                }
                $failureReason = $this->readStartupFailureReason((array) ($readyResult['data'] ?? []));
                $this->printStartupFailureDiagnostics((array) ($readyResult['data'] ?? []));
                if ($failureReason !== '') {
                    $this->printer->warning(__('启动失败原因：%{1}', [$failureReason]));
                }
                $this->printer->note(__('本次启动未视为完成，请稍后执行以下命令检查状态：'));
                $this->printer->note(__('  php bin/w server:status'));
                $this->printer->note(__('  php bin/w server:status --all'));
            }
        } else {
            // 启动确认失败：只依据 Master 控制面上报，不再用 tasklist/PID 反查判断存活。
            $this->printer->warning(__('后台启动已发起，但未能在 %{1} 秒内确认 Master 控制面就绪。', [$maxWaitMs / 1000]));
            $this->printer->note(__('可能原因：'));
            $this->printer->note(__('  1. 框架加载耗时较长（首次启动或 opcache 未预热）'));
            $this->printer->note(__('  2. 端口被占用导致启动失败'));
            $this->printer->note(__('  3. 权限不足（特权端口需要 root/sudo）'));
            $this->printer->note(__(''));
            $this->printer->note(__('请执行以下命令检查状态：'));
            $this->printer->note(__('  php bin/w server:status'));
            $this->printer->note(__('  php bin/w server:status --all'));
            $this->printer->note(__('The instance may still be inside an Orchestrator bootstrap or full-restart cycle; avoid repeating server:start based only on this warning.'));
            $this->printer->note(__('Set wls.orchestrator.background_master_confirm_wait_sec to extend the Master control-plane confirmation window.'));

            // 输出日志文件路径便于排查
            $logDir = WlsLogService::getLogDir($instanceName);
            $this->printer->note(__('日志目录：%{1}', [$logDir]));
        }
        
        if (\function_exists('flush')) {
            @\flush();
        }
        
        // Windows + HTTPS 时在提示之后集中输出，避免被前面输出淹没
        if (IS_WIN && $sslEnabled) {
            if (!\extension_loaded('event')) {
                $this->printWindowsEventHttpsWarning();
            }
            $this->showWindowsNginxProxyHint($host, $port);
        }

        return $startupCompleted;
    }

    /**
     * @return list<string>
     */
    protected function buildMasterBackgroundArgv(
        string $phpBinary,
        string $script,
        string $instanceName,
        string $masterName,
        bool $foregroundMode = false,
        bool $windowMode = false
    ): array {
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

        if ($windowMode) {
            $argv[] = '--window-title=' . MasterProcess::getMasterProcessDisplayName($instanceName, true);
        }

        return $argv;
    }

    protected function buildMasterBackgroundCommand(
        string $phpBinary,
        string $script,
        string $instanceName,
        string $masterName,
        bool $foregroundMode = false,
        bool $windowMode = false
    ): string {
        $phpCommand = '"' . \str_replace('"', '\"', $phpBinary) . '"';
        $phpArguments = \implode(' ', \array_map(
            'escapeshellarg',
            \Weline\Server\Service\LongRunningPhpRuntime::startupCliArguments()
        ));
        $command = $phpCommand;
        if ($phpArguments !== '') {
            $command .= ' ' . $phpArguments;
        }
        $command .= ' ' . \escapeshellarg($script)
            . ' server:start '
            . \escapeshellarg($instanceName)
            . ' --master-only';

        if ($foregroundMode) {
            $command .= ' --foreground';
        }

        if ($windowMode) {
            $command .= ' --win';
        }

        $command .= ' --name=' . \escapeshellarg($masterName);

        if ($windowMode) {
            $command .= ' --window-title=' . \escapeshellarg(MasterProcess::getMasterProcessDisplayName($instanceName, true));
        }

        return $command;
    }

    /**
     * @param list<string> $arguments
     */
    protected function buildPowerShellArgumentListLiteral(array $arguments): string
    {
        $quoted = [];
        foreach ($arguments as $argument) {
            $quoted[] = "'" . \str_replace("'", "''", $argument) . "'";
        }

        return \implode(',', $quoted);
    }

    protected function resolveBackgroundMasterConfirmWaitMs(int $spawnedMasterPid = 0): int
    {
        $configuredSec = (float) ($this->getEnvironmentValue('wls.orchestrator.background_master_confirm_wait_sec', 0.0) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int) \round(\max(0.5, \min(900.0, $configuredSec)) * 1000);
        }

        $configuredStartupSec = (float) ($this->getEnvironmentValue('wls.orchestrator.startup_timeout_sec', 0.0) ?? 0.0);
        if ($configuredStartupSec > 0.0) {
            return (int) \round(\max(30.0, \min(900.0, $configuredStartupSec)) * 1000);
        }

        // Windows can create the PHP process several seconds before the Master control
        // plane writes instance metadata. Do not use tasklist/PID probing here; wait only
        // for the control-plane report. The window must cover cold framework boot and
        // orchestrator recovery cycles; 18s is too short for real WLS startup on Windows.
        if (IS_WIN) {
            return 120000;
        }

        return $spawnedMasterPid > 0 ? 60000 : 30000;
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
        // 硬上限默认为控制面上报窗口的 4 倍，封顶 60 秒。
        return (int) \min(60000, \max($softMs, $softMs * 4));
    }

    protected function resolveBackgroundStartupReadyWaitMs(array $instanceData = []): int
    {
        $configuredSec = (float) ($this->getEnvironmentValue('wls.orchestrator.background_ready_wait_sec', 0.0) ?? 0.0);
        if ($configuredSec > 0.0) {
            return (int) \round(\max(5.0, \min(900.0, $configuredSec)) * 1000);
        }

        $workerCount = \max(1, (int) ($instanceData['count'] ?? $instanceData['worker_count'] ?? 1));
        $dispatcherEnabled = (bool) ($instanceData['dispatcher_enabled'] ?? false);
        $sslEnabled = (bool) ($instanceData['ssl_enabled'] ?? false);
        $startupTimeout = $this->getEnvironmentValue('wls.orchestrator.startup_timeout_sec', null);
        if ($startupTimeout !== null && (float) $startupTimeout > 0.0) {
            $startupTimeoutSec = \max(5.0, \min(300.0, (float) $startupTimeout));
            $timeoutSec = $startupTimeoutSec
                + \max(0, $workerCount - 1) * 4.0
                + ($dispatcherEnabled ? 8.0 : 0.0)
                + ($sslEnabled ? 5.0 : 0.0);

            return (int) \round(\max(5.0, \min(180.0, $timeoutSec)) * 1000);
        }

        // 默认软超时：按并发子服务规模估算（与 Orchestrator 批量拉起对齐），避免固定 15s 在 8 进程栈上偏紧。
        $workerCount = \max(1, (int) ($instanceData['count'] ?? $instanceData['worker_count'] ?? 1));
        $dispatcherEnabled = (bool) ($instanceData['dispatcher_enabled'] ?? false);
        $sslEnabled = (bool) ($instanceData['ssl_enabled'] ?? false);
        $softSec = 12.0
            + \max(0, $workerCount - 1) * 2.5
            + ($dispatcherEnabled ? 4.0 : 0.0)
            + ($sslEnabled ? 3.0 : 0.0);

        return (int) \round(\max(15.0, \min(90.0, $softSec)) * 1000);
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
        foreach (['win', 'window'] as $name) {
            if (\array_key_exists($name, $args) && $this->isTruthyCliFlagValue($args[$name])) {
                return true;
            }
        }

        if (\array_key_exists('frontend', $args) && $this->isTruthyCliFlagValue($args['frontend'])) {
            return true;
        }

        foreach ($args as $key => $value) {
            if (\is_int($key) && \is_string($value)
                && \in_array($value, ['--win', '-win', '--window', '--frontend', '-frontend'], true)) {
                return true;
            }
        }

        return $this->hasCliArgvToken(['--win', '-win', '--window', '--frontend', '-frontend']);
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
            SchedulerSystem::usleep($waitStepMs * 1000);
            $waited += $waitStepMs;
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
        if (!\is_file($instanceFile)) {
            return [];
        }

        $content = @\file_get_contents($instanceFile);
        if ($content === false) {
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
            'dispatcher_enabled',
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
     * Explain the Windows Worker loop fallback. Public TLS/ALPN/QUIC is owned
     * by the native WLS protocol engine and does not depend on ext-event.
     */
    protected function printWindowsEventHttpsWarning(): void
    {
        $extDir = \ini_get('extension_dir');
        $extDirAbs = $extDir;
        if ($extDir) {
            if (\preg_match('#^[a-zA-Z]:[\\\\/]|^/#', $extDir)) {
                $extDirAbs = \is_dir($extDir) ? \realpath($extDir) : $extDir;
            } else {
                $phpDir = \defined('PHP_BINARY') && PHP_BINARY ? \dirname(PHP_BINARY) : '';
                $candidate = $phpDir ? $phpDir . \DIRECTORY_SEPARATOR . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $extDir) : $extDir;
                $extDirAbs = \is_dir($candidate) ? \realpath($candidate) : $candidate;
            }
        }
        $extPath = $extDirAbs ?: $extDir ?: __('(未知)');
        $iniFile = \php_ini_loaded_file() ?: __('(未找到，请 php --ini 查看)');
        echo "\n";
        $this->printer->warning(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->warning(__('║  【Windows Worker】当前未安装可信且 ABI 匹配的 PHP event 扩展。             ║'));
        $this->printer->warning(__('║  Worker 将使用稳定的 stream/select 事件循环；不会关闭任何安全策略。         ║'));
        $this->printer->warning(__('║  HTTP/3、HTTP/2、TLS 1.3 与会话复用仍由 WLS Native Protocol Engine 提供。  ║'));
        $this->printer->warning(__('╠══════════════════════════════════════════════════════════════════════════════╣'));
        $this->printer->warning(__('║  WLS 只会自动安装 PHP版本、架构、TS/NTS 与依赖均可验证的 event DLL。       ║'));
        $this->printer->warning(__('║  不会为了显示“已安装”而加载来源不明或 ABI 不匹配的 DLL。                    ║'));
        $this->printer->warning(__('║  ext 目录：%{1}                                                                ║', [$extPath]));
        $this->printer->warning(__('║  php.ini：%{1}                                                                 ║', [$iniFile]));
        $this->printer->warning(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
    }
    
    /**
     * 运行 Master 进程（监控并自动重启 Worker；HTTPS 启用时可自动启动 HTTP 重定向进程）
     */
    protected function runMasterProcess(string $instanceName, array $config, string $workerScript, string $sslCert = '', string $sslKey = '', bool $sslEnabled = false, int $httpRedirectPort = 0, bool $windowMode = false): void
    {
        $masterPid = \getmypid();
        
        // 更新实例信息，记录 Master PID
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
        
        $dispatcherEnabled = (bool) ($config['dispatcher_enabled'] ?? true);
        $workerCount = $config['worker_count'] ?? null;
        $workerBasePort = isset($config['worker_base_port']) ? (int) $config['worker_base_port'] : null;
        $workerPort = isset($config['worker_port']) ? (int) $config['worker_port'] : null;
        $masterMode = (string)($config['master_mode'] ?? match ((string)($config['topology'] ?? '')) {
            'direct' => MasterProcess::MODE_DIRECT,
            'dispatcher' => MasterProcess::MODE_DISPATCHER,
            'independent' => MasterProcess::MODE_INDEPENDENT,
            default => MasterProcess::MODE_LEGACY,
        });

        /** @var MasterProcess $master */
        $master = ObjectManager::getInstance(MasterProcess::class);
        try {
            $this->configureMasterRuntime(
                $master,
                $dispatcherEnabled,
                $workerCount,
                $workerBasePort,
                $workerPort,
                $masterMode,
                (int) ($config['port'] ?? 0)
            )->setPrinter($this->printer)
                ->setOnStartedCallback(function () {
                    $this->wlsStartupProcessHandoffDone = true;
                    $this->releaseStartLock();
                })
                ->init($instanceName, $config, $workerScript, $sslCert, $sslKey, $sslEnabled, $httpRedirectPort, $windowMode)
                ->run();
        } catch (\Throwable $e) {
            // 启动中途失败时，强制清理当前实例已拉起的子进程，避免半启动残留。
            $this->cleanupFailedStartupProcesses($instanceName, (int) ($config['worker_count'] ?? 0));
            $this->wlsChildProcessesMayExist = false;
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
    protected function cleanupFailedStartupProcesses(string $instanceName, int $workerCount = 0): void
    {
        $workerCount = $workerCount > 0 ? $workerCount : 16;
        $scopedWorkerPrefix = MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName) . '-';
        $scopedMaintenancePrefix = MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName) . '-';
        $prefixes = [
            MasterProcess::getMasterProcessName($instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-session', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-memory', $instanceName),
            MasterProcess::buildScopedProcessName('weline-wls-redirect', $instanceName),
            $scopedWorkerPrefix,
            $scopedMaintenancePrefix,
        ];

        Processer::killByProcessNamePrefixes(\array_values(\array_unique($prefixes)));

        Processer::removePidFile('--name=' . MasterProcess::getMasterProcessName($instanceName));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $instanceName));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', $instanceName));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-memory', $instanceName));
        Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-redirect', $instanceName));
        for ($i = 1; $i <= $workerCount; $i++) {
            Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', $instanceName, $i));
            Processer::removePidFile('--name=' . MasterProcess::buildScopedProcessName('weline-wls-maintenance', $instanceName, $i));
        }

        Processer::cleanupStalePidFiles();
    }

    protected function configureMasterRuntime(
        MasterProcess $master,
        bool $dispatcherEnabled,
        int|string|null $workerCount,
        ?int $workerBasePort,
        ?int $workerPort,
        string $masterMode,
        int $mainPort
    ): MasterProcess {
        $runtimeWorkerBasePort = $workerBasePort;
        if (!MasterProcess::isDirectMode($masterMode) && $workerPort !== null && $workerPort > 0) {
            $runtimeWorkerBasePort = $workerPort - 1;
        }

        return $master
            ->setMode($masterMode)
            ->setMainPort($mainPort)
            ->setDispatcherEnabled($dispatcherEnabled)
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
            $managerConfig['session_server_token_file_name'] = (string)($resolvedRuntime['session']['token_file_name'] ?? 'session_server.token');
            $managerConfig['memory_server_port'] = (int)($resolvedRuntime['memory']['port'] ?? 0);
            $managerConfig['memory_server_token_file_name'] = (string)($resolvedRuntime['memory']['token_file_name'] ?? 'memory_server.token');

            $ensuredRuntime = $this->createSharedStateServiceManager()->ensureRuntime(
                $instanceName,
                $managerConfig,
                $envConfig,
                $windowMode,
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
            $sessionToken = 'session_server.token';
        }
        $memoryToken = (string) ($config['memory_server_token_file_name'] ?? '');
        if ($memoryToken === '') {
            $memoryToken = 'memory_server.token';
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
        
        ServerInstanceManager::updateJsonFileAtomically($instanceFile, function (array $data) use ($masterPid, $enabled): array {
            $data['master_enabled'] = $enabled;
            $data['master_pid'] = $masterPid;
            $data['master_started_at'] = \date('Y-m-d H:i:s');
            return $data;
        });
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
        $startedAtNs = \hrtime(true);
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            if ($instanceName !== null && $instanceName !== '') {
                // 启动刚完成时实例管理器的运行态缓存可能尚未收敛；显式实例必须
                // 直接使用 Master endpoint，不能让广播层 attempted=[] 伪装成功。
                $commandResult = $gateway->setMaintenanceMode($instanceName, $enabled);
                $result = [
                    'success' => !empty($commandResult['success']),
                    'attempted' => [$instanceName],
                    'results_by_instance' => [$instanceName => $commandResult],
                    'message' => (string)($commandResult['message'] ?? 'unknown'),
                ];
            } else {
                /** @var BroadcastControlDispatchService $dispatchService */
                $dispatchService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
                $result = $dispatchService->setMaintenanceMode($enabled, null);
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

            $deadlineNs = $startedAtNs + (int)(self::MAINTENANCE_SYNC_TIMEOUT_SEC * 1_000_000_000);
            $lastObserved = [];

            while ($pending !== [] && \hrtime(true) < $deadlineNs) {
                foreach ($pending as $targetInstance => $operationId) {
                    $remainingSec = ($deadlineNs - \hrtime(true)) / 1_000_000_000;
                    if ($remainingSec <= 0) {
                        break 2;
                    }

                    $status = $gateway->getStatus(
                        $targetInstance,
                        \max(0.1, \min(0.75, $remainingSec))
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
                    . self::MAINTENANCE_SYNC_TIMEOUT_SEC . 's (' . \implode('; ', $details) . ')'
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
     * 启动 PHP 内置 CLI 服务器（委托给 Framework）
     */
    protected function startCliServer(array $args, array $data): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('╔════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║        PHP 内置 CLI 服务器                         ║'));
        $this->printer->note(__('╚════════════════════════════════════════════════════╝'));
        $this->printer->note(__(''));
        
        // 移除 --cli，避免 Framework 解析异常
        $cliArgs = $args;
        unset($cliArgs['cli']);
        
        $cliStart = ObjectManager::getInstance(\Weline\Server\Console\Console\Server\Start::class);
        $cliStart->execute($cliArgs, $data);
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
            'worker_base_port' => 10000 + MasterProcess::getProjectPortOffset(),  // Dispatcher 模式下 Worker 内网端口基数 + 项目偏移
            'worker_memory_limit' => '256M',
            'runtime_strategy' => 'auto',
            'topology' => 'auto',
            'runtime' => ['strategy' => 'auto', 'topology' => 'auto'],
            'event_loop' => 'auto',
            'loop' => ['driver' => 'auto'],
            'http' => [
                'protocols' => HttpProtocolSelection::DEFAULT_PROTOCOLS,
                'preferred' => HttpProtocolSelection::HTTP_3,
                'protocol_edge' => 'auto',
                'protocol_edge_binary' => '',
                'tls_session_resumption' => true,
                'alt_svc' => true,
            ],
            'supervisor' => ['enabled' => 'auto'],
            'source' => __('默认值'),
        ];
        
        $config = $defaults;
        
        // 1. 加载已保存的实例配置（配置记忆）
        // 优先级：命令行参数 > env 配置 > 已保存实例配置 > 默认值
        $savedConfig = $this->loadSavedInstanceConfig($instanceName);
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
        
        $wlsServers = ($envConfig['wls'] ?? [])['servers'] ?? [];
        $instanceTopologyExplicit = false;
        // 2. 多实例：wls.servers[实例名]
        if ($instanceName !== 'default' && isset($wlsServers[$instanceName]) && \is_array($wlsServers[$instanceName])) {
            $instanceConfig = $wlsServers[$instanceName];
            $instanceRuntime = \is_array($instanceConfig['runtime'] ?? null) ? $instanceConfig['runtime'] : [];
            $instanceTopologyExplicit = \array_key_exists('topology', $instanceRuntime);
            // 移除 env 配置中的 worker_base_port，强制使用带项目偏移的默认值
            unset($instanceConfig['worker_base_port']);
            $config = \array_merge($config, $instanceConfig);
            $config['source'] = __('env.wls.servers.%{1}', [$instanceName]);
        }
        // 3. 默认实例：wls
        elseif (isset($envConfig['wls']) && \is_array($envConfig['wls'])) {
            $baseWls = $envConfig['wls'];
            unset($baseWls['servers'], $baseWls['log'], $baseWls['session']);
            // 移除 env 配置中的 worker_base_port，强制使用带项目偏移的默认值
            unset($baseWls['worker_base_port']);
            $config = \array_merge($config, $baseWls);
            $config['source'] = __('env.wls');
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
        } elseif (\array_key_exists('topology', $savedConfig ?? [])) {
            // 仅读兼容旧实例根级 topology。
            $config['topology'] = (string)$savedConfig['topology'];
        }
        if ($savedWorkerCountExplicit) {
            // A count remembered from an explicit -c/--count belongs to this
            // instance and therefore outranks the global wls.worker_count
            // default. Runtime-resolved auto counts are never persisted here.
            $config['worker_count'] = \max(1, (int)$savedConfig['worker_count']);
            $config['_instance_worker_count_explicit'] = true;
        }

        $config = $this->applyGatewayTrafficModeTopology($config);

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
            $config['host'] = $args['host'];
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        if (isset($args['port']) || isset($args['p'])) {
            $config['port'] = (int) ($args['port'] ?? $args['p']);
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
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
        if (isset($args['topology'])) {
            $config['topology'] = (string)$args['topology'];
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
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $sessionTokenArg = $args['session-token-file-name']
            ?? $args['session_token_file_name']
            ?? null;
        if ($sessionTokenArg !== null) {
            $config['session_server_token_file_name'] = (string)$sessionTokenArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $memoryPortArg = $args['memory-port']
            ?? $args['memory_port']
            ?? null;
        if ($memoryPortArg !== null) {
            $config['memory_server_port'] = (int)$memoryPortArg;
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        $memoryTokenArg = $args['memory-token-file-name']
            ?? $args['memory_token_file_name']
            ?? null;
        if ($memoryTokenArg !== null) {
            $config['memory_server_token_file_name'] = (string)$memoryTokenArg;
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
        
        /** @var SslCertificateService $sslService */
        $sslService = ObjectManager::getInstance(SslCertificateService::class);

        // 如果已配置 SSL 域名但本地证书文件丢失，优先从证书管理重载到 app/etc/ssl
        if (!empty($config['ssl_domain'])) {
            $this->restoreManagedCertificateForConfig($config, $sslService, (string) $config['host']);
        }

        // 如果未显式配置 SSL，检查是否有已存在的证书可用
        if (empty($config['no_ssl']) && empty($config['ssl_cert']) && empty($config['ssl_key'])) {
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
        }
        
        // 确保本地域名（0.0.0.0/127.0.0.1/localhost）有自签证书
        if (empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'local-certificates:before');
            $this->ensureLocalSelfSignedCertificates($config);
            $this->traceStartupPhase($instanceName, 'local-certificates:after');
        } else {
            $this->traceStartupPhase($instanceName, 'local-certificates:skipped-http-only');
        }

        // 自动配置 hosts 文件（将项目域名映射到 127.0.0.1）
        $this->traceStartupPhase($instanceName, 'hosts:before', [
            'host' => (string)($config['host'] ?? '127.0.0.1'),
        ]);
        $this->ensureHostsFileConfigured($config['host'] ?? '127.0.0.1');
        $this->traceStartupPhase($instanceName, 'hosts:after');

        // 开发环境：确保 *.weline.test 泛域名证书存在，避免 hosts 中其他子域 TLS 主机名不匹配
        if (empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:before');
            $this->ensureManagedLocalWildcardCertificate();
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:after');
        } else {
            $this->traceStartupPhase($instanceName, 'wildcard-certificate:skipped-http-only');
        }

        // 生成多域名证书映射文件（用于 SNI 支持）
        if (empty($config['no_ssl'])) {
            $this->traceStartupPhase($instanceName, 'certificate-map:before');
            $this->generateCertificateMap();
            $this->traceStartupPhase($instanceName, 'certificate-map:after');
        } else {
            $this->traceStartupPhase($instanceName, 'certificate-map:skipped-http-only');
        }
        
        // 4. 计算实际 Worker 数量（runtime auto strategy）
        $profile = $this->detectRuntimeProfile(
            $this->resolveServerListenHost((string)($config['host'] ?? '127.0.0.1'))
        );
        $strategyName = (string)($config['runtime_strategy'] ?? ($config['runtime']['strategy'] ?? 'auto'));
        $this->latestRuntimeStrategy = $strategyName;
        // Preserve the user/config intent for RuntimeStrategy diagnostics.
        // getServerConfig resolves the effective integer before the final
        // RuntimeSelection pass; without this field an automatic choice is
        // incorrectly reported as an explicit Worker count.
        $config['worker_count_requested'] = $config['worker_count'];
        $config['worker_count'] = (new RuntimeStrategyResolver())->resolveWorkerCount(
            $config['worker_count'],
            (string)($config['mode'] ?? 'io'),
            $strategyName,
            $profile
        );
        $config['worker_memory_limit'] = ServiceContext::normalizeMemoryLimit($config['worker_memory_limit'] ?? '256M');
        if (isset($config['dispatcher_memory_limit'])) {
            $config['dispatcher_memory_limit'] = ServiceContext::normalizeMemoryLimit(
                $config['dispatcher_memory_limit'],
                $config['worker_memory_limit']
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
     * @param array<string, mixed> $envConfig
     */
    private function hasEnvWlsConfigKey(array $envConfig, string $instanceName, string $key): bool
    {
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $servers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
        if ($instanceName !== 'default'
            && isset($servers[$instanceName])
            && \is_array($servers[$instanceName])
        ) {
            return \array_key_exists($key, $servers[$instanceName]);
        }

        return \array_key_exists($key, $wls);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyGatewayTrafficModeTopology(array $config): array
    {
        $topology = \strtolower(\trim((string)($config['topology'] ?? 'auto')));
        if ($topology !== '' && $topology !== 'auto') {
            return $config;
        }

        $gateway = \is_array($config['gateway'] ?? null) ? $config['gateway'] : [];
        $trafficMode = (string)($gateway['traffic_mode'] ?? 'auto');
        $envTrafficMode = \getenv('WLS_GATEWAY_TRAFFIC_MODE');
        if ($envTrafficMode !== false && \trim((string)$envTrafficMode) !== '') {
            $trafficMode = (string)$envTrafficMode;
        }

        $trafficMode = \strtolower(\trim(\str_replace('-', '_', $trafficMode)));
        if ($trafficMode === 'direct_listen') {
            $config['topology'] = 'direct';
            $config['_legacy_topology_source'] = 'legacy.gateway.traffic_mode';
        } elseif ($trafficMode === 'passthrough') {
            $config['topology'] = 'dispatcher';
            $config['_legacy_topology_source'] = 'legacy.gateway.traffic_mode';
        }

        return $config;
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
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
        $host = $config['host'] ?? '127.0.0.1';
        $certificateHost = $this->resolveCertificateHost($config, (string)$host);
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
        if (!empty($config['ssl_cert']) && !empty($config['ssl_key'])) {
            $certPath = $config['ssl_cert'];
            $keyPath = $config['ssl_key'];
            
            if (!\is_file($certPath) || !\is_file($keyPath)) {
                $this->restoreManagedCertificateForConfig($config, $sslService, (string) $host);
                $certPath = (string) ($config['ssl_cert'] ?? '');
                $keyPath = (string) ($config['ssl_key'] ?? '');

                // 本地/内网环境：证书文件不存在时尝试自动生成，而不是直接报错
                if ($needsLocalCert || !\is_file($certPath) || !\is_file($keyPath)) {
                    $sslService->cleanupInvalidSslConfigAndMap();
                    $config['ssl_cert'] = '';
                    $config['ssl_key'] = '';
                    // 清除后 fall through 到下方 ensureCertificate 逻辑
                } else {
                    // 证书文件不存在：立即清理实例配置和映射中的失效路径，避免反复报错
                    $sslService->cleanupInvalidSslConfigAndMap();
                    if (!\is_file($certPath)) {
                        return ['success' => false, 'message' => __('SSL 证书文件不存在：%{1}，已清理失效配置，请重新启动', [$certPath])];
                    }
                    if (!\is_file($keyPath)) {
                        return ['success' => false, 'message' => __('SSL 私钥文件不存在：%{1}，已清理失效配置，请重新启动', [$keyPath])];
                    }
                }
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
                // 框架级同步：正在使用的本地/手动证书也必须入库，确保后台证书管理可见。
                $sslService->syncCertificateRecordFromFiles(
                    $syncDomain,
                    $certPath,
                    $keyPath,
                    0,
                    true,
                    '',
                    false
                );
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
                    'issuer' => $certInfo['issuer'] ?? __('手动配置'),
                    'expires_at' => $certInfo['expires_at'] ?? '',
                    'is_new' => false,
                ];
                }
            }
        }
        
        // 2. 确定域名
        // 优先使用 host（项目唯一域名），忽略可能来自旧配置的 ssl_domain
        $domain = $certificateHost;
        $startupCertResult = $this->tryUseStartupCertificateFiles($sslService, $domain, $syncDomain);
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

        $webroot = $this->resolveAcmeWebrootForStartup($instanceName, $config);
        $email = Env::get('admin_email', 'admin@' . $domain);

        // 3. 先快速探测本地是否已有可复用证书，避免「明明复用却先喊『正在准备...』」的误导性输出。
        //    hasValidLocalCertificate 只检查文件是否存在 + 有效期 + 本地 CA 复用能力，
        //    不做任何 DNS/签发/IO 重活，耗时可忽略。
        $willReuse = $sslService->hasValidLocalCertificate($domain);
        if (!$willReuse) {
            // 真正要走签发/续签路径：本地 CA + CSR + 可能的 Windows 信任库操作存在长尾风险，
            // 提前提示并在结束后连同耗时、签发方一起打印，配合 SslCertificateService 内部分阶段
            // w_log_info 可在事故时快速定位瓶颈。
            $this->printer->note(__('正在为 %{1} 准备 SSL 证书...', [$domain]));
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

    protected function tryUseStartupCertificateFiles(
        SslCertificateService $sslService,
        string $domain,
        string $syncDomain
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
        if ($sslService->needsSelfSignedCertificate($domain)
            && !$sslService->hasValidLocalCertificate($domain)) {
            return null;
        }
        if (!$sslService->canReuseConfiguredCertificate($certPath, $keyPath)) {
            return null;
        }
        if (!$sslService->certificateMatchesHost($certPath, $domain)) {
            return null;
        }

        $recordDomain = \strtolower(\trim($syncDomain));
        if ($recordDomain === '') {
            $recordDomain = $domain;
        }
        $sslService->syncCertificateRecordFromFiles(
            $recordDomain,
            $certPath,
            $keyPath,
            0,
            true,
            '',
            false
        );
        if ($recordDomain !== $domain) {
            $sslService->syncCertificateRecordFromFiles(
                $domain,
                $certPath,
                $keyPath,
                0,
                true,
                '',
                false
            );
        }
        $sslService->regenerateCertificateMap(false);

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
        $host = \strtolower(\trim($host));
        if (!$this->isWildcardBindHost($host)) {
            return $host === '' ? '127.0.0.1' : $host;
        }

        $publicHost = \strtolower(\trim((string)($config['public_host'] ?? '')));
        if ($this->isUsablePublicHost($publicHost)) {
            return $publicHost;
        }

        $defaultProjectHost = \strtolower(\trim($this->getDefaultHost()));
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
        $primaryKey = \strtolower(\trim($primaryDomain));
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

        return \rtrim($candidate, '.');
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
            $domains = @\scandir($sslDir);
            if ($domains) {
                foreach ($domains as $domain) {
                    if ($domain === '.' || $domain === '..' || !\is_dir($sslDir . $domain)) {
                        continue;
                    }
                    
                    $domainDir = $sslDir . $domain . DS;
                    
                    foreach ($certFormats as $format) {
                        $certPath = $domainDir . $format['cert'];
                        $keyPath = $domainDir . $format['key'];
                        
                        if (\is_file($certPath) && \is_file($keyPath)) {
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
        }
        
        // 2. 兼容旧格式：app/etc/ 下直接放置的证书
        foreach ($certFormats as $format) {
            $certPath = $etcDir . $format['cert'];
            $keyPath = $etcDir . $format['key'];
            
            if (\is_file($certPath) && \is_file($keyPath)) {
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
        // 计算项目哈希（与 getProjectPortOffset 使用相同算法）
        $basePath = \str_replace('\\', '/', \rtrim((string) BP, "\\/"));
        $hash = \sha1(\strtolower($basePath));
        $shortHash = \substr($hash, 0, 8);

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
        // 只处理 .local 域名
        if (!LocalDomainPolicy::requiresHostsEntry($host)) {
            return;
        }

        // 跳过 localhost
        if ($host === 'localhost') {
            return;
        }

        $result = \Weline\Server\Service\HostsFileManager::addDomain($host);

        if ($result['success']) {
            if (!($result['already_exists'] ?? false)) {
                $this->printer->note(__('已将 %{1} 添加到 hosts 文件', [$host]));
            }
        } elseif ($result['needs_admin'] ?? false) {
            $this->printer->warning(__('无法自动配置 hosts 文件（需要管理员权限）'));
            $this->printer->note(__('请手动添加以下内容到 hosts 文件：'));
            $this->printer->note("  127.0.0.1 {$host}");

            if (PHP_OS_FAMILY === 'Windows') {
                $this->printer->note(__('Windows hosts 文件位置：'));
                $this->printer->note('  C:\Windows\System32\drivers\etc\hosts');
                $this->printer->note(__('或以管理员身份运行 PowerShell 执行：'));
                $this->printer->note('  ' . ($result['command'] ?? ''));
            } else {
                $this->printer->note(__('Linux/Mac 执行：'));
                $this->printer->note('  ' . ($result['command'] ?? ''));
            }
        } else {
            $this->printer->warning(__('配置 hosts 文件失败: %{1}', [$result['message'] ?? '未知错误']));
        }
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
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
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
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
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
        $trust = $sslService->ensureLocalDevelopmentCaTrusted();
        if (($trust['trusted'] ?? false) !== true && !empty($trust['message'])) {
            $this->printer->warning((string) $trust['message']);
        }
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
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
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
            return (int) (\getenv('NUMBER_OF_PROCESSORS') ?: 4);
        }
        
        // Linux/Mac
        $nproc = @\shell_exec('nproc 2>/dev/null');
        if ($nproc) {
            return (int) \trim($nproc);
        }
        
        // Mac 备用方案
        $sysctl = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($sysctl) {
            return (int) \trim($sysctl);
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
     * 显示启动信息
     */
    protected function showStartupInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, string $source = '', bool $sslEnabled = false, bool $dispatcherEnabled = false, int $workerPort = 0, int $httpRedirectPort = 0, bool $directReusePortEnabled = false): void
    {
        $this->printer->setup(__('Weline Server'));
        echo "\n";
        
        $cpuCores = $this->getCpuCoreCount();
        $protocol = $sslEnabled ? 'https' : 'http';
        $workerPort = $workerPort ?: $port;
        $endpoint = $this->getInstanceManager()->getRawInstanceData($instanceName);
        $endpoint = \is_array($endpoint) ? $endpoint : [];
        $protocolEdgeEnabled = (bool)($endpoint['protocol_edge_enabled'] ?? false);
        $httpProtocols = \is_array($endpoint['http_protocols'] ?? null)
            ? \array_values(\array_filter(\array_map('strval', $endpoint['http_protocols'])))
            : [];
        
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                   服务器启动配置                               ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', "{$protocol}://{$host}:{$port}"));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', "{$count} (CPU: {$cpuCores} 核)"));
        
        if ($protocolEdgeEnabled) {
            $protocolLabel = $httpProtocols !== []
                ? \strtoupper(\implode('/', $httpProtocols))
                : 'H3/H2/H1';
            $transportLabel = \in_array(HttpProtocolSelection::HTTP_3, $httpProtocols, true)
                ? 'TCP + UDP/QUIC'
                : 'TCP';
            $this->printer->note(\sprintf('║  HTTP 协商：%-49s║', $protocolLabel . ' (' . $transportLabel . ')'));
            if ($dispatcherEnabled) {
                $dispatcherPort = (int)($endpoint['dispatcher_port'] ?? 0);
                $dispatcherLabel = $dispatcherPort > 0
                    ? 'Dispatcher ' . $dispatcherPort . ' → Worker '
                    : 'Dispatcher → Worker ';
                $this->printer->note(\sprintf('║  内部拓扑：%-49s║', $dispatcherLabel . $workerPort . ' - ' . ($workerPort + $count - 1)));
            } else {
                $this->printer->note(\sprintf('║  Worker 端口：%-47s║', $workerPort . ' - ' . ($workerPort + $count - 1) . ' (loopback)'));
            }
        } elseif ($dispatcherEnabled) {
            $this->printer->note(\sprintf('║  流量分发：%-50s║', __('Dispatcher 模式（TCP 透传）')));
            $dispatcherProtocol = $sslEnabled ? 'TCP→SSL' : 'TCP';
            $this->printer->note(\sprintf('║  Dispatcher：%-48s║', "端口 {$port} ({$dispatcherProtocol})"));
            $workerProtocol = $sslEnabled ? 'SSL' : 'HTTP';
            $this->printer->note(\sprintf('║  Worker 端口：%-47s║', "{$workerPort} - " . ($workerPort + $count - 1) . " ({$workerProtocol})"));
        } else {
            if ($directReusePortEnabled) {
                $directLabel = PHP_OS_FAMILY === 'Darwin'
                    ? __('Master 共享监听 FD')
                    : __('SO_REUSEPORT 同端口复用');
                $this->printer->note(\sprintf('║  端口模式：%-50s║', $directLabel));
                $this->printer->note(\sprintf('║  Worker 端口：%-47s║', "{$port} (共享端口)"));
            } else {
                $this->printer->note(\sprintf('║  端口范围：%-50s║', "{$port} - " . ($port + $count - 1)));
            }
        }
        
        // HTTPS 模式显示 HTTP 重定向端口
        if ($sslEnabled && $httpRedirectPort > 0) {
            $this->printer->note(\sprintf('║  HTTP 重定向：%-47s║', "端口 {$httpRedirectPort} → HTTPS"));
        }
        
        $this->printer->note(\sprintf('║  运行模式：%-50s║', $daemon ? __('后台运行（默认）') : __('前台运行')));
        $this->printer->note(\sprintf('║  SSL/HTTPS：%-49s║', $sslEnabled ? __('已启用') : __('未启用')));
        $this->printer->note(\sprintf('║  平台：%-54s║', IS_WIN ? 'Windows' : 'Linux/Mac'));
        $this->printer->note(\sprintf('║  配置来源：%-50s║', $source ?: __('智能模式')));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";

        // 显示函数状态
        $this->showFunctionStatus();
    }
    
    /**
     * 检查特权端口权限，不足时自动使用 sudo 重新执行并触发密码输入。
     *
     * @return bool true=可继续执行；false=当前进程应终止（已交给 sudo 子进程或提示失败）
     */
    protected function ensurePrivilegedPortPermission(int $mainPort, int $httpRedirectPort, bool $sslEnabled): bool
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
            $privilegedPorts[] = $mainPort;
        }
        if ($sslEnabled && $httpRedirectPort > 0 && $httpRedirectPort < 1024) {
            $privilegedPorts[] = $httpRedirectPort;
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
    protected function ensureUnixSocketPermission(string $host, int $port): bool
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
        
        $testSocket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
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
        if (!\is_file($instanceFile)) {
            return null;
        }

        $raw = @\file_get_contents($instanceFile);
        if (!\is_string($raw) || $raw === '') {
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
            foreach (['port', 'main_port', 'control_port', 'dispatcher_port', 'http_redirect_port'] as $field) {
                $candidate = (int)($rawData[$field] ?? 0);
                if ($candidate > 0) {
                    $candidates[] = $candidate;
                }
            }

            $topology = \strtolower((string)($rawData['topology'] ?? $rawData['master_mode'] ?? ''));
            $direct = \in_array($topology, ['direct', 'linux-direct'], true);
            $recordedWorkerPort = (int)($rawData['worker_port'] ?? 0);
            $recordedWorkerCount = \max(1, (int)($rawData['count'] ?? $workerCount));
            if ($recordedWorkerPort > 0) {
                $lastOffset = $direct ? 0 : $recordedWorkerCount - 1;
                for ($offset = 0; $offset <= $lastOffset; $offset++) {
                    $candidates[] = $recordedWorkerPort + $offset;
                }
            }
        } elseif ($workerPort > 0) {
            for ($offset = 0; $offset < \max(1, $workerCount); $offset++) {
                $candidates[] = $workerPort + $offset;
            }
        }

        // IPC 只给 400ms 总预算；失败仍使用上面的持久 endpoint，不阻塞成秒级启动扫描。
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
        $timeoutNanoseconds = (int)(($fastLocal ? 6.0 : 12.0) * 1_000_000_000);
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
            $waitMicroseconds = (int)\min(100_000, \max(1_000, \intdiv($remainingNanoseconds, 1_000)));
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
        if ($this->restartHandoffPorts === []) {
            $this->restartHandoffPorts = $this->captureRestartHandoffPorts(
                $instanceName,
                $mainPort,
                $workerCount,
                $workerPort
            );
        }

        // 交接期间任一目标端口仍在 LISTEN 都必须 fail closed。
        // owner/scope 不参与放行判断，否则 unknown/orphan/foreign 会被误判为“已清理”。
        foreach ($this->restartHandoffPorts as $handoffPort) {
            if (Processer::isPortInUse($handoffPort)) {
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

    /**
     * 超时后才查 owner，仅用于诊断；此方法不杀进程、不切换端口、不修改交接结果。
     */
    protected function reportRestartHandoffTimeout(string $instanceName): void
    {
        foreach ($this->restartHandoffPorts as $handoffPort) {
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
            static fn (int $port): bool => $port > 0
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

        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        do {
            Processer::clearPortCache();
            $remaining = [];
            foreach ($ports as $port) {
                if (Processer::isPortInUse($port)) {
                    $remaining[] = $port;
                }
            }
            if ($remaining === []) {
                return true;
            }
            SchedulerSystem::usleep(300000);
        } while (\microtime(true) < $deadline);

        Processer::clearPortCache();
        foreach ($ports as $port) {
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
            if (($portInspect['state'] ?? '') === 'orphan') {
                $this->printer->warning(__('%{1} 端口 %{2} 处于异常占用状态（系统返回的 PID 已失效），尝试端口级兜底清壳...', [$label, $port]));
                Processer::forceReleasePort($port);
                if (!Processer::isPortInUse($port)) {
                    $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
                    return true;
                }
                if ($this->releaseRuntimeRecordedOrphanPorts($instanceName, [$port], $label)) {
                    $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
                    return true;
                }
                $this->printer->error(__('端口 %{1} 异常占用兜底清壳后仍未释放', [$port]));
            } else {
                $this->printer->error(__('%{1} 端口 %{2} 被非框架进程占用，不予杀死', [$label, $port]));
            }
            $this->printer->note(__('请手动停止占用该端口的进程，或更换端口'));
            $this->printer->note(__('或使用: php bin/w server:kill-port %{1} -f', [$port]));
            return false;
        }

        $this->printer->warning(__('%{1} 端口 %{2} 已被框架进程占用，强制释放...', [$label, $port]));
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $released = Processer::killProcessByPort($port);
            if (!$released) {
                $released = Processer::forceReleasePort($port);
            }
            if (IS_WIN && !$released) {
                $waited = 0;
                while ($waited < 3000 && Processer::isPortInUse($port)) {
                    SchedulerSystem::usleep(300000);
                    $waited += 300;
                }
                $released = !Processer::isPortInUse($port);
            }
            if (!Processer::isPortInUse($port)) {
                $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
                return true;
            }
            if ($attempt < $maxAttempts) {
                SchedulerSystem::usleep(500000);
            }
        }

        // 三次仍杀不死：存在逃逸 Master 在不断拉起子进程，从 var/process 按 Master 前缀找并杀
        $this->printer->warning(__('端口 %{1} 经 %{2} 次仍占用，按 Master 前缀清理逃逸进程...', [$port, $maxAttempts]));
        $masterPrefix = MasterProcess::buildScopedProcessName(MasterProcess::MASTER_PROCESS_NAME_PREFIX, $instanceName);
        $pnamesInProcessDir = Processer::getProcessNamesByPrefix($masterPrefix);
        if (\count($pnamesInProcessDir) > 0) {
            $this->printer->note(__('  从 var/process 发现 %{1} 个匹配 Master，正在按前缀杀死', [\count($pnamesInProcessDir)]));
        }
        $killed = Processer::killByProcessNamePrefixes([
            $masterPrefix,
            MasterProcess::MASTER_PROCESS_NAME_PREFIX . $instanceName,
        ]);
        if ($killed > 0) {
            $this->printer->note(__('  已按前缀清理 %{1} 个逃逸 Master 进程', [$killed]));
        }
        SchedulerSystem::sleep(1);
        $released = Processer::killProcessByPort($port) || Processer::forceReleasePort($port);
        if (!Processer::isPortInUse($port)) {
            $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
            return true;
        }

        $this->printer->error(__('无法释放 %{1} 端口 %{2}', [$label, $port]));
        $this->printer->note(__('请尝试: php bin/w server:kill-port %{1} -f', [$port]));
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
                if (($portInspect['state'] ?? '') === 'orphan') {
                    $this->printer->warning(__('端口 %{1} 处于异常占用状态（系统返回的 PID 已失效），尝试端口级兜底清壳...', [$p]));
                    Processer::forceReleasePort($p);
                    if (!Processer::isPortInUse($p)) {
                        continue;
                    }
                    if ($this->releaseRuntimeRecordedOrphanPorts($instanceName, [$p], 'Worker')) {
                        continue;
                    }
                    $this->printer->error(__('端口 %{1} 异常占用兜底清壳后仍未释放', [$p]));
                } else {
                    $this->printer->error(__('端口 %{1} 被非框架进程占用，不予杀死', [$p]));
                }
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

        $maxAttempts = 3;
        for ($round = 1; $round <= $maxAttempts; $round++) {
            foreach ($portsInUse as $p) {
                Processer::killProcessByPort($p);
                Processer::forceReleasePort($p);
            }
            if (IS_WIN) {
                SchedulerSystem::usleep(500000);
            }
            $stillInUse = [];
            foreach ($portsInUse as $p) {
                if (Processer::isPortInUse($p)) {
                    $stillInUse[] = $p;
                }
            }
            $portsInUse = $stillInUse;
            if (empty($portsInUse)) {
                $this->printer->success(__('端口检查通过'));
                echo "\n";
                return true;
            }
            if ($round < $maxAttempts) {
                SchedulerSystem::usleep(500000);
            }
        }

        // 三轮仍杀不死：从 var/process 按 Master 前缀找并杀逃逸 Master 后再试
        $this->printer->warning(__('端口经 %{1} 轮仍占用，按 Master 前缀清理逃逸进程...', [$maxAttempts]));
        $masterPrefix = MasterProcess::buildScopedProcessName(MasterProcess::MASTER_PROCESS_NAME_PREFIX, $instanceName);
        $pnamesInProcessDir = Processer::getProcessNamesByPrefix($masterPrefix);
        if (\count($pnamesInProcessDir) > 0) {
            $this->printer->note(__('  从 var/process 发现 %{1} 个匹配 Master，正在按前缀杀死', [\count($pnamesInProcessDir)]));
        }
        $killed = Processer::killByProcessNamePrefixes([
            $masterPrefix,
            MasterProcess::MASTER_PROCESS_NAME_PREFIX . $instanceName,
        ]);
        if ($killed > 0) {
            $this->printer->note(__('  已按前缀清理 %{1} 个逃逸 Master 进程', [$killed]));
        }
        SchedulerSystem::sleep(1);
        foreach ($portsInUse as $p) {
            Processer::killProcessByPort($p);
            Processer::forceReleasePort($p);
        }
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
        bool $protocolEdgeEnabled = false,
        bool $protocolEdgeDispatcherEnabled = false,
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

        $base = \max($startPort, 1);
        for ($attempt = 0; $attempt < $maxScan; $attempt++, $base++) {
            $hasConflict = false;
            foreach ($this->buildWorkerAllocationCandidatePorts(
                $base,
                $count,
                $protocolEdgeEnabled,
                $protocolEdgeDispatcherEnabled,
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
        return $startPort;
    }

    /**
     * @return list<int>
     */
    protected function buildWorkerAllocationCandidatePorts(
        int $workerPort,
        int $count,
        bool $protocolEdgeEnabled = false,
        bool $protocolEdgeDispatcherEnabled = false,
    ): array
    {
        if ($workerPort <= 0) {
            return [];
        }

        $count = \max(1, $count);
        $ports = \range($workerPort, $workerPort + $count - 1);

        $maintenanceCount = \max(1, (int) \ceil($count / 3));
        $maintenancePort = $workerPort + $count + 99;
        for ($i = 0; $i < $maintenanceCount; $i++) {
            $ports[] = $maintenancePort + $i;
        }

        if ($protocolEdgeEnabled) {
            $ports = \array_merge(
                $ports,
                ProtocolEdgeRuntime::directReloadSurgePortsFromWorkerRange($workerPort, $count),
            );
            if ($protocolEdgeDispatcherEnabled) {
                $ports[] = ProtocolEdgeRuntime::dispatcherPortFromWorkerRange($workerPort, $count);
            }
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
        bool $useDirectMode
    ): int
    {
        if ($dispatcherEnabled) {
            return $workerBasePort + $mainPort;
        }

        if ($workerCount <= 1 || $useDirectMode) {
            return $mainPort;
        }

        return $workerBasePort + $mainPort;
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

        $controlPort = $this->resolvePreferredControlPort($mainPort);
        if ($controlPort <= 0) {
            return [];
        }

        return [$controlPort];
    }

    protected function resolvePreferredControlPort(int $mainPort): int
    {
        $configuredControlPort = (int) (Env::get('server.control_port', 0) ?? 0);
        if ($configuredControlPort > 0) {
            return $configuredControlPort;
        }

        return 20000 + $mainPort + MasterProcess::getProjectPortOffset();
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
        $instanceDir = $this->getInstanceRuntimeDir();
        if (!\is_dir($instanceDir)) {
            return [];
        }

        $reservedPortLookup = [];
        $instanceFiles = \glob($instanceDir . '*.json') ?: [];
        foreach ($instanceFiles as $instanceFile) {
            $instanceName = \basename($instanceFile, '.json');
            if ($ignoreInstanceName !== null && $instanceName === $ignoreInstanceName) {
                continue;
            }

            $raw = @\file_get_contents($instanceFile);
            if (!\is_string($raw) || $raw === '') {
                continue;
            }

            $data = \json_decode($raw, true);
            if (!\is_array($data)) {
                continue;
            }

            if (!$this->isWorkerPortReservationActive($data, $instanceFile)) {
                continue;
            }

            foreach ($this->extractReservedWorkerPortsFromInstanceData($data) as $reservedPort) {
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

    protected function isWorkerPortReservationActive(array $instanceData, string $instanceFile = ''): bool
    {
        if ($this->extractReservedWorkerPortsFromInstanceData($instanceData) === []) {
            return false;
        }

        $startedTimestamp = (int) ($instanceData['started_timestamp'] ?? 0);
        if ($startedTimestamp <= 0 && $instanceFile !== '' && \is_file($instanceFile)) {
            $startedTimestamp = (int) (@\filemtime($instanceFile) ?: 0);
        }

        return $startedTimestamp > 0
            && (\time() - $startedTimestamp) <= self::WORKER_PORT_RESERVATION_TTL;
    }

    /**
     * @return list<int>
     */
    protected function extractReservedWorkerPortsFromInstanceData(array $instanceData): array
    {
        $masterMode = (string) ($instanceData['master_mode'] ?? MasterProcess::MODE_LEGACY);
        $protocolEdgeEnabled = (bool)($instanceData['protocol_edge_enabled'] ?? false);
        if (!$protocolEdgeEnabled
            && (($instanceData['topology'] ?? '') === 'direct' || MasterProcess::isDirectMode($masterMode))
        ) {
            return [];
        }

        $workerPort = (int) ($instanceData['worker_port'] ?? 0);
        if ($workerPort <= 0) {
            return [];
        }

        $count = \max(1, (int) ($instanceData['count'] ?? 1));

        $protocolEdgeDispatcherEnabled = $protocolEdgeEnabled
            && (bool)($instanceData['dispatcher_enabled'] ?? false);

        return $this->buildWorkerAllocationCandidatePorts(
            $workerPort,
            $count,
            $protocolEdgeEnabled,
            $protocolEdgeDispatcherEnabled,
        );
    }

    /**
     * 保存实例信息
     */
    protected function saveInstanceInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, bool $sslEnabled = false, string $sslCert = '', string $sslKey = '', bool $dispatcherEnabled = false, int $workerPort = 0, int $httpRedirectPort = 0, bool $windowMode = false, bool $enableLog = false, bool $useDirectMode = false, int $workerBasePort = 10000, array $sharedStateRuntime = [], array $orchestratorRuntimeOptions = [], string $workerMemoryLimit = '256M', string $dispatcherMemoryLimit = '', string $publicHost = '', array $gatewayRuntime = [], array $runtimeSelection = []): void
    {
        $selection = $runtimeSelection['runtime_selection'] ?? null;
        if (\is_array($selection)) {
            $selection = RuntimeSelection::fromArray($selection);
        }
        if (!$selection instanceof RuntimeSelection) {
            throw new \RuntimeException('WLS Start must persist a complete RuntimeSelection.');
        }
        if ($selection->effectiveTopology->value === 'independent') {
            throw new \RuntimeException('WLS independent topology is not startable.');
        }
        $containerRegistryDigest = \strtolower(\trim((string)($runtimeSelection['container_registry_digest'] ?? '')));
        if (\preg_match('/^[a-f0-9]{64}$/D', $containerRegistryDigest) !== 1) {
            throw new \RuntimeException('WLS Start must persist a valid container_registry_digest.');
        }

        $selectionData = $selection->toArray();
        $effectiveTopology = $selection->effectiveTopology->value;
        $dispatcherEnabled = $selection->isDispatcher();
        $useDirectMode = $selection->isDirect();
        $httpProtocolSelection = \is_array($runtimeSelection['http_protocol_selection'] ?? null)
            ? HttpProtocolSelection::fromArray($runtimeSelection['http_protocol_selection'])
            : HttpProtocolSelection::fromConfig([], $sslEnabled);
        $protocolEdgeEnabled = $httpProtocolSelection->isProtocolEdgeEnabled();
        $protocolEdgeBinary = \trim((string)($runtimeSelection['protocol_edge_binary'] ?? ''));
        $instanceData = [
            'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
            'name' => $instanceName,
            'host' => $host,
            'public_host' => $publicHost !== '' ? $publicHost : $host,
            'port' => $port,
            'count' => $count,
            'daemon' => $daemon,
            'ssl_enabled' => $sslEnabled,
            'ssl_cert' => $sslCert,
            'ssl_key' => $sslKey,
            'http_protocol_selection' => $httpProtocolSelection->toArray(),
            'http_protocols' => $httpProtocolSelection->protocols,
            'http_preferred_protocol' => $httpProtocolSelection->preferred,
            'protocol_edge_enabled' => $protocolEdgeEnabled,
            'protocol_edge_binary' => $protocolEdgeBinary,
            'tls_session_resumption' => $httpProtocolSelection->tlsSessionResumption,
            'tls_key_exchange_profile' => (string)($runtimeSelection['tls_key_exchange_profile'] ?? ''),
            'runtime_selection' => $selectionData,
            'topology' => $effectiveTopology,
            'requested_topology' => $selection->requestedTopology->value,
            'effective_topology' => $effectiveTopology,
            'topology_source' => $selection->source,
            'topology_reason' => $selection->reason,
            'topology_reason_codes' => $selection->reasonCodes,
            'runtime_strategy' => (string)($runtimeSelection['runtime_strategy'] ?? 'auto'),
            'os_family' => $selection->osFamily,
            'event_loop_driver' => $selection->eventLoopDriver,
            'direct_listener_mode' => $selection->listenerMode,
            'listener_strategy' => $selection->listenerMode,
            'ssl_engine' => $selection->sslEngine,
            'policy_compatible' => $selection->policyCompatible,
            'policy_digest' => (string)($runtimeSelection['policy_digest'] ?? ''),
            'container_registry_digest' => $containerRegistryDigest,
            'supervisor_enabled' => (bool)($runtimeSelection['supervisor_enabled'] ?? false),
            'supervisor_reason' => (string)($runtimeSelection['supervisor_reason'] ?? ''),
            'started_at' => \date('Y-m-d H:i:s'),
            'started_timestamp' => \time(),
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
            // Dispatcher 模式信息
            'dispatcher_enabled' => $dispatcherEnabled,
            'dispatcher_port' => $dispatcherEnabled
                ? ($protocolEdgeEnabled
                    ? ProtocolEdgeRuntime::dispatcherPortFromWorkerRange($workerPort ?: $port, $count)
                    : $port)
                : 0,
            'worker_port' => $workerPort ?: $port,  // Worker 实际监听的端口（Dispatcher 模式下为内网端口）
            'worker_base_port' => $workerBasePort,   // Worker 基础端口（用于计算各 Worker 端口）
            'worker_memory_limit' => ServiceContext::normalizeMemoryLimit($workerMemoryLimit),
            'dispatcher_memory_limit' => ServiceContext::normalizeMemoryLimit(
                $dispatcherMemoryLimit !== '' ? $dispatcherMemoryLimit : $workerMemoryLimit,
                ServiceContext::normalizeMemoryLimit($workerMemoryLimit)
            ),
            'session_server_port' => (int) ($sharedStateRuntime['session']['port'] ?? (19970 + MasterProcess::getProjectPortOffset())),
            'session_server_token_file_name' => (string) ($sharedStateRuntime['session']['token_file_name'] ?? 'session_server.token'),
            'memory_server_port' => (int) ($sharedStateRuntime['memory']['port'] ?? (19971 + MasterProcess::getProjectPortOffset())),
            'memory_server_token_file_name' => (string) ($sharedStateRuntime['memory']['token_file_name'] ?? 'memory_server.token'),
            'shared_state' => $sharedStateRuntime,
            'gateway' => $gatewayRuntime,
            // HTTP 重定向端口（HTTPS 模式下用于 HTTP→HTTPS 跳转）
            'http_redirect_port' => $httpRedirectPort,
            // Windows 窗口模式（与阻塞前台 Master 无关）
            'window_mode' => $windowMode,
            'frontend' => $windowMode,
            'runtime_state' => 'running',
            'last_verified_at' => \time(),
            // 启动参数固化：子进程拉起链路统一读取实例参数。
            'orchestrator_runtime_options' => $orchestratorRuntimeOptions,
            // 与 -log 对齐：进程管理日志 + WLS 全量调试（子进程读此字段）
            'enable_log' => $enableLog,
            // IPC 控制端口（由 Master 进程计算并更新）
            // 初始值设为 0，Master 启动时会根据 main_port 计算真实端口并覆盖此值
            'control_port' => 0,
        ];

        // 设置 Master 运行模式
        // - 直连模式（SO_REUSEPORT）：所有 Worker 共用主端口
        // - 独立端口模式：每个 Worker 使用独立端口
        // - Dispatcher 模式：Worker 使用内网端口，Dispatcher 监听主端口
        if ($useDirectMode) {
            $instanceData['master_mode'] = MasterProcess::MODE_DIRECT;
            $instanceData['main_port'] = $port;
        } elseif (!$dispatcherEnabled) {
            // 独立端口模式（非直连、非 Dispatcher）
            $instanceData['master_mode'] = MasterProcess::MODE_INDEPENDENT;
            $instanceData['main_port'] = $workerPort;
        } else {
            // Dispatcher 模式
            $instanceData['master_mode'] = MasterProcess::MODE_DISPATCHER;
            $instanceData['main_port'] = 0;
        }
        
        $this->getInstanceManager()->saveInstance($instanceName, $instanceData);
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
        $stagingRoot = BP . 'var' . DS . 'tmp' . DS . 'framework-start-stage-'
            . (string)(\getmypid() ?: 0)
            . '-'
            . \bin2hex(\random_bytes(8));
        $stagingDirectory = $stagingRoot . DS . 'framework';
        $stagingHookRegistry = $stagingRoot . DS . 'hooks.php';
        $publisher = new AtomicCompiledFilePublisher();
        $finalSnapshots = [];
        $runtimeContainerInstalled = false;

        try {
            // Reuse is allowed only while holding the same generation lock as
            // promotion and only after exact source + artifact content hashes
            // match. Invalid/missing manifests fall through to a full compile.
            $publisher->acquireDirectoryLock($finalDirectory);
            try {
                $freshness = (new FrameworkCompileManifest())->validate(
                    BP . 'app' . DS . 'code' . DS . 'Weline',
                    $finalDirectory,
                );
                if ($freshness['fresh']) {
                    $compiledContainer = new CompiledContainer(
                        $finalDirectory . DS . 'container.php',
                        false,
                    );
                    $containerRegistryDigest = $compiledContainer->registryDigest();
                    if (\preg_match('/^[a-f0-9]{64}$/D', $containerRegistryDigest) !== 1) {
                        throw new \RuntimeException('Reused compiled container registry digest is invalid.');
                    }
                    $policyControl = new RuntimePolicyControlService(
                        new RuntimePolicyCompiler($finalDirectory . DS . 'runtime_policy_providers.php'),
                    );
                    $policyCheck = $policyControl->check(
                        $policyTopology,
                        $instanceName,
                        $compileContext,
                    );
                    if (!empty($policyCheck['valid'])) {
                        ContainerRuntime::preflight($containerRegistryDigest);
                    }
                    return [
                        'container_registry_digest' => $containerRegistryDigest,
                        'policy_check' => $policyCheck,
                    ];
                }
            } finally {
                AtomicCompiledFilePublisher::releaseProcessLocks();
            }

            if (!@\mkdir($stagingRoot, 0700, true) && !\is_dir($stagingRoot)) {
                throw new \RuntimeException('Unable to create private framework registry staging directory.');
            }

            // Template policies consume hooks.php but framework:compile does
            // not own it. Compile against one immutable copy, then fence the
            // live hook digest both before and after final promotion.
            $hookSnapshot = $this->snapshotCompiledArtifact($hookRegistry);
            if ($hookSnapshot['exists']) {
                $publisher->publish($stagingHookRegistry, (string)$hookSnapshot['content']);
                AtomicCompiledFilePublisher::releaseProcessLocks();
            }

            /** @var FrameworkCompiler $compiler */
            $compiler = ObjectManager::getInstance(FrameworkCompiler::class);
            $compiler->compile(
                BP . 'app' . DS . 'code' . DS . 'Weline',
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
                // have not been locked or touched at this point.
                return [
                    'container_registry_digest' => $containerRegistryDigest,
                    'policy_check' => $policyCheck,
                ];
            }

            $this->assertCompiledArtifactSnapshot($hookRegistry, $hookSnapshot, 'hook registry');
            $publisher->acquireDirectoryLock($finalDirectory);
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
            ];
        } catch (\Throwable $exception) {
            $message = \str_replace(
                [$stagingRoot, \str_replace('\\', '/', $stagingRoot)],
                '[private framework staging]',
                $exception->getMessage(),
            );
            throw new \RuntimeException($message, 0, $exception);
        } finally {
            AtomicCompiledFilePublisher::releaseProcessLocks();
            $this->removePrivateStagingDirectory($stagingRoot);
        }
    }

    /**
     * @return array{exists:bool,content:?string,sha256:string}
     */
    private function snapshotCompiledArtifact(string $path): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            \clearstatcache(true, $path);
            if (!\is_file($path)) {
                if (!\file_exists($path) && !\is_link($path)) {
                    return ['exists' => false, 'content' => null, 'sha256' => ''];
                }
                throw new \RuntimeException('Compiled registry path is not a regular file.');
            }

            $before = @\hash_file('sha256', $path);
            $content = @\file_get_contents($path);
            $after = @\hash_file('sha256', $path);
            if (\is_string($before)
                && \is_string($content)
                && \is_string($after)
                && $before !== ''
                && \hash_equals($before, $after)
                && \hash_equals($before, \hash('sha256', $content))
            ) {
                return ['exists' => true, 'content' => $content, 'sha256' => $before];
            }
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
        if ($directory === '' || (!\is_dir($directory) && !\is_link($directory))) {
            return;
        }
        if (\is_link($directory)) {
            @\unlink($directory);
            return;
        }
        $entries = @\scandir($directory);
        if (!\is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DS . $entry;
            if (\is_dir($path) && !\is_link($path)) {
                $this->removePrivateStagingDirectory($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($directory);
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
            $this->getInstanceManager()->saveInstance($instanceName, ['launcher_pid' => $launcherPid]);
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
        $configFile = $this->getInstanceConfigFile($instanceName);
        if (!\is_file($configFile)) {
            return null;
        }
        $content = @\file_get_contents($configFile);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        if (!\is_array($data) || empty($data)) {
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
        
        // 仅保存可复用的配置项（不包含运行时状态）
        $existingSavedConfig = $this->loadSavedInstanceConfig($instanceName) ?? [];
        $savedConfig = [];
        
        // 从当前合并后的配置中提取可复用项
        // 注意：不保存 no_ssl，这是临时参数，HTTPS 偏好应以 wls.https 为准
        $persistKeys = ['host', 'public_host', 'port', 'mode', 'ssl_cert', 'ssl_key', 'ssl_domain', 'worker_base_port', 'worker_memory_limit', 'dispatcher_memory_limit'];
        foreach ($persistKeys as $key) {
            if (isset($config[$key])) {
                $savedConfig[$key] = $config[$key];
            }
        }

        $existingRuntime = \is_array($existingSavedConfig['runtime'] ?? null)
            ? $existingSavedConfig['runtime']
            : [];
        if (\array_key_exists('topology', $existingRuntime)) {
            $savedConfig['runtime']['topology'] = (string)$existingRuntime['topology'];
        }
        $topologyExplicit = isset($args['topology'])
            || isset($args['direct'])
            || isset($args['no-dispatcher'])
            || isset($args['no_dispatcher'])
            || isset($args['dispatcher'])
            || isset($args['force-dispatcher']);
        if ($topologyExplicit && isset($config['requested_topology'])) {
            $savedConfig['runtime']['topology'] = (string)$config['requested_topology'];
        }
        if (\array_key_exists('worker_count', $existingSavedConfig)) {
            $savedConfig['worker_count'] = \max(1, (int)$existingSavedConfig['worker_count']);
        }
        if (isset($args['count']) || isset($args['c'])) {
            $savedConfig['worker_count'] = \max(1, (int)($args['count'] ?? $args['c']));
        }
        
        // 记录保存时间
        $savedConfig['saved_at'] = \date('Y-m-d H:i:s');
        
        $configFile = $this->getInstanceConfigFile($instanceName);
        \file_put_contents($configFile, \json_encode($savedConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /*----------------------------------------实例配置记忆结束------------------------------------------*/
    
    /**
     * 更新实例的 Worker PID 列表（原子更新，带文件锁）
     */
    /**
     * 确保 Worker 脚本存在
     * 
     * 注意：不再覆盖已有文件，bin/worker.php 和 bin/worker_ssl.php 已集成框架路由
     */
    protected function ensureWorkerScript(bool $sslEnabled = false): string
    {
        $suffix = $sslEnabled ? '_ssl' : '';
        $workerScript = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin' . DS . "worker{$suffix}.php";
        $scriptDir = \dirname($workerScript);
        
        if (!\is_dir($scriptDir)) {
            @\mkdir($scriptDir, 0755, true);
        }
        
        // 只在文件不存在时创建（不覆盖已有的框架集成版本）
        if (!\file_exists($workerScript)) {
            $script = $sslEnabled ? $this->getSslWorkerScriptContent() : $this->getWorkerScriptContent();
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
     * 获取 SSL Worker 脚本内容
     */
    protected function getSslWorkerScriptContent(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';
$sslCert = $argv[5] ?? '';
$sslKey = $argv[6] ?? '';

// 静默模式，不输出到控制台
error_reporting(0);

// Keep native WLS HTTPS on modern TLS only; legacy TLS1.0/1.1 is slower to
// negotiate and should not be offered by generated worker stubs.
$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
} elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
}
$wlsModernTlsCiphers = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:!aNULL:!eNULL:!MD5:!RC4:!DES:!3DES:!DSS:!SHA1:!DHE';
$wlsModernTlsCurves = 'X25519:prime256v1';

// 创建 SSL 上下文（支持所有协议，默认使用最高版本）
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ],
    'ssl' => [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        'ciphers' => $wlsModernTlsCiphers,
        'ecdh_curve' => $wlsModernTlsCurves,
        'single_dh_use' => true,
        'honor_cipher_order' => true,
    ]
]);

$socket = @stream_socket_server(
    "ssl://{$host}:{$port}",
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
        $body = "Hello Weline Server (HTTPS)! Instance: {$instanceName}, Worker: {$workerId}, Port: {$port}, Request: {$requestCount}";
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
                'opcache.enable_cli' => ['recommended' => '1', 'desc' => __('CLI 模式开启 OPCache')],
                'opcache.jit' => ['recommended' => 'tracing', 'desc' => __('JIT 编译器（PHP 8+）')],
                'opcache.jit_buffer_size' => ['recommended' => '128M', 'desc' => __('JIT 缓冲区大小')],
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
        bool $directReusePortEnabled = false
    ): array
    {
        $issues = [];
        $recommended = $this->getRecommendedConfig();
        
        // 0. 检查事件循环（最重要的性能因素！）
        $eventLoopIssues = $this->detectEventLoopIssues();
        $issues = \array_merge($issues, $eventLoopIssues);
        
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

        if (!IS_WIN && $dispatcherEnabled && $supportsReusePort && !$directReusePortEnabled) {
            $issues['direct_reuse_port'] = [
                'level' => 'info',
                'message' => __('当前显式使用 Dispatcher 模式；Linux auto 使用 SO_REUSEPORT 直连，macOS auto 使用 Master 共享监听 FD 直连'),
                'benefit' => __('Dispatcher 适合需要集中转发的兼容场景；峰值性能优先使用 auto/direct'),
                'action' => __('移除 --dispatcher，或配置 wls.runtime.topology = auto/direct'),
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
            $opcacheCliEnabled = \ini_get('opcache.enable_cli');
            if (!$opcacheCliEnabled || $opcacheCliEnabled === '0') {
                $issues['opcache_cli'] = [
                    'level' => 'info',
                    'message' => __('OPCache CLI 模式未启用'),
                    'benefit' => __('启用后可提升 CLI 脚本执行速度'),
                    'action' => __('在 php.ini 设置 opcache.enable_cli = 1'),
                ];
            }
            
            // JIT（PHP 8+）
            if (\version_compare(PHP_VERSION, '8.0.0', '>=')) {
                $jit = \ini_get('opcache.jit');
                if (empty($jit) || $jit === '0' || $jit === 'off') {
                    $issues['opcache_jit'] = [
                        'level' => 'info',
                        'message' => __('JIT 编译器未启用'),
                        'benefit' => __('PHP 8 JIT 可提升 CPU 密集型任务性能 2-3 倍'),
                        'action' => __('在 php.ini 设置 opcache.jit = tracing'),
                    ];
                }
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
                    : __('Linux/Mac: pecl install event && echo "extension=event" >> php.ini'),
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
        bool $directReusePortEnabled = false
    ): void
    {
        // 检测性能问题
        $issues = $this->detectPerformanceIssues(
            $workerCount,
            $mode,
            $dispatcherEnabled,
            $supportsReusePort,
            $directReusePortEnabled
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
     * Display the Windows native protocol ownership boundary. The historical
     * method name is retained for compatibility with downstream overrides.
     */
    protected function showWindowsNginxProxyHint(string $host, int $port): void
    {
        unset($host);
        echo "\n";
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║  Windows 公网协议由 WLS Native Protocol Engine 原生处理，无需反向代理。    ║'));
        $this->printer->note(__('║  同一端口 %{1} 自动协商 HTTP/3 → HTTP/2 → HTTP/1.1，并复用 TLS 会话。      ║', [$port]));
        $this->printer->note(__('║  Windows Dispatcher 是 WLS 内部数据面，只负责透传与 L4 门禁。              ║'));
        $this->printer->note(__('║  默认链路完全由 WLS 自己提供，不启动或依赖任何外置 Web 服务器。            ║'));
        $this->printer->note(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
    }

    /**
     * 显示使用说明（含各区域入口地址）
     */
    protected function showUsageInfo(string $host, int $port, string $instanceName, bool $sslEnabled = false): void
    {
        $scheme = $sslEnabled ? 'https' : 'http';
        // 默认端口（HTTP 80 或 HTTPS 443）不在 URL 中显示
        $portNum = (int)$port;
        $portSuffix = (($portNum == 80 && !$sslEnabled) || ($portNum == 443 && $sslEnabled)) ? '' : ':' . $port;
        $baseUrl = $scheme . '://' . $host . $portSuffix . '/';
        $testUrl = $baseUrl;

        $backendPrefix = Env::getAreaRoutePrefix('backend') ?? '';
        $restBackendPrefix = Env::getAreaRoutePrefix('rest_backend') ?? '';
        $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend') ?? 'api';

        $urlFrontend = rtrim($baseUrl, '/') . '/';
        // 后台入口 = 密钥路径 + /admin
        $urlBackend = rtrim($baseUrl, '/') . '/' . ($backendPrefix !== '' ? $backendPrefix . '/' : '') . 'admin';
        // REST 接口路径：未配置时显示"未配置"
        $urlRestBackend = $restBackendPrefix !== '' 
            ? rtrim($baseUrl, '/') . '/' . $restBackendPrefix . '/' 
            : __('未配置（请在 env.php 中设置 area_routes.rest_backend.prefix）');
        $urlRestFrontend = rtrim($baseUrl, '/') . '/' . ($restFrontendPrefix !== '' ? $restFrontendPrefix . '/' : 'api/');

        echo "\n";
        $this->printer->title(__('使用说明'), '═');
        
        // 访问地址
        $urlRows = [
            __('前台/首页') => $urlFrontend,
            __('后台入口') => $urlBackend,
            __('后台 REST 接口') => $urlRestBackend,
            __('前台 REST 接口') => $urlRestFrontend,
        ];
        if ($this->isUsablePublicHost($host)) {
            $urlRows[__('默认外网地址')] = rtrim($baseUrl, '/');
        }
        $this->printer->keyValue($urlRows, '→', 18);
        
        $this->printer->separator('─');
        
        $this->printer->note(__('公网协议由 WLS Native Protocol Engine 原生处理，无需外置 Web 服务器。'));
        if ($sslEnabled) {
            $this->printer->note(__('同一端口自动协商 HTTP/3 → HTTP/2 → HTTP/1.1，并复用 TLS 会话。'));
        }
        if (!$this->isUsablePublicHost($host)) {
            $this->printer->note(__('当前仅绑定本机；需要外网直连时使用：') . 'php bin/w server:start --host 0.0.0.0');
        }
        $this->printer->separator('─');
        
        // 常用命令
        $this->printer->keyValue([
            __('测试请求') => 'curl ' . $testUrl,
            __('查看状态') => 'php bin/w server:status ' . $instanceName,
            __('停止服务') => 'php bin/w server:stop ' . $instanceName,
            __('压力测试') => 'php bin/w server:benchmark',
            __('优化指南') => 'php bin/w server:doc',
        ], '→', 18);
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
        bool $directReusePortEnabled = false
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
            $directReusePortEnabled
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
        bool $directReusePortEnabled = false
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
            $directReusePortEnabled
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
                '--cli' => __('使用 PHP 内置 CLI 服务器（开发模式，无 HTTPS）'),
                '--host <ip>' => __('监听地址（默认：127.0.0.1，仅本机；需外网访问时用 --host 0.0.0.0；-h 保留给帮助）'),
                '-p, --port <port>' => __('基础端口（默认：80/443，HTTPS 时用 443；可 -p 9981 等自定义）'),
                '-c, --count <n>' => __('Worker 进程数（默认：auto 智能模式）'),
                '--no-daemon' => __('前台运行（查看实时日志）'),
                '-m, --mode <mode>' => __('运行模式：io（I/O密集）或 cpu（CPU密集）'),
                '-r, --restart' => __('平滑重启：开维护模式，并在全部 READY Worker 确认请求排空后切换'),
                '-f' => __('与 -r 同用时直接切换（停机型更新，不等待排空，建议先开启维护模式）；仅 --cli 时 -f 表示前台运行'),
                '--no-ssl' => __('仅 HTTP，不启用 HTTPS（Windows 下可不装 event 扩展）'),
                '--ssl-cert <path>' => __('SSL 证书文件路径（启用 HTTPS）'),
                '--ssl-key <path>' => __('SSL 私钥文件路径（启用 HTTPS）'),
                '--worker-memory-limit <size>' => __('Worker 进程 PHP memory_limit（如 512M，数字按 MB 处理，-1 为不限）'),
                '--dispatcher-memory-limit <size>' => __('Dispatcher 进程 PHP memory_limit（默认跟随 Worker）'),
                '--runtime-strategy <mode>' => __('运行策略：auto/performance/stability/compatibility（默认 auto）'),
                '--topology <mode>' => __('拓扑：auto/direct/dispatcher（默认 auto；independent 已禁止启动）'),
                '--event-loop <driver>' => __('事件循环：auto/event/select（默认 auto）'),
                '--no-auto-deps' => __('显式禁用启动前依赖自动安装；Direct 与 h2/h3 协议边缘仍 fail-closed，Dispatcher event 可使用有界 select'),
                '--supervisor <value>' => __('Supervisor：auto/true/false（默认 auto）'),
                '--direct' => __('直连拓扑：不启动 WLS Dispatcher；默认 HTTPS 协议边缘直达私有 Worker'),
                '--no-dispatcher' => __('已禁用：independent 尚不具备完整 READY/策略保证，请使用 direct 或 --dispatcher'),
                '--dispatcher' => __('Linux/macOS 显式改用 Dispatcher；Windows 默认且只能使用此模式'),
                '--help' => __('显示帮助信息'),
            ],
            [
                __('配置优先级') => __('命令行参数 > 已保存实例配置 > wls.servers.[name] > wls > 默认值'),
                __('拓扑优先级') => __('CLI > 当前实例显式拓扑 > wls.runtime.topology > 旧 wls.topology/gateway > auto'),
                __('多实例支持') => __('可同时运行多个命名实例，每个实例使用不同端口。首次指定 -p 后配置会自动记住，下次直接用实例名启动'),
                __('配置记忆') => __('首次 server:start api -p 8443 会保存配置，之后 server:start api 自动使用端口 8443'),
                __('智能模式') => __('worker_count 设为 "auto" 时由运行时策略按 OS/CPU/内存自动计算'),
                __('事件循环') => __('Linux/macOS Direct 自动安装 sockets/Event 且失败即停止；显式 Dispatcher 的 Event 安装失败时保持 Dispatcher + 有界 select'),
                __('默认拓扑') => __('Linux 在 SO_REUSEPORT 真实分流探测通过后 direct；macOS 在共享 FD 真实 accept 分布探测通过后 direct；Windows 固定 Dispatcher'),
                __('多进程') => __('优先级：proc_open > pcntl_fork > exec'),
                __('HTTPS 支持') => __('自动检测 app/etc/ 下的证书，或手动指定 --ssl-cert 和 --ssl-key'),
                __('HTTP 自动协商') => __('HTTPS 默认 h3/h2/h1：QUIC HTTP/3、ALPN HTTP/2、HTTP/1.1 自动回退；状态由 server:status/doctor 展示'),
                __('连接复用') => __('默认开启 TLS session ticket；HTTP/2/3 多路复用与协议边缘到 Worker 的私有 keep-alive 连接池'),
                __('协议边缘依赖') => __('h2/h3 默认使用 WLS Native Protocol Engine；启动前自动构建并验证 ALPN + QUIC，失败不静默降级；Caddy 仅为显式兼容模式'),
                __('禁用 HTTPS') => __('wls.https = false 或 命令行 --no-ssl，二者任一即可；同时影响 http:request 等生成地址'),
                __('SSL 协议') => __('仅支持 TLS 1.2/1.3；空值或无效 wls.ssl.protocols 会在启动前被拒绝'),
                __('Master 进程') => __('默认启用，持续监控 Worker 状态，Worker 崩溃自动重启；HTTPS 时自动启动 HTTP 重定向进程'),
                __('80/443 端口') => __('默认监听 80/443 省去 Nginx；HTTPS 时自动用 443，可 -p 9981 等改端口；Linux/Mac 特权端口需 root/setcap'),
                __('HTTP 重定向端口') => __('固定规则：仅 HTTPS 主端口为 443 时启动 HTTP:80 重定向 Worker；非 443 时不启动独立重定向 Worker'),
                __('Worker 内存') => __('可通过 wls.worker_memory_limit 或 --worker-memory-limit 设置；wls.dispatcher_memory_limit 未设置时跟随 Worker'),
            ],
            [
                __('启动默认实例') => 'php bin/w server:start',
                __('使用 CLI 服务器') => 'php bin/w server:start --cli',
                __('启动命名实例（首次需指定端口）') => 'php bin/w server:start api -p 8443',
                __('再次启动已配置实例（自动记忆）') => 'php bin/w server:start api',
                __('同时运行多个实例') => 'php bin/w server:start web -p 8443 && php bin/w server:start api -p 9443',
                __('启动 8 个进程') => 'php bin/w server:start -c 8',
                __('CPU密集模式') => 'php bin/w server:start -m cpu',
                __('平滑重启') => 'php bin/w server:start -r',
                __('强制重启（停机型更新）') => 'php bin/w server:start -r -f',
                __('启用 HTTPS') => 'php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
                __('Windows 无 HTTPS 运行') => 'php bin/w server:start --no-ssl',
                __('设置 Worker 内存') => 'php bin/w server:start --worker-memory-limit=512M',
                __('查看所有实例状态') => 'php bin/w server:status --all',
                __('停止指定实例') => 'php bin/w server:stop api',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('压力测试') => 'php bin/w server:benchmark',
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
        $this->runFileWatcher($absoluteDirs, $watchInterval);
    }
    
    /**
     * 文件监控进程名（符合 Processer 规范：--name 标识）
     */
    protected const FILE_WATCHER_PROCESS_NAME = 'weline-wls-watcher';

    /**
     * 运行文件监控器（子进程模式）
     *
     * 遵循 Processer 进程管理规范：注册、检测、终止均通过 Processer
     * 文件监控在独立子进程中运行，主进程负责信号处理
     */
    protected function runFileWatcher(array $watchDirs, float $interval): void
    {
        $configDir = Env::VAR_DIR . 'tmp' . DS;
        if (!\is_dir($configDir)) {
            @\mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . 'file_watcher_' . \getmypid() . '_' . \time() . '.json';
        \file_put_contents($configFile, \json_encode([
            'watch_dirs' => $watchDirs,
            'check_interval' => $interval,
        ]));

        $watcherScript = \dirname(__DIR__, 2) . DS . 'bin' . DS . 'file_watcher.php';
        $phpBinary = \defined('PHP_BINARY') && \PHP_BINARY ? \PHP_BINARY : 'php';
        $processName = "\"{$phpBinary}\" \"{$watcherScript}\" \"{$configFile}\" --name=" . self::FILE_WATCHER_PROCESS_NAME;

        // 若已存在同进程，先销毁
        if (Processer::running($processName)) {
            Processer::destroy($processName);
        }

        $this->printer->note(__('按 Ctrl+C 停止监控...'));
        echo "\n";

        // 方案1：pcntl_fork（Linux/Mac），主进程可正确处理信号
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $this->runFileWatcherWithFork($phpBinary, $watcherScript, $configFile, $processName);
            return;
        }

        // 方案2：proc_open（Windows 或 pcntl 不可用）
        $this->runFileWatcherWithProcOpen($phpBinary, $watcherScript, $configFile, $processName);
    }

    /**
     * 使用 pcntl_fork 运行文件监控子进程
     */
    protected function runFileWatcherWithFork(string $phpBinary, string $watcherScript, string $configFile, string $processName): void
    {
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
            \pcntl_exec($phpBinary, [$watcherScript, $configFile, '--name=' . self::FILE_WATCHER_PROCESS_NAME]);
            exit(1);
        }

        Processer::setPid($processName, $pid);

        $shutdown = false;
        \pcntl_async_signals(true);
        \pcntl_signal(\SIGINT, function () use (&$shutdown) {
            $shutdown = true;
        });
        \pcntl_signal(\SIGTERM, function () use (&$shutdown) {
            $shutdown = true;
        });

        while (!$shutdown) {
            $result = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($result === $pid) {
                break;
            }
            if ($result === -1) {
                break;
            }
            \pcntl_signal_dispatch();
            SchedulerSystem::usleep(200000);
        }

        if ($shutdown && Processer::isRunningByPid($pid)) {
            Processer::killByPid($pid);
            \pcntl_waitpid($pid, $status);
        }
        Processer::destroy($processName);
        @\unlink($configFile);
    }

    /**
     * 使用 proc_open 运行文件监控子进程
     */
    protected function runFileWatcherWithProcOpen(string $phpBinary, string $watcherScript, string $configFile, string $processName): void
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
        ];
        $command = [$phpBinary, $watcherScript, $configFile, '--name=' . self::FILE_WATCHER_PROCESS_NAME];
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
        $pid = $status['pid'] ?? 0;
        if ($pid > 0) {
            Processer::setPid($processName, $pid);
        }

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
                Processer::killByPid($pid);
                break;
            }
            if (!IS_WIN) {
                \pcntl_signal_dispatch();
            }
            SchedulerSystem::usleep(200000);
        }
        \proc_close($proc);
        Processer::destroy($processName);
        @\unlink($configFile);
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

        $dir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $context['memory_mb'] = \round(\memory_get_usage(true) / 1048576, 2);
        $contextJson = $context === []
            ? '{}'
            : (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $now = \microtime(true);
        $seconds = (int)$now;
        $timestamp = \date('Y-m-d H:i:s', $seconds)
            . \sprintf('.%06d', (int)(($now - $seconds) * 1000000));

        @\file_put_contents(
            $dir . 'wls-startup-trace.log',
            \sprintf(
                "[%s] pid=%d instance=%s phase=%s context=%s%s",
                $timestamp,
                \getmypid(),
                $instanceName,
                $phase,
                $contextJson,
                PHP_EOL
            ),
            FILE_APPEND | LOCK_EX
        );
    }

    protected function acquireStartLock(string $instanceName, int $timeout = 3): bool
    {
        $lockDir = Env::VAR_DIR . 'server' . DS . 'locks' . DS;
        if (!\is_dir($lockDir)) {
            @\mkdir($lockDir, 0755, true);
        }
        
        $this->startLockFile = $lockDir . 'start_' . $instanceName . '.lock';
        $fp = @\fopen($this->startLockFile, 'c');
        if ($fp === false) {
            return false;
        }
        
        $startTime = \time();
        while (\time() - $startTime < $timeout) {
            if (\flock($fp, \LOCK_EX | \LOCK_NB)) {
                $this->startLockHandle = $fp;
                // 写入锁持有者信息，便于调试
                @\ftruncate($fp, 0);
                @\fwrite($fp, \json_encode([
                    'pid' => \getmypid(),
                    'instance' => $instanceName,
                    'started_at' => \date('Y-m-d H:i:s'),
                    'command' => \implode(' ', $_SERVER['argv'] ?? []),
                ], JSON_PRETTY_PRINT));
                @\fflush($fp);
                return true;
            }
            SchedulerSystem::usleep(100000); // 100ms
        }
        
        @\fclose($fp);
        return false;
    }

    /**
     * PHP 进程异常结束（fatal / 未捕获错误）时：若曾尝试拉起本实例 WLS 且未完成交接，则清理残留子进程。
     */
    public function shutdownCleanupOrphanWlsProcessesIfNeeded(): void
    {
        if ($this->wlsStartupProcessHandoffDone || !$this->wlsChildProcessesMayExist) {
            return;
        }
        $instanceName = $this->startLockInstanceName;
        if ($instanceName === '') {
            return;
        }
        try {
            $this->cleanupFailedStartupProcesses($instanceName, 16);
        } catch (\Throwable) {
            // shutdown 阶段尽力而为
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
        
        // 清理锁文件（可选，不影响锁机制）
        if ($this->startLockFile !== '' && \is_file($this->startLockFile)) {
            @\unlink($this->startLockFile);
        }
    }

    protected function acquireWorkerPortAllocationLock(int $timeout = self::WORKER_PORT_ALLOCATION_LOCK_TIMEOUT): bool
    {
        $lockDir = Env::VAR_DIR . 'server' . DS . 'locks' . DS;
        if (!\is_dir($lockDir)) {
            @\mkdir($lockDir, 0755, true);
        }

        $this->workerPortAllocationLockFile = $this->getWorkerPortAllocationLockFilePath();
        $fp = @\fopen($this->workerPortAllocationLockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $startTime = \time();
        while ((\time() - $startTime) < $timeout) {
            if (\flock($fp, \LOCK_EX | \LOCK_NB)) {
                $this->workerPortAllocationLockHandle = $fp;
                @\ftruncate($fp, 0);
                @\fwrite($fp, \json_encode([
                    'pid' => \getmypid(),
                    'started_at' => \date('Y-m-d H:i:s'),
                    'command' => \implode(' ', $_SERVER['argv'] ?? []),
                ], JSON_PRETTY_PRINT));
                @\fflush($fp);
                return true;
            }

            SchedulerSystem::usleep(100000);
        }

        @\fclose($fp);
        return false;
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

    /**
     * 获取 Weline 版本
     */
    protected function getWelineVersion(): string
    {
        // 尝试从 composer.json 获取版本
        static $version = null;
        if ($version === null) {
            $composerFile = BP . 'composer.json';
            if (\is_file($composerFile)) {
                $composer = \json_decode(\file_get_contents($composerFile), true);
                $version = $composer['version'] ?? '3.0.0';
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
}
