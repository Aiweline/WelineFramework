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

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Service\MainLoopUnblockedLogConfig;
use Weline\Server\Service\AttackLogService;
use Weline\Server\Service\AttackSignalFileService;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\StatusLogService;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

class Dispatcher
{
    /**
     * Worker 切换窗口内，主动开启 TCP keep-alive，减少中间设备对空闲连接的误回收。
     */
    private const CLIENT_TCP_KEEPALIVE_IDLE_SEC = 20;
    private const CLIENT_TCP_KEEPALIVE_INTERVAL_SEC = 8;
    private const CLIENT_TCP_KEEPALIVE_PROBES = 3;
    private const MASTER_PID_CHECK_INTERVAL_SEC = 5;
    private const MASTER_PID_DEAD_THRESHOLD = 1;

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
     * Fast path for ordinary TLS ClientHello traffic.
     */
    private bool $fastTlsPathEnabled = true;

    /**
     * Bound each accept burst so active tunnels can flush between fresh connects.
     */
    private int $maxAcceptPerLoop = 64;
    
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
    private float $clientHalfClosedRequestIdleTimeoutSec = 30.0;
    
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
    private int $workerProbeInterval = 30;
    
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
    /** @var array<string, float> */
    private array $masterBackendAlertLoggedAt = [];

    /**
     * 启动保护：窗口期内未达到最小 READY 阈值时，对外返回 503 而非直接断开。
     */
    private bool $startupProtectionEnabled = true;
    private float $startupProtectionWindowSec = 45.0;
    private float $startupProtectionReadyRatio = 0.0;
    private int $startupProtectionMinReady = 1;
    private int $expectedWorkerCount = 0;
    private float $backendRouteWaitTimeoutSec = 0.0;
    
    // ========== IPC 控制通道 ==========
    
    /**
     * IPC 控制客户端
     */
    private ?ChildControlClientInterface $ipcClient = null;
    
    /**
     * IPC 控制端口
     */
    private int $controlPort = 0;
    private int $lastAppliedWorkerPoolSnapshotVersion = 0;
    private int $currentRouteVersion = 0;

    /**
     * Latest authoritative route-table version applied from Master.
     */
    private int $observedRouteTableVersion = 0;

    /**
     * Latest authoritative route-table checksum applied from Master.
     */
    private string $observedRouteTableChecksum = '';

    /**
     * SET_ROUTE_TABLE is the only Dispatcher route-table authority.
     */
    private bool $routeTableAsAuthority = true;
    
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
     * 是否曾经观察到至少 1 个健康 Worker。
     *
     * P1-4 修复：替代旧的「uptime <= startupProtectionWindowSec=45s」硬窗口判定，
     * 只要从未观察过健康 Worker，就视为"仍在启动"，持续返回启动中维护页；
     * 一旦有过健康 Worker，再次全挂时改由 healthy==0 持续阈值 + startup 保护判定走维护页。
     */
    private bool $hasEverObservedHealthyWorker = false;

    /**
     * healthy==0 且 total>0 持续开始时间戳。
     * P1-5 修复：区分瞬时抖动 vs 真正全挂；仅当持续时长 >= 阈值时才走维护页兜底。
     */
    private float $healthyZeroSince = 0.0;

    /** healthy==0 && total>0 触发维护页兜底的持续时长阈值（秒） */
    private float $healthyZeroMaintenanceThresholdSec = 2.0;
    private bool $workerHealthAuditEnabled = false;

    /**
     * 首字节尚未到达的 pending 维护页连接等待超时（秒）。
     *
     * P0-5 修复：对新 accept 的 non-blocking socket，MSG_PEEK 第一次即无数据时
     * 不再立即 close（浏览器会看到 ERR_EMPTY_RESPONSE），而是入队等待首字节；
     * 超过此阈值仍未到首字节（或对端断开）才 close。
     */
    private float $pendingMaintenanceWaitTimeoutSec = 2.0;

    /**
     * 等待首字节的 pending 维护页连接。
     *
     * key = socketId($clientSocket)
     *
     * @var array<int, array{socket: mixed, clientIp: string, acceptedAt: float, allWorkersUnavailable: bool}>
     */
    private array $pendingMaintenancePageQueue = [];

    /** pending 维护页队列容量上限，防内存膨胀；溢出时直接关闭新连接 */
    private int $pendingMaintenancePageQueueMax = 4096;

    /**
     * Route-table and worker health jobs are resumed by the main loop in Fiber slices.
     *
     * @var list<array{type: 'set_pool'|'homepage_warmup'|'probe_blacklisted_workers'|'audit_worker_health', ports?: int[], workers?: array, role?: string, source?: string, route_version?: int}>
     */
    private array $deferredWorkerPoolJobs = [];

    private ?\Fiber $deferredWorkerPoolFiber = null;

    /** @var 'set_pool'|'homepage_warmup'|'probe_blacklisted_workers'|'audit_worker_health'|null */
    private ?string $deferredWorkerPoolFiberKind = null;
    /** @var array{type: 'set_pool'|'homepage_warmup'|'probe_blacklisted_workers'|'audit_worker_health', ports?: int[], workers?: array, role?: string, source?: string, route_version?: int}|null */
    private ?array $deferredWorkerPoolFiberJob = null;
    private bool $spinWaitTickInProgress = false;
    private int $maintenanceTakeoverRetryTicks = 3;
    private float $lastMaintenanceOperationLogAt = 0.0;
    private string $lastMaintenanceOperationSignature = '';
    private int $mainLoopUnblockedLogEvery = 10000;
    private float $mainLoopUnblockedLogIntervalSec = 30.0;
    private float $lastMainLoopUnblockedLogAt = 0.0;
    
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
        // 暂时不自旋等待，否则无法进入维护模式
        // $this->passthroughCore->setSpinWaitTickCallback(function (): void {
        //     $this->pumpSpinWaitControlTick();
        // });
        $this->attackDetector = AttackDetector::getInstance()->setInstanceName($instanceName);
        $this->startTime = \time();
        // 注册 PID
        if ($processName) {
            Processer::setPid('--name=' . $processName, \getmypid());
            if ($port > 0) {
                Processer::setProcessPorts('--name=' . $processName, [$port]);
            }
        }
        
        // 初始化硬编码维护页（纯内存，最后一道防线）
        $this->fallbackMaintenancePage = $this->buildFriendlyStartupMaintenancePage();

        $this->routeTableAsAuthority = self::resolveRouteTableAuthorityFromEnv();
        if ($this->routeTableAsAuthority) {
            $this->log(
                'SET_ROUTE_TABLE is the Dispatcher route-table authority.',
                'WARN'
            );
        }

