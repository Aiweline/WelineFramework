<?php
declare(strict_types=1);

/**
 * Weline Server - 精简版 Master 进程管理服务
 *
 * 重构后职责：
 * - 解析启动参数，构建 ServiceContext
 * - 创建 ServiceOrchestrator 并委托服务管理
 * - 信号处理
 * - 向后兼容的公共接口
 *
 * 核心服务管理逻辑已移至 ServiceOrchestrator。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\LogConfig;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\LongRunningPhpRuntime;
use Weline\Server\Service\SslCertificateService;

class MasterProcess
{
    private static ?string $projectScopeToken = null;
    /**
     * 运行模式常量
     */
    public const MODE_LEGACY = 'legacy';
    public const MODE_LINUX_DIRECT = 'linux-direct';
    public const MODE_WINDOWS_DISPATCHER = 'windows-dispatcher';

    /**
     * Master 进程名统一前缀
     */
    public const MASTER_PROCESS_NAME_PREFIX = 'weline-wls-master-';

    /**
     * HTTP 重定向进程名
     */
    public const HTTP_REDIRECT_PROCESS_NAME = 'weline-wls-redirect';

    /**
     * Session Server 进程名
     */
    public const SESSION_SERVER_PROCESS_NAME = 'weline-wls-session';
    
    /** IPC 消息颜色 ANSI 转义码 */
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BLUE = "\033[34m";    // 一般信息
    private const ANSI_GREEN = "\033[32m";   // 上报成功
    private const ANSI_YELLOW = "\033[33m";  // 通知排水/重载
    private const ANSI_RED = "\033[31m";     // 通知停止/错误

    /**
     * Worker 状态常量
     */
    public const WORKER_STATE_STARTING = 'starting';
    public const WORKER_STATE_RUNNING = 'running';
    public const WORKER_STATE_DRAINING = 'draining';
    public const WORKER_STATE_STOPPED = 'stopped';

    protected string $instanceName = '';
    protected string $mode = self::MODE_LEGACY;
    protected int $mainPort = 0;
    protected array $config = [];
    protected bool $running = true;
    protected bool $stopRequested = false;
    protected bool $frontend = false;
    protected int $controlPort = 0;
    protected string $sslCert = '';
    protected string $sslKey = '';
    protected bool $sslEnabled = false;
    protected int $httpRedirectPort = 0;
    
    // 运行态配置（由 Start.php 传递）
    protected ?bool $dispatcherEnabled = null;
    protected int|string|null $workerCount = null;
    protected ?int $workerBasePort = null;
    protected ?int $workerPort = null;

    protected ?Printing $printer = null;
    protected ?WlsLogger $logger = null;
    protected ?ServiceOrchestrator $orchestrator = null;
    protected ?ServiceContext $context = null;
    private bool $deferredSslRetryTriggered = false;

    /**
     * 启动完成后的回调（用于释放启动锁等）
     * @var ?\Closure
     */
    protected ?\Closure $onStartedCallback = null;

    public function __construct()
    {
        $this->logger = WlsLogger::getInstance();
    }

    public function setPrinter(Printing $printer): self
    {
        $this->printer = $printer;
        return $this;
    }

    /**
     * 设置启动完成后的回调
     * 
     * 在所有子进程启动完成后调用，用于释放启动锁等资源。
     */
    public function setOnStartedCallback(\Closure $callback): self
    {
        $this->onStartedCallback = $callback;
        return $this;
    }

    public function setMode(string $mode): self
    {
        $validModes = [self::MODE_LEGACY, self::MODE_LINUX_DIRECT, self::MODE_WINDOWS_DISPATCHER];
        if (\in_array($mode, $validModes, true)) {
            $this->mode = $mode;
        }
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMainPort(int $port): self
    {
        $this->mainPort = $port;
        return $this;
    }
    
    /**
     * 设置 Dispatcher 是否启用（运行态配置）
     */
    public function setDispatcherEnabled(?bool $enabled): self
    {
        $this->dispatcherEnabled = $enabled;
        return $this;
    }
    
    /**
     * 设置 Worker 数量（运行态配置）
     */
    public function setWorkerCount(int|string|null $count): self
    {
        $this->workerCount = $count;
        return $this;
    }
    
    /**
     * 设置 Worker 基础端口（运行态配置）
     */
    public function setWorkerBasePort(?int $port): self
    {
        $this->workerBasePort = $port;
        return $this;
    }
    
    /**
     * 设置首个 Worker 端口（运行态配置）
     */
    public function setWorkerPort(?int $port): self
    {
        $this->workerPort = $port;
        return $this;
    }

    /**
     * 初始化 Master 进程
     */
    public function init(
        string $instanceName,
        array $config,
        string $workerScript,
        string $sslCert = '',
        string $sslKey = '',
        bool $sslEnabled = false,
        int $httpRedirectPort = 0,
        bool $frontend = false
    ): self {
        $this->instanceName = $instanceName;
        $this->config = $config;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        $this->sslEnabled = $sslEnabled;
        $this->frontend = $frontend;
        $this->logger->setProcessTag('Master@' . $instanceName);

        // 始终启用文件日志（后台模式也需要日志）
        $this->logger->setFileEnabled(LogConfig::isEnabled());

        // 前台模式或全量调试 (-log)：控制台输出；默认后台仅写文件
        if (LogConfig::isVerboseWlsLog() && LogConfig::isStdoutEnabled($frontend, LogConfig::isDevMode())) {
            $this->logger->setStdoutEnabled(true);
        }

        $this->log('========================================');
        $this->log(__('Master 初始化开始 (Orchestrator 模式)'));
        $this->log(__('  实例名称: %{1}', [$instanceName]));
        $this->log(__('  运行模式: %{1}', [$this->mode]));
        $this->log(__('  前台模式: %{1}', [$frontend ? 'Yes' : 'No']));

        $port = (int) ($config['port'] ?? 80);
        $this->mainPort = $port;
        $this->httpRedirectPort = $httpRedirectPort;

        $this->log(__('Master 初始化完成'));
        $this->log('========================================');

        return $this;
    }

    /**
     * 运行 Master 主循环
     */
    public function run(): void
    {
        (new LongRunningPhpRuntime())->apply();

        // Master 不经 WlsRuntime::bootstrap()，须显式进入 WLS 模式，否则 Runtime::isWls() 为 false，
        // SchedulerWaitObserver 不注册 yield 定时器，runLoop 内延迟启动 Fiber 会永久挂起、子进程无法拉起。
        if (!\defined('WLS_MODE')) {
            \define('WLS_MODE', true);
        }
        Runtime::resetModeCache();

        $this->applyProcessTitle();

        // 当前 Master 进程 PID；finally 中 finalizeInstanceRuntimeAfterMasterExit() 需要此值，
        // 若 run() 早期 throw，getmypid() 仍能返回当前进程 PID（不会是 false，Master 必然在进程中运行）。
        $masterPid = (int) \getmypid();

        // 强制刷新日志缓冲区，确保后台模式下日志能写入
        $this->logger->flush(true);

        $this->log(__('启动 Master 进程...'));
        $this->logger->flush(true);

        try {
            // 注册 Master PID 到索引（用于快速检测 Master 是否退出）
            $this->registerMasterPid();
            $this->log(__('  已注册 Master PID'));
            $this->logger->flush(true);

            // 标记/清理孤儿实例记录；保留实例 JSON 供后续控制与恢复使用。
            $this->cleanupStaleInstanceFiles();
            $this->logger->flush(true);

            // 初始化控制端口：
            // 1) 优先使用 server.control_port（手动配置，不扫描）
            // 2) 未配置时：20000 + main_port + project_offset 为首选，若被占用则顺延（仅非其它 WLS 实例声明的占用）
            $configuredControlPort = (int) (Env::get('server.control_port', 0) ?? 0);
            $autoAssign = $configuredControlPort <= 0;
            $scanMax = (int) (Env::get('server.control_port_scan_max', 64) ?? 64);
            if ($scanMax < 1) {
                $scanMax = 1;
            }
            if ($scanMax > 512) {
                $scanMax = 512;
            }
            if (!$autoAssign) {
                $scanMax = 1;
            }

            if ($autoAssign) {
                $projectOffset = self::getProjectPortOffset();
                $preferredBase = 20000 + $this->mainPort + $projectOffset;
            } else {
                $preferredBase = $configuredControlPort;
            }

            if ($preferredBase <= 0 || $preferredBase > 65535) {
                throw new \RuntimeException(
                    "Invalid control port base: {$preferredBase}. Please set env server.control_port to a valid TCP port."
                );
            }

            $this->controlPort = 0;
            $chosenFallback = false;
            for ($i = 0; $i < $scanMax; $i++) {
                $candidate = $preferredBase + $i;
                if ($candidate > 65535) {
                    break;
                }
                $conflictInstance = $this->findRunningInstanceByControlPort($candidate);
                if ($conflictInstance !== null) {
                    if (!$autoAssign) {
                        throw new \RuntimeException(
                            "IPC control port {$candidate} is already used by instance '{$conflictInstance}'. " .
                            "Please set a unique server.control_port per instance."
                        );
                    }

                    continue;
                }
                if (Processer::isPortInUse($candidate)) {
                    if (!$autoAssign) {
                        throw new \RuntimeException(
                            "IPC control port {$candidate} is unavailable. " .
                            'Please free it or set a fixed server.control_port.'
                        );
                    }

                    continue;
                }
                $this->controlPort = $candidate;
                $chosenFallback = $autoAssign && $i > 0;
                break;
            }

            if ($this->controlPort <= 0) {
                throw new \RuntimeException(
                    $autoAssign
                        ? "IPC control port could not be bound: tried {$scanMax} candidate(s) from {$preferredBase}. " .
                          'Free a port in range or set server.control_port in env.'
                        : "IPC control port {$preferredBase} is unavailable. " .
                          'Please free it or set a fixed server.control_port.'
                );
            }

            if ($chosenFallback) {
                $this->log(__('  控制端口: %{1}（首选 %{2} 不可用，已自动顺延）', [$this->controlPort, $preferredBase]));
            } else {
                $this->log(__('  控制端口: %{1}', [$this->controlPort]));
            }

            // ========== 启动前清理：检查端口占用并清理僵尸进程 ==========
            $this->log(__('检查控制端口是否被占用...'));
            if (!MasterCleanupBootstrap::preBoot($this->instanceName, $this->controlPort)) {
                throw new \RuntimeException(
                    "无法清理控制端口 {$this->controlPort}，该端口被其他进程占用。" .
                    "请确保没有其他 WLS 实例运行，或手动杀死占用该端口的进程。"
                );
            }
            
            // 清理陈旧的锁文件（Master 上次崩溃留下的）
            MasterCleanupBootstrap::cleanupLockFiles($this->instanceName);

            // 构建 ServiceContext
            $this->context = $this->buildContext();

            // 创建 Orchestrator
            $this->orchestrator = new ServiceOrchestrator();
            $this->orchestrator->loadProviders();
            $this->log(__('Master 启动阶段：Orchestrator providers 已加载'));
            $this->logger->flush(true);

            // 注册信号处理
            $this->registerSignalHandlers();
            $this->log(__('Master 启动阶段：信号处理器已注册'));
            $this->logger->flush(true);

            // 先拉起控制面并落盘 Master 信息，让后台启动确认不再被子服务启动阶段阻塞
            $this->orchestrator->bootstrapControlPlane($this->context);
            $this->context = $this->orchestrator->getContext() ?? $this->context;
            $this->controlPort = $this->context?->controlPort ?? $this->controlPort;
            $this->log(__('Master 启动阶段：控制面已启动'));
            $this->logger->flush(true);
            $this->saveMasterInfo('bootstrapping');
            $this->log(__('Master 启动阶段：bootstrapping 实例信息已写入'));
            $this->logger->flush(true);

            // 打印当前 Master 自愈（子进程复活）HA 模式，便于部署方快速判断行为
            $selfHealMode = $this->resolveSelfHealModeForLog();
            $this->log(__('Master 自愈模式：%{1}', [$selfHealMode]));
            $this->logger->flush(true);

            // 进入主循环；子服务在 Fiber 中拉起，等待期间仍可 poll 控制面 IPC（启动完成后再释放启动锁，方案 B）
            $this->log(__('Master 进入主循环，监控子进程...'));
            $this->logger->flush(true);
            $this->orchestrator->runLoopWithDeferredChildStartup($this->context, function (): void {
                $this->releaseStartupLock();
            });
            $this->log(__('Master 主循环结束'));
        } catch (\Throwable $e) {
            $this->log(__('Master 启动失败: %{1}', [$e->getMessage()]));
            WlsLogger::error_('[Master] 启动异常: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        } finally {
            // 尝试优雅关闭 Orchestrator（停止所有子进程）
            try {
                if ($this->orchestrator !== null && $this->orchestrator->isRunning()) {
                    $this->log(__('Master 正在关闭，通知所有子进程...'));
                    $this->saveMasterInfo('stopping');
                    $this->orchestrator->stopAll('master_shutdown', null);
                }
            } catch (\Throwable $shutdownError) {
                WlsLogger::warning_('[Master] 关闭子进程过程中出现错误: ' . $shutdownError->getMessage());
            }
            
            // 清理 IPC 控制服务器
            try {
                $controlServer = $this->orchestrator?->getControlServer();
                if ($controlServer !== null) {
                    WlsLogger::warning_(
                        '[Master] IPC control server remained open in finally, closing directly. '
                        . ($this->orchestrator?->describeLifecycleState() ?? 'orchestrator=null')
                    );
                    $controlServer->close();
                    WlsLogger::info_('[Master] IPC 控制服务器已关闭');
                }
            } catch (\Throwable $ipcError) {
                WlsLogger::debug_('[Master] IPC 关闭过程中出现错误: ' . $ipcError->getMessage());
            }

            // 最后再注销 Master PID，并在保留实例 JSON 的前提下标记/清理运行记录。
            try {
                $this->unregisterMasterPid();
            } catch (\Throwable $indexCleanupError) {
                WlsLogger::debug_('[Master] 注销 Master PID 索引失败: ' . $indexCleanupError->getMessage());
            }

            try {
                $this->finalizeInstanceRuntimeAfterMasterExit($masterPid);
            } catch (\Throwable $instanceCleanupError) {
                WlsLogger::warning_('[Master] 整理实例运行记录失败: ' . $instanceCleanupError->getMessage());
            }
        }
    }
    
    /**
     * 注册 Master PID 到索引
     * 
     * 这样 CLI 可以通过检查索引快速判断 Master 是否已退出，
     * 而不需要调用 tasklist/ps 等外部命令。
     */
    private function registerMasterPid(): void
    {
        $masterPid = \getmypid();
        $masterName = '--name=' . self::getMasterProcessName($this->instanceName);
        
        Processer::setPid($masterName, $masterPid);
        $this->log(__('Master PID %{1} 已注册到索引', [$masterPid]));
    }

    private function applyProcessTitle(): void
    {
        if (!\function_exists('cli_set_process_title')) {
            return;
        }

        @\cli_set_process_title(self::getMasterProcessCliTitle($this->instanceName, $this->frontend));
    }

    /**
     * 从进程索引移除 Master PID
     */
    private function unregisterMasterPid(): void
    {
        $masterName = '--name=' . self::getMasterProcessName($this->instanceName);
        Processer::removePidFile($masterName);
        $this->log(__('Master PID 索引已移除'));
    }

    /**
     * Master 退出后整理实例运行记录。
     *
     * 若子进程仍存活，则保留 instance.json，避免 stop/status 失去恢复线索。
     * 若所有受管进程都已退出，则标记/清理当前运行记录；实例 JSON 仍保留。
     */
    private function finalizeInstanceRuntimeAfterMasterExit(int $masterPid): void
    {
        if ($this->instanceName === '') {
            return;
        }

        $manager = new ServerInstanceManager();
        $retained = $manager->finalizeAfterMasterExit($this->instanceName, $masterPid);

        if ($retained) {
            $this->log(__('检测到 Master 退出后仍有子进程存活，已保留实例记录供后续恢复控制'));
            return;
        }

        $this->log(__('实例记录已完成清理'));
    }

    /**
     * 清理孤儿实例记录（启动时调用）
     *
     * 标记/清理 updated_at 超过 60 秒未更新的实例记录，
     * 说明对应的 Master 进程已经死亡或崩溃；实例 JSON 文件保留供后续控制与恢复使用。
     */
    private function cleanupStaleInstanceFiles(): void
    {
        try {
            $cleaned = (new ServerInstanceManager())->cleanupStaleInstances();
            if ($cleaned > 0) {
                $this->log(__('共清理 %{1} 个孤儿实例记录，实例 JSON 已保留', [$cleaned]));
            }
        } catch (\Throwable $e) {
            $this->log(__('孤儿实例清理过程出错: %{1}', [$e->getMessage()]));
        }
    }

    /**
     * 构建 ServiceContext
     */
    private function buildContext(): ServiceContext
    {
        $envConfig = Env::getInstance()->getConfig() ?: [];
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }
        $envConfig = $this->applySharedStateRuntimeConfig($envConfig);
        $envConfig = $this->applyRuntimeWlsConfig($envConfig);

        $publicHostRaw = $this->config['public_host'] ?? null;
        $publicHost = \is_string($publicHostRaw) && $publicHostRaw !== '' ? $publicHostRaw : null;

        return new ServiceContext(
            instanceName: $this->instanceName,
            epoch: 1,
            controlPort: $this->controlPort,
            masterPid: \getmypid(),
            host: $this->config['host'] ?? '127.0.0.1',
            mainPort: $this->mainPort,
            sslEnabled: $this->sslEnabled,
            sslCert: $this->sslCert,
            sslKey: $this->sslKey,
            mode: $this->mode,
            daemon: !$this->frontend,
            debug: (bool) ($this->config['debug'] ?? false),
            frontend: $this->frontend,
            envConfig: $envConfig,
            httpRedirectPort: $this->httpRedirectPort,
            // 运行态配置：由 Start.php 传入，优先级高于 envConfig
            dispatcherEnabled: $this->dispatcherEnabled,
            workerCount: $this->workerCount,
            workerBasePort: $this->workerBasePort,
            workerPort: $this->workerPort,
            publicHost: $publicHost,
        );
    }

    /**
     * Keep start-time WLS options available to providers without mutating env.php.
     *
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    private function applyRuntimeWlsConfig(array $envConfig): array
    {
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];

        if (isset($this->config['worker_memory_limit'])) {
            $wls['worker_memory_limit'] = ServiceContext::normalizeMemoryLimit($this->config['worker_memory_limit']);
        }
        if (isset($this->config['dispatcher_memory_limit'])) {
            $wls['dispatcher_memory_limit'] = ServiceContext::normalizeMemoryLimit(
                $this->config['dispatcher_memory_limit'],
                ServiceContext::normalizeMemoryLimit($wls['worker_memory_limit'] ?? '256M')
            );
        }

        if ($wls !== []) {
            $envConfig['wls'] = $wls;
        }

        return $envConfig;
    }

    /**
     * Keep sidecar runtime ports/token names instance-local without mutating env.php on disk.
     *
     * @param array<string, mixed> $envConfig
     * @return array<string, mixed>
     */
    private function applySharedStateRuntimeConfig(array $envConfig): array
    {
        $sessionPort = (int) ($this->config['session_server_port'] ?? 0);
        $sessionTokenFileName = (string) ($this->config['session_server_token_file_name'] ?? '');
        $memoryPort = (int) ($this->config['memory_server_port'] ?? 0);
        $memoryTokenFileName = (string) ($this->config['memory_server_token_file_name'] ?? '');
        $sharedStateRuntime = \is_array($this->config['shared_state'] ?? null) ? $this->config['shared_state'] : [];

        if ($sessionPort > 0) {
            if (!isset($envConfig['session']) || !\is_array($envConfig['session'])) {
                $envConfig['session'] = [];
            }
            $envConfig['session']['server_host'] = '127.0.0.1';
            $envConfig['session']['server_port'] = $sessionPort;

            if (!isset($envConfig['wls']) || !\is_array($envConfig['wls'])) {
                $envConfig['wls'] = [];
            }
            if (!isset($envConfig['wls']['session']) || !\is_array($envConfig['wls']['session'])) {
                $envConfig['wls']['session'] = [];
            }
            $envConfig['wls']['session']['host'] = '127.0.0.1';
            $envConfig['wls']['session']['port'] = $sessionPort;
            if ($sessionTokenFileName !== '') {
                $envConfig['wls']['session']['token_file_name'] = $sessionTokenFileName;
            }

            if (!isset($envConfig['wls']['session']['wls_server']) || !\is_array($envConfig['wls']['session']['wls_server'])) {
                $envConfig['wls']['session']['wls_server'] = [];
            }
            $envConfig['wls']['session']['wls_server']['host'] = '127.0.0.1';
            $envConfig['wls']['session']['wls_server']['port'] = $sessionPort;
            if ($sessionTokenFileName !== '') {
                $envConfig['wls']['session']['wls_server']['token_file_name'] = $sessionTokenFileName;
            }
        }

        if ($memoryPort > 0) {
            if (!isset($envConfig['wls']) || !\is_array($envConfig['wls'])) {
                $envConfig['wls'] = [];
            }
            if (!isset($envConfig['wls']['memory_service']) || !\is_array($envConfig['wls']['memory_service'])) {
                $envConfig['wls']['memory_service'] = [];
            }
            $envConfig['wls']['memory_service']['host'] = '127.0.0.1';
            $envConfig['wls']['memory_service']['port'] = $memoryPort;
            if ($memoryTokenFileName !== '') {
                $envConfig['wls']['memory_service']['token_file_name'] = $memoryTokenFileName;
            }
        }

        if ($sharedStateRuntime !== []) {
            if (!isset($envConfig['wls']) || !\is_array($envConfig['wls'])) {
                $envConfig['wls'] = [];
            }
            if (!isset($envConfig['wls']['shared_state']) || !\is_array($envConfig['wls']['shared_state'])) {
                $envConfig['wls']['shared_state'] = [];
            }
            $envConfig['wls']['shared_state']['runtime'] = $sharedStateRuntime;
        }

        $orchestratorRuntimeOptions = \is_array($this->config['orchestrator_runtime_options'] ?? null)
            ? $this->config['orchestrator_runtime_options']
            : [];
        if ($orchestratorRuntimeOptions !== []) {
            if (!isset($envConfig['wls']) || !\is_array($envConfig['wls'])) {
                $envConfig['wls'] = [];
            }
            if (!isset($envConfig['wls']['orchestrator']) || !\is_array($envConfig['wls']['orchestrator'])) {
                $envConfig['wls']['orchestrator'] = [];
            }
            $envConfig['wls']['orchestrator'] = \array_merge(
                $envConfig['wls']['orchestrator'],
                $orchestratorRuntimeOptions
            );
        }

        return $envConfig;
    }

    /**
     * 注册信号处理器
     */
    private function registerSignalHandlers(): void
    {
        // Linux/macOS: 使用 pcntl_signal
        if (\function_exists('pcntl_signal')) {
            \pcntl_async_signals(true);

            if (\defined('SIGCHLD') && \function_exists('pcntl_waitpid')) {
                \pcntl_signal(SIGCHLD, function () {
                    $this->reapExitedChildren();
                });
            }

            // SIGTERM / SIGINT: 优雅停止（统一走 IPC 停机通道）
            \pcntl_signal(SIGTERM, function () {
                $this->handleTerminationSignal('SIGTERM', __('收到 SIGTERM 信号，开始统一停机流程...'));
            });

            \pcntl_signal(SIGINT, function () {
                $this->handleTerminationSignal('SIGINT (Ctrl+C)', __('收到 SIGINT 信号，开始统一停机流程...'));
            });

            if (\defined('SIGQUIT')) {
                \pcntl_signal(SIGQUIT, function () {
                    $this->handleTerminationSignal('SIGQUIT', __('收到 SIGQUIT 信号，开始统一停机流程...'));
                });
            }

            // SIGHUP: 重载
            \pcntl_signal(SIGHUP, function () {
                if ($this->stopRequested || $this->orchestrator?->isShuttingDown()) {
                    $this->log(__('停机流程进行中，忽略 SIGHUP 重载信号'), 'warning');
                    return;
                }
                $this->log(__('收到 SIGHUP 信号，开始重载...'));
                $this->reload();
            });

            // SIGUSR1: 状态报告
            \pcntl_signal(SIGUSR1, function () {
                $status = $this->getStatus();
                $this->log(__('状态报告: %{1}', [\json_encode($status, JSON_PRETTY_PRINT)]));
            });

            $registeredSignals = [];
            if (\defined('SIGCHLD') && \function_exists('pcntl_waitpid')) {
                $registeredSignals[] = 'SIGCHLD';
            }
            $registeredSignals[] = 'SIGTERM';
            $registeredSignals[] = 'SIGINT';
            if (\defined('SIGQUIT')) {
                $registeredSignals[] = 'SIGQUIT';
            }
            $registeredSignals[] = 'SIGHUP';
            $registeredSignals[] = 'SIGUSR1';
            $this->log(__('已注册 pcntl 信号: %{1}', [\implode(', ', $registeredSignals)]));
            return;
        }

        // Windows: 使用 sapi_windows_set_ctrl_handler
        if (\function_exists('sapi_windows_set_ctrl_handler')) {
            \sapi_windows_set_ctrl_handler(function (int $event): bool {
                if ($event === \PHP_WINDOWS_EVENT_CTRL_C || $event === \PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    $signal = $event === \PHP_WINDOWS_EVENT_CTRL_BREAK ? 'Ctrl+Break (Windows)' : 'Ctrl+C (Windows)';
                    $message = $event === \PHP_WINDOWS_EVENT_CTRL_BREAK
                        ? __('Windows 模式：收到 Ctrl+Break 信号，开始统一停机流程...')
                        : __('Windows 模式：收到 Ctrl+C 信号，开始统一停机流程...');
                    $this->handleTerminationSignal($signal, $message);
                    return true;
                }
                return false;
            });
            $this->log(__('Windows 模式：已注册 Ctrl+C 处理函数（sapi_windows_set_ctrl_handler）'));
            return;
        }

        // 后备：register_shutdown_function（无法捕获 Ctrl+C，但可处理正常退出）
        \register_shutdown_function(function () {
            if ($this->running) {
                $this->log(__('检测到异常退出，执行清理...'));
                $this->stop();
            }
        });
        $this->log(__('已注册 shutdown 清理函数（Ctrl+C 可能不会触发优雅停止）'), 'warning');
    }

    private function handleTerminationSignal(string $signal, string $message): void
    {
        if ($this->stopRequested) {
            // Ctrl+C 的语义：第一次进入带排水的统一停机；连续点击直接升级强制清场。
            $this->escalateTerminationToForceExit($signal);
            return;
        }

        $this->log($message);
        $this->stopWithProgress($signal);
    }

    /**
     * 优雅停机已启动后再次收到终止信号：强杀子进程并退出（Windows 上常见“再按一次 Ctrl+C”预期）。
     */
    private function escalateTerminationToForceExit(string $signal): void
    {
        $this->log("停机流程进行中，再次收到终止信号：将强制结束子进程并退出 Master（signal={$signal}）", 'warning');
        $this->logger?->flush(true);
        if ($this->orchestrator !== null) {
            $this->orchestrator->forceTerminateMasterAndChildren('repeat_signal:' . $signal);
        }
        exit(2);
    }

    /**
     * 停止 Master（用于信号处理，统一走 IPC 停机通道）
     */
    public function stopWithProgress(string $signal): void
    {
        if ($this->stopRequested || $this->orchestrator?->isShuttingDown()) {
            $this->escalateTerminationToForceExit($signal);
        }

        $this->stopRequested = true;
        $this->orchestrator?->setMasterShutdownIntent(true);
        $this->running = false;

        if ($this->frontend) {
            self::ipcMsg("收到 {$signal}，已切换到统一停机流程", 'stop');
        }

        if ($this->orchestrator?->requestStop($signal)) {
            // Windows 前台 Ctrl+C 期望“按一次就生效”：
            // requestStop 仅设置 pending 标记，这里补一次同步 nudge，尽快将 stop_all 入队。
            $this->orchestrator?->applyRepeatTerminationNudge();
            return;
        }

        if ($this->orchestrator?->isShuttingDown()) {
            return;
        }

        if ($this->frontend) {
            self::ipcMsg("统一停机请求未入队，回退为本地停机流程（signal={$signal}）", 'error');
        }

        $this->orchestrator?->stopAll($signal);
    }

    /**
     * 停止 Master（静默模式，用于 IPC 命令）
     */
    public function stop(): void
    {
        if ($this->stopRequested) {
            return;
        }

        $this->stopRequested = true;
        $this->orchestrator?->setMasterShutdownIntent(true);
        $this->running = false;
        $this->orchestrator?->stopAll('manual');
        $this->orchestrator?->stop();
    }

    /**
     * 回收已退出的子进程，避免在前台模式下残留僵尸进程。
     */
    private function reapExitedChildren(): void
    {
        if (!\function_exists('pcntl_waitpid') || !\defined('WNOHANG')) {
            return;
        }

        do {
            $status = 0;
            $pid = \pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                $detail = 'unknown';
                if (\function_exists('pcntl_wifexited') && \pcntl_wifexited($status) && \function_exists('pcntl_wexitstatus')) {
                    $detail = 'exit=' . \pcntl_wexitstatus($status);
                } elseif (\function_exists('pcntl_wifsignaled') && \pcntl_wifsignaled($status) && \function_exists('pcntl_wtermsig')) {
                    $detail = 'signal=' . \pcntl_wtermsig($status);
                }
                $this->log(__('已回收子进程 PID %{1} (%{2})', [$pid, $detail]));
            }
        } while ($pid > 0);
    }

    /**
     * 重载所有服务
     */
    public function reload(string $type = 'code'): void
    {
        $this->orchestrator?->reloadAll($type);
    }

    /**
     * 获取状态
     */
    public function getStatus(): array
    {
        return $this->orchestrator?->getStatus() ?? [
            'running' => false,
            'message' => 'Orchestrator not initialized',
        ];
    }

    /**
     * 保存 Master 信息到实例文件
     * 
     * 注意：合并现有数据而非覆盖，保留 Start.php 保存的 worker_port、count 等字段
     */
    public function saveMasterInfo(string $startupPhase = 'running'): void
    {
        $instanceFile = $this->getInstanceFile();
        $dir = \dirname($instanceFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        // 读取现有数据并合并，保留 Start.php 保存的配置
        $existingData = [];
        if (\is_file($instanceFile)) {
            $content = @\file_get_contents($instanceFile);
            if ($content !== false) {
                $existingData = \json_decode($content, true) ?: [];
            }
        }

        // Master 进程的状态信息
        $masterData = [
            'master_pid' => \getmypid(),
            'master_enabled' => true,
            'master_started_at' => \date('Y-m-d H:i:s'),
            'master_mode' => $this->mode,
            'main_port' => $this->mainPort,
            'control_port' => $this->controlPort,
            'startup_phase' => $startupPhase,
            'instance_name' => $this->instanceName,
            'orchestrator_mode' => true,
            'updated_at' => \time(),  // 心跳时间戳（用于检测 Master 是否存活）
        ];

        // 合并：保留现有配置（如 worker_port、count、ssl_enabled 等）并更新 Master 状态
        $controlServer = $this->orchestrator?->getControlServer();
        if ($controlServer instanceof HybridControlPlaneServer) {
            $masterData['control_plane_mode'] = $controlServer->isSupervisorEnabled() ? 'hybrid' : 'legacy';
            $masterData['supervisor_enabled'] = $controlServer->isSupervisorEnabled();
            $masterData['supervisor_channel'] = $controlServer->supervisorChannelId();
            $masterData['supervisor_endpoint'] = $controlServer->supervisorEndpointUri();
        }

        $data = \array_merge($existingData, $masterData);

        // 使用原子写入确保与Start.php的并发写入不产生竞态条件
        // （Start.php的saveInstanceInfo()也使用了atomicWriteJsonStatic）
        \Weline\Server\Service\ServerInstanceManager::atomicWriteJsonStatic($instanceFile, $data, 10);
    }

    /**
     * 获取实例文件路径
     */
    protected function getInstanceFile(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
    }

    /**
     * 获取 Master 进程名
     */
    public static function getMasterProcessName(string $instanceName): string
    {
        return self::buildScopedProcessName(self::MASTER_PROCESS_NAME_PREFIX, $instanceName);
    }

    public static function getMasterProcessDisplayName(string $instanceName, bool $frontend = false): string
    {
        $name = self::getMasterProcessName($instanceName);

        return $frontend ? $name . '-frontend' : $name;
    }

    public static function getMasterProcessCliTitle(string $instanceName, bool $frontend = false): string
    {
        $title = 'weline-wls-master --name=' . self::getMasterProcessName($instanceName);

        return $frontend ? $title . ' --frontend' : $title;
    }

    /**
     * 返回当前项目的稳定作用域标识。
     * 用于在同机多项目时避免进程名冲突。
     */
    public static function getProjectScopeToken(): string
    {
        if (self::$projectScopeToken !== null) {
            return self::$projectScopeToken;
        }

        $basePath = \str_replace('\\', '/', \rtrim((string) BP, "\\/"));
        $hash = \substr(\sha1(\strtolower($basePath)), 0, 8);
        self::$projectScopeToken = 'p' . $hash;

        return self::$projectScopeToken;
    }

    /**
     * 获取项目级端口偏移量（用于多项目部署时避免端口冲突）。
     *
     * 基于项目路径哈希计算偏移量，确保同一服务器上不同项目的端口不重叠。
     * 偏移范围：0-9999（每个项目占用约 10000 个端口）
     *
     * @return int 端口偏移量（0-9999）
     */
    public static function getProjectPortOffset(): int
    {
        static $offset = null;
        if ($offset !== null) {
            return $offset;
        }

        // 优先使用环境变量配置（手动指定偏移量）
        $envOffset = (int) (Env::get('server.project_port_offset', 0) ?? 0);
        if ($envOffset > 0 && $envOffset < 60000) {
            $offset = $envOffset;
            return $offset;
        }

        // 基于项目路径哈希自动计算偏移量
        $basePath = \str_replace('\\', '/', \rtrim((string) BP, "\\/"));
        $hash = \sha1(\strtolower($basePath));

        // 取哈希的前 4 个字节转为整数，模 10000 得到偏移量
        $hashInt = \hexdec(\substr($hash, 0, 8));
        $offset = ($hashInt % 10000);

        return $offset;
    }

    /**
     * 实例名附加项目作用域，避免跨项目重名。
     */
    public static function getScopedInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            $instanceName = 'default';
        }

        return $instanceName . '-' . self::getProjectScopeToken();
    }

    /**
     * 生成带项目作用域的进程名。
     */
    public static function buildScopedProcessName(string $prefix, string $instanceName, ?int $slot = null): string
    {
        $prefix = \rtrim($prefix, '-');
        $name = $prefix . '-' . self::getScopedInstanceName($instanceName);
        if ($slot !== null) {
            $name .= '-' . $slot;
        }

        return $name;
    }

    /**
     * 获取实例的 Master 信息
     */
    public static function getMasterInfo(string $instanceName = 'default'): ?array
    {
        $file = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!\is_file($file)) {
            return null;
        }
        $content = @\file_get_contents($file);
        if (!$content) {
            return null;
        }
        $data = \json_decode($content, true);
        if (!\is_array($data) || empty($data['master_enabled'])) {
            return null;
        }
        return $data;
    }

    /**
     * 检查指定实例的 Master 是否运行中
     */
    public static function isMasterRunning(string $instanceName = 'default'): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['master_pid'])) {
            return false;
        }
        $processName = self::getMasterProcessName($instanceName);

        return Processer::isManagedProcessRunning(
            (int) $info['master_pid'],
            $processName,
            '',
            '--name=' . $processName
        );
    }

    /**
     * 格式化 IPC 消息（带颜色）
     * 
     * @param string $message 消息内容
     * @param string $type 消息类型：success, drain, stop, error, info
     */
    protected static function ipcMsg(string $message, string $type = 'info'): void
    {
        $color = match ($type) {
            'success' => self::ANSI_GREEN,   // 绿色：上报成功
            'drain' => self::ANSI_YELLOW,    // 黄色：通知排水/重载
            'stop' => self::ANSI_RED,        // 红色：通知停止
            'error' => self::ANSI_RED,       // 红色：错误
            default => self::ANSI_BLUE,      // 蓝色：一般信息
        };
        
        $tag = self::ANSI_BLUE . '[IPC]' . self::ANSI_RESET;
        $content = $color . $message . self::ANSI_RESET;
        echo "  {$tag} {$content}\n";
    }

    /**
     * 停机进度消息类型判定。
     */
    protected static function classifyStopProgressMessage(string $message): string
    {
        if (\str_contains($message, '✓')
            || \str_contains($message, '已退出')
            || \str_contains($message, '已断开')
            || \str_contains($message, '排水完成')) {
            return 'success';
        }

        if (\str_contains($message, '失败')
            || \str_contains($message, '错误')
            || \str_contains($message, '超时')) {
            return 'error';
        }

        if (\str_contains($message, 'SHUTDOWN')
            || \str_contains($message, '通知子进程退出')
            || \str_contains($message, '强制')
            || \str_contains($message, '校验子进程退出')
            || \str_contains($message, 'Master 即将退出')
            || \str_contains($message, 'Stopping')) {
            return 'stop';
        }

        if (\str_contains($message, 'DRAIN')
            || \str_contains($message, '排水')
            || \str_contains($message, '等待排水')
            || \str_contains($message, '阶段')) {
            return 'drain';
        }

        return 'info';
    }

    /**
     * 输出统一的 IPC 停机进度。
     */
    protected static function renderStopProgress(string $message): void
    {
        self::ipcMsg($message, self::classifyStopProgressMessage($message));
    }

    /**
     * 信号停止时，自连本地控制端口并复用 IPC 停机流程。
     */
    private function stopWithProgressViaIpc(string $signal): bool
    {
        $server = $this->orchestrator?->getControlServer();
        if ($server === null || $this->controlPort <= 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$this->controlPort}", $errno, $errstr, 3);
        if (!$conn) {
            return false;
        }

        $written = @\fwrite($conn, ControlMessage::command(
            ControlMessage::ACTION_STOP,
            '',
            [
                'stop_intent' => 'explicit',
                'stop_source' => 'master:signal-bridge',
                'stop_trace_id' => 'sig-stop-' . \getmypid() . '-' . \time(),
            ]
        ));
        if ($written === false || $written === 0) {
            @\fclose($conn);
            return false;
        }

        \stream_set_blocking($conn, false);
        \stream_set_timeout($conn, 0);

        $deadline = \microtime(true) + 20.0;
        $buffer = '';
        $lastProgress = '';

        if ($this->frontend) {
            self::ipcMsg("收到 {$signal}，已切换到统一 IPC 停机流程", 'stop');
        }

        while (\microtime(true) < $deadline) {
            $server->poll(0, 100000);

            $chunk = @\fread($conn, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                foreach (ControlMessage::extractMessages($buffer) as $msg) {
                    if (($msg['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                        continue;
                    }

                    $message = (string) ($msg['message'] ?? '');
                    if ($message === '' || $message === $lastProgress) {
                        continue;
                    }

                    if ($this->frontend) {
                        self::renderStopProgress($message);
                    }
                    $lastProgress = $message;
                }
            }

            if (\feof($conn)) {
                @\fclose($conn);
                return true;
            }
        }

        @\fclose($conn);

        if ($this->frontend) {
            self::ipcMsg("等待本地 IPC 停机进度超时（signal={$signal}）", 'error');
        }

        return false;
    }

    /**
     * 通过 IPC 控制通道发送停止命令给 Master
     * 
     * @param string $instanceName 实例名称
     * @param bool $verbose 是否输出详细日志到控制台
     * @return bool 是否发送成功
     */
    public static function sendStopCommand(string $instanceName = 'default', bool $verbose = true): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            if ($verbose) {
                self::ipcMsg("无法获取实例 [{$instanceName}] 的控制端口信息", 'error');
            }
            return false;
        }

        $controlPort = (int) $info['control_port'];
        $masterPid = (int) ($info['master_pid'] ?? 0);
        $host = '127.0.0.1';

        if ($verbose) {
            self::ipcMsg("连接 Master (PID:{$masterPid}) 控制端口 {$host}:{$controlPort}...", 'info');
        }

        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            if ($verbose) {
                self::ipcMsg("连接失败: {$errstr} (errno:{$errno})", 'error');
            }
            return false;
        }

        if ($verbose) {
            self::ipcMsg("连接成功 ✓", 'success');
            self::ipcMsg("发送 STOP 命令...", 'stop');
        }

        $stopMsg = ControlMessage::command(
            ControlMessage::ACTION_STOP,
            '',
            [
                'stop_intent' => 'explicit',
                'stop_source' => 'master:send-stop-command',
                'stop_trace_id' => 'send-stop-' . \getmypid() . '-' . \time(),
            ]
        );
        $written = @\fwrite($conn, $stopMsg);
        
        if ($written === false || $written === 0) {
            if ($verbose) {
                self::ipcMsg("发送命令失败", 'error');
            }
            @\fclose($conn);
            return false;
        }

        if ($verbose) {
            self::ipcMsg("等待 Master 响应...", 'stop');
        }

        // 设置阻塞读取超时
        \stream_set_timeout($conn, 5);
        \stream_set_blocking($conn, true);
        
        $response = '';
        $deadline = \microtime(true) + 5;
        
        while (\microtime(true) < $deadline) {
            $read = [$conn];
            $write = $except = null;
            $ready = @\stream_select($read, $write, $except, 1);
            
            if ($ready === false) {
                break;
            }
            
            if ($ready > 0) {
                $data = @\fread($conn, 4096);
                if ($data === false || $data === '') {
                    // 连接关闭
                    break;
                }
                $response .= $data;
                if (\strpos($response, "\n") !== false) {
                    break;
                }
            }
        }
        @\fclose($conn);

        $msg = ControlMessage::decode($response);
        $success = $msg !== null && ($msg['success'] ?? false);
        
        if ($verbose) {
            if ($success) {
                $message = $msg['message'] ?? 'OK';
                self::ipcMsg("Master 响应: {$message}", 'success');
                self::ipcMsg("Master 开始广播 SHUTDOWN 给所有子进程...", 'stop');
            } else {
                // 如果响应为空但命令发送成功，可能 Master 收到命令后就开始停止了
                // 这种情况下也认为成功
                if ($response === '' && $written > 0) {
                    self::ipcMsg("命令已发送，Master 正在处理...", 'drain');
                    return true;
                }
                self::ipcMsg("Master 响应失败或超时 (response: " . \strlen($response) . " bytes)", 'error');
            }
        }

        return $success;
    }

    /**
     * 通过 IPC 控制通道发送重载命令给 Master
     * 
     * @param string $instanceName 实例名称
     * @param string $type 重载类型 (code, force, cache)
     * @return bool 是否发送成功
     */
    public static function sendReloadCommand(string $instanceName = 'default', string $type = 'code'): bool
    {
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $result = $type === ControlMessage::RELOAD_TYPE_CACHE
                ? $gateway->cacheClear($instanceName)
                : $gateway->reloadAsync($instanceName, $type, 3.0);
            return (bool)($result['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 通知 Master 广播 SSL 证书热重载命令给所有 Worker。
     * Worker 会重新读取 ssl_certificate_map.json 并更新 SNI 证书映射，无需重启。
     *
     * @param string   $instanceName WLS 实例名
     * @param string[] $domains      需要清除负缓存并刷新的域名列表；空 = 全量重载
     */
    public static function sendSslCertReloadCommand(string $instanceName = 'default', array $domains = []): bool
    {
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $result = $gateway->reloadSslCert($instanceName, $domains);
            return (bool)($result['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 通过 IPC 控制通道查询 Master 状态
     * 
     * @param string $instanceName 实例名称
     * @param float $timeout 超时时间（秒）
     * @return array|null 状态数据或 null（超时/失败）
     */
    public static function sendStatusQuery(string $instanceName = 'default', float $timeout = 3): ?array
    {
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $result = $gateway->getStatus($instanceName, $timeout);
            if (!($result['success'] ?? false)) {
                return null;
            }

            return \is_array($result['data'] ?? null) ? $result['data'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取 Orchestrator 实例
     */
    public function getOrchestrator(): ?ServiceOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * 获取控制端口
     */
    public function getControlPort(): int
    {
        return $this->controlPort;
    }

    /**
     * 日志输出
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $formatted = "[Master@" . ($this->instanceName !== '' ? $this->instanceName : 'unknown') . "] {$message}";

        switch ($level) {
            case 'error':
                WlsLogger::error_($formatted);
                break;
            case 'warning':
                WlsLogger::warning_($formatted);
                break;
            case 'debug':
                WlsLogger::debug_($formatted);
                break;
            default:
                WlsLogger::info_($formatted);
        }

        // 前台模式下，WlsLogger 已输出到控制台，无需再通过 printer 重复输出
        // 后台模式下，通过 printer 输出（此时 Logger 只写文件）
        if (!$this->frontend && $this->printer !== null) {
            $this->printer->note($formatted);
        }
    }

    // ========== 向后兼容的方法（新架构中由 Orchestrator 管理，这些方法仅保留接口兼容）==========

    /**
     * 设置 Worker PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setWorkerPids(array $pids): self
    {
        return $this;
    }

    /**
     * 设置复活模式
     * @deprecated 由 Orchestrator 管理
     */
    public function setResurrectionMode(bool $resurrection): self
    {
        return $this;
    }

    /**
     * 设置 Dispatcher PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setDispatcherPid(int $pid): self
    {
        return $this;
    }

    /**
     * 设置 HTTP Redirect PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setHttpRedirectPid(int $pid): self
    {
        return $this;
    }

    /**
     * 清除 Master 信息（用于 stop 命令）
     */
    public static function clearMasterInfo(string $instanceName = 'default'): void
    {
        (new ServerInstanceManager())->deleteInstance($instanceName);
    }

    /**
     * 服务异常标记文件路径
     */
    public static function getServiceExceptionFile(string $instanceName): string
    {
        $dir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir . $instanceName . '.exception';
    }

    /**
     * 检查服务是否存在异常标记
     */
    public static function hasServiceException(string $instanceName): bool
    {
        return \is_file(self::getServiceExceptionFile($instanceName));
    }

    /**
     * 设置服务异常标记
     *
     * @param string $instanceName 实例名
     * @param string $reason       异常原因（人类可读，会落盘）
     * @param int    $attempts     相关重试次数（>0 时追加到内容，便于排障；=0 时忽略）
     */
    public static function setServiceException(string $instanceName, string $reason = '', int $attempts = 0): void
    {
        $file = self::getServiceExceptionFile($instanceName);
        $payload = $reason !== '' ? $reason : 'unknown';
        if ($attempts > 0) {
            $payload .= ' [attempts=' . $attempts . ']';
        }
        @\file_put_contents($file, $payload);
    }

    /**
     * 启动日志展示用：当前 Master 自愈模式
     *
     * 依据 env.php 的 `wls.orchestrator.allow_child_resurrection`：
     *   - 未配置 → 默认开启（2026-04-23 起）
     *   - 显式 false → 关闭
     */
    protected function resolveSelfHealModeForLog(): string
    {
        try {
            $config = Env::getInstance()->getConfig() ?: [];
            $raw = $config['wls']['orchestrator']['allow_child_resurrection'] ?? null;
            if ($raw === null) {
                return 'on (默认)';
            }
            return ((bool)$raw) ? 'on (env.php)' : 'off (env.php)';
        } catch (\Throwable) {
            return 'on (默认)';
        }
    }

    /**
     * 清除服务异常标记
     */
    public static function clearServiceException(string $instanceName): void
    {
        $file = self::getServiceExceptionFile($instanceName);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    /**
     * 释放启动锁
     * 
     * 在所有子进程启动完成后调用，允许其他进程检测服务器状态或重新启动。
     * 优先使用回调（可正确关闭文件句柄），否则直接删除锁文件。
     */
    private function releaseStartupLock(): void
    {
        $this->triggerDeferredSslRetryAfterStartup();

        // 优先使用回调（Start.php 传入的 releaseStartLock 方法可正确关闭句柄）
        if ($this->onStartedCallback !== null) {
            ($this->onStartedCallback)();
            $this->log(__('启动锁已释放（回调）'));
            return;
        }

        // 后备方案：直接删除锁文件
        $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $this->instanceName . '.lock';
        
        if (\is_file($lockFile)) {
            @\unlink($lockFile);
            $this->log(__('启动锁已释放'));
        }
    }

    private function triggerDeferredSslRetryAfterStartup(): void
    {
        if ($this->deferredSslRetryTriggered) {
            return;
        }

        if (!$this->sslEnabled) {
            return;
        }

        /** @var SslCertificateService $sslService */
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
        $domain = \strtolower(\trim((string) ($this->config['public_host'] ?? ($this->config['host'] ?? ''))));
        if ($domain === '') {
            $this->log(__('跳过 SSL 启动后申请：缺少域名配置。'));
            return;
        }
        if ($sslService->isLocalDomain($domain) || $sslService->resolvesToLoopback($domain)) {
            return;
        }

        $email = \trim((string) Env::get('admin_email', 'admin@' . $domain));
        if ($email === '') {
            $email = Env::get('admin_email', 'admin@' . $domain);
        }
        $forceAcme = $this->shouldForceAcmeOnPostStartup($sslService);

        $phpBinary = \defined('PHP_BINARY') ? (string) PHP_BINARY : 'php';
        $script = BP . 'bin' . DS . 'w';
        $command = \sprintf(
            '%s %s ssl:auto request --domain=%s --email=%s --webroot=%s%s',
            \escapeshellarg($phpBinary),
            \escapeshellarg($script),
            \escapeshellarg($domain),
            \escapeshellarg($email),
            \escapeshellarg(SslCertificateService::WEBROOT_WLS_VIRTUAL),
            $forceAcme ? ' --force-acme' : ''
        );

        $pid = (int) Processer::create($command, false);
        $this->deferredSslRetryTriggered = true;

        if ($pid > 0) {
            $this->log(__('已触发 SSL 延迟重试：%{1}（pid=%{2}）', [$domain, (string) $pid]));
            return;
        }

        $this->log(__('已触发 SSL 延迟重试：%{1}（后台进程 PID 未返回，可通过 ssl:auto list 查看结果）', [$domain]));
    }

    private function shouldForceAcmeOnPostStartup(SslCertificateService $sslService): bool
    {
        $certPath = \trim($this->sslCert !== '' ? $this->sslCert : (string) ($this->config['ssl_cert'] ?? ''));
        if ($certPath === '' || !\is_file($certPath)) {
            return false;
        }

        $certInfo = $sslService->parseCertificate($certPath);
        $issuer = \strtolower(\trim((string) ($certInfo['issuer'] ?? '')));
        if ($issuer === '') {
            return false;
        }

        return \str_contains($issuer, \strtolower(SslCertificateService::ISSUER_SELF_SIGNED))
            || \str_contains($issuer, \strtolower(SslCertificateService::ISSUER_LOCAL_CA));
    }

    private function findRunningInstanceByControlPort(int $controlPort): ?string
    {
        if ($controlPort <= 0) {
            return null;
        }

        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            return null;
        }

        $files = @\glob($instanceDir . '*.json') ?: [];
        foreach ($files as $instanceFile) {
            $instanceName = (string)\pathinfo($instanceFile, \PATHINFO_FILENAME);
            if ($instanceName === '' || $instanceName === $this->instanceName) {
                continue;
            }

            $data = @\json_decode((string)@\file_get_contents($instanceFile), true);
            if (!\is_array($data)) {
                continue;
            }

            $candidatePort = (int)($data['control_port'] ?? 0);
            if ($candidatePort !== $controlPort) {
                continue;
            }

            if (!self::isMasterRunning($instanceName)) {
                continue;
            }

            return $instanceName;
        }

        return null;
    }
}
