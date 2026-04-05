<?php
declare(strict_types=1);

/**
 * Weline Server - 统一 Dispatcher
 *
 * TCP 代理模式的 Dispatcher，负责连接转发。
 * 
 * 工作模式：
 * 1. 接受客户端 TCP 连接
 * 2. Peek ClientHello 提取 SNI（HTTPS 场景）
 * 3. 攻击探测（频率限制、恶意特征等）
 * 4. 查询路由缓存或轮询选择 Worker
 * 5. 建立到 Worker 的 TCP 连接
 * 6. 双向透传数据
 * 7. 从 Worker 响应学习路由信息（Keep-Alive 场景）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Service\AttackLogService;
use Weline\Server\Service\AttackSignalFileService;
use Weline\Server\Service\StatusLogService;

class Dispatcher
{
    /**
     * 服务器 socket
     * @var \Socket|resource
     */
    private $serverSocket;
    
    /**
     * 透传核心
     */
    private PassthroughCore $passthroughCore;
    
    /**
     * 攻击探测器
     */
    private AttackDetector $attackDetector;
    
    /**
     * 是否启用攻击探测
     */
    private bool $attackDetectionEnabled = true;
    
    /**
     * 实例名称
     */
    private string $instanceName;
    
    /**
     * 进程名称
     */
    private string $processName;
    
    /**
     * 监听端口
     */
    private int $port;

    /**
     * 当前实例是否启用 HTTPS（用于同端口 HTTP→HTTPS 301）
     */
    private bool $httpsEnabled = false;

    /**
     * HTTP 重定向端口（用于将明文 HTTP 请求转发到 http_redirect_worker）
     */
    private int $httpRedirectPort = 0;
    
    /**
     * 活跃的客户端连接
     * @var array<int, \Socket|resource>
     */
    private array $clientConnections = [];
    
    /**
     * 连接接受时间（用于超时检测）
     * @var array<int, float>
     */
    private array $connectionAcceptTime = [];
    
    /**
     * 连接上次活动时间
     * @var array<int, float>
     */
    private array $connectionLastActivity = [];
    
    /**
     * 连接超时（秒）
     */
    private int $connectionTimeout = 300;
    /**
     * 客户端已半关闭但尚未上送任何请求字节时的短超时（秒）。
     * 用于快速回收 preconnect/握手失败遗留连接，避免 CLOSE_WAIT 堆积。
     */
    private float $clientHalfClosedIdleTimeoutSec = 5.0;
    
    /**
     * 请求计数
     */
    private int $requestCount = 0;
    
    /**
     * 字节计数
     */
    private array $bytesCount = ['in' => 0, 'out' => 0];
    
    /**
     * 启动时间
     */
    private int $startTime;
    
    /**
     * 最后统计时间
     */
    private int $lastStatsTime = 0;
    
    /**
     * 最后 Master 检查时间
     */
    private int $lastMasterCheck = 0;
    
    /**
     * Master 检查间隔（秒）
     */
    private int $masterCheckInterval = 5;
    
    /**
     * Master 不存在计数
     */
    private int $masterMissingCount = 0;
    
    /**
     * 最大 Master 不存在次数
     */
    private int $maxMasterMissing = 6;
    
    /**
     * 上次连接清理时间
     */
    private int $lastConnectionCleanup = 0;
    
    /**
     * 连接清理间隔（秒）
     */
    private int $connectionCleanupInterval = 30;

    /**
     * 首包超时（秒）：请求已转发到 Worker 但迟迟无任何响应字节时，主动断开并标记 Worker 失败。
     */
    private float $firstResponseTimeout = 0.0;

    /**
     * 是否启用“首包超时强制断连”启发式。
     * 默认关闭，避免高负载/长处理请求被误判导致 ERR_CONNECTION_CLOSED。
     */
    private bool $enforceFirstResponseTimeout = false;
    
    /**
     * 上次 Worker 健康探活时间
     */
    private float $lastWorkerProbeTime = 0;
    
    /**
     * Worker 探活间隔（秒）
     */
    private int $workerProbeInterval = 3;
    
    /**
     * 是否运行中
     */
    private bool $running = true;
    
    /**
     * 是否 DEV 开发模式（输出详细调试信息）
     */
    private bool $isDevMode = false;

    /**
     * 上次输出「所有 Worker 不可用」日志时间（节流，避免启动期刷屏）
     */
    private float $lastAllWorkersUnavailableLogAt = 0.0;
    private int $lastAllWorkersDownReported = 0;

    /**
     * 启动保护：窗口期内未达到最小 READY 阈值时，对外返回 503 而非直接断开。
     */
    private bool $startupProtectionEnabled = true;
    private float $startupProtectionWindowSec = 45.0;
    private float $startupProtectionReadyRatio = 0.0;
    private int $startupProtectionMinReady = 1;
    private int $expectedWorkerCount = 0;
    
    // ========== IPC 控制通道 ==========
    
    /**
     * IPC 控制客户端
     */
    private ?ControlClient $ipcClient = null;
    
    /**
     * IPC 控制端口
     */
    private int $controlPort = 0;
    
    /**
     * 是否收到 shutdown 命令
     */
    private bool $ipcReceivedShutdown = false;
    
    /**
     * Master PID（用于孤儿检测）
     */
    private int $masterPid = 0;
    private int $orchestratorEpoch = 0;
    private string $orchestratorLaunchId = '';
    
    private int $lastMasterPidCheck = 0;
    private int $masterDeadCount = 0;
    
    /**
     * 硬编码维护页响应（纯内存，不依赖框架/文件系统）
     */
    private string $fallbackMaintenancePage = '';
    
    /**
     * 是否启用维护页兜底
     */
    private bool $maintenanceFallbackActive = false;

    /**
     * SET_WORKER_POOL / ADD_WORKER / 黑名单 Worker 探活统一入队，
     * 由主循环分片 resume Fiber，避免 IPC 回调与健康探活同步阻塞。
     *
     * @var list<array{type: 'set_pool'|'add_workers'|'probe_blacklisted_workers', ports?: int[]}>
     */
    private array $deferredWorkerPoolJobs = [];

    private ?\Fiber $deferredWorkerPoolFiber = null;

    /** @var 'set_pool'|'add_workers'|'probe_blacklisted_workers'|null */
    private ?string $deferredWorkerPoolFiberKind = null;
    private bool $spinWaitTickInProgress = false;
    private int $maintenanceTakeoverRetryTicks = 3;
    
    /**
     * 封禁拦截日志限流记录（IP => 上次记录时间）
     */
    private array $banLogThrottle = [];
    /**
     * 半关闭空连接快速回收日志限流（IP => 上次记录时间）
     */
    private array $halfClosedFastCloseLogThrottle = [];
    
    /**
     * 封禁日志限流间隔（秒）
     */
    private int $banLogInterval = 60;
    /**
     * 半关闭空连接快速回收日志限流间隔（秒）
     */
    private int $halfClosedFastCloseLogInterval = 10;
    
    /**
     * 每连接字节统计（connId => ['in' => int, 'out' => int]）
     * 用于判断连接是否有有效数据交换（避免 SSL 失败误判）
     */
    private array $connectionBytes = [];
    
    /**
     * 构造函数
     *
     * @param resource $serverSocket 服务器 socket
     * @param string $workerHost Worker 主机地址
     * @param int $workerBasePort Worker 基础端口
     * @param int $workerCount Worker 数量
     * @param string $instanceName 实例名称
     * @param string $processName 进程名称
     * @param int $port 监听端口
     */
    public function __construct(
        $serverSocket,
        string $workerHost,
        int $workerBasePort,
        int $workerCount,
        string $instanceName,
        string $processName = '',
        int $port = 0
    ) {
        $this->serverSocket = $serverSocket;
        $this->instanceName = $instanceName;
        $this->processName = $processName;
        $this->port = $port;
        $this->expectedWorkerCount = \max(0, $workerCount);
        $this->httpsEnabled = $this->detectHttpsEnabled($instanceName);
        $this->passthroughCore = new PassthroughCore($workerHost, $workerBasePort, $workerCount, $this->httpsEnabled);
        $this->passthroughCore->setSpinWaitTickCallback(function (): void {
            $this->pumpSpinWaitControlTick();
        });
        $this->passthroughCore->setWorkerSslModeResolvedCallback(function (bool $sslEnabled, int $portHint): void {
            unset($portHint);
            if ($this->httpsEnabled !== $sslEnabled) {
                $this->httpsEnabled = $sslEnabled;
                $this->log(
                    'Dispatcher HTTPS/明文模式已与 Worker 后端探活对齐为: ' . ($sslEnabled ? 'HTTPS(worker_ssl)' : 'HTTP(worker.php)'),
                    'WARN'
                );
            }
        });
        $this->attackDetector = AttackDetector::getInstance()->setInstanceName($instanceName);
        $this->startTime = \time();
        $this->lastMasterCheck = \time();
        
        // 注册 PID
        if ($processName) {
            Processer::setPid('--name=' . $processName, \getmypid());
            if ($port > 0) {
                Processer::setProcessPorts('--name=' . $processName, [$port]);
            }
        }
        
        // 初始化硬编码维护页（纯内存，最后一道防线）
        $this->fallbackMaintenancePage = "HTTP/1.1 503 Service Unavailable\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Retry-After: 5\r\n"
            . "Connection: close\r\n\r\n"
            . "<!DOCTYPE html><html><head><meta charset='UTF-8'>"
            . "<title>WLS Starting</title></head><body>"
            . "<h1 style='text-align:center;margin-top:20vh'>"
            . "WLS服务器正在启动中...</h1>"
            . "<p style='text-align:center'>Maintenance worker is not ready yet. "
            . "Please retry in a moment.</p></body></html>";
        
        // 注册信号处理
        $this->registerSignals();
    }

    /**
     * 读取实例配置判断是否 HTTPS 模式
     */
    private function detectHttpsEnabled(string $instanceName): bool
    {
        $instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (\is_file($instanceFile)) {
            $instData = @\json_decode((string)\file_get_contents($instanceFile), true);
            if (\is_array($instData) && \array_key_exists('ssl_enabled', $instData)) {
                return (bool) $instData['ssl_enabled'];
            }
        }

        return $this->detectHttpsEnabledFromEnvWls();
    }

    /**
     * 实例 JSON 无 ssl_enabled 时回退 wls.https / wls.ssl_enabled，避免 Dispatcher 与 worker_ssl 维护进程协议不一致。
     */
    private function detectHttpsEnabledFromEnvWls(): bool
    {
        try {
            $cfg = Env::getInstance()->getConfig();
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($cfg)) {
            return false;
        }
        $wls = \is_array($cfg['wls'] ?? null) ? $cfg['wls'] : [];
        if (\array_key_exists('https', $wls)) {
            return (bool) $wls['https'];
        }
        if (\array_key_exists('ssl_enabled', $wls)) {
            return (bool) $wls['ssl_enabled'];
        }

        return false;
    }

    /**
     * Master 下发 Worker 池前同步一次（实例文件可能在运行期更新）。
     */
    private function syncHttpsAndWorkerBackendFromInstance(): void
    {
        $want = $this->detectHttpsEnabled($this->instanceName);
        if ($want === $this->httpsEnabled && $want === $this->passthroughCore->isWorkerSslEnabled()) {
            return;
        }
        $this->httpsEnabled = $want;
        $this->passthroughCore->setWorkerSslEnabled($want);
        $this->log(
            '已从实例/env 同步 Worker 后端协议: ' . ($want ? 'TLS(worker_ssl)' : 'TCP(worker.php)'),
            'INFO'
        );
    }
    
    /**
     * 配置透传核心
     *
     * @param array $config 配置
     */
    public function configure(array $config): void
    {
        $this->passthroughCore->configure($config);
        
        if (isset($config['connection_timeout'])) {
            $this->connectionTimeout = (int) $config['connection_timeout'];
        }
        if (isset($config['client_half_closed_idle_timeout_sec'])) {
            $this->clientHalfClosedIdleTimeoutSec = \max(0.5, (float)$config['client_half_closed_idle_timeout_sec']);
        }
        if (isset($config['first_response_timeout'])) {
            // <=0 表示关闭该启发式（避免对长处理请求误杀）。
            $this->firstResponseTimeout = \max(0.0, (float)$config['first_response_timeout']);
        }
        if (isset($config['enforce_first_response_timeout'])) {
            $this->enforceFirstResponseTimeout = (bool)$config['enforce_first_response_timeout'];
        }
        
        if (isset($config['attack_detection_enabled'])) {
            $this->attackDetectionEnabled = (bool) $config['attack_detection_enabled'];
        }
        
        // 传递攻击探测规则配置
        if (isset($config['attack_rules'])) {
            $this->attackDetector->updateRules($config['attack_rules']);
        }
        
        // HTTP 重定向端口配置
        if (isset($config['http_redirect_port'])) {
            $this->httpRedirectPort = (int) $config['http_redirect_port'];
            $this->passthroughCore->setHttpRedirectPort($this->httpRedirectPort);
        }

        if (isset($config['startup_protection_enabled'])) {
            $this->startupProtectionEnabled = (bool)$config['startup_protection_enabled'];
        }
        if (isset($config['startup_protection_window_sec'])) {
            $this->startupProtectionWindowSec = (float)$config['startup_protection_window_sec'];
        }
        if (isset($config['startup_protection_ready_ratio'])) {
            $this->startupProtectionReadyRatio = (float)$config['startup_protection_ready_ratio'];
        }
        if (isset($config['startup_protection_min_ready'])) {
            $this->startupProtectionMinReady = (int)$config['startup_protection_min_ready'];
        }
        if ($this->startupProtectionWindowSec < 0.0) {
            $this->startupProtectionWindowSec = 0.0;
        }
        if ($this->startupProtectionReadyRatio < 0.0) {
            $this->startupProtectionReadyRatio = 0.0;
        }
        if ($this->startupProtectionReadyRatio > 1.0) {
            $this->startupProtectionReadyRatio = 1.0;
        }
        if ($this->startupProtectionMinReady < 1) {
            $this->startupProtectionMinReady = 1;
        }
    }
    
    /**
     * 设置日志函数
     *
    /**
     * 设置开发模式
     * DEV 模式下打印详细的活动信息（连接路由、数据转发、探活等）
     */
    public function setDevMode(bool $devMode): void
    {
        $this->isDevMode = $devMode;
        if ($devMode) {
            $this->log('DEV 模式已启用，将输出详细调试信息', 'INFO');
        }
    }
    
    /**
     * 内部日志方法（直接使用 WlsLogger）
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        $logLevel = match (\strtoupper($level)) {
            'ERROR', 'BAN', 'SSL_FAIL' => LogLevel::ERROR,
            'WARN', 'WARNING' => LogLevel::WARNING,
            'DEBUG', 'CLOSE', 'HEALTH', 'STATS' => LogLevel::DEBUG,
            default => LogLevel::INFO,
        };
        WlsLogger::log_($logLevel, $message);
    }

    private function socketId($socket): int
    {
        if (\is_object($socket)) {
            return \spl_object_id($socket);
        }
        if (\is_resource($socket)) {
            return \get_resource_id($socket);
        }
        return 0;
    }
    
    /**
     * 设置 Master PID（用于孤儿检测）
     */
    public function setMasterPid(int $pid): void
    {
        $this->masterPid = $pid;
    }

    /**
     * 设置 Orchestrator 生命周期令牌
     */
    public function setLifecycleTokens(int $epoch, string $launchId): void
    {
        $this->orchestratorEpoch = $epoch;
        $this->orchestratorLaunchId = $launchId;
    }
    
    /**
     * 连接 IPC 控制通道
     *
     * @param int $controlPort Master 控制端口（0 = 从实例文件读取）
     */
    public function connectIpc(int $controlPort = 0): void
    {
        $this->controlPort = $controlPort;
        
        if ($this->controlPort <= 0) {
            // 从实例文件读取
            $instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
            if (\is_file($instanceFile)) {
                $instData = @\json_decode(\file_get_contents($instanceFile), true);
                $this->controlPort = (int)($instData['control_port'] ?? 0);
            }
        }
        
        if ($this->controlPort <= 0) {
            return;
        }
        
        $this->ipcClient = new ControlClient();
        $this->ipcClient->setSelfTag('Dispatcher');
        // DEV 模式下输出详细 IPC SEND/RECV 明细
        $this->ipcClient->setVerboseLog($this->isDevMode);
        if (!$this->ipcClient->connect('127.0.0.1', $this->controlPort)) {
            $this->log("IPC 控制通道连接失败 (端口: {$this->controlPort})", 'WARN');
            $this->ipcClient = null;
            return;
        }
        
        $this->ipcClient->register(
            ControlMessage::ROLE_DISPATCHER,
            \getmypid(),
            $this->port,
            0,
            $this->orchestratorEpoch,
            $this->orchestratorLaunchId,
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
            $this->instanceName
        );
        $this->log("IPC 控制通道已连接 (端口: {$this->controlPort})", 'INFO');
        
        // 设置消息处理器
        $this->ipcClient->onMessage(function (array $msg, ControlClient $client) {
            $this->handleIpcMessage($msg);
        });
        
        // 设置断开处理器
        $this->ipcClient->onDisconnect(function (bool $receivedShutdown, ControlClient $client) {
            // 已收到 shutdown 或正在退出，不做任何复活/重连操作
            if ($receivedShutdown || $this->ipcReceivedShutdown || !$this->running) {
                $this->log('Master 连接断开（已收到 shutdown，不复活）', 'INFO');
                return;
            }
            $this->log('Master 连接意外断开，控制面已收口，不执行子进程复活。', 'WARN');
            $client->tryReconnect();
        });
        
        // 上报就绪
        $this->ipcClient->sendReady(
            ControlMessage::ROLE_DISPATCHER,
            0,
            $this->port,
            $this->orchestratorEpoch,
            $this->orchestratorLaunchId
        );
        $this->log('已上报就绪状态 (WORKER_READY)', 'INFO');
        // 开发模式：日志统一汇聚到 Master 控制台
        if (\Weline\Server\Log\LogConfig::isDevMode() && $this->ipcClient !== null) {
            $ipc = $this->ipcClient;
            $this->log('WlsLogger 已接入 IPC 日志汇聚', 'INFO');
            WlsLogger::getInstance()->setIpcLogSink(static function (string $line, string $level, string $tag) use ($ipc): void {
                if ($ipc->isConnected()) {
                    $ipc->sendLogLine($line, $level, $tag);
                }
            });
        }
    }
    
    /**
     * 处理 IPC 控制消息
     */
    private function pumpIpcOnce(): void
    {
        if (!$this->ipcClient) {
            return;
        }

        if ($this->ipcClient->isConnected()) {
            $ipcSocket = $this->ipcClient->getSocket();
            if ($ipcSocket && \is_resource($ipcSocket)) {
                $ipcRead = [$ipcSocket];
                $ipcWrite = [];
                $ipcExcept = [];
                $ipcChanged = @\stream_select($ipcRead, $ipcWrite, $ipcExcept, 0, 0);
                if ($ipcChanged > 0) {
                    $this->ipcClient->handleReadable();
                }
            }
            return;
        }

        if (!$this->ipcReceivedShutdown) {
            $this->ipcClient->tryReconnect();
        }
    }

    /**
     * 在 PassthroughCore 自旋等待阶段推进控制面：
     * - 先处理 IPC 收发（含 SET_WORKER_POOL / ADD_WORKER）
     * - 再推进 deferred warmup Fiber 一个步进
     *
     * 避免「handleNewConnection 自旋中」主循环被占用时，预热任务得不到推进。
     */
    private function pumpSpinWaitControlTick(): void
    {
        // 防重入：warmup Fiber 里可能再次触发回调，避免递归 tick。
        if ($this->spinWaitTickInProgress) {
            return;
        }
        $this->spinWaitTickInProgress = true;
        try {
            $this->pumpIpcOnce();
            $this->pumpDeferredWorkerPoolJobs();
        } finally {
            $this->spinWaitTickInProgress = false;
        }
    }

    /**
     * 每轮事件循环最多推进一次 suspend/resume，使探活/预热不霸占 IPC 回调栈。
     */
    private function pumpDeferredWorkerPoolJobs(): void
    {
        if ($this->deferredWorkerPoolFiber === null) {
            if ($this->deferredWorkerPoolJobs === []) {
                return;
            }
            $job = \array_shift($this->deferredWorkerPoolJobs);
            $this->deferredWorkerPoolFiber = $this->createDeferredWorkerPoolFiber($job);
            $this->deferredWorkerPoolFiberKind = $job['type'];
        }

        $fiber = $this->deferredWorkerPoolFiber;
        if ($fiber === null) {
            return;
        }

        try {
            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        } catch (\Throwable $e) {
            $this->log('Deferred worker pool Fiber 异常: ' . $e->getMessage(), 'ERROR');
            $this->passthroughCore->setWarmupCooperativeYield(null);
            $this->deferredWorkerPoolFiber = null;
            $this->deferredWorkerPoolFiberKind = null;
            return;
        }
        if ($fiber->isTerminated()) {
            $this->finalizeDeferredWorkerPoolFiber();
        }
    }

    /**
     * @param array{type: 'set_pool'|'add_workers'|'probe_blacklisted_workers', ports?: int[]} $job
     */
    private function createDeferredWorkerPoolFiber(array $job): \Fiber
    {
        if ($job['type'] === 'set_pool') {
            $ports = $job['ports'];

            return new \Fiber(function () use ($ports): array {
                $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
                try {
                    return $this->passthroughCore->setWorkerPorts($ports);
                } finally {
                    $this->passthroughCore->setWarmupCooperativeYield(null);
                }
            });
        }

        if ($job['type'] === 'add_workers') {
            $ports = $job['ports'];

            return new \Fiber(function () use ($ports): array {
                $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
                try {
                    $acceptedPorts = [];
                    $rejectedParts = [];
                    foreach ($ports as $port) {
                        $result = $this->passthroughCore->addWorkerPort((int) $port);
                        if (!empty($result['accepted'])) {
                            $acceptedPorts[] = (int) $port;
                        } else {
                            $rejectedParts[] = (int) $port . ': ' . (string) ($result['error'] ?? 'warmup rejected');
                        }
                    }

                    return ['accepted_ports' => $acceptedPorts, 'rejected_parts' => $rejectedParts];
                } finally {
                    $this->passthroughCore->setWarmupCooperativeYield(null);
                }
            });
        }

        return new \Fiber(function (): array {
            $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
            try {
                return $this->passthroughCore->probeBlacklistedWorkers();
            } finally {
                $this->passthroughCore->setWarmupCooperativeYield(null);
            }
        });
    }

    private function createWarmupCooperativeYieldCallback(): \Closure
    {
        return static function (): void {
            if (\Fiber::getCurrent() === null) {
                return;
            }

            \Fiber::suspend();
        };
    }

    private function finalizeDeferredWorkerPoolFiber(): void
    {
        $fiber = $this->deferredWorkerPoolFiber;
        $kind = $this->deferredWorkerPoolFiberKind;
        $this->deferredWorkerPoolFiber = null;
        $this->deferredWorkerPoolFiberKind = null;

        if ($fiber === null) {
            return;
        }
        try {
            $payload = $fiber->getReturn();
        } catch (\Throwable $e) {
            $this->log('Deferred worker pool Fiber 结束异常: ' . $e->getMessage(), 'ERROR');

            return;
        }
        if ($kind === 'set_pool') {
            $result = \is_array($payload) ? $payload : [];
            $acceptedPorts = \is_array($result['accepted'] ?? null) ? $result['accepted'] : [];
            $rejectedPorts = \is_array($result['rejected'] ?? null) ? $result['rejected'] : [];
            $this->maintenanceFallbackActive = $acceptedPorts === [];
            $this->log('SET_WORKER_POOL: ' . \implode(',', $acceptedPorts), 'INFO');
            if ($rejectedPorts !== []) {
                $items = [];
                foreach ($rejectedPorts as $port => $reason) {
                    $items[] = "{$port}: {$reason}";
                }
                $this->log('SET_WORKER_POOL 预热失败，拒绝纳入负载池: ' . \implode('; ', $items), 'ERROR');
            }

            return;
        }
        if ($kind === 'add_workers') {
            $acceptedPorts = \is_array($payload['accepted_ports'] ?? null) ? $payload['accepted_ports'] : [];
            $rejectedParts = \is_array($payload['rejected_parts'] ?? null) ? $payload['rejected_parts'] : [];
            if ($acceptedPorts !== []) {
                $this->maintenanceFallbackActive = false;
                $this->log(
                    '已添加 Worker 端口到负载均衡池: ' . \implode(',', $acceptedPorts)
                    . ', 当前总数: ' . $this->passthroughCore->getWorkerCount(),
                    'INFO'
                );
            }
            if ($rejectedParts !== []) {
                $this->log(
                    'ADD_WORKER 预热失败，拒绝纳入负载池: ' . \implode('; ', $rejectedParts),
                    'ERROR'
                );
            }

            return;
        }

        if ($kind === 'probe_blacklisted_workers') {
            $recovered = \is_array($payload) ? \array_values(\array_map('intval', $payload)) : [];
            if ($recovered !== []) {
                $ports = \implode(', ', $recovered);
                $this->log("Worker 恢复: 端口 {$ports} 已重新加入负载均衡", 'HEALTH');
            }
        }
    }

    private function handleIpcMessage(array $msg): void
    {
        $type = $msg['type'] ?? '';

        // 添加详细的 IPC 消息接收日志
        $timestamp = date('Y-m-d H:i:s');
        $this->log("[IPC-Recv] {$timestamp} type={$type} msg=" . json_encode($msg), 'DEBUG');

        // 帝王令：已收 shutdown 后不再处理 DRAIN/ADD_WORKER 等其他 IPC
        if ($type !== ControlMessage::TYPE_SHUTDOWN && $this->ipcReceivedShutdown) {
            return;
        }
        switch ($type) {
            case ControlMessage::TYPE_DRAIN:
                $ports = $msg['ports'] ?? [];
                if (!empty($ports)) {
                    // 指定端口加入黑名单（热重载时使用）
                    foreach ($ports as $port) {
                        $this->passthroughCore->blacklistWorker((int)$port);
                    }
                    $this->log('Drain: 端口 ' . \implode(',', $ports) . ' 已加入黑名单', 'DRAIN');
                } else {
                    // 全局 drain（stopAll 时使用），Dispatcher 自己不需要排水，直接完成
                    $this->log('Received global drain signal, completing immediately...', 'DRAIN');
                    $this->ipcClient?->sendDrainingComplete(0, $this->port);
                }
                break;
                
            case ControlMessage::TYPE_UNDRAIN:
                // 将指定端口从黑名单移除
                $ports = $msg['ports'] ?? [];
                foreach ($ports as $port) {
                    $this->passthroughCore->unblacklistWorker((int)$port);
                }
                $this->log('Undrain: 端口 ' . \implode(',', $ports) . ' 已从黑名单移除', 'DRAIN');
                break;
                
            case ControlMessage::TYPE_ADD_WORKER:
                $this->syncHttpsAndWorkerBackendFromInstance();
                $ports = $msg['ports'] ?? [];
                $this->log('收到 ADD_WORKER 消息（已入队异步入池）: ' . \json_encode($ports), 'INFO');
                $norm = [];
                foreach (\is_array($ports) ? $ports : [] as $port) {
                    $p = (int) $port;
                    if ($p > 0) {
                        $norm[] = $p;
                    }
                }
                if ($norm !== []) {
                    $this->deferredWorkerPoolJobs[] = ['type' => 'add_workers', 'ports' => $norm];
                }
                break;
                
            case ControlMessage::TYPE_REMOVE_WORKER:
                // 从负载均衡池移除端口，并关闭所有使用该 Worker 的客户端连接
                $ports = $msg['ports'] ?? [];
                $totalAffectedConns = 0;
                foreach ($ports as $port) {
                    $affectedConnIds = $this->passthroughCore->removeWorkerPort((int)$port);
                    foreach ($affectedConnIds as $connId) {
                        $this->closeConnection($connId, 'worker_removed');
                        $totalAffectedConns++;
                    }
                }
                $this->log(
                    '移除 Worker 端口: ' . \implode(',', $ports) .
                    ', 关闭受影响的客户端连接: ' . $totalAffectedConns,
                    'WARN'
                );
                break;

            case ControlMessage::TYPE_SET_WORKER_POOL:
                $this->syncHttpsAndWorkerBackendFromInstance();
                $ports = $msg['ports'] ?? [];
                if (\is_array($ports)) {
                    $this->deferredWorkerPoolJobs[] = ['type' => 'set_pool', 'ports' => $ports];
                    $this->log('收到 SET_WORKER_POOL（已入队异步入池），候选端口数: ' . \count($ports), 'INFO');
                }
                break;

            case ControlMessage::TYPE_SHUTDOWN:
                $this->log('收到 shutdown 命令', 'WARN');
                $this->ipcReceivedShutdown = true;
                $this->running = false;
                break;
                
            case ControlMessage::TYPE_SET_REDIRECT_PORT:
                $this->httpRedirectPort = (int) ($msg['port'] ?? 0);
                $this->passthroughCore->setHttpRedirectPort($this->httpRedirectPort);
                $this->log("HTTP Redirect 端口设置为: {$this->httpRedirectPort}", 'INFO');
                break;

            case ControlMessage::TYPE_SECURITY_UNBLOCK:
                if (!empty($msg['clear_all'])) {
                    $this->attackDetector->clearAllBlocks();
                    $this->log('已清空全部封禁列表', 'INFO');
                } elseif (!empty($msg['ip'])) {
                    $this->attackDetector->unblock((string) $msg['ip']);
                    $this->log("已解封 IP: {$msg['ip']}", 'INFO');
                }
                break;

            case ControlMessage::TYPE_WORKER_SATURATION:
                $workerId = (int) ($msg['worker_id'] ?? 0);
                $port = (int) ($msg['port'] ?? 0);
                $longLivedCount = (int) ($msg['long_lived_count'] ?? 0);
                $longLivedMax = (int) ($msg['long_lived_max'] ?? 0);
                $totalFiber = (int) ($msg['total_fiber_count'] ?? 0);
                if ($port > 0) {
                    $this->passthroughCore->setWorkerSaturation($port, $longLivedCount, $longLivedMax);
                    $this->log("Worker 长连接饱和 (port={$port}, long_lived={$longLivedCount}/{$longLivedMax}, fibers={$totalFiber})", 'WARN');
                }
                break;

            case ControlMessage::TYPE_WORKER_SATURATION_CLEARED:
                $port = (int) ($msg['port'] ?? 0);
                $longLivedCount = (int) ($msg['long_lived_count'] ?? 0);
                $longLivedMax = (int) ($msg['long_lived_max'] ?? 0);
                if ($port > 0) {
                    $this->passthroughCore->clearWorkerSaturation($port);
                    $this->log("Worker 长连接饱和解除 (port={$port}, long_lived={$longLivedCount}/{$longLivedMax})", 'INFO');
                }
                break;
        }
    }
    
    /**
     * 运行事件循环
     */
    public function run(): void
    {
        $workerCount = $this->passthroughCore->getStats()['active_connections'] ?? 0;
        $this->log("Started on tcp://0.0.0.0:{$this->port}", 'INFO');
        $this->log("Instance: {$this->instanceName}, TCP Proxy Mode, DEV=" . ($this->isDevMode ? 'ON' : 'OFF'), 'INFO');
        
        // 设置服务器 socket 为非阻塞
        \socket_set_nonblock($this->serverSocket);

        // Dispatcher 无请求 Fiber：PassthroughCore 内 SchedulerSystem::usleep 须为真实阻塞式微睡眠，
        // 显式停用协作调度标记，避免与其它进程/残留状态混淆（休眠量级通常为微秒～毫秒，IPC 仍由每轮 pumpIpcOnce 处理）。
        SchedulerSystem::disableScheduler();
        
        $loopCount = 0;
        $consecutiveLoopErrors = 0;
        $maxConsecutiveLoopErrors = 50;
        while ($this->running) {
            try {
                $loopCount++;
                // 信号处理
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                
                // IPC 控制通道：处理消息（非阻塞读取）
                $this->pumpIpcOnce();

                // Master 心跳检查（保留文件方式作为兜底，IPC 断开时使用）
                if (!$this->ipcClient || !$this->ipcClient->isConnected()) {
                    $this->checkMasterHeartbeat();
                }
                
                // 孤儿检测：定期检查 Master PID 是否存活
                $this->checkMasterPidAlive();
                
                // Worker 健康探活只负责入队，真正网络探活交由 deferred Fiber 分片执行。
                $this->probeWorkerHealth();

                // Worker 入池预热 / 黑名单探活：Fiber 分片推进，避免阻塞 IPC 与 accept
                $this->pumpDeferredWorkerPoolJobs();
                
                // 连接超时清理
                $this->cleanupExpiredConnections();
                
                // 事件处理
                $this->selectAndProcess();
                
                // 定期统计
                $this->printStats();
                $consecutiveLoopErrors = 0;
            } catch (\Throwable $e) {
                $consecutiveLoopErrors++;
                $this->log(
                    "事件循环异常 ({$consecutiveLoopErrors}/{$maxConsecutiveLoopErrors}): "
                    . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                    'ERROR'
                );
                if ($consecutiveLoopErrors >= $maxConsecutiveLoopErrors) {
                    $this->log('连续异常过多，Dispatcher 进入保护性退出', 'ERROR');
                    $this->running = false;
                    break;
                }
                $this->pumpIpcOnce();
                SchedulerSystem::usleep(10000);
            }
        }
        
        $this->shutdown();
    }
    
    /**
     * 检查 Master 心跳
     */
    private function checkMasterHeartbeat(): void
    {
        // 已收到 shutdown，跳过心跳检查
        if ($this->ipcReceivedShutdown || !$this->running) {
            return;
        }
        
        $now = \time();
        if ($now - $this->lastMasterCheck < $this->masterCheckInterval) {
            return;
        }
        $this->lastMasterCheck = $now;
        
        $instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
        if (!\is_file($instanceFile)) {
            return;
        }
        
        $instData = @\json_decode(\file_get_contents($instanceFile), true);
        $masterPid = (int) ($instData['master_pid'] ?? 0);
        $masterEnabled = (bool) ($instData['master_enabled'] ?? false);
        
        if ($masterEnabled && $masterPid > 0) {
            $masterAlive = Processer::isRunningByPid($masterPid);
            if (!$masterAlive) {
                $this->masterMissingCount++;
                if ($this->masterMissingCount >= $this->maxMasterMissing) {
                    $this->log('Master 已退出，Dispatcher 自动停止', 'ERROR');
                    $this->running = false;
                }
            } else {
                $this->masterMissingCount = 0;
            }
        }
    }
    
    /**
     * 通过 posix_kill 直接检测 Master PID 是否存活（孤儿保护）
     */
    /**
     * 孤儿检测（IPC 优先）：定期检查 Master 是否存活
     */
    private function checkMasterPidAlive(): void
    {
        if ($this->masterPid <= 0 || $this->ipcReceivedShutdown) {
            return;
        }
        $now = \time();
        if (($now - $this->lastMasterPidCheck) < 5) {
            return;
        }
        $this->lastMasterPidCheck = $now;
        
        // IPC 连接正常 → Master 存活，无需 PID 检测
        if ($this->ipcClient && $this->ipcClient->isConnected()) {
            $this->masterDeadCount = 0;
            return;
        }
        
        // IPC 断开，用 PID 检测确认 Master 是否真的死了
        $alive = false;
        if (\function_exists('posix_kill')) {
            $alive = @\posix_kill($this->masterPid, 0);
            // macOS/Linux: 非 root 进程探测 root 进程时可能返回 EPERM（进程存在但无权限）
            if (!$alive && \function_exists('posix_get_last_error')) {
                $errno = (int)@\posix_get_last_error();
                $eperm = 1; // EPERM
                if ($errno === $eperm) {
                    $alive = true;
                }
            }
        } elseif (\defined('IS_WIN') && IS_WIN) {
            // Windows: 使用 Processer::isRunningByPid() 检测
            $alive = Processer::isRunningByPid($this->masterPid);
        } else {
            $alive = @\file_exists("/proc/{$this->masterPid}");
            if (!$alive) {
                @\exec("kill -0 {$this->masterPid} 2>/dev/null", $output, $code);
                $alive = ($code === 0);
            }
        }
        
        if ($alive) {
            $this->masterDeadCount = 0;
            return;
        }
        
        $this->masterDeadCount++;
        $this->log("Master PID {$this->masterPid} 不可达且 IPC 断开 ({$this->masterDeadCount}/3)", 'WARN');
        if ($this->masterDeadCount >= 3) {
            $this->log("Master PID {$this->masterPid} 已死亡，Dispatcher 自行退出（孤儿保护）", 'ERROR');
            $this->running = false;
        }
    }
    
    /**
     * 定期调度黑名单 Worker 探活。
     *
     * 真正的网络探活由 deferred Fiber 分片执行，避免主循环同步阻塞。
     */
    private function probeWorkerHealth(): void
    {
        // 已收到 shutdown，跳过健康探测
        if ($this->ipcReceivedShutdown || !$this->running) {
            return;
        }
        
        $now = \microtime(true);
        if ($now - $this->lastWorkerProbeTime < $this->workerProbeInterval) {
            return;
        }

        if ($this->deferredWorkerPoolFiber !== null || $this->deferredWorkerPoolJobs !== []) {
            return;
        }

        $this->lastWorkerProbeTime = $now;
        $this->deferredWorkerPoolJobs[] = ['type' => 'probe_blacklisted_workers'];
    }
    
    /**
     * 清理超时连接
     */
    private function cleanupExpiredConnections(): void
    {
        $now = \time();
        if ($now - $this->lastConnectionCleanup < $this->connectionCleanupInterval) {
            return;
        }
        $this->lastConnectionCleanup = $now;
        
        $nowMicro = \microtime(true);
        $closedCount = 0;
        
        foreach ($this->connectionLastActivity as $connId => $lastActivity) {
            $elapsed = $nowMicro - (float)$lastActivity;
            $hasClientSocket = isset($this->clientConnections[$connId]);
            $clientInputClosed = false;
            if ($hasClientSocket) {
                $clientInputClosed = $this->passthroughCore->isClientInputClosed($this->clientConnections[$connId]);
            }
            $bytes = $this->connectionBytes[$connId] ?? ['in' => 0, 'out' => 0];
            $inBytes = (int)($bytes['in'] ?? 0);
            $outBytes = (int)($bytes['out'] ?? 0);

            // 快速回收：客户端已半关闭且从未发送请求数据，短时间内直接清理（典型于 preconnect/握手中止）。
            if ($hasClientSocket
                && $this->shouldFastCloseHalfClosedWithoutRequest($connId, $clientInputClosed, $inBytes, $outBytes)
                && $elapsed > $this->clientHalfClosedIdleTimeoutSec) {
                $this->logHalfClosedFastCloseIfNeeded($connId, 'timeout');
                $this->closeConnection($connId, 'client_half_closed_without_request_timeout');
                $closedCount++;
                continue;
            }

            if ($elapsed > $this->connectionTimeout) {
                // 客户端上行半关闭后仍可能等待 worker 长处理：
                // 这里不要简单按 connectionTimeout 直接关闭，改为续约。
                if ($hasClientSocket && $clientInputClosed) {
                    if ($inBytes > 0 && $outBytes <= 0) {
                        $this->connectionLastActivity[$connId] = $nowMicro;
                        continue;
                    }
                }
                
                $this->closeConnection($connId, 'connection_timeout');
                $closedCount++;
            }
        }
        
        if ($closedCount > 0) {
            $this->log("清理超时连接: {$closedCount} 个", 'HEALTH');
        }
        
        // 清理过期的封禁日志限流记录（保留最近 5 分钟内的）
        $expireThreshold = $now - 300;
        foreach ($this->banLogThrottle as $ip => $logTime) {
            if ($logTime < $expireThreshold) {
                unset($this->banLogThrottle[$ip]);
            }
        }
    }

    /**
     * socket_select 事件处理
     */
    private function selectAndProcess(): void
    {
        // H15: 先刷新所有有缓冲数据的客户端连接
        $this->flushPendingBuffers();
        // 清理“请求已发出但 Worker 无响应”的卡死连接，避免用户侧随机超时。
        $this->cleanupStalledResponseConnections();
        
        // 准备 socket 列表
        $readSockets = [$this->serverSocket];
        $workerSockets = [];
        
        // 添加所有客户端连接
        foreach ($this->clientConnections as $connId => $clientSocket) {
            // 客户端上行半关闭后，不再监听其可读事件（避免持续 EOF 触发误关连接）
            if (!$this->passthroughCore->isClientInputClosed($clientSocket)) {
                $readSockets[] = $clientSocket;
            }
            
            // 添加对应的 Worker 连接（如果 Worker 未关闭）
            $workerSocket = $this->passthroughCore->getWorkerSocket($clientSocket);
            if ($workerSocket !== null) {
                $readSockets[] = $workerSocket;
                $workerSockets[$this->socketId($workerSocket)] = $connId;
            }
        }
        
        $writeSockets = [];
        $exceptSockets = [];
        
        // socket_select 等待事件（如果有缓冲数据，缩短等待时间）
        $hasBuffers = !empty($this->passthroughCore->getPendingBufferConnIds());
        $timeout = 0;
        $microTimeout = $hasBuffers ? 1000 : 5000; // 有缓冲数据时 1ms，否则 5ms（优化响应速度）
        
        $changed = @\socket_select($readSockets, $writeSockets, $exceptSockets, $timeout, $microTimeout);
        
        if ($changed === false || $changed === 0) {
            return;
        }
        
        // 处理新连接
        if (\in_array($this->serverSocket, $readSockets, true)) {
            $this->acceptConnections();
            $key = \array_search($this->serverSocket, $readSockets, true);
            unset($readSockets[$key]);
        }
        
        // 处理可读的 socket
        foreach ($readSockets as $socket) {
            $socketId = $this->socketId($socket);
            
            // 检查是否是 Worker socket
            if (isset($workerSockets[$socketId])) {
                $clientConnId = $workerSockets[$socketId];
                if (isset($this->clientConnections[$clientConnId])) {
                    $clientSocket = $this->clientConnections[$clientConnId];
                    $this->handleWorkerData($clientSocket);
                }
                continue;
            }
            
            // 检查是否是客户端 socket
            if (isset($this->clientConnections[$socketId])) {
                $this->handleClientData($socket);
            }
        }
    }

    /**
     * 清理首包超时的连接（请求已转发但无任何响应字节）。
     */
    private function cleanupStalledResponseConnections(): void
    {
        if (!$this->enforceFirstResponseTimeout || $this->firstResponseTimeout <= 0) {
            return;
        }

        $nowMicro = \microtime(true);
        foreach ($this->connectionLastActivity as $connId => $lastActivity) {
            if (!isset($this->clientConnections[$connId]) || !isset($this->connectionBytes[$connId])) {
                continue;
            }

            $bytes = $this->connectionBytes[$connId];
            $inBytes = (int)($bytes['in'] ?? 0);
            $outBytes = (int)($bytes['out'] ?? 0);

            // 条件：请求已经转发给 Worker，但还没有收到任何返回字节
            if ($inBytes <= 0 || $outBytes > 0) {
                continue;
            }

            if (($nowMicro - (float)$lastActivity) < $this->firstResponseTimeout) {
                continue;
            }

            $clientSocket = $this->clientConnections[$connId];
            $workerPort = $this->passthroughCore->getConnectionWorkerPort($clientSocket);
            if ($workerPort !== null) {
                $this->passthroughCore->markWorkerFailureByPort($workerPort);
            }

            if ($this->isDevMode) {
                $this->log("首包超时，关闭连接 connId: {$connId}, worker: {$workerPort}", 'WARN');
            }
            $this->closeConnection($connId, 'stalled_first_response_timeout');
        }
    }

    /**
     * H15: 刷新所有有缓冲数据的客户端连接
     */
    private function flushPendingBuffers(): void
    {
        $pendingConnIds = $this->passthroughCore->getPendingBufferConnIds();
        
        foreach ($pendingConnIds as $connId) {
            if (!isset($this->clientConnections[$connId])) {
                continue;
            }
            
            $clientSocket = $this->clientConnections[$connId];
            $flushed = $this->passthroughCore->flushClientBuffer($clientSocket);
            
            if ($flushed === -1) {
                // 写入失败，关闭连接
                $this->closeConnection($connId, 'receive_request_failed');
                continue;
            }
            
            if ($flushed > 0) {
                $this->connectionLastActivity[$connId] = \microtime(true);
                $this->bytesCount['out'] += $flushed;
                if (isset($this->connectionBytes[$connId])) {
                    $this->connectionBytes[$connId]['out'] += $flushed;
                }
            }
            
            // 如果缓冲区已空且 Worker 已关闭，现在可以安全关闭连接
            if (!$this->passthroughCore->hasBufferedData($clientSocket) 
                && $this->passthroughCore->isWorkerClosedWithBuffer($clientSocket)) {
                $this->closeConnection($connId, 'forward_to_worker_failed');
            }
        }
    }
    
    /**
     * 接受新连接
     */
    private function acceptConnections(): void
    {
        $accepted = 0;
        $maxAcceptPerLoop = 100;
        
        do {
            $clientSocket = @\socket_accept($this->serverSocket);
            if ($clientSocket === false) {
                break;
            }
            
            $connId = $this->socketId($clientSocket);
            
            // 获取客户端 IP
            $clientIp = '127.0.0.1';
            if (@\socket_getpeername($clientSocket, $addr)) {
                $clientIp = $addr;
            }

            // HTTPS 模式：主端口收到明文 HTTP 时，直接返回 301 到 https://同主机同路径
            if ($this->httpsEnabled && $this->handlePlainHttpRedirect($clientSocket, $connId, $clientIp)) {
                $accepted++;
                continue;
            }
            
            // 攻击探测（在建立 Worker 连接前）
            // 本地与可信回源 IP 白名单，跳过攻击检测
            $isTrustedIp = $this->isTrustedSourceIp($clientIp);
            
            // SSL 握手失败封禁检查（独立于通用攻击检测，非本地 IP 才封禁）
            if (!$isTrustedIp && $this->attackDetector->isSslBanned($clientIp)) {
                // 封禁拦截日志限流：同一 IP 每 60 秒最多记录一次
                $now = \time();
                $lastLog = $this->banLogThrottle[$clientIp] ?? 0;
                if (($now - $lastLog) >= $this->banLogInterval) {
                    $this->banLogThrottle[$clientIp] = $now;
                    $this->log("SSL 封禁拦截: {$clientIp} — IP 因频繁 SSL 握手失败被封禁（后续拦截 {$this->banLogInterval}s 内不再记录）", 'BAN');
                }
                @\socket_close($clientSocket);
                $accepted++;
                continue;
            }
            
            if ($this->attackDetectionEnabled && !$isTrustedIp) {
                // 获取 SNI（如果可用）用于攻击探测
                $sni = $this->passthroughCore->extractSniFromSocketPublic($clientSocket);
                
                $detection = $this->attackDetector->detect(
                    $clientIp,
                    '/', // TCP 代理模式下没有 URI，使用根路径
                    'CONNECT',
                    [],
                    ''
                );
                
                if ($detection['is_attack'] && $detection['should_block']) {
                    $this->log("攻击检测: {$clientIp} - {$detection['type']}: {$detection['reason']}", 'WARN');
                    
                    // 记录攻击信号（供后续请求使用）
                    $this->recordAttackSignal($clientIp, $sni, $detection);
                    
                    @\socket_close($clientSocket);
                    $accepted++;
                    continue;
                }
            }
            
            // 设置非阻塞
            \socket_set_nonblock($clientSocket);
            
            // 尝试建立到 Worker 的连接（含故障转移：失败时自动尝试其他 Worker）
            if ($this->passthroughCore->handleNewConnection($clientSocket, $clientIp)) {
                $this->registerAcceptedClientConnection($clientSocket, $clientIp, $connId);
            } else {
                if ($this->shouldRespondWithStartupProtectionBeforeMaintenanceRouting()) {
                    $this->respondWithStartupProtection($clientSocket);
                    $accepted++;
                    continue;
                }

                // 业务 Worker 暂不可用时，优先尝试推进控制面并让维护 Worker 接管。
                if ($this->tryRouteToMaintenanceWorker($clientSocket, $clientIp, $connId)) {
                    $accepted++;
                    if (($accepted % 10) === 0) {
                        // 高并发 accept 风暴下也要周期推进 IPC/预热，避免主循环“看似活着但控制面饥饿”。
                        $this->pumpSpinWaitControlTick();
                    }
                    continue;
                }

                // 所有 Worker 均不可用（启动窗口内池尚未下发 total=0 为预期，不记日志）
                $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
                $healthy = (int) ($healthSummary['healthy'] ?? 0);
                $total = (int) ($healthSummary['total'] ?? 0);
                $uptime = \time() - $this->startTime;
                $suppressEmptyPoolNoise = ($total <= 0
                    && $this->startupProtectionEnabled
                    && $this->startupProtectionWindowSec > 0.0
                    && $uptime >= 0
                    && $uptime <= (int) $this->startupProtectionWindowSec);
                if (!$suppressEmptyPoolNoise) {
                    $now = \microtime(true);
                    if ($now - $this->lastAllWorkersUnavailableLogAt >= 10.0) {
                        $logLevel = $total <= 0 ? 'WARN' : 'ERROR';
                        $detail = $total <= 0
                            ? 'worker pool is empty'
                            : 'all workers unavailable';
                        $this->log("所有 Worker 不可用! {$clientIp} (connId: {$connId}), "
                            . "healthy: {$healthy}/{$total}, {$detail}", $logLevel);
                        $this->lastAllWorkersUnavailableLogAt = $now;
                    }
                }
                if ($this->shouldServeMaintenanceFallback()) {
                    $this->respondWithStartupProtection($clientSocket);
                } elseif (!$this->tryRespondServiceUnavailable($clientSocket)) {
                    // HTTPS/TLS 原始流无法返回明文 503，只能关闭连接
                    @\socket_close($clientSocket);
                } else {
                    // 已返回 503 并关闭连接
                }
            }
            
            $accepted++;
            if (($accepted % 10) === 0) {
                // 高并发 accept 风暴下也要周期推进 IPC/预热，避免主循环“看似活着但控制面饥饿”。
                $this->pumpSpinWaitControlTick();
            }
        } while ($accepted < $maxAcceptPerLoop);
    }

    private function registerAcceptedClientConnection($clientSocket, string $clientIp, int $connId): void
    {
        $this->clientConnections[$connId] = $clientSocket;
        $this->connectionAcceptTime[$connId] = \microtime(true);
        $this->connectionLastActivity[$connId] = \microtime(true);
        $this->connectionBytes[$connId] = ['in' => 0, 'out' => 0];
        $this->requestCount++;

        $workerPort = $this->passthroughCore->getConnectionWorkerPort($clientSocket);
        if ($this->isDevMode) {
            $this->log("新连接: {$clientIp} (connId: {$connId}) → Worker:{$workerPort}", 'ROUTE');
        }
    }

    private function tryRouteToMaintenanceWorker($clientSocket, string $clientIp, int $connId): bool
    {
        if (!$this->shouldServeMaintenanceFallback()) {
            return false;
        }

        $ticks = \max(1, $this->maintenanceTakeoverRetryTicks);
        for ($i = 0; $i < $ticks; $i++) {
            $this->pumpSpinWaitControlTick();
            if ($this->passthroughCore->handleNewConnection($clientSocket, $clientIp)) {
                $this->registerAcceptedClientConnection($clientSocket, $clientIp, $connId);
                if ($this->isDevMode) {
                    $this->log("维护接管成功: {$clientIp} (connId: {$connId})", 'ROUTE');
                }

                return true;
            }
        }

        return false;
    }

    private function shouldApplyStartupProtection(): bool
    {
        if (!$this->startupProtectionEnabled || $this->startupProtectionWindowSec <= 0.0) {
            return false;
        }

        $uptime = \time() - $this->startTime;
        if ($uptime < 0 || $uptime > $this->startupProtectionWindowSec) {
            return false;
        }

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthy = (int)($healthSummary['healthy'] ?? 0);
        $dynamicTotal = (int)($healthSummary['total'] ?? 0);
        $poolSize = $this->passthroughCore->getWorkerCount();
        $expected = $this->expectedWorkerCount > 0 ? $this->expectedWorkerCount : $dynamicTotal;
        // 仅维护 Worker 先入池时，期望业务 Worker 数会大于当前池大小；若不封顶会长期误判「未就绪」而只返回 503
        if ($poolSize > 0 && $expected > $poolSize) {
            $expected = $poolSize;
        }
        if ($expected <= 0) {
            $expected = 1;
        }

        $requiredByRatio = (int)\ceil($expected * $this->startupProtectionReadyRatio);
        $required = \max($this->startupProtectionMinReady, $requiredByRatio);
        if ($required > $expected) {
            $required = $expected;
        }

        return $healthy < $required;
    }

    private function shouldServeMaintenanceFallback(): bool
    {
        // 仅当池确实为空时，才把「维护兜底」理解为硬编码 503；若已有端口（含维护 Worker），应已走透传+自旋，
        // 避免 maintenanceFallbackActive 与池状态短暂不一致时误杀可转发流量。
        if ($this->maintenanceFallbackActive && $this->passthroughCore->getWorkerCount() <= 0) {
            return true;
        }

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $total = (int)($healthSummary['total'] ?? 0);
        if ($total <= 0) {
            // 还没有任何可路由后端（启动窗口/池未下发）→ 返回维护页而非断开连接
            return true;
        }

        return $this->shouldApplyStartupProtection();
    }

    private function shouldRespondWithStartupProtectionBeforeMaintenanceRouting(): bool
    {
        return $this->shouldServeMaintenanceFallback()
            && $this->passthroughCore->getWorkerCount() <= 0;
    }

    /**
     * 启动保护响应：在可控窗口内明确返回 503，避免客户端表现为随机断连。
     *
     * @param \Socket|resource $clientSocket
     */
    private function respondWithStartupProtection($clientSocket): void
    {
        @\socket_write($clientSocket, $this->fallbackMaintenancePage);
        @\socket_close($clientSocket);
    }

    /**
     * 在非 TLS 的 HTTP 请求上返回 503，避免浏览器表现为 ERR_CONNECTION_CLOSED。
     *
     * @param \Socket|resource $clientSocket
     */
    private function tryRespondServiceUnavailable($clientSocket): bool
    {
        $peek = '';
        $peekLen = @\socket_recv($clientSocket, $peek, 8, \MSG_PEEK);
        if ($peekLen === false || $peekLen <= 0 || $peek === '') {
            return false;
        }

        $firstByte = \ord($peek[0]);
        // TLS 握手流不发送明文 HTTP 503，避免破坏协议
        if ($firstByte === 0x16) {
            return false;
        }

        @\socket_write($clientSocket, $this->fallbackMaintenancePage);
        @\socket_close($clientSocket);
        return true;
    }

    /**
     * HTTPS 模式下识别并处理明文 HTTP 请求
     *
     * 优先将连接转发给 http_redirect_worker 处理，若转发失败则回退到内联 301。
     *
     * @return bool true=已处理（转发或内联 301）；false=非明文 HTTP，继续走 TCP 透传
     */
    private function handlePlainHttpRedirect($clientSocket, int $connId, string $clientIp): bool
    {
        $peek = '';
        $peekLen = @\socket_recv($clientSocket, $peek, 8, \MSG_PEEK);
        if ($peekLen === false || $peekLen <= 0 || $peek === '') {
            return false;
        }

        $firstByte = \ord($peek[0]);
        // TLS ClientHello 首字节通常是 0x16，不需要重定向
        if ($firstByte === 0x16) {
            return false;
        }

        $head = \strtoupper($peek);
        $isPlainHttp = \str_starts_with($head, 'GET ')
            || \str_starts_with($head, 'POST ')
            || \str_starts_with($head, 'HEAD ')
            || \str_starts_with($head, 'PUT ')
            || \str_starts_with($head, 'PATCH ')
            || \str_starts_with($head, 'DELETE ')
            || \str_starts_with($head, 'OPTIONS ')
            || \str_starts_with($head, 'TRACE ')
            || \str_starts_with($head, 'CONNECT ');
        if (!$isPlainHttp) {
            return false;
        }

        // 优先转发到 http_redirect_worker
        $redirectPort = $this->passthroughCore->getHttpRedirectPort();
        if ($redirectPort > 0) {
            // 设置非阻塞
            @\socket_set_nonblock($clientSocket);
            
            if ($this->passthroughCore->handleHttpRedirectConnection($clientSocket, $clientIp)) {
                // 成功建立到 redirect_worker 的连接
                $this->clientConnections[$connId] = $clientSocket;
                $this->connectionAcceptTime[$connId] = \microtime(true);
                $this->connectionLastActivity[$connId] = \microtime(true);
                $this->connectionBytes[$connId] = ['in' => 0, 'out' => 0];
                $this->requestCount++;
                
                if ($this->isDevMode) {
                    $this->log("HTTP→Redirect: {$clientIp} (connId: {$connId}) → redirect_worker:{$redirectPort}", 'ROUTE');
                }
                return true;
            }
            // 连接失败，回退到内联 301
        }

        // 回退：内联返回 301
        return $this->sendInlineHttpRedirect($clientSocket, $connId, $clientIp);
    }

    /**
     * 内联返回 HTTP→HTTPS 301 重定向响应
     *
     * 当无法转发到 http_redirect_worker 时的回退方案。
     *
     * @return bool 始终返回 true（已处理并关闭连接）
     */
    private function sendInlineHttpRedirect($clientSocket, int $connId, string $clientIp): bool
    {
        // 非阻塞读取请求头，短超时（约 300ms），避免 Windows 下阻塞整条 accept 导致大批请求排队
        @\socket_set_nonblock($clientSocket);
        $raw = '';
        $readDeadline = \microtime(true) + 0.3;
        while (\strpos($raw, "\r\n\r\n") === false && \strlen($raw) < 65536) {
            if (\microtime(true) >= $readDeadline) {
                @\socket_close($clientSocket);
                return true;
            }
            $read = [$clientSocket];
            $w = $e = [];
            $n = @\socket_select($read, $w, $e, 0, 50000); // 50ms
            if ($n > 0 && \in_array($clientSocket, $read, true)) {
                $chunk = '';
                $nr = @\socket_recv($clientSocket, $chunk, 8192, 0);
                if ($nr === false || $nr <= 0) {
                    break;
                }
                $raw .= $chunk;
            }
        }
        if (\strpos($raw, "\r\n\r\n") === false) {
            @\socket_close($clientSocket);
            return true;
        }
        $target = '/';
        if (\preg_match('/^\w+\s+(\S+)\s+/i', $raw, $m)) {
            $target = (string)$m[1];
            if ($target === '') {
                $target = '/';
            }
        }
        // 绝对 URL 场景保留 path+query；相对 URL 直接使用
        if (\preg_match('/^https?:\/\//i', $target)) {
            $path = (string)(\parse_url($target, \PHP_URL_PATH) ?? '/');
            $query = (string)(\parse_url($target, \PHP_URL_QUERY) ?? '');
            $target = $query !== '' ? "{$path}?{$query}" : $path;
        }
        if ($target === '' || $target[0] !== '/') {
            $target = '/' . \ltrim($target, '/');
        }

        ['host' => $redirectHost, 'port' => $redirectPort] = $this->resolveHttpsRedirectHostAndPort($raw);
        $redirectUrl = $redirectPort === 443 ? "https://{$redirectHost}{$target}" : "https://{$redirectHost}:{$redirectPort}{$target}";

        $response = "HTTP/1.1 301 Moved Permanently\r\n"
            . "Location: {$redirectUrl}\r\n"
            . "Content-Length: 0\r\n"
            . "Connection: close\r\n\r\n";
        $toWrite = \strlen($response);
        $written = 0;
        $writeDeadline = \microtime(true) + 0.2;
        while ($written < $toWrite && \microtime(true) < $writeDeadline) {
            $write = [$clientSocket];
            $r = $e = [];
            $n = @\socket_select($r, $write, $e, 0, 50000);
            if ($n > 0 && \in_array($clientSocket, $write, true)) {
                $nw = @\socket_send($clientSocket, \substr($response, $written), $toWrite - $written, 0);
                if ($nw === false || $nw <= 0) {
                    break;
                }
                $written += $nw;
            }
        }
        @\socket_close($clientSocket);
        $this->log("HTTP->HTTPS 301 (inline): {$clientIp} (connId: {$connId}) => {$redirectUrl}", 'ROUTE');
        return true;
    }

    /**
     * 透传模式下解析 HTTPS 重定向目标（仅信任 Host 头）
     *
     * 规则：
     * - Host 带端口：使用该端口
     * - Host 不带端口：默认 443
     */
    private function resolveHttpsRedirectHostAndPort(string $raw): array
    {
        $redirectHost = '127.0.0.1';
        $redirectPort = 443;

        if (\preg_match('/\r\nHost:\s*([^\r\n]+)/i', $raw, $h)) {
            $redirectHost = \trim((string)$h[1]);
        }

        if (\str_contains($redirectHost, ':')) {
            [$hostOnly, $hostPort] = \explode(':', $redirectHost, 2);
            $redirectHost = $hostOnly;
            if (\ctype_digit($hostPort)) {
                $redirectPort = (int)$hostPort;
            }
        }

        return [
            'host' => $redirectHost,
            'port' => $redirectPort,
        ];
    }
    
    /**
     * 记录攻击信号（非阻塞方式）
     *
     * 直接写入攻击信号文件，由 Cron 定时任务异步处理 CDN 通知
     *
     * @param string $clientIp 客户端 IP
     * @param string $sni SNI
     * @param array $detection 检测结果
     */
    private function recordAttackSignal(string $clientIp, string $sni, array $detection): void
    {
        // 非阻塞写入攻击信号文件
        AttackSignalFileService::recordAttack(
            $clientIp,
            $sni,
            $detection['type'],
            $detection['reason']
        );
    }
    
    /**
     * 处理客户端数据（转发到 Worker）
     *
     * @param resource $clientSocket 客户端 socket
     */
    private function handleClientData($clientSocket): void
    {
        $connId = $this->socketId($clientSocket);
        
        $result = $this->passthroughCore->forwardToWorker($clientSocket);
        $terminalReasonPeek = $this->passthroughCore->peekConnectionTerminalReasonByConnId($connId);

        if ($result === -1) {
            // 兜底保护：客户端上行 FIN 在某些分支被映射为 -1 时，不应关闭整连接。
            if ($terminalReasonPeek === 'forward_to_worker_client_read_eof'
                || $this->passthroughCore->isClientInputClosed($clientSocket)) {
                $this->connectionLastActivity[$connId] = \microtime(true);
                return;
            }
            // 连接真正关闭或错误
            if ($this->isDevMode) {
                $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket);
                $clientIp = $connInfo['client_ip'] ?? 'unknown';
                $this->log("连接关闭(客户端→Worker): {$clientIp} (connId: {$connId})", 'CLOSE');
            }
            $this->closeConnection($connId, 'forward_to_client_failed');
            return;
        }
        
        // result === -2: 客户端上行半关闭（FIN），但下行仍可继续，不关闭连接
        if ($result === -2) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            $bytes = $this->connectionBytes[$connId] ?? ['in' => 0, 'out' => 0];
            $inBytes = (int)($bytes['in'] ?? 0);
            $outBytes = (int)($bytes['out'] ?? 0);
            // 客户端已断开且未产生有效请求/响应，快速回收，避免残留 CLOSE_WAIT。
            $clientInputClosed = $this->passthroughCore->isClientInputClosed($clientSocket);
            if ($this->shouldFastCloseHalfClosedWithoutRequest($connId, $clientInputClosed, $inBytes, $outBytes)
                && !$this->passthroughCore->hasBufferedData($clientSocket)) {
                $this->logHalfClosedFastCloseIfNeeded($connId, 'event');
                $this->closeConnection($connId, 'client_half_closed_without_request');
            }
            return;
        }
        
        // result === 0: 暂无数据（WOULDBLOCK），连接正常，不做任何操作
        
        if ($result > 0) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            $this->bytesCount['in'] += $result;
            if (isset($this->connectionBytes[$connId])) {
                $this->connectionBytes[$connId]['in'] += $result;
            }
        }
    }
    
    /**
     * 处理 Worker 数据（转发到客户端）
     *
     * @param resource $clientSocket 客户端 socket
     */
    private function handleWorkerData($clientSocket): void
    {
        $connId = $this->socketId($clientSocket);
        
        $result = $this->passthroughCore->forwardToClient($clientSocket);
        
        if ($result === -1) {
            // 连接真正关闭或错误
            if ($this->isDevMode) {
                $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket);
                $clientIp = $connInfo['client_ip'] ?? 'unknown';
                $this->log("连接关闭(Worker→客户端): {$clientIp} (connId: {$connId})", 'CLOSE');
            }
            $this->closeConnection($connId, 'worker_closed_or_client_disconnected');
            return;
        }
        
        // H15: result === -2: Worker 关闭但有缓冲数据待发送
        // 不关闭客户端连接，等待缓冲区数据发送完成
        
        if ($result > 0 || $result === -2) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            if ($result > 0 && $this->isDevMode) {
                $this->log("Dispatcher 转发到客户端 connId: {$connId} bytes: {$result}", 'ROUTE');
            }
            if ($result > 0) {
                $this->bytesCount['out'] += $result;
                if (isset($this->connectionBytes[$connId])) {
                    $this->connectionBytes[$connId]['out'] += $result;
                }
            }
        }
    }
    
    /**
     * 关闭连接
     *
     * @param int $connId 连接 ID
     */
    private function closeConnection(int $connId, string $reason = 'unknown'): void
    {
        if (isset($this->clientConnections[$connId])) {
            $clientSocket = $this->clientConnections[$connId];
            
            // 关闭透传核心中的连接
            $this->passthroughCore->closeConnection($clientSocket);
            
            // 关闭客户端连接
            @\socket_shutdown($clientSocket, 2);
            @\socket_close($clientSocket);
            
            unset($this->clientConnections[$connId]);
        }
        
        unset(
            $this->connectionAcceptTime[$connId],
            $this->connectionLastActivity[$connId],
            $this->connectionBytes[$connId]
        );
    }

    private function shouldFastCloseHalfClosedWithoutRequest(
        int $connId,
        bool $clientInputClosed,
        int $inBytes,
        int $outBytes
    ): bool {
        if (!$clientInputClosed || $inBytes > 0 || $outBytes > 0) {
            return false;
        }

        if (!isset($this->clientConnections[$connId])) {
            return false;
        }

        return true;
    }

    private function logHalfClosedFastCloseIfNeeded(int $connId, string $source): void
    {
        if (!$this->isDevMode) {
            return;
        }

        $clientSocket = $this->clientConnections[$connId] ?? null;
        $clientIp = 'unknown';
        if ($clientSocket !== null) {
            $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket);
            $clientIp = (string)($connInfo['client_ip'] ?? 'unknown');
        }

        $now = \time();
        $lastLog = $this->halfClosedFastCloseLogThrottle[$clientIp] ?? 0;
        if (($now - $lastLog) < $this->halfClosedFastCloseLogInterval) {
            return;
        }
        $this->halfClosedFastCloseLogThrottle[$clientIp] = $now;
        $this->log("快速回收半关闭空连接: {$clientIp} (connId: {$connId}, source={$source})", 'HEALTH');
    }
    
    /**
     * 定期打印统计信息
     */
    private function printStats(): void
    {
        $now = \time();
        if ($now - $this->lastStatsTime < 60) {
            return;
        }
        $this->lastStatsTime = $now;
        
        $uptime = $now - $this->startTime;
        $rps = $uptime > 0 ? \round($this->requestCount / $uptime, 2) : 0;
        $activeConns = \count($this->clientConnections);
        $bytesInKb = \round($this->bytesCount['in'] / 1024, 2);
        $bytesOutKb = \round($this->bytesCount['out'] / 1024, 2);
        
        $coreStats = $this->passthroughCore->getStats();
        $cacheHitRate = $coreStats['cache_stats']['sni_hit_rate'] ?? 0;
        $failoverRouted = $coreStats['failover_routed'] ?? 0;
        $workerFailures = $coreStats['worker_failures'] ?? 0;
        $allWorkersDown = (int)($coreStats['all_workers_down'] ?? 0);
        
        // Worker 健康状态
        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthStatus = "{$healthSummary['healthy']}/{$healthSummary['total']} healthy";
        if ($healthSummary['blacklisted'] > 0) {
            $healthStatus .= ", {$healthSummary['blacklisted']} blacklisted";
        }
        
        $this->log("Stats: {$this->requestCount} conns, {$rps} conn/s, {$activeConns} active, "
            . "in: {$bytesInKb}KB, out: {$bytesOutKb}KB, cache hit: {$cacheHitRate}%, "
            . "failovers: {$failoverRouted}, failures: {$workerFailures}, "
            . "workers: {$healthStatus}, uptime {$uptime}s", 'STATS');
        
        // 如果有黑名单 Worker，输出详情
        if ($healthSummary['blacklisted'] > 0) {
            foreach ($healthSummary['details'] as $port => $detail) {
                if ($detail['status'] === 'blacklisted') {
                    $this->log("  Worker :{$port} BLACKLISTED (failures: {$detail['total_failures']}, last success: {$detail['last_success']})", 'WARN');
                }
            }
        }
        
        // 只输出自上次统计以来新增的 all_workers_down，避免累计值每分钟重复刷同一错误。
        $newAllWorkersDown = $allWorkersDown - $this->lastAllWorkersDownReported;
        if ($newAllWorkersDown > 0) {
            $this->log("{$newAllWorkersDown} requests failed - all workers were unavailable!", 'ERROR');
            $this->lastAllWorkersDownReported = $allWorkersDown;
        } elseif ($allWorkersDown < $this->lastAllWorkersDownReported) {
            // 核心统计被重置（如热重载/进程恢复）后，重新对齐基线。
            $this->lastAllWorkersDownReported = $allWorkersDown;
        }
        
        // 记录 Dispatcher 状态到数据库
        try {
            StatusLogService::logDispatcherStatus([
                'instance' => $this->instanceName,
                'port' => $this->port,
                'pid' => \getmypid(),
                'status' => 'running',
                'connections' => $activeConns,
                'active_requests' => $activeConns,
                'total_requests' => $this->requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $uptime,
                'worker_count' => $this->passthroughCore->getWorkerCount(),
                'worker_health' => $healthSummary,
                'failover_count' => $failoverRouted,
                'worker_failures' => $workerFailures,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    /**
     * 注册信号处理（仅 Linux/Mac）
     * 
     * 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
     */
    private function registerSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        
        \pcntl_signal(SIGINT, SIG_IGN);
        \pcntl_signal(SIGTERM, function () {
            $this->log('收到 SIGTERM 信号', 'WARN');
            $this->running = false;
        });
    }
    
    /**
     * 优雅关闭
     */
    private function shutdown(): void
    {
        // 刷新攻击日志缓冲区
        try {
            AttackLogService::flush();
        } catch (\Throwable $e) {
            // 忽略刷新失败
        }
        
        // 通知 Master 即将退出（IPC exited 消息）
        if ($this->ipcClient && $this->ipcClient->isConnected()) {
            $this->ipcClient->send(ControlMessage::exited(
                ControlMessage::ROLE_DISPATCHER,
                \getmypid(),
                $this->port
            ));
            $this->log('已发送 exited 消息给 Master', 'INFO');
        }
        
        // 关闭 IPC 控制客户端
        if ($this->ipcClient) {
            $this->ipcClient->close();
            $this->ipcClient = null;
        }
        
        // 关闭所有连接
        foreach ($this->clientConnections as $connId => $socket) {
            $this->closeConnection($connId, 'socket_select_exception_or_read_error');
        }
        
        // 关闭透传核心中的所有连接
        $this->passthroughCore->closeAllConnections();
        
        // 关闭服务器 socket
        @\socket_close($this->serverSocket);
        
        // 清理 PID
        if ($this->processName) {
            Processer::destroy('--name=' . $this->processName);
        }
        
        $this->log('Shutting down...', 'INFO');
    }
    
    /**
     * 停止运行
     */
    public function stop(): void
    {
        $this->running = false;
    }
    
    /**
     * 获取统计信息
     *
     * @return array 统计信息
     */
    public function getStats(): array
    {
        return [
            'uptime' => \time() - $this->startTime,
            'total_connections' => $this->requestCount,
            'active_connections' => \count($this->clientConnections),
            'bytes_in' => $this->bytesCount['in'],
            'bytes_out' => $this->bytesCount['out'],
            'core_stats' => $this->passthroughCore->getStats(),
        ];
    }

    private function isTrustedSourceIp(string $ip): bool
    {
        if (\in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)
            || \str_starts_with($ip, '192.168.')
            || \str_starts_with($ip, '10.')
            || \str_starts_with($ip, '172.')) {
            return true;
        }

        $rules = $this->attackDetector->getRules();
        $trusted = $rules['cdn_trusted_ips'] ?? [];
        if (!($trusted['enabled'] ?? true)) {
            return false;
        }
        $ipRules = $trusted['ips'] ?? [];
        foreach ((array)$ipRules as $item) {
            $pattern = \trim((string)$item);
            if ($pattern === '') {
                continue;
            }
            if ($this->ipMatches($ip, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function ipMatches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }
        if (!\str_contains($pattern, '/')) {
            return false;
        }
        [$subnet, $mask] = \explode('/', $pattern, 2);
        $maskBits = (int)$mask;
        $ipLong = \ip2long($ip);
        $subnetLong = \ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $maskBits < 0 || $maskBits > 32) {
            return false;
        }
        $maskLong = $maskBits === 0 ? 0 : (~0 << (32 - $maskBits));
        return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
    }
}