        // 注册信号处理
        $this->registerSignals();
    }

    /**
     * SET_ROUTE_TABLE authority is mandatory.
     */
    public static function resolveRouteTableAuthorityFromEnv(): bool
    {
        return true;
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
                return !empty($instData['ssl_enabled']);
            }
        }

        $configFile = BP . 'var' . DS . 'server' . DS . 'config' . DS . $instanceName . '.json';
        if (!\is_file($configFile)) {
            return false;
        }

        $configData = @\json_decode((string)\file_get_contents($configFile), true);
        if (!\is_array($configData)) {
            return false;
        }

        if (\array_key_exists('ssl_enabled', $configData)) {
            return !empty($configData['ssl_enabled']);
        }

        $sslCert = \trim((string) ($configData['ssl_cert'] ?? ''));
        $sslKey = \trim((string) ($configData['ssl_key'] ?? ''));

        return $sslCert !== '' || $sslKey !== '';
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
        if (isset($config['client_half_closed_request_idle_timeout_sec'])) {
            $this->clientHalfClosedRequestIdleTimeoutSec = \max(
                $this->clientHalfClosedIdleTimeoutSec,
                (float)$config['client_half_closed_request_idle_timeout_sec']
            );
        }
        if (isset($config['first_response_timeout'])) {
            // <=0 表示关闭该启发式（避免对长处理请求误杀）。
            $this->firstResponseTimeout = \max(0.0, (float)$config['first_response_timeout']);
        }
        if (isset($config['enforce_first_response_timeout'])) {
            $this->enforceFirstResponseTimeout = (bool)$config['enforce_first_response_timeout'];
        }
        if (isset($config['worker_probe_interval'])) {
            $this->workerProbeInterval = \max(3, (int)$config['worker_probe_interval']);
        }
        if (isset($config['worker_health_audit_enabled'])) {
            $this->workerHealthAuditEnabled = (bool)$config['worker_health_audit_enabled'];
        }
        
        if (isset($config['attack_detection_enabled'])) {
            $this->attackDetectionEnabled = (bool) $config['attack_detection_enabled'];
        }
        if (isset($config['fast_tls_path_enabled'])) {
            $this->fastTlsPathEnabled = (bool) $config['fast_tls_path_enabled'];
        }
        if (isset($config['max_accept_per_loop'])) {
            $this->maxAcceptPerLoop = \max(1, \min(256, (int)$config['max_accept_per_loop']));
        }
        if (isset($config['main_loop_unblocked_log_every'])) {
            $this->mainLoopUnblockedLogEvery = \max(0, (int) $config['main_loop_unblocked_log_every']);
        }
        if (isset($config['main_loop_unblocked_log_interval_sec'])) {
            $this->mainLoopUnblockedLogIntervalSec = \max(0.0, (float) $config['main_loop_unblocked_log_interval_sec']);
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
        if (isset($config['backend_route_wait_timeout_sec'])) {
            $this->backendRouteWaitTimeoutSec = (float)$config['backend_route_wait_timeout_sec'];
        }
        if ($this->backendRouteWaitTimeoutSec < 0.0) {
            $this->backendRouteWaitTimeoutSec = 0.0;
        }

        if (isset($config['healthy_zero_maintenance_threshold_sec'])) {
            $this->healthyZeroMaintenanceThresholdSec = \max(0.0, (float)$config['healthy_zero_maintenance_threshold_sec']);
        }
        if (isset($config['pending_maintenance_wait_timeout_sec'])) {
            $this->pendingMaintenanceWaitTimeoutSec = \max(0.1, (float)$config['pending_maintenance_wait_timeout_sec']);
        }
        if (isset($config['pending_maintenance_page_queue_max'])) {
            $this->pendingMaintenancePageQueueMax = \max(128, (int)$config['pending_maintenance_page_queue_max']);
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

    private function formatMaintenanceRoutingContext(): string
    {
        $workerPoolSize = $this->passthroughCore->getWorkerCount();
        $maintenancePorts = $this->passthroughCore->getMaintenanceWorkerPorts();
        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthy = (int) ($healthSummary['healthy'] ?? 0);
        $total = (int) ($healthSummary['total'] ?? 0);

        return 'maintenance_fallback_active=' . ($this->maintenanceFallbackActive ? 'true' : 'false')
            . ', worker_pool_size=' . $workerPoolSize
            . ', maintenance_candidates=' . ($maintenancePorts !== [] ? \implode(',', $maintenancePorts) : '(none)')
            . ", health={$healthy}/{$total}";
    }

    private function logMaintenanceOperation(
        string $message,
        string $level = 'INFO',
        ?string $signature = null,
        float $throttleSec = 10.0
    ): void {
        $signature ??= $message;
        $now = \microtime(true);
        if (
            $signature === $this->lastMaintenanceOperationSignature
            && ($now - $this->lastMaintenanceOperationLogAt) < $throttleSec
        ) {
            return;
        }

        $this->lastMaintenanceOperationSignature = $signature;
        $this->lastMaintenanceOperationLogAt = $now;
        $this->log('[MaintenanceFlow] ' . $message, $level);
    }

    private function reportAllWorkersUnavailableToMaster(): void
    {
        if ($this->ipcClient === null || !$this->ipcClient->isConnected()) {
            return;
        }

        $businessPool = \array_values(\array_map('intval', $this->passthroughCore->getWorkerPorts()));
        $maintenanceCandidates = \array_values(\array_map('intval', $this->passthroughCore->getMaintenanceWorkerPorts()));
        \sort($businessPool, SORT_NUMERIC);
        \sort($maintenanceCandidates, SORT_NUMERIC);

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthy = (int) ($healthSummary['healthy'] ?? 0);
        $total = (int) ($healthSummary['total'] ?? 0);
        $maintenancePort = $this->passthroughCore->getMaintenancePort();

        $signature = 'all_workers_unavailable:'
            . \implode(',', $businessPool)
            . '|'
            . \implode(',', $maintenanceCandidates)
            . '|'
            . $maintenancePort
            . '|'
            . $healthy
            . '/'
            . $total;
        $now = \microtime(true);
        $last = (float) ($this->masterBackendAlertLoggedAt[$signature] ?? 0.0);
        if (($now - $last) < 3.0) {
            return;
        }
        $this->masterBackendAlertLoggedAt[$signature] = $now;
        if (\count($this->masterBackendAlertLoggedAt) > 128) {
            $this->masterBackendAlertLoggedAt = \array_slice($this->masterBackendAlertLoggedAt, -64, 64, true);
        }

        $sent = $this->ipcClient->send(ControlMessage::dispatcherAlert(
            $this->instanceName,
            'all_workers_unavailable',
            [
                'dispatcher_port' => $this->port,
                'worker_pool_size' => \count($businessPool),
                'business_pool' => $businessPool,
                'maintenance_candidates' => $maintenanceCandidates,
                'maintenance_port' => $maintenancePort,
                'healthy' => $healthy,
                'total' => $total,
            ],
            ControlMessage::ROLE_WORKER
        ));
        if ($sent) {
            $this->logMaintenanceOperation(
                '已向 Master 上报业务/维护 Worker 全不可用，signature=' . $signature,
                'WARN',
                'master_backend_alert:' . $signature,
                3.0
            );
        }
    }

    private function updateMaintenanceFallbackState(bool $active, string $reason): void
    {
        $previous = $this->maintenanceFallbackActive;
        $this->maintenanceFallbackActive = $active;

        $state = $active ? 'ACTIVE' : 'INACTIVE';
        $transition = $previous !== $active ? '切换' : '保持';
        $context = $this->formatMaintenanceRoutingContext();
        $level = $active ? 'WARN' : 'INFO';

        $this->logMaintenanceOperation(
            "维护兜底{$transition}为 {$state}，reason={$reason}，{$context}",
            $level,
            "{$transition}:{$state}:{$reason}:{$context}"
        );
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
     * @param int $controlPort Master 控制端口（0 = 从 Master endpoint bootstrap 读取）
     */
    public function connectIpc(int $controlPort = 0, bool $sendReady = true): void
    {
        $this->controlPort = $controlPort;
        
        if ($this->controlPort <= 0 && !$this->isSupervisorEnabled()) {
            $this->controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort(
                $this->instanceName,
                0,
                0
            );
        }
        
        if ($this->controlPort <= 0 && !$this->isSupervisorEnabled()) {
            return;
        }
        
        $this->ipcClient = $this->createIpcClient();
        $this->ipcClient->setSelfTag('Dispatcher');
        // DEV 模式下输出详细 IPC SEND/RECV 明细
        $this->ipcClient->setVerboseLog($this->isDevMode);
        $this->ipcClient->rememberRegistration(
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
        $this->ipcClient->markReadyState($sendReady);
        $this->ipcClient->onMessage(function (array $msg, ChildControlClientInterface $client) {
            unset($client);
            $this->handleIpcMessage($msg);
        });
        
        // 设置断开处理器
        $this->ipcClient->onDisconnect(function (bool $receivedShutdown, ChildControlClientInterface $client) {
            // 已收到 shutdown 或正在退出，不做任何复活/重连操作
            if ($receivedShutdown || $this->ipcReceivedShutdown || !$this->running) {
                $this->log('Master 连接断开（已收到 shutdown，不复活）', 'INFO');
                return;
            }
            $this->log('Master 连接意外断开，准备自愈协调 + 持续重连', 'WARN');
            $client->tryReconnect();

            // Master 自愈（由 MasterResurrectionCoordinator 编排 grace + attempt）
            try {
                $priority = $client->getResurrectionPriority();
                if ($priority > 0 && $this->instanceName !== '' && $this->controlPort > 0) {
                    (new \Weline\Server\IPC\MasterResurrectionCoordinator())
                        ->handleDisconnect($priority, $this->instanceName, $this->controlPort, $receivedShutdown);
                }
            } catch (\Throwable $e) {
                $this->log('[Dispatcher] Master 自愈触发失败: ' . $e->getMessage(), 'WARN');
            }
        });
        if (!$this->ipcClient->connect('127.0.0.1', $this->controlPort)) {
            $this->log("IPC 控制通道初次连接失败 (端口: {$this->controlPort})，将在启动宽限期内自动重连", 'WARN');
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
        
        // 上报就绪
        if ($sendReady) {
            $this->sendIpcReady();
        } else {
            $this->log('IPC registered with Master; READY deferred until dispatcher bootstrap is complete', 'INFO');
        }
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
     * - 先处理 IPC 收发（含 SET_ROUTE_TABLE）
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
            $this->deferredWorkerPoolFiberJob = $job;
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
            $this->deferredWorkerPoolFiberJob = null;
            return;
        }
        if ($fiber->isTerminated()) {
            $this->finalizeDeferredWorkerPoolFiber();
        }
    }

    /**
     * @param array{
     *   type: 'set_pool'|'homepage_warmup'|'probe_blacklisted_workers'|'audit_worker_health',
     *   ports?: int[],
     *   role?: string,
     *   claims?: array<int, array{port?: int, ticket?: int}>,
     *   source?: string
     * } $job
     */
    private function createDeferredWorkerPoolFiber(array $job): \Fiber
    {
        if ($job['type'] === 'set_pool') {
            $ports = $job['ports'];
            $role = (string)($job['role'] ?? ControlMessage::ROLE_WORKER);
            $preserveOnReject = $role !== ControlMessage::ROLE_MAINTENANCE;

            return new \Fiber(function () use ($ports, $preserveOnReject): array {
                $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
                try {
                    return $this->passthroughCore->setWorkerPorts($ports, $preserveOnReject);
                } finally {
                    $this->passthroughCore->setWarmupCooperativeYield(null);
                }
            });
        }

        if ($job['type'] === 'audit_worker_health') {
            return new \Fiber(function (): array {
                $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
                try {
                    return $this->passthroughCore->auditWorkerApplicationHealth();
                } finally {
                    $this->passthroughCore->setWarmupCooperativeYield(null);
                }
            });
        }

        if ($job['type'] === 'homepage_warmup') {
            $claims = \is_array($job['claims'] ?? null) ? $job['claims'] : [];

            return new \Fiber(function () use ($claims): array {
                $this->passthroughCore->setWarmupCooperativeYield($this->createWarmupCooperativeYieldCallback());
                try {
                    return $this->passthroughCore->warmupJoinedWorkersViaHomepage($claims);
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

    private function isSupervisorEnabled(): bool
    {
        $raw = \getenv('WLS_SUPERVISOR_ENABLED');
        if ($raw !== false && $raw !== '') {
            return \in_array(\strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
        }

        $instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
        if (!\is_file($instanceFile)) {
            return false;
        }

        $raw = @\file_get_contents($instanceFile);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        $data = @\json_decode($raw, true);
        if (!\is_array($data)) {
            return false;
        }

        if (isset($data['supervisor_enabled'])) {
            return (bool) $data['supervisor_enabled'];
        }

        return (string)($data['control_plane_mode'] ?? '') === 'hybrid';
    }

    private function createIpcClient(): ChildControlClientInterface
    {
        if ($this->isSupervisorEnabled()) {
            $channelId = (string) (\getenv('WLS_SUPERVISOR_CHANNEL') ?: $this->resolveSupervisorChannelId());
            $basePath = (string) (\getenv('WLS_SUPERVISOR_BASE_PATH') ?: BP);

            return new SupervisorChildClient(
                instanceName: $this->instanceName,
                channelId: $channelId,
                endpointResolver: new ControlEndpointResolver($basePath),
            );
        }

        return new ControlClient();
    }

    private function resolveSupervisorChannelId(): string
    {
        $instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
        if (\is_file($instanceFile)) {
            $raw = @\file_get_contents($instanceFile);
            $data = \is_string($raw) ? @\json_decode($raw, true) : null;
            if (\is_array($data)) {
                $channelId = (string)($data['supervisor_channel'] ?? '');
                if ($channelId !== '') {
                    return $channelId;
                }
            }
        }

        return 'channel-' . $this->instanceName;
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     */
    private function applyWorkerPoolSnapshot(array $workers, int $version, string $scope = 'business'): void
    {
        if ($scope !== 'business') {
            return;
        }

        if ($version <= 0 || $version <= $this->lastAppliedWorkerPoolSnapshotVersion) {
            return;
        }

        $ports = [];
        $acceptedWorkers = [];
        foreach ($workers as $worker) {
            $state = (string)($worker['state'] ?? '');
            $port = (int)($worker['port'] ?? 0);
            if ($state !== 'ready' || $port <= 0) {
                continue;
            }
            $ports[$port] = $port;
            $acceptedWorkers[] = $this->normalizeWorkerDescriptor($worker, ControlMessage::ROLE_WORKER);
        }

        $normalizedPorts = \array_values($ports);
        $this->deferredWorkerPoolJobs[] = [
            'type' => 'set_pool',
            'ports' => $normalizedPorts,
            'workers' => $acceptedWorkers,
            'role' => ControlMessage::ROLE_WORKER,
            'pool_snapshot_version' => $version,
            'pool_snapshot_scope' => $scope,
        ];
        $this->lastAppliedWorkerPoolSnapshotVersion = $version;

        if ($this->ipcClient !== null && $this->ipcClient->isConnected()) {
            $ack = ControlMessage::encode([
                'type' => ControlMessage::TYPE_POOL_SNAPSHOT_ACK,
                'scope' => $scope,
                'version' => $version,
                'accepted' => true,
            ]);
            $this->ipcClient->send($ack);
        }

        $this->log(
            '鏀跺埌 POOL_SNAPSHOT锛堝凡鍏ラ槦寮傛鍏ユ睜锛夛紝version=' . $version . ', workers=' . \count($normalizedPorts),
            'INFO'
        );
    }

    private function finalizeDeferredWorkerPoolFiber(): void
    {
        $fiber = $this->deferredWorkerPoolFiber;
        $kind = $this->deferredWorkerPoolFiberKind;
        $job = $this->deferredWorkerPoolFiberJob;
        $this->deferredWorkerPoolFiber = null;
        $this->deferredWorkerPoolFiberKind = null;
        $this->deferredWorkerPoolFiberJob = null;

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
            $role = (string)($job['role'] ?? ControlMessage::ROLE_WORKER);
            $source = (string)($job['source'] ?? 'SET_ROUTE_TABLE');
            $routeVersion = (int)($job['route_version'] ?? 0);
            $currentWorkerPoolSize = $this->passthroughCore->getWorkerCount();
            $this->updateMaintenanceFallbackState(
                $currentWorkerPoolSize === 0,
                $source . ' accepted=' . \count($acceptedPorts)
                . ', rejected=' . \count($rejectedPorts)
                . ', current_pool=' . $currentWorkerPoolSize
            );
            $this->log($source . ': ' . \implode(',', $acceptedPorts), 'INFO');
            if ($routeVersion > 0) {
                $this->currentRouteVersion = \max($this->currentRouteVersion, $routeVersion);
            }
            if ($rejectedPorts !== []) {
                $items = [];
                foreach ($rejectedPorts as $port => $reason) {
                    $items[] = "{$port}: {$reason}";
                }
                $this->log($source . ' warmup rejected ports: ' . \implode('; ', $items), 'ERROR');
            }
            if ($role === ControlMessage::ROLE_WORKER) {
                $requestedPorts = \is_array($job['ports'] ?? null) ? $job['ports'] : [];
                $requestedWorkers = \is_array($job['workers'] ?? null) ? $job['workers'] : [];
                $this->sendWorkerPoolAckForPorts($requestedPorts, $requestedWorkers);
                $this->queueJoinedWorkerHomepageWarmup($acceptedPorts, $source);
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
        if ($kind === 'audit_worker_health') {
            $this->handleWorkerHealthAuditResult(\is_array($payload) ? $payload : []);
            return;
        }

        if ($kind === 'homepage_warmup') {
            $warmed = \is_array($payload['warmed'] ?? null) ? $payload['warmed'] : [];
            $failed = \is_array($payload['failed'] ?? null) ? $payload['failed'] : [];
            $skipped = \is_array($payload['skipped'] ?? null) ? $payload['skipped'] : [];
            $source = (string)($job['source'] ?? 'homepage_warmup');

            if ($warmed !== []) {
                $this->log(
                    $source . ' 宸插畬鎴?Worker 棣栭〉棰勭儹: ' . \implode(',', \array_map('intval', $warmed)),
                    'INFO'
                );
            }
            if ($failed !== []) {
                $items = [];
                foreach ($failed as $port => $reason) {
                    $items[] = (int)$port . ': ' . (string)$reason;
                }
                $this->log(
                    $source . ' Worker 棣栭〉棰勭儹澶辫触锛堜笉褰卞搷宸插叆姹狅級: ' . \implode('; ', $items),
                    'WARN'
                );
            }
            if ($skipped !== []) {
                $this->log(
                    $source . ' Worker 棣栭〉棰勭儹宸茶烦杩囨棫绁ㄦ嵁/宸查€€姹? ' . \implode(',', \array_map('intval', $skipped)),
                    'DEBUG'
                );
            }
            if (\array_key_exists('ack_ports', $job)) {
                $ackPorts = \is_array($job['ack_ports'] ?? null) ? $job['ack_ports'] : [];
                $ackWorkers = \is_array($job['ack_workers'] ?? null) ? $job['ack_workers'] : [];
                $this->sendWorkerPoolAckForPorts($ackPorts, $ackWorkers);
            }
        }
    }

    /**
     * @param array{healthy?: int[], failed?: array<int|string, string>} $payload
     */
    private function handleWorkerHealthAuditResult(array $payload): void
    {
        $failed = \is_array($payload['failed'] ?? null) ? $payload['failed'] : [];
        if ($failed === []) {
            return;
        }

        $failedPorts = [];
        $failedReasons = [];
        foreach ($failed as $port => $reason) {
            $p = (int)$port;
            if ($p <= 0) {
                continue;
            }

            $affectedConnIds = $this->passthroughCore->removeWorkerPort($p);
            foreach ($affectedConnIds as $connId) {
                $this->closeConnection((int)$connId, 'worker_health_audit_failed');
            }
            $failedPorts[] = $p;
            $failedReasons[$p] = (string)$reason;
            $this->log(
                "Worker health audit failed: removed port {$p}, closed_connections=" . \count($affectedConnIds)
                . ', reason=' . (string)$reason,
                'ERROR'
            );
        }

        if ($failedPorts !== []) {
            $this->reportUnhealthyWorkersToMaster($failedPorts, $failedReasons);
        }
    }

    /**
     * @param int[] $failedPorts
     * @param array<int, string> $failedReasons
     */
    private function reportUnhealthyWorkersToMaster(array $failedPorts, array $failedReasons): void
    {
        if ($this->ipcClient === null || !$this->ipcClient->isConnected()) {
            return;
        }

        $businessPool = \array_values(\array_map('intval', $this->passthroughCore->getWorkerPorts()));
        $maintenanceCandidates = \array_values(\array_map('intval', $this->passthroughCore->getMaintenanceWorkerPorts()));
        \sort($businessPool, SORT_NUMERIC);
        \sort($maintenanceCandidates, SORT_NUMERIC);

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $this->ipcClient->send(ControlMessage::dispatcherAlert(
            $this->instanceName,
            'worker_health_probe_failed',
            [
                'dispatcher_port' => $this->port,
                'business_pool' => $businessPool,
                'maintenance_candidates' => $maintenanceCandidates,
                'maintenance_port' => $this->passthroughCore->getMaintenancePort(),
                'healthy' => (int)($healthSummary['healthy'] ?? 0),
                'total' => (int)($healthSummary['total'] ?? 0),
                'failed_ports' => \array_values(\array_unique(\array_map('intval', $failedPorts))),
                'failed_reasons' => $failedReasons,
            ],
            ControlMessage::ROLE_WORKER
        ));
    }

    /**
     * Dispatcher 侧闭环确认：逐个端口回执当前是否已在业务池中。
     * 即使端口已存在也会回执，便于 Worker 侧停止重报。
     *
     * @param int[] $ports
     * @param array<int, array<string, mixed>> $workers
     */
    private function sendWorkerPoolAckForPorts(array $ports, array $workers = []): void
    {
        if ($this->ipcClient === null || !$this->ipcClient->isConnected()) {
            return;
        }
        $pool = $this->passthroughCore->getWorkerPorts();
        $workersByPort = [];
        foreach ($workers as $worker) {
            if (!\is_array($worker)) {
                continue;
            }
            $descriptor = $this->normalizeWorkerDescriptor($worker, ControlMessage::ROLE_WORKER);
            $p = (int)($descriptor['port'] ?? 0);
            if ($p > 0) {
                $workersByPort[$p] = $descriptor;
            }
        }
        foreach ($ports as $port) {
            $p = (int)$port;
            if ($p <= 0) {
                continue;
            }
            $inPool = \in_array($p, $pool, true);
            $worker = $workersByPort[$p] ?? [];
            $this->ipcClient->send(ControlMessage::workerPoolAck(
                $p,
                $inPool,
                (string)($worker['role'] ?? ControlMessage::ROLE_WORKER),
                (string)($worker['slot_id'] ?? ''),
                (string)($worker['lease_id'] ?? ''),
                (int)($worker['generation'] ?? 0)
            ));
            $this->log(
                "Worker 入池回执: port={$p}, in_pool=" . ($inPool ? '1' : '0') . ", dispatcher_port={$this->port}",
                'DEBUG'
            );
        }
    }

    /**
     * Startup consensus is based on Master READY, not dispatcher-side probes.
     *
     * @param int[] $ports
     * @param array<int, array<string, mixed>> $workers
     */
    private function acceptWorkerPoolFromMasterReady(array $ports, array $workers, string $source): void
    {
        $result = $this->passthroughCore->setWorkerPortsFromMasterReady($ports);
        $acceptedPorts = \is_array($result['accepted'] ?? null) ? $result['accepted'] : [];
        $rejectedPorts = \is_array($result['rejected'] ?? null) ? $result['rejected'] : [];
        $this->lastWorkerProbeTime = \microtime(true);
        $currentWorkerPoolSize = $this->passthroughCore->getWorkerCount();
        $this->updateMaintenanceFallbackState(
            $currentWorkerPoolSize === 0,
            $source . ' trusted_master_ready accepted=' . \count($acceptedPorts)
            . ', rejected=' . \count($rejectedPorts)
            . ', current_pool=' . $currentWorkerPoolSize
        );
        $this->log(
            $source . ' 信任 Master READY 直接入池，跳过启动探活: ' . (\implode(',', $acceptedPorts) ?: '(空)'),
            'INFO'
        );
        $this->sendWorkerPoolAckForPorts($ports, $workers);
        $this->queueJoinedWorkerHomepageWarmup($acceptedPorts, $source);
    }

    /**
     * 把一份"维护池"端口列表同步到 PassthroughCore（含删除多余、新增缺失）。
     *
     * @param int[] $rawPorts 未规范化的维护池端口集合
     * @param string $source  来源标识（仅用于日志）
     */
    private function applyMaintenanceWorkerPoolSync(array $rawPorts, string $source): void
    {
        $normalizedPorts = [];
        foreach ($rawPorts as $port) {
            $p = (int)$port;
            if ($p > 0) {
                $normalizedPorts[$p] = $p;
            }
        }
        $normalizedPorts = \array_values($normalizedPorts);

        // 维护池只更新维护端口，不得覆盖业务 worker 池，
        // 否则会出现业务流量在「维护/正常」之间抖动。
        $result = $this->passthroughCore->setMaintenanceWorkerPortsFromMasterReady($normalizedPorts);
        $rejectedPorts = \is_array($result['rejected'] ?? null) ? $result['rejected'] : [];
        foreach ($rejectedPorts as $port => $reason) {
            $this->log("{$source} 维护端口注册失败: {$port} - {$reason}", 'WARN');
        }
        if ($this->ipcClient !== null && $this->ipcClient->isConnected()) {
            $currentMaintenancePorts = $this->passthroughCore->getMaintenanceWorkerPorts();
            foreach ($normalizedPorts as $port) {
                $p = (int)$port;
                $this->ipcClient->send(ControlMessage::workerPoolAck(
                    $p,
                    \in_array($p, $currentMaintenancePorts, true),
                    ControlMessage::ROLE_MAINTENANCE
                ));
            }
        }
        $this->log(
            "{$source}（maintenance）: 维护端口已同步，端口数: " . \count($normalizedPorts),
            'INFO'
        );
        $this->passthroughCore->setMaintenanceRoutingActive(true);
    }

    /**
     * 业务 Worker 池"全量切换"，由 SET_ROUTE_TABLE 调用。
     *
     * @param int[]                              $rawPorts
     * @param array<int, array<string, mixed>>   $workerDescriptors
     */
    private function applyBusinessWorkerPoolSwitch(
        array $rawPorts,
        array $workerDescriptors,
        string $source,
        int $version
    ): void {
        // 合并 workers[] 中标记 ready 的端口，避免 Master 端 ports/workers 短暂不一致。
        $ports = $rawPorts;
        if ($workerDescriptors !== []) {
            foreach ($workerDescriptors as $worker) {
                if ((string)($worker['state'] ?? 'ready') !== 'ready') {
                    continue;
                }
                $p = (int)($worker['port'] ?? 0);
                if ($p > 0) {
                    $ports[] = $p;
                }
            }
            $ports = \array_values(\array_unique(\array_map('intval', $ports)));
        }

        $this->acceptWorkerPoolFromMasterReady($ports, $workerDescriptors, $source);
        if ($version > 0) {
            $this->currentRouteVersion = $version;
        }
        $this->log(
            "{$source} applied Master READY route table: candidates=" . \count($ports) . ', role=' . ControlMessage::ROLE_WORKER . ', version=' . $version,
            'INFO'
        );
    }

    /**
     * @param int[] $ports
     * @param int[]|null $ackPorts
     * @param array<int, array<string, mixed>>|null $ackWorkers
     */
    private function queueJoinedWorkerHomepageWarmup(
        array $ports,
        string $source,
        ?array $ackPorts = null,
        ?array $ackWorkers = null
    ): bool
    {
        $claims = $this->passthroughCore->claimJoinedWorkerHomepageWarmup($ports);
        if ($claims === []) {
            return false;
        }

        $job = [
            'type' => 'homepage_warmup',
            'claims' => $claims,
            'source' => $source,
        ];
        if ($ackPorts !== null) {
            $job['ack_ports'] = $ackPorts;
            $job['ack_workers'] = $ackWorkers ?? [];
        }

        $this->deferredWorkerPoolJobs[] = $job;

        return true;
    }

    /**
     * @param array<string, mixed> $worker
     * @return array<string, mixed>
     */
    private function normalizeWorkerDescriptor(array $worker, string $defaultRole): array
    {
        return [
            'role' => (string)($worker['role'] ?? $defaultRole),
            'slot_id' => (string)($worker['slot_id'] ?? ''),
            'lease_id' => (string)($worker['lease_id'] ?? ''),
            'generation' => (int)($worker['generation'] ?? 0),
            'port' => (int)($worker['port'] ?? 0),
            'state' => (string)($worker['state'] ?? 'ready'),
        ];
    }

    /**
     * @param array<string, mixed> $msg
     * @return array<int, array<string, mixed>>
     */
    private function extractWorkerDescriptors(array $msg, string $defaultRole): array
    {
        $workers = \is_array($msg['workers'] ?? null) ? $msg['workers'] : [];
        $normalized = [];
        foreach ($workers as $worker) {
            if (!\is_array($worker)) {
                continue;
            }
            $descriptor = $this->normalizeWorkerDescriptor($worker, $defaultRole);
            if ((int)($descriptor['port'] ?? 0) <= 0) {
                continue;
            }
            $normalized[] = $descriptor;
        }

        return $normalized;
    }

    /**
     * Apply the authoritative route table published by Master.
     */
    private function handleSetRouteTableAsAuthority(array $msg): void
    {
        $role = (string)($msg['role'] ?? ControlMessage::ROLE_WORKER);
        $routeVersion = (int)($msg['route_version'] ?? 0);
        $remoteChecksum = (string)($msg['checksum'] ?? '');
        $epoch = (int)($msg['epoch'] ?? 0);
        $traceId = (string)($msg['trace_id'] ?? '');

        $rawPorts = \is_array($msg['ports'] ?? null) ? $msg['ports'] : [];
        $normalizedPorts = [];
        foreach ($rawPorts as $port) {
            $p = (int)$port;
            if ($p > 0) {
                $normalizedPorts[$p] = $p;
            }
        }
        $normalizedPorts = \array_values($normalizedPorts);
        \sort($normalizedPorts, \SORT_NUMERIC);

        $normalizedWorkers = $this->extractWorkerDescriptors($msg, $role);
        $localChecksum = ControlMessage::computeRouteTableChecksum(
            $role,
            $routeVersion,
            $epoch,
            $normalizedPorts,
            $normalizedWorkers
        );

        $status = 'applied';
        $reason = '';

        // 版本号去重：与业务路由源 currentRouteVersion 比较（B-ii 已统一以此为权威）
        if ($role === ControlMessage::ROLE_WORKER && $routeVersion > 0 && $routeVersion <= $this->currentRouteVersion) {
            $status = 'duplicate';
            $reason = 'old_or_same_version';
        } elseif ($routeVersion > 0
            && $routeVersion === $this->observedRouteTableVersion
            && $remoteChecksum !== ''
            && $remoteChecksum === $this->observedRouteTableChecksum) {
            $status = 'duplicate';
            $reason = 'same_version_and_checksum';
        } elseif ($remoteChecksum !== '' && $localChecksum !== $remoteChecksum) {
            $status = 'rejected';
            $reason = 'checksum_mismatch';
        }

        if ($status === 'applied') {
            // 记录新版本（无论 role 类型）
            $this->observedRouteTableVersion = $routeVersion;
            $this->observedRouteTableChecksum = $remoteChecksum !== '' ? $remoteChecksum : $localChecksum;

            // Apply the authoritative table to the role-specific worker pool.
            if ($role === ControlMessage::ROLE_MAINTENANCE) {
                $this->applyMaintenanceWorkerPoolSync($normalizedPorts, '收到 SET_ROUTE_TABLE');
                $this->passthroughCore->setMaintenanceRoutingActive(true);
            } else {
                $this->passthroughCore->setMaintenanceRoutingActive(false);
                $this->applyBusinessWorkerPoolSwitch(
                    $normalizedPorts,
                    $normalizedWorkers,
                    'SET_ROUTE_TABLE',
                    $routeVersion
                );
            }
        }

        $this->log(\sprintf(
            'SET_ROUTE_TABLE %s (authority): role=%s, version=%d%s, ports=%d, workers=%d, checksum=%s',
            $status,
            $role,
            $routeVersion,
            $reason !== '' ? ', reason=' . $reason : '',
            \count($normalizedPorts),
            \count($normalizedWorkers),
            $remoteChecksum !== '' ? \substr($remoteChecksum, 0, 12) : 'n/a'
        ), $status === 'rejected' ? 'WARN' : 'INFO');

        if ($this->ipcClient !== null && $this->ipcClient->isConnected()) {
            $this->ipcClient->send(ControlMessage::routeTableAck(
                $routeVersion,
                $remoteChecksum !== '' ? $remoteChecksum : $localChecksum,
                $status,
                $role,
                $epoch,
                $reason,
                $traceId
            ));
        }
    }

    private function handleIpcMessage(array $msg): void
    {
        $type = $msg['type'] ?? '';

        // 添加详细的 IPC 消息接收日志
        $timestamp = date('Y-m-d H:i:s');
        $this->log("[IPC-Recv] {$timestamp} type={$type} msg=" . json_encode($msg), 'DEBUG');

        // 帝王令：已收 shutdown 后不再处理 DRAIN / ROUTE_TABLE 等其他 IPC
        if ($type !== ControlMessage::TYPE_SHUTDOWN && $this->ipcReceivedShutdown) {
            return;
        }
        switch ($type) {
            case SupervisorMessage::TYPE_POOL_SNAPSHOT:
                $workers = \is_array($msg['workers'] ?? null) ? $msg['workers'] : [];
                $version = (int)($msg['version'] ?? 0);
                $scope = (string)($msg['scope'] ?? 'business');
                $this->applyWorkerPoolSnapshot($workers, $version, $scope);
                break;

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
                
            case ControlMessage::TYPE_SET_ROUTE_TABLE:
                $this->handleSetRouteTableAsAuthority($msg);
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
        $this->logMaintenanceOperation(
            'Dispatcher 启动后的维护路由初始状态：' . $this->formatMaintenanceRoutingContext(),
            'INFO',
            'dispatcher_start:' . $this->formatMaintenanceRoutingContext(),
            0.0
        );
        WlsLogger::flush_(true);
        
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
                $loopHeartbeatNow = \microtime(true);

                // 定期输出循环计数，便于直观看到主循环是否仍在推进
                if (
                    MainLoopUnblockedLogConfig::shouldEmit($loopCount, $this->mainLoopUnblockedLogEvery)
                    || MainLoopUnblockedLogConfig::shouldEmitByInterval(
                        $loopHeartbeatNow,
                        $this->lastMainLoopUnblockedLogAt,
                        $this->mainLoopUnblockedLogIntervalSec
                    )
                ) {
                    $this->lastMainLoopUnblockedLogAt = $loopHeartbeatNow;
                    $this->log("Dispatcher 主循环未被阻塞 #{$loopCount}", 'INFO');
                }

                // 信号处理
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                
                // IPC 控制通道：处理消息（非阻塞读取）
                $this->pumpIpcOnce();

                // 孤儿检测：定期检查 Master PID 是否存活
                $this->checkMasterPidAlive();
                
                // Worker 健康探活只负责入队，真正网络探活交由 deferred Fiber 分片执行。
                $this->probeWorkerHealth();

                // Worker 入池预热 / 黑名单探活：Fiber 分片推进，避免阻塞 IPC 与 accept
                $this->pumpDeferredWorkerPoolJobs();

                // SSL worker cold preconnect refill is incremental; never refill
                // synchronously inside a client accept hot path.
                $this->passthroughCore->tickSslBackendPreconnectPool(2);
                
                // 连接超时清理
                $this->cleanupExpiredConnections();

                // 推进首字节未到的 pending 维护页队列（P0-5）
                $this->pumpPendingMaintenancePageQueue();

                // 事件处理
                $this->selectAndProcess();
                
                // 定期统计
                $this->printStats();
                WlsLogger::tick_();
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
                WlsLogger::tick_();
            }
        }
        
        $this->shutdown();
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
        if (($now - $this->lastMasterPidCheck) < self::MASTER_PID_CHECK_INTERVAL_SEC) {
            return;
        }
        $this->lastMasterPidCheck = $now;

        // IPC 连接正常 → Master 存活，无需 PID 检测
        if ($this->ipcClient && $this->ipcClient->isConnected()) {
            $this->masterDeadCount = 0;
            return;
        }

        // IPC 断开，用 PID 检测确认 Master 是否真的死了
        // 注意：Windows 下 isRunningByPid 会执行 tasklist 命令（阻塞），但只在 IPC 断开时才执行
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
        } elseif ($this->isWindows()) {
            // Windows: 使用 Processer::isRunningByPid() 检测（会阻塞，但只在 IPC 断开时执行）
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
        $this->log(
            "Master PID {$this->masterPid} 不可达且 IPC 断开 ({$this->masterDeadCount}/" . self::MASTER_PID_DEAD_THRESHOLD . ")",
            'WARN'
        );
        if ($this->masterDeadCount >= self::MASTER_PID_DEAD_THRESHOLD) {
            $this->log("Master PID {$this->masterPid} 已死亡，Dispatcher 自行退出（孤儿保护）", 'ERROR');
            $this->running = false;
        }
    }

    private function isWindows(): bool
    {
        if (\defined('PHP_OS_FAMILY')) {
            return \PHP_OS_FAMILY === 'Windows';
        }

        return \stripos(\PHP_OS, 'WIN') === 0;
    }

    public function sendIpcReady(): bool
    {
        if ($this->ipcClient === null || !$this->ipcClient->isConnected()) {
            return false;
        }

        $sent = $this->ipcClient->sendReady(
            ControlMessage::ROLE_DISPATCHER,
            0,
            $this->port,
            $this->orchestratorEpoch,
            $this->orchestratorLaunchId
        );
        if ($sent) {
            $this->ipcClient->flushPendingWrites(0.05);
            $this->log('Dispatcher READY reported to Master', 'INFO');
        }

        return $sent;
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
        if (!$this->workerHealthAuditEnabled) {
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
        $this->deferredWorkerPoolJobs[] = ['type' => 'audit_worker_health'];
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
            if ($hasClientSocket
                && $clientInputClosed
                && $inBytes > 0
                && !$this->passthroughCore->hasBufferedData($this->clientConnections[$connId])
                && $elapsed > $this->clientHalfClosedRequestIdleTimeoutSec) {
                $this->logHalfClosedFastCloseIfNeeded($connId, 'request-timeout');
                $this->closeConnection($connId, 'client_half_closed_after_request_timeout');
                $closedCount++;
                continue;
            }

            if ($elapsed > $this->connectionTimeout) {
                // 客户端上行半关闭后仍可能等待 worker 长处理：
                // 这里不要简单按 connectionTimeout 直接关闭，改为续约。
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
        $writeSockets = [];
        $clientWriteSockets = [];
        $workerWriteSockets = [];
        
        // 添加所有客户端连接
        foreach ($this->clientConnections as $connId => $clientSocket) {
            // 客户端上行半关闭后，不再监听其可读事件（避免持续 EOF 触发误关连接）
            if (!$this->passthroughCore->isClientInputClosed($clientSocket)
                && !$this->passthroughCore->hasWorkerBufferedData($clientSocket)) {
                $readSockets[] = $clientSocket;
            }
            if ($this->passthroughCore->hasBufferedData($clientSocket)) {
                $writeSockets[] = $clientSocket;
                $clientWriteSockets[$this->socketId($clientSocket)] = $connId;
            }
            
            // 添加对应的 Worker 连接（如果 Worker 未关闭）
            $workerSocket = $this->passthroughCore->getWorkerSocket($clientSocket);
            if ($workerSocket !== null) {
                if ($this->passthroughCore->hasWorkerBufferedData($clientSocket)) {
                    $writeSockets[] = $workerSocket;
                    $workerWriteSockets[$this->socketId($workerSocket)] = $connId;
                } else {
                    $readSockets[] = $workerSocket;
                    $workerSockets[$this->socketId($workerSocket)] = $connId;
                }
            }
        }
        
        $exceptSockets = [];
        
        // socket_select 等待事件（如果有缓冲数据，缩短等待时间）
        $hasBuffers = !empty($this->passthroughCore->getPendingBufferConnIds());
        $hasWorkerBuffers = !empty($this->passthroughCore->getPendingWorkerBufferConnIds());
        $hasActiveConnections = !empty($this->clientConnections);
        $timeout = 0;
        $microTimeout = $hasBuffers ? 250 : 5000; // 活跃写缓冲用更短等待片，降低高并发转发尾延迟。
        if ($hasActiveConnections || $hasWorkerBuffers) {
            $microTimeout = 250;
        }
        
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

        foreach ($writeSockets as $socket) {
            $socketId = $this->socketId($socket);

            if (isset($workerWriteSockets[$socketId])) {
                $clientConnId = $workerWriteSockets[$socketId];
                if (isset($this->clientConnections[$clientConnId])) {
                    $this->flushWorkerBuffer($clientConnId);
                }
                continue;
            }

            if (isset($clientWriteSockets[$socketId])) {
                $clientConnId = $clientWriteSockets[$socketId];
                if (isset($this->clientConnections[$clientConnId])) {
                    $this->flushClientBufferForConnection($clientConnId);
                }
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
            if ($this->passthroughCore->hasWorkerBufferedData($this->clientConnections[$connId])) {
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
    private function flushClientBufferForConnection(int $connId): void
    {
        if (!isset($this->clientConnections[$connId])) {
            return;
        }

        $clientSocket = $this->clientConnections[$connId];
        $flushed = $this->passthroughCore->flushClientBuffer($clientSocket);

        if ($flushed === -1) {
            $this->closeConnection($connId, 'receive_request_failed');
            return;
        }

        if ($flushed > 0) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            $this->bytesCount['out'] += $flushed;
            if (isset($this->connectionBytes[$connId])) {
                $this->connectionBytes[$connId]['out'] += $flushed;
            }
        }

        if (!$this->passthroughCore->hasBufferedData($clientSocket)
            && $this->passthroughCore->isWorkerClosedWithBuffer($clientSocket)) {
            $this->closeConnection($connId, 'forward_to_worker_failed');
        }
    }

    private function flushWorkerBuffer(int $connId): void
    {
        if (!isset($this->clientConnections[$connId])) {
            return;
        }

        $clientSocket = $this->clientConnections[$connId];
        $flushed = $this->passthroughCore->flushWorkerBuffer($clientSocket);
        if ($flushed === -1) {
            $this->closeConnection($connId, 'forward_to_worker_failed');
            return;
        }
        if ($flushed > 0) {
            $this->connectionLastActivity[$connId] = \microtime(true);
        }
    }

    private function acceptConnections(): void
    {
        $accepted = 0;
        $maxAcceptPerLoop = $this->maxAcceptPerLoop;
        
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
            if ($this->shouldLogIngressDiagnostics()) {
                $this->log(
                    "[DispatcherIngress] ACCEPT client={$clientIp} connId={$connId} dispatcher_port={$this->port} active="
                    . \count($this->clientConnections),
                    'INFO'
                );
            }

            // Keep accept-path protocol probes non-blocking; otherwise concurrent
            // TLS handshakes serialize into dispatcher tail latency.
            \socket_set_nonblock($clientSocket);

            $fastTlsPath = $this->httpsEnabled
                && $this->fastTlsPathEnabled
                && $this->isAcceptedClientTlsHandshake($clientSocket);

            // ACME HTTP-01 must be answered before any HTTP->HTTPS redirect or worker routing.
            if (!$fastTlsPath && $this->tryServeAcmeHttp01Challenge($clientSocket, $connId, $clientIp)) {
                $accepted++;
                continue;
            }

            // HTTPS 模式：主端口收到明文 HTTP 时，直接返回 301 到 https://同主机同路径
            if (!$fastTlsPath && $this->httpsEnabled && $this->handlePlainHttpRedirect($clientSocket, $connId, $clientIp)) {
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
            
            if (!$fastTlsPath && $this->attackDetectionEnabled && !$isTrustedIp) {
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
            
            // 尝试建立到 Worker 的连接（含故障转移：失败时自动尝试其他 Worker）
            if ($this->passthroughCore->handleNewConnection($clientSocket, $clientIp)) {
                $this->registerAcceptedClientConnection($clientSocket, $clientIp, $connId);
                $this->pumpNewConnectionOnce($clientSocket, $connId);
            } else {
                $allWorkersUnavailable = $this->passthroughCore->lastNewConnectionEndedInAllWorkersDown();
                if ($allWorkersUnavailable) {
                    $this->reportAllWorkersUnavailableToMaster();
                    if (!$this->tryRespondWithStartupProtection($clientSocket, true, $clientIp, $connId)) {
                        @\socket_close($clientSocket);
                    }
                    $accepted++;
                    continue;
                }
                // 业务 Worker 与维护 Worker 均不可用时，立即返回 503（WLS 启动中），不再等待路由重试。
                if ($this->shouldReturnStartup503Immediately()) {
                    if (!$this->tryRespondWithStartupProtection($clientSocket, false, $clientIp, $connId)) {
                        @\socket_close($clientSocket);
                    }
                    $accepted++;
                    continue;
                }

                if ($this->tryWaitAndRouteUnavailableBackend($clientSocket, $clientIp, $connId)) {
                    $accepted++;
                    if (($accepted % 10) === 0) {
                        $this->pumpSpinWaitControlTick();
                    }
                    continue;
                }
                if ($this->shouldRespondWithStartupProtectionBeforeMaintenanceRouting()) {
                    if (!$this->tryRespondWithStartupProtection($clientSocket, false, $clientIp, $connId)) {
                        @\socket_close($clientSocket);
                    }
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
                            . "healthy: {$healthy}/{$total}, {$detail}, "
                            . $this->formatMaintenanceRoutingContext(), $logLevel);
                        $this->logMaintenanceOperation(
                            "请求命中维护/启动兜底前置条件：client={$clientIp}, connId={$connId}, detail={$detail}, "
                            . $this->formatMaintenanceRoutingContext(),
                            $logLevel,
                            "all_workers_unavailable:{$detail}:" . $this->formatMaintenanceRoutingContext()
                        );
                        $this->lastAllWorkersUnavailableLogAt = $now;
                    }
                }
                if ($this->shouldServeMaintenanceFallback()) {
                    if (!$this->tryRespondWithStartupProtection($clientSocket, $allWorkersUnavailable, $clientIp, $connId)) {
                        @\socket_close($clientSocket);
                    }
                } elseif (!$this->tryRespondServiceUnavailable($clientSocket, $allWorkersUnavailable, $clientIp, $connId)) {
                    // HTTPS/TLS 原始流无法返回明文 503，只能关闭连接
                    @\socket_close($clientSocket);
                } else {
                    // 已返回 503、关闭连接，或已入队等待首字节（pump 负责后续回写）
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
        $this->applyClientSocketKeepAlive($clientSocket);
        $this->clientConnections[$connId] = $clientSocket;
        $this->connectionAcceptTime[$connId] = \microtime(true);
        $this->connectionLastActivity[$connId] = \microtime(true);
        $this->connectionBytes[$connId] = ['in' => 0, 'out' => 0];
        $this->requestCount++;
        // P2 观测性埋点：此 hook 是 Dispatcher 三条"成功转发到 Worker"路径的汇合点，
        // 计入一次即覆盖「直接命中 / 维护 Worker 接管 / 重试后命中」全部子路径，避免重复计数。
        \Weline\Server\Observability\MetricsRegistry::inc('dispatcher.connection.dispatched');
        // 连接成功路由到 Worker 也意味着"至少有 1 个健康 Worker"事实成立（P1-4 latch）
        if (!$this->hasEverObservedHealthyWorker) {
            $this->hasEverObservedHealthyWorker = true;
            $this->healthyZeroSince = 0.0;
        }

        $workerPort = $this->passthroughCore->getConnectionWorkerPort($clientSocket);
        if ($this->isDevMode) {
            $this->log("新连接: {$clientIp} (connId: {$connId}) → Worker:{$workerPort}", 'ROUTE');
        }
    }

    /**
     * The accept path has already peeked the TLS ClientHello for SNI routing.
     * Forward the still-buffered bytes immediately instead of waiting for the
     * next socket_select tick; this trims cold-connection tail latency without
     * changing the passthrough protocol.
     */
    private function pumpNewConnectionOnce($clientSocket, int $connId): void
    {
        if (!isset($this->clientConnections[$connId])) {
            return;
        }

        $this->handleClientData($clientSocket);
    }

    /**
     * @param \Socket|resource $clientSocket
     */
    private function applyClientSocketKeepAlive($clientSocket): void
    {
        try {
            @\socket_set_option($clientSocket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
            if (\defined('TCP_NODELAY')) {
                @\socket_set_option($clientSocket, \SOL_TCP, (int)\TCP_NODELAY, 1);
            }
            if (\defined('TCP_KEEPIDLE')) {
                @\socket_set_option($clientSocket, \SOL_TCP, (int)\TCP_KEEPIDLE, self::CLIENT_TCP_KEEPALIVE_IDLE_SEC);
            }
            if (\defined('TCP_KEEPINTVL')) {
                @\socket_set_option($clientSocket, \SOL_TCP, (int)\TCP_KEEPINTVL, self::CLIENT_TCP_KEEPALIVE_INTERVAL_SEC);
            }
            if (\defined('TCP_KEEPCNT')) {
                @\socket_set_option($clientSocket, \SOL_TCP, (int)\TCP_KEEPCNT, self::CLIENT_TCP_KEEPALIVE_PROBES);
            }
        } catch (\Throwable) {
            // keep-alive 仅为增强项；平台不支持时保持原有转发路径。
        }
    }

    private function tryRouteToMaintenanceWorker($clientSocket, string $clientIp, int $connId): bool
    {
        // 维护 Worker 已通过维护路由表注册时，即使池已非空、启动保护已过期
        // （shouldServeMaintenanceFallback=false），首次 handleNewConnection 仍可能因瞬时不可连失败。
        // 此处必须继续自旋重试；否则 HTTPS 会落入 tryRespondServiceUnavailable→TLS 无法写 503→直接关断，
        // 浏览器表现为 ERR_CONNECTION_ABORTED。
        if (!$this->shouldAttemptMaintenanceWorkerRouting()) {
            return false;
        }

        $this->logMaintenanceOperation(
            "开始尝试维护接管：client={$clientIp}, connId={$connId}, retry_ticks={$this->maintenanceTakeoverRetryTicks}, "
            . $this->formatMaintenanceRoutingContext(),
            'INFO',
            'maintenance_takeover_attempt:' . $this->formatMaintenanceRoutingContext()
        );

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

        $this->logMaintenanceOperation(
            "维护接管未成功：client={$clientIp}, connId={$connId}, {$this->formatMaintenanceRoutingContext()}",
            'WARN',
            'maintenance_takeover_failed:' . $this->formatMaintenanceRoutingContext()
        );

        return false;
    }

    /**
     * 是否应对失败连接做「维护 Worker 接管」重试（含控制面 tick + 再次 handleNewConnection）。
     */
    private function shouldAttemptMaintenanceWorkerRouting(): bool
    {
        // 只有存在维护 Worker 端口时，维护接管重试才有意义。
        // 无维护候选时由 startup fallback/503 分支兜底，避免空转刷日志。
        $maintenancePorts = $this->passthroughCore->getMaintenanceWorkerPorts();
        if ($maintenancePorts === []) {
            return false;
        }

        if ($this->shouldServeMaintenanceFallback()) {
            return true;
        }

        return true;
    }

    private function shouldApplyStartupProtection(): bool
    {
        if (!$this->startupProtectionEnabled) {
            return false;
        }

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthy = (int)($healthSummary['healthy'] ?? 0);
        $dynamicTotal = (int)($healthSummary['total'] ?? 0);
        $this->latchHealthyObservation($healthy);

        // P1-4 修复：替代旧的「uptime <= startupProtectionWindowSec=45s」硬窗口判定。
        // 只要从未观察过健康 Worker，就视为"仍在启动中"，持续返回启动中维护页；
        // 避免冷启动超过 45 秒的场景直接丢维护页变成裸 503/断开。
        if (!$this->hasEverObservedHealthyWorker) {
            return true;
        }

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
        $healthy = (int)($healthSummary['healthy'] ?? 0);
        $this->latchHealthyObservation($healthy);

        if ($total <= 0) {
            // 还没有任何可路由后端（启动窗口/池未下发）→ 返回维护页而非断开连接
            return true;
        }

        // P1-5 修复：healthy==0 且 total>0（Worker 全挂但注册表尚未清理）持续 >= 阈值时，
        // 走维护页兜底而非裸 503 / 裸关断。
        $now = \microtime(true);
        if ($healthy <= 0) {
            if ($this->healthyZeroSince <= 0.0) {
                $this->healthyZeroSince = $now;
            }
            if (($now - $this->healthyZeroSince) >= $this->healthyZeroMaintenanceThresholdSec) {
                return true;
            }
        } else {
            $this->healthyZeroSince = 0.0;
        }

        // 增强：如果业务 Worker 健康数量为 0，且存在维护 Worker 端口（已注册但可能未就绪），
        // 且启动保护触发，应返回维护页面友好提示，而非关闭连接
        $maintenancePorts = $this->passthroughCore->getMaintenanceWorkerPorts();
        if ($healthy <= 0 && $maintenancePorts !== [] && $this->shouldApplyStartupProtection()) {
            return true;
        }

        return $this->shouldApplyStartupProtection();
    }

    /**
     * 捕获并持久化「曾经观察到至少 1 个健康 Worker」这一事实。
     *
     * 用于 {@see shouldApplyStartupProtection()} 在没有硬 uptime 窗口的前提下
     * 判断系统是否曾进入"正常服务"状态；一旦 latch 为 true，即从"启动中维护页"切换到"业务降级维护页"。
     */
    private function latchHealthyObservation(int $healthy): void
    {
        if ($healthy > 0 && !$this->hasEverObservedHealthyWorker) {
            $this->hasEverObservedHealthyWorker = true;
            $this->healthyZeroSince = 0.0;
        }
    }

    private function shouldRespondWithStartupProtectionBeforeMaintenanceRouting(): bool
    {
        if (!$this->shouldServeMaintenanceFallback() || $this->passthroughCore->getWorkerCount() > 0) {
            return false;
        }
        // 已注册维护 Worker 端口时必须先走 tryRouteToMaintenanceWorker（含自旋重试），
        // 不得在此处对明文 HTTP 写死 503；否则维护 Worker 已 listen 仍永远接不到连接，
        // HTTPS 还会在后续 tryRespondServiceUnavailable 失败后直接关断 → ERR_CONNECTION_ABORTED。
        if ($this->passthroughCore->getMaintenanceWorkerPorts() !== []) {
            return false;
        }

        return true;
    }

    /**
     * 当业务 Worker 与维护 Worker 均不可用时，立即返回启动中 503。
     *
     * 含「IPC 已下发业务端口但尚未 listen / 短时全挂」：池非空但 handleNewConnection 已走 all_workers_down。
     */
    private function shouldReturnStartup503Immediately(): bool
    {
        $maintenancePorts = $this->passthroughCore->getMaintenanceWorkerPorts();
        if ($this->passthroughCore->lastNewConnectionEndedInAllWorkersDown()) {
            return $maintenancePorts === [];
        }

        // PassthroughCore 已穷尽业务池、自旋与维护候选仍失败，且当前不存在维护候选
        // → 直接走启动中的明文 HTTP 维护页；TLS 原始流则由调用方关闭连接。
        if (!$this->shouldServeMaintenanceFallback()) {
            return false;
        }

        $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
        $healthy = (int)($healthSummary['healthy'] ?? 0);
        if ($healthy > 0) {
            return false;
        }

        $workerPoolSize = $this->passthroughCore->getWorkerCount();
        if ($workerPoolSize > 0) {
            return false;
        }

        return true;
    }

    /**
     * 在后端尚未可路由的短窗口内，先 pump 控制面并重试一次路由。
     * 默认不再同步自旋等待（backendRouteWaitTimeoutSec=0），启动兜底交给 pending 维护页队列。
     *
     * @param \Socket|resource $clientSocket
     */
    private function tryWaitAndRouteUnavailableBackend($clientSocket, string $clientIp, int $connId): bool
    {
        if ($this->backendRouteWaitTimeoutSec <= 0.0) {
            $this->pumpSpinWaitControlTick();
            if ($this->passthroughCore->handleNewConnection($clientSocket, $clientIp)) {
                $this->registerAcceptedClientConnection($clientSocket, $clientIp, $connId);
                return true;
            }
            if ($this->tryRouteToMaintenanceWorker($clientSocket, $clientIp, $connId)) {
                return true;
            }

            return false;
        }

        if (!$this->shouldServeMaintenanceFallback()) {
            return false;
        }

        $deadline = \microtime(true) + $this->backendRouteWaitTimeoutSec;
        while (\microtime(true) < $deadline) {
            $this->pumpSpinWaitControlTick();
            if ($this->passthroughCore->handleNewConnection($clientSocket, $clientIp)) {
                $this->registerAcceptedClientConnection($clientSocket, $clientIp, $connId);
                return true;
            }
            if ($this->tryRouteToMaintenanceWorker($clientSocket, $clientIp, $connId)) {
                return true;
            }
            SchedulerSystem::usleep(50_000);
        }

        return false;
    }

    private function buildFriendlyStartupMaintenancePage(bool $includeAllWorkersUnavailableDevOverlay = false): string
    {
        $body = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <title>WLS正在启动中...</title>
    <style>
        :root {
            color-scheme: light;
            --bg-top: #f6efe4;
            --bg-bottom: #fffdf9;
            --card: rgba(255,255,255,0.88);
            --border: rgba(120,93,55,0.16);
            --text: #2b241c;
            --muted: #75624c;
            --accent: #b06a2f;
            --accent-soft: rgba(176,106,47,0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: linear-gradient(180deg, var(--bg-top) 0%, var(--bg-bottom) 100%);
            color: var(--text);
        }
        .panel {
            width: min(560px, 100%);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 32px;
            background: var(--card);
            box-shadow: 0 24px 80px rgba(88, 62, 31, 0.10);
            text-align: center;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 14px;
            color: var(--accent);
            background: var(--accent-soft);
        }
        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 0 rgba(176,106,47,0.45);
            animation: pulse 1.6s infinite;
        }
        h1 {
            margin: 22px 0 14px;
            font-size: clamp(30px, 5vw, 42px);
            line-height: 1.1;
        }
        p {
            margin: 0;
            font-size: 16px;
            line-height: 1.8;
            color: var(--muted);
        }
        .hint {
            margin-top: 22px;
            font-size: 14px;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(176,106,47,0.45); }
            70% { box-shadow: 0 0 0 12px rgba(176,106,47,0.0); }
            100% { box-shadow: 0 0 0 0 rgba(176,106,47,0.0); }
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="badge"><span class="dot"></span><span>业务 Worker 启动中</span></div>
        <h1>WLS正在启动中...</h1>
        <p>业务 Worker 正在初始化，系统正在检测维护 Worker 并切换入口。</p>
        <p>如果已有维护 Worker 就绪，请求会自动转接；否则本页会每隔数秒自动刷新，直至服务恢复。</p>
        <p class="hint">这是一个临时提示。系统启动完成后会自动恢复正常服务，无需手动反复刷新。</p>
    </main>
    <script>
    (function () {
        var intervalMs = 5000;
        var timer = null;

        function startAutoReload() {
            if (timer !== null || document.hidden) {
                return;
            }
            timer = window.setInterval(function () {
                if (!document.hidden) {
                    window.location.reload();
                }
            }, intervalMs);
        }

        function stopAutoReload() {
            if (timer === null) {
                return;
            }
            window.clearInterval(timer);
            timer = null;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopAutoReload();
                return;
            }
            startAutoReload();
        });

        startAutoReload();
    })();
    </script>
</body>
</html>
HTML;

        if ($includeAllWorkersUnavailableDevOverlay) {
            $body = \str_replace('</body>', $this->buildAllWorkersUnavailableDevOverlay() . "\n</body>", $body);
        }

        $contentLength = \strlen($body);

        return "HTTP/1.1 503 Service Unavailable\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Length: {$contentLength}\r\n"
            . "Cache-Control: no-store, no-cache, must-revalidate\r\n"
            . "Pragma: no-cache\r\n"
            . "Retry-After: 5\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
    }

    private function buildAllWorkersUnavailableDevOverlay(): string
    {
        return <<<'HTML'
    <aside class="wls-dev-alert" role="status" aria-live="polite" style="position:fixed;right:18px;bottom:18px;z-index:2147483647;max-width:min(420px,calc(100vw - 36px));padding:14px 16px;border:1px solid #fca5a5;border-left:6px solid #dc2626;border-radius:10px;background:#fee2e2;color:#7f1d1d;box-shadow:0 18px 50px rgba(127,29,29,0.22);text-align:left;font-size:14px;line-height:1.55;">
        <strong style="display:block;margin-bottom:4px;color:#991b1b;font-size:15px;">DEV：当前所有 Worker 不可用</strong>
        <span>Dispatcher 已进入维护页响应。请检查 Worker 自检、IPC 入池、端口占用和 Master 复活队列。</span>
    </aside>
HTML;
    }
    private function resolveFallbackMaintenancePage(bool $allWorkersUnavailable = false): string
    {
        if (!$allWorkersUnavailable || !$this->isDevMode) {
            return $this->fallbackMaintenancePage;
        }

        return $this->buildFriendlyStartupMaintenancePage(true);
    }

    /**
     * 启动保护响应：在可控窗口内明确返回 503，避免客户端表现为随机断连。
     *
     * P0-5 修复：刚 accept 的 non-blocking socket 首字节未到时不再立即 close
     * （浏览器会看到 ERR_EMPTY_RESPONSE），而是入队由主循环 pumpPendingMaintenancePageQueue
     * 稍后再 peek；首字节到达后再写维护页，超时（pendingMaintenanceWaitTimeoutSec）才关闭。
     *
     * 返回值语义：
     *   true  — 已写维护页并关闭，或已入队（调用方无需 close）
     *   false — 检测到 TLS 握手（0x16）或入队已满，调用方应自行 close
     *
     * @param \Socket|resource $clientSocket
     */
    private function tryRespondWithStartupProtection(
        $clientSocket,
        bool $allWorkersUnavailable = false,
        string $clientIp = '',
        int $connId = 0
    ): bool {
        return $this->respondOrEnqueueMaintenancePage($clientSocket, $allWorkersUnavailable, $clientIp, $connId);
    }

    /**
     * 在非 TLS 的 HTTP 请求上返回 503，避免浏览器表现为 ERR_CONNECTION_CLOSED。
     *
     * 语义与 {@see tryRespondWithStartupProtection()} 一致；两者均统一走
     * {@see respondOrEnqueueMaintenancePage()} 以保证新 accept 的 non-blocking socket
     * 可以被可靠地回写维护页（P0-5）。
     *
     * @param \Socket|resource $clientSocket
     */
    private function tryRespondServiceUnavailable(
        $clientSocket,
        bool $allWorkersUnavailable = false,
        string $clientIp = '',
        int $connId = 0
    ): bool {
        return $this->respondOrEnqueueMaintenancePage($clientSocket, $allWorkersUnavailable, $clientIp, $connId);
    }

    /**
     * 统一的维护页写入 / 入队策略（P0-5 核心）。
     *
     * 行为：
     *   1) peek 首字节为 TLS(0x16) → 返回 false，由调用方关闭（TLS 无法写明文 503）。
     *   2) peek 到非 TLS 首字节 → 立即写维护页并关闭，返回 true。
     *   3) peek 返回 0（对端已关闭）→ 关闭 socket，返回 true。
     *   4) peek 返回 false（非阻塞 EAGAIN，首字节尚未到达）→ 入队等待，返回 true。
     *   5) 队列已满 → 返回 false，让调用方关闭。
     *
     * @param \Socket|resource $clientSocket
     */
    private function respondOrEnqueueMaintenancePage(
        $clientSocket,
        bool $allWorkersUnavailable,
        string $clientIp,
        int $connId
    ): bool {
        // P2 观测性埋点：所有进入"维护页 / 503 启动保护 / all-workers-down"兜底路径的连接都在此汇合。
        // 和 `registerAcceptedClientConnection` 中的 dispatched 计数互斥（要么成功路由要么兜底），
        // 运维侧 "dispatched vs. degraded" 比值 = 当前 Dispatcher 健康度。
        \Weline\Server\Observability\MetricsRegistry::inc(
            $allWorkersUnavailable
                ? 'dispatcher.connection.all_workers_down'
                : 'dispatcher.connection.startup_protected'
        );

        $peek = '';
        $peekLen = @\socket_recv($clientSocket, $peek, 8, \MSG_PEEK);

        if ($peekLen !== false && $peekLen > 0 && $peek !== '' && $this->isTlsHandshakePeek($peek)) {
            return false;
        }

        if ($peekLen !== false && $peekLen > 0 && $peek !== '') {
            $this->writeMaintenancePageAndClose($clientSocket, $allWorkersUnavailable);
            return true;
        }

        if ($peekLen === 0) {
            // 对端已关闭写端或已 RST；直接回收即可
            @\socket_close($clientSocket);
            return true;
        }

        // peekLen === false：非阻塞 socket 上首字节尚未到达。
        // 旧实现会在此直接关闭连接，导致客户端收到 ERR_EMPTY_RESPONSE（P0-5）。
        // 现改为入队由主循环异步推进，首字节到达或超时后再写页/关闭。
        return $this->enqueuePendingMaintenancePage($clientSocket, $allWorkersUnavailable, $clientIp, $connId);
    }

    /**
     * 写维护页 + 优雅关闭。
     *
     * Windows 下 closesocket() 时若接收缓冲仍有未读字节（例如刚 peek 了请求首字节），
     * 内核会发 **RST** 而非 FIN，浏览器表现为 ERR_CONNECTION_RESET 读不到 503 页面。
     * 这里在写完响应后：
     *   1) `socket_shutdown($sock, 1)` 发送 FIN，通知对端"响应已发完"；
     *   2) 短暂 drain 对端剩余请求数据（最多 50ms，遇到 EAGAIN 立刻返回），消除 RST 风险；
     *   3) `socket_close` 回收。
     *
     * 此路径只在维护页兜底时走，低吞吐路径可以容忍 drain 开销；
     * 正常业务连接由 PassthroughCore 转发，不会走这里。
     *
     * @param \Socket|resource $clientSocket
     */
    private function writeMaintenancePageAndClose($clientSocket, bool $allWorkersUnavailable): void
    {
        $response = $this->resolveFallbackMaintenancePage($allWorkersUnavailable);
        $remaining = \strlen($response);
        $offset = 0;
        while ($remaining > 0) {
            $written = @\socket_write($clientSocket, \substr($response, $offset), $remaining);
            if ($written === false || $written === 0) {
                break;
            }
            $offset += $written;
            $remaining -= $written;
        }

        // SHUT_WR = 1：发送 FIN 但保留读端继续抽干，避免 Windows 下 closesocket 发 RST
        @\socket_shutdown($clientSocket, 1);

        $drainDeadline = \microtime(true) + 0.05;
        while (\microtime(true) < $drainDeadline) {
            $read = [$clientSocket];
            $write = null;
            $except = null;
            $ready = @\socket_select($read, $write, $except, 0, 5_000);
            if ($ready === false || $ready === 0) {
                break;
            }
            $buf = '';
            $n = @\socket_recv($clientSocket, $buf, 4096, 0);
            if ($n === false || $n <= 0) {
                break;
            }
        }

        @\socket_close($clientSocket);
    }

    /**
     * 把首字节未到的连接入 pending 维护页队列。
     *
     * @param \Socket|resource $clientSocket
     */
    private function enqueuePendingMaintenancePage(
        $clientSocket,
        bool $allWorkersUnavailable,
        string $clientIp,
        int $connId
    ): bool {
        if (\count($this->pendingMaintenancePageQueue) >= $this->pendingMaintenancePageQueueMax) {
            return false;
        }
        $key = $connId > 0 ? $connId : $this->socketId($clientSocket);
        if (isset($this->pendingMaintenancePageQueue[$key])) {
            // 防止同一 connId 重复入队（同一 socket 指针）
            return true;
        }
        $this->pendingMaintenancePageQueue[$key] = [
            'socket' => $clientSocket,
            'clientIp' => $clientIp !== '' ? $clientIp : '0.0.0.0',
            'acceptedAt' => \microtime(true),
            'allWorkersUnavailable' => $allWorkersUnavailable,
        ];
        return true;
    }

    /**
     * 主循环每 tick 推进 pending 维护页队列：
     *   - 首字节到达且非 TLS → 写维护页并关闭
     *   - 首字节是 TLS(0x16) → 直接关闭（TLS 原始流无法写明文 503）
     *   - 对端断开（peekLen===0）→ 关闭
     *   - 超时（pendingMaintenanceWaitTimeoutSec）→ 关闭
     *   - 其余（EAGAIN）→ 保留等下一 tick
     */
    private function pumpPendingMaintenancePageQueue(): void
    {
        if ($this->pendingMaintenancePageQueue === []) {
            return;
        }

        $now = \microtime(true);
        foreach ($this->pendingMaintenancePageQueue as $key => $entry) {
            $sock = $entry['socket'];

            $peek = '';
            $peekLen = @\socket_recv($sock, $peek, 8, \MSG_PEEK);

            if ($peekLen !== false && $peekLen > 0 && $peek !== '' && $this->isTlsHandshakePeek($peek)) {
                @\socket_close($sock);
                unset($this->pendingMaintenancePageQueue[$key]);
                continue;
            }

            if ($peekLen !== false && $peekLen > 0 && $peek !== '') {
                $this->writeMaintenancePageAndClose($sock, $entry['allWorkersUnavailable']);
                unset($this->pendingMaintenancePageQueue[$key]);
                continue;
            }

            if ($peekLen === 0) {
                @\socket_close($sock);
                unset($this->pendingMaintenancePageQueue[$key]);
                continue;
            }

            // peekLen === false（EAGAIN）→ 超时检查
            if (($now - $entry['acceptedAt']) >= $this->pendingMaintenanceWaitTimeoutSec) {
                @\socket_close($sock);
                unset($this->pendingMaintenancePageQueue[$key]);
            }
        }
    }

    private function isTlsHandshakePeek(string $peek): bool
    {
        return $peek !== '' && \ord($peek[0]) === 0x16;
    }

    private function isAcceptedClientTlsHandshake($clientSocket): bool
    {
        $peek = '';
        $peekLen = @\socket_recv($clientSocket, $peek, 5, \MSG_PEEK);

        return $peekLen !== false
            && $peekLen > 0
            && $peek !== ''
            && $this->isTlsHandshakePeek($peek);
    }

    private function tryServeAcmeHttp01Challenge($clientSocket, int $connId, string $clientIp): bool
    {
        $peek = '';
        $peekLen = @\socket_recv($clientSocket, $peek, 4096, \MSG_PEEK);
        if ($peekLen === false || $peekLen <= 0 || $peek === '' || $this->isTlsHandshakePeek($peek)) {
            return false;
        }

        $request = $this->parseAcmeHttp01Request($peek);
        if ($request === null) {
            return false;
        }

        $raw = $this->readHttpRequestHeader($clientSocket, 0.5);
        if ($raw === '') {
            @\socket_close($clientSocket);
            return true;
        }

        $request = $this->parseAcmeHttp01Request($raw) ?? $request;
        $host = $this->extractHttpHostForAcme($raw, (string)$request['target']);
        $body = $this->resolveAcmeHttp01ChallengeBody($host, (string)$request['token']);

        if ($body === null) {
            $body = 'ACME challenge not found';
            $this->writeAcmeHttpResponseAndClose($clientSocket, '404 Not Found', $body, (string)$request['method'] === 'HEAD');
            $this->log("ACME HTTP-01 challenge not found: host={$host}, token={$request['token']}, client={$clientIp}, connId={$connId}", 'WARN');
            return true;
        }

        $this->writeAcmeHttpResponseAndClose($clientSocket, '200 OK', $body, (string)$request['method'] === 'HEAD');
        $this->log("ACME HTTP-01 challenge served by Dispatcher: host={$host}, token={$request['token']}, client={$clientIp}, connId={$connId}", 'ROUTE');
        return true;
    }

    /**
     * @return array{method:string,target:string,path:string,token:string}|null
     */
    private function parseAcmeHttp01Request(string $raw): ?array
    {
        if (!\preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?/i', $raw, $m)) {
            return null;
        }

        $method = \strtoupper((string)$m[1]);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $target = (string)$m[2];
        $path = $target;
        if (\preg_match('/^https?:\/\//i', $target)) {
            $parsedPath = \parse_url($target, \PHP_URL_PATH);
            $path = \is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
        } else {
            $queryAt = \strpos($target, '?');
            if ($queryAt !== false) {
                $path = \substr($target, 0, $queryAt);
            }
        }

        if (!\preg_match('#^/\.well-known/acme-challenge/([A-Za-z0-9_-]+)/?$#', $path, $matches)) {
            return null;
        }

        return [
            'method' => $method,
            'target' => $target,
            'path' => $path,
            'token' => (string)$matches[1],
        ];
    }

    private function readHttpRequestHeader($clientSocket, float $timeoutSec): string
    {
        @\socket_set_nonblock($clientSocket);
        $raw = '';
        $deadline = \microtime(true) + \max(0.05, $timeoutSec);

        while (\strpos($raw, "\r\n\r\n") === false && \strlen($raw) < 65536) {
            if (\microtime(true) >= $deadline) {
                break;
            }

            $read = [$clientSocket];
            $write = $except = [];
            $ready = @\socket_select($read, $write, $except, 0, 50000);
            if ($ready > 0 && \in_array($clientSocket, $read, true)) {
                $chunk = '';
                $bytes = @\socket_recv($clientSocket, $chunk, 8192, 0);
                if ($bytes === false || $bytes <= 0) {
                    break;
                }
                $raw .= $chunk;
            }
        }

        return $raw;
    }

    private function extractHttpHostForAcme(string $raw, string $target): string
    {
        $host = '';
        if (\preg_match('/^https?:\/\//i', $target)) {
            $parsedHost = \parse_url($target, \PHP_URL_HOST);
            if (\is_string($parsedHost)) {
                $host = $parsedHost;
            }
        }

        if ($host === '' && \preg_match('/(?:^|\r\n)Host:\s*([^\r\n]+)/i', $raw, $m)) {
            $host = \trim((string)$m[1]);
        }

        $host = \trim($host);
        if ($host === '') {
            return '';
        }

        if ($host[0] === '[' && \preg_match('/^\[([^\]]+)\]/', $host, $m)) {
            $host = (string)$m[1];
        } elseif (\strpos($host, ':') !== false) {
            $host = \explode(':', $host, 2)[0];
        }

        return \strtolower(\rtrim(\trim($host), '.'));
    }

    private function resolveAcmeHttp01ChallengeBody(string $host, string $token): ?string
    {
        $dir = \rtrim(BP, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR . 'generated'
            . \DIRECTORY_SEPARATOR . 'acme-http01'
            . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            return null;
        }

        $checked = [];
        if ($host !== '') {
            $file = $dir . SslCertificateService::domainToAcmeChallengeFilename($host) . '.json';
            $checked[$file] = true;
            $body = $this->readAcmeHttp01ChallengeFile($file, $token);
            if ($body !== null) {
                return $body;
            }
        }

        $files = \glob($dir . '*.json') ?: [];
        foreach ($files as $file) {
            if (isset($checked[$file])) {
                continue;
            }
            $body = $this->readAcmeHttp01ChallengeFile((string)$file, $token);
            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    private function readAcmeHttp01ChallengeFile(string $file, string $token): ?string
    {
        if (!\is_file($file)) {
            return null;
        }

        $json = @\file_get_contents($file);
        if ($json === false || $json === '') {
            return null;
        }

        $data = \json_decode($json, true);
        if (!\is_array($data)
            || (string)($data['token'] ?? '') !== $token
            || !isset($data['keyAuth'])
            || !\is_string($data['keyAuth'])
        ) {
            return null;
        }

        return $data['keyAuth'];
    }

    private function writeAcmeHttpResponseAndClose($clientSocket, string $status, string $body, bool $headOnly): void
    {
        $responseBody = $headOnly ? '' : $body;
        $response = "HTTP/1.1 {$status}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Cache-Control: no-store\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $responseBody;

        $toWrite = \strlen($response);
        $written = 0;
        $deadline = \microtime(true) + 0.5;
        while ($written < $toWrite && \microtime(true) < $deadline) {
            $write = [$clientSocket];
            $read = $except = [];
            $ready = @\socket_select($read, $write, $except, 0, 50000);
            if ($ready > 0 && \in_array($clientSocket, $write, true)) {
                $bytes = @\socket_send($clientSocket, \substr($response, $written), $toWrite - $written, 0);
                if ($bytes === false || $bytes <= 0) {
                    break;
                }
                $written += $bytes;
            }
        }

        @\socket_close($clientSocket);
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
            if ($result > 0 && $this->shouldLogHotPathDiagnostics()) {
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
            $this->logConnectionCloseSummary($connId, $clientSocket, $reason);
            
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

    private function logConnectionCloseSummary(int $connId, $clientSocket, string $reason): void
    {
        $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket) ?? [];
        $terminalReason = $this->passthroughCore->peekConnectionTerminalReasonByConnId($connId);
        $bytes = $this->connectionBytes[$connId] ?? ['in' => 0, 'out' => 0];
        $inBytes = (int)($bytes['in'] ?? 0);
        $outBytes = (int)($bytes['out'] ?? 0);
        $isAbnormal = $this->isConnectionCloseReasonAbnormal($reason, $terminalReason, $inBytes, $outBytes);

        if (!$isAbnormal && !$this->shouldLogIngressDiagnostics()) {
            return;
        }

        $acceptedAt = (float)($this->connectionAcceptTime[$connId] ?? 0.0);
        $ageMs = $acceptedAt > 0.0 ? \round((\microtime(true) - $acceptedAt) * 1000, 1) : 0.0;
        $clientIp = (string)($connInfo['client_ip'] ?? 'unknown');
        $workerPort = $connInfo['worker_port'] ?? null;
        $worker = $workerPort !== null ? (string)$workerPort : 'unknown';
        $requestLine = $this->sanitizeDiagnosticValue((string)($connInfo['request_line'] ?? ''));
        $responseLine = $this->sanitizeDiagnosticValue((string)(
            $connInfo['response_status_line']
            ?? $connInfo['response_first_line']
            ?? ''
        ));

        $parts = [
            'connId=' . $connId,
            'client=' . $clientIp,
            'worker=' . $worker,
            'reason=' . $reason,
            'terminal=' . ($terminalReason ?: 'none'),
            'bytes_in=' . $inBytes,
            'bytes_out=' . $outBytes,
            'age_ms=' . $ageMs,
        ];

        if ($requestLine !== '') {
            $parts[] = 'request="' . $requestLine . '"';
        }
        if ($responseLine !== '') {
            $parts[] = 'response="' . $responseLine . '"';
        }

        $this->log('[DispatcherIngress] CLOSE ' . \implode(' ', $parts), $isAbnormal ? 'WARN' : 'INFO');
        if ($isAbnormal) {
            WlsLogger::flush_(true);
        }
    }

    private function isConnectionCloseReasonAbnormal(
        string $reason,
        ?string $terminalReason,
        int $inBytes,
        int $outBytes
    ): bool {
        $terminalReason = (string)$terminalReason;

        if ($reason === 'worker_closed_or_client_disconnected'
            && $terminalReason === 'forward_to_client_worker_eof_without_buffer') {
            return $outBytes <= 0;
        }

        if (\in_array($reason, [
            'client_half_closed_without_request',
            'client_half_closed_without_request_timeout',
            'connection_timeout',
        ], true)) {
            return false;
        }

        if (\str_contains($terminalReason, 'error')
            || \str_contains($terminalReason, 'timeout')
            || \str_contains($terminalReason, 'missing_connection')
            || \str_contains($terminalReason, 'without_buffer')) {
            return true;
        }

        return \in_array($reason, [
            'worker_health_audit_failed',
            'worker_removed',
            'client_half_closed_after_request_timeout',
            'stalled_first_response_timeout',
            'receive_request_failed',
            'forward_to_worker_failed',
            'forward_to_client_failed',
            'socket_select_exception_or_read_error',
        ], true) || ($inBytes > 0 && $outBytes <= 0);
    }

    private function sanitizeDiagnosticValue(string $value, int $maxLength = 200): string
    {
        $value = (string)\preg_replace('/[[:cntrl:]]+/', ' ', $value);
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        return \substr($value, 0, $maxLength);
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
        if (!$this->shouldLogIngressDiagnostics()) {
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

    private function shouldLogIngressDiagnostics(): bool
    {
        return $this->shouldLogHotPathDiagnostics()
            || (\defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE);
    }

    private function shouldLogHotPathDiagnostics(): bool
    {
        return \Weline\Server\Log\LogConfig::isVerboseWlsLog()
            || (bool)\Weline\Framework\App\Env::get('wls.debug.hot_path_logs', false);
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

        // 关闭 pending 维护页队列中未完成的连接（P0-5）
        foreach ($this->pendingMaintenancePageQueue as $entry) {
            @\socket_close($entry['socket']);
        }
        $this->pendingMaintenancePageQueue = [];

        // 关闭透传核心中的所有连接
        $this->passthroughCore->closeAllConnections();
        
        // 关闭服务器 socket
        @\socket_close($this->serverSocket);
        
        // Master owns process-record cleanup; dispatcher exit must not block on
        // shared PID/name/port index locks.
        
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
