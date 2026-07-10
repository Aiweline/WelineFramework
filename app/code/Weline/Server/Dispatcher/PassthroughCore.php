<?php
declare(strict_types=1);

/**
 * Weline Server - TCP 透传核心
 *
 * 实现 Dispatcher 的 TCP 透传逻辑：
 * 1. 接收客户端连接
 * 2. Peek ClientHello 提取 SNI（可选）
 * 3. 查询路由缓存决定目标 Worker
 * 4. 建立到 Worker 的连接
 * 5. 双向透传数据
 * 6. 从 Worker 响应中学习路由信息
 *
 * 架构要点：
 * - Dispatcher 不做 SSL 握手，只做 TCP 透传
 * - SSL 握手由 Worker 完成
 * - 路由决策基于 SNI + IP + 连接缓存
 *
 * 调度说明：本类内 SchedulerSystem::usleep 用于写重试自旋。Dispatcher 进程无 Fiber 请求上下文，
 * 应在 Dispatcher::run() 入口调用 SchedulerSystem::disableScheduler()，使此处为真实微秒级休眠。
 * IPC 入池探活/首页预热路径可通过 setWarmupCooperativeYield() 注册回调（通常为 Fiber::suspend），
 * 由 Dispatcher 主循环分片 resume，避免长时间占用 handleIpcMessage。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\InternalRequestLabel;

// 确保 SOCKET_EAGAIN 常量存在（Windows 兼容）
if (!\defined('SOCKET_EAGAIN')) {
    \define('SOCKET_EAGAIN', 11);
}

class PassthroughCore
{
    /**
     * WOULDBLOCK 错误码列表（跨平台）
     * - 11: EAGAIN/EWOULDBLOCK (Linux/Unix)
     * - 10035: WSAEWOULDBLOCK (Windows)
     * - 10060: WSAETIMEDOUT (Windows)
     */
    private const WOULDBLOCK_ERRORS = [11, 10035, 10060];
    
    /**
     * Worker 连续失败多少次后加入黑名单
     */
    private const WORKER_FAIL_THRESHOLD = 3;
    private const WORKER_HEALTH_PATH = '/_wls/health';

    /**
     * Master 已通过 IPC 确认 Worker READY 后的入池探活。
     * 注意：当 Worker 处理慢事件（如 detect_website 数据库查询）时，
     * 需要足够的响应时间才能完成探活。已增加超时时间以适应 Worker 繁忙场景。
     */
    private const IPC_READY_WARMUP_CONNECT_MIN = 0.2;
    private const IPC_READY_WARMUP_CONNECT_MAX = 0.6;
    private const IPC_READY_WARMUP_RESPONSE_SEC = 1.0;
    private const IPC_READY_WARMUP_MIN_ATTEMPTS = 2;
    private const IPC_READY_WARMUP_MAX_ATTEMPTS = 8;
    private const IPC_READY_WARMUP_RETRY_GRACE_SEC = 1.0;
    private const IPC_READY_WARMUP_RETRY_DELAY_USEC = 50000;
    private const HEALTH_AUDIT_RECENT_SUCCESS_GRACE_SEC = 30.0;
    private const RECENT_CONNECT_FAILURE_GRACE_SEC = 5.0;
    /**
     * 首页预热仅用于“提前点燃”应用栈，不参与入池成败判定；因此采用更短预算，避免拖慢维护接流。
     */
    private const IPC_READY_HOMEPAGE_WARMUP_RETRIES = 1;
    private const IPC_READY_HOMEPAGE_CONNECT_TIMEOUT_SEC = 2.0;
    private const IPC_READY_HOMEPAGE_TLS_TIMEOUT_SEC = 3.0;
    private const IPC_READY_HOMEPAGE_WRITE_TIMEOUT_SEC = 2.0;
    private const IPC_READY_HOMEPAGE_READ_TIMEOUT_SEC = 1.2;
    private const IPC_READY_HOMEPAGE_ROUTE_GATE_TARGETS = 1;
    private const HOMEPAGE_WARMUP_MAX_TARGETS = 16;
    private const HOMEPAGE_WARMUP_RECENT_SUCCESS_GRACE_SEC = 300.0;
    private const HOMEPAGE_WARMUP_BODY_DRAIN_SEC = 0.05;
    private const HOMEPAGE_WARMUP_BODY_DRAIN_BYTES = 16384;
    
    /**
     * Worker 黑名单恢复时间（秒）
     * 黑名单中的 Worker 在此时间后自动尝试恢复
     */
    private const WORKER_BLACKLIST_RECOVERY_SECONDS = 5;

    /**
     * HTTPS 透传模式下的启动兜底等待（秒）
     *
     * 在 TLS 握手之前 Dispatcher 无法返回可读的 HTTP 维护页；若把 spin wait 配成 0，
     * 首个 HTTPS 请求会把 Worker 启动空窗直接暴露为浏览器 400/协议错误。
     * 因此在 SSL Worker 模式下为启动恢复保留一个最小等待窗口。
     */
    /**
     * SSL 冷启动场景下 spin_wait_max_seconds 被显式置 0 时的下限（秒）。
     *
     * P0-1 修复：旧值 15.0s 会把单个 accept 的 handleNewConnection 同步自旋拖长到 15s，
     * 阻塞主循环造成连接风暴放大。现降至 3.0s，并通过 maxHandleNewConnectionSpinBudgetSec 再截断
     * 到单连接 0.8s，让 Phase 1 的 pending 维护页队列兜底剩余等待。
     */
    private const MIN_SSL_STARTUP_SPIN_WAIT_SECONDS = 3.0;
    
    /**
     * Worker 基础端口（仅用于兼容初始化，实际端口由动态列表管理）
     */
    private int $workerBasePort;

    /**
     * Worker 数量（动态计算，等于 workerPorts 数组长度）
     */
    private int $workerCount;

    /**
     * Worker 主机地址
     */
    private string $workerHost;

    /**
     * 动态 Worker 端口列表（由 Master 通过 IPC 通知）
     *
     * PHP 8.4 优化：int[] 类型提升数组访问性能 10-15%
     *
     * @var int[]
     */
    private array $workerPorts = [];

    /**
     * 维护 Worker 端口列表（动态注册，Worker 启动时以 ROLE_MAINTENANCE 身份注册）
     * 
     * 当业务 Worker 全部失败或池为空时，Dispatcher 会尝试连接维护 Worker 池中的端口
     * 以便向用户返回友好的维护页面而非冷断开连接
     * 
     * @var int[]
    */
    private array $maintenanceWorkerPorts = [];
    private int $maintenancePort = 0;
    private bool $maintenanceRoutingActive = false;

    /**
     * 最近一次 handleNewConnection 是否以「业务与维护均不可连」结束（all_workers_down 路径）。
     * 供 Dispatcher 在池非空但全端口失败时仍返回 503「WLS正在启动」页。
     */
    private bool $lastNewConnectionEndedInAllWorkersDown = false;

    /**
     * 可选：探活/预热过程中协作式让出（仅应在 Dispatcher 的入池 Fiber 内注册为 Fiber::suspend）。
     */
    private ?\Closure $warmupCooperativeYield = null;
    private bool $homepageWarmupEnabled = false;
    /** @var string[] */
    private array $homepageWarmupHosts = ['localhost'];
    /** @var string[] */
    private array $homepageWarmupPaths = ['/'];
    /** @var string[] */
    private array $homepageWarmupCookies = [''];
    private int $homepageWarmupRouteGateTargets = self::IPC_READY_HOMEPAGE_ROUTE_GATE_TARGETS;
    /** @var array<int, int> port => warmup ticket */
    private array $workerHomepageWarmupTickets = [];
    /** @var array<int, float> port => last successful best-effort homepage warmup timestamp */
    private array $workerHomepageWarmupCompletedAt = [];
    private int $nextWorkerHomepageWarmupTicket = 1;
    private ?int $activeHomepageWarmupPort = null;

    /**
     * HTTP 重定向端口（用于明文 HTTP 请求转发到 http_redirect_worker）
     */
    private int $httpRedirectPort = 0;

    /**
     * Worker 是否启用 SSL
     */
    private bool $workerSslEnabled = false;

    /**
     * 是否启用 SNI 路由
     */
    private bool $sniRoutingEnabled = true;

    /**
     * 是否启用学习模式
     * H15: 默认禁用 - TCP 透传模式下数据是 SSL 加密的，
     * 不能当作明文 HTTP 解析/修改，否则会破坏 SSL 记录
     */
    private bool $learningModeEnabled = false;

    /**
     * 路由缓存服务
     */
    private RoutingCacheService $routingCache;

    /**
     * 当前连接计数（用于轮询）
     */
    private int $connectionCounter = 0;

    /**
     * 活跃连接映射
     *
     * PHP 8.4 优化：结构化数组类型提升性能和内存布局
     *
     * @var array<int, array{worker: resource, port: int, clientIp: string, sni: string, open_time: float, request_sent_at: float, last_client_to_worker_at: float, last_worker_to_client_at: float, worker_responded: bool, request_line: string, response_first_line: string, response_status_line: string}>
     */
    private array $connections = [];

    /**
     * 空闲 Worker 连接池
     *
     * PHP 8.4 优化：嵌套类型化数组减少内存碎片
     *
     * @var array<int, array<int, array{socket: resource, expires_at: float}>>
     */
    private array $idleWorkerPool = [];

    /**
     * 是否启用后端连接池复用（默认关闭，需显式配置）
     */
    private bool $backendPoolEnabled = false;

    /**
     * 每个 Worker 端口允许缓存的最大空闲连接数
     */
    private int $backendPoolMaxIdlePerWorker = 16;

    /**
     * 空闲连接 TTL（秒）
     */
    private int $backendPoolIdleTtl = 15;

    /**
     * SSL worker cold preconnect pool size per worker.
     *
     * These sockets have not carried TLS bytes yet. Once a socket is bound to
     * a client TLS stream it is never returned to the pool.
     */
    private int $sslBackendPreconnectPerWorker = 0;

    /**
     * Round-robin cursor for incremental SSL backend preconnect refill.
     */
    private int $sslBackendPreconnectCursor = 0;

    /**
     * H15: 客户端写入缓冲区
     * 当客户端 TCP 发送缓冲区满时，暂存未写入的数据
     *
     * PHP 8.4 优化：string 值数组提升性能
     *
     * @var array<int, string>
     */
    private array $clientWriteBuffers = [];

    /**
     * Upstream write buffer for bytes already read from the client but not yet
     * accepted by the worker socket. Dispatcher flushes this on write readiness.
     *
     * @var array<int, string>
     */
    private array $workerWriteBuffers = [];

    /**
     * H15: Worker 已关闭但还有缓冲数据需要发送的连接
     *
     * PHP 8.4 优化：bool 值数组访问性能提升
     *
     * @var array<int, bool>
     */
    private array $workerClosed = [];

    /**
     * 客户端上行（client->worker）是否已半关闭（FIN）
     *
     * PHP 8.4 优化：bool 值数组减少类型检查
     *
     * @var array<int, bool>
     */
    private array $clientInputClosed = [];
    
    /**
     * 每条连接最近一次终止原因（用于 Dispatcher 侧诊断）
     *
     * PHP 8.4 优化：string 值数组提升性能
     *
     * @var array<int, string>
     */
    private array $connectionTerminalReasons = [];

    /**
     * Worker 健康状态
     *
     * PHP 8.4 优化：结构化数组类型减少运行时检查
     *
     * @var array<int, array{failures: int, blacklisted_at: float, last_success: float, total_failures: int}>
     */
    private array $workerHealth = [];

    /**
     * Worker 长连接饱和状态
     * 当 Worker 上报长连接饱和时，Dispatcher 暂缓向该 Worker 分配新连接，
     * 但仍保持现有长连接（让现有 SSE/长轮询继续工作）
     *
     * PHP 8.4 优化：类型化状态数组提升访问性能
     *
     * @var array<int, array{long_lived_count: int, long_lived_max: int, saturated_at: float|null}>
     */
    private array $workerSaturation = [];

    /**
     * 读取缓冲区大小
     */
    private int $readBufferSize = 65536;

    /**
     * Peek 缓冲区大小
     */
    private int $peekBufferSize = 512;

    /**
     * 连接超时（秒）
     */
    private int $connectTimeout = 5;

    /**
     * Worker 首字节响应超时（秒）
     * 连接已建立但长时间无任何响应字节时，判定该 Worker 假活跃并触发故障转移黑名单
     */
    private float $firstByteTimeoutSeconds = 5.0;

    /**
     * In SSL passthrough mode the dispatcher cannot see HTTP, but it can see
     * encrypted byte direction. If client->worker traffic is not followed by
     * worker->client traffic quickly, the worker is probably rendering or stuck
     * in TLS/userland work and should not receive more fresh connections.
     */
    private float $workerBusyPenaltyAfterSeconds = 0.12;

    /**
     * Health audit probes run outside the browser request path, so they can
     * tolerate a slightly wider TCP/TLS budget than request failover. The old
     * 0.8s cap was too aggressive on Windows SSL workers under load and could
     * evict otherwise healthy workers from the dispatcher pool.
     */
    private float $workerHealthConnectTimeoutSec = 2.0;
    private float $workerHealthResponseTimeoutSec = 2.0;
    private bool $workerHealthAuditEnabled = true;

    /**
     * Worker 全部不可用时的自旋等待总时长（秒）
     * 热重载期间 Worker 可能有短暂空窗，自旋等待可避免请求直接失败
     */
    private float $spinWaitMaxSeconds = 0.0;

    /**
     * workerPorts 为空时的自旋上限（秒）。避免 SSL 模式下 15s 级自旋 × 大量连接拖死 Dispatcher、放大重试风暴。
     */
    private float $emptyPoolSpinMaxSeconds = 0.5;

    /**
     * 自旋等待间隔（毫秒）
     */
    private int $spinWaitIntervalMs = 50;

    /**
     * 单次 handleNewConnection 的自旋预算硬上限（秒）。
     *
     * P0-1 修复：即使 spinWaitMaxSeconds 配置得较大（例如 SSL 3s、reload 10s），
     * 单个 accept 也不应阻塞主循环超过此值。超过阈值即 fallback，由
     * Dispatcher 的 pending 维护页队列 / 维护 Worker 接管继续推进。
     *
     * 默认 0.8s：HTTPS 冷启动短窗口足够，正常业务路径远低于此。
     */
    private float $maxHandleNewConnectionSpinBudgetSec = 0.0;

    /**
     * connectToWorker 里 non-blocking connect 失败后 socket_select 的阻塞超时（秒）。
     *
     * P0-2 修复：旧硬编码 max(0.3, min(connectTimeout, 0.5)) 对 localhost Worker 过大；
     * 高并发失败路径每次额外阻塞 300-500ms 会级联拖垮主循环。
     * 默认 0.1s 对 localhost TCP connect 绰绰有余；远端后端可通过 configure 覆盖。
     */
    private float $workerConnectSelectTimeoutSec = 0.02;

    /**
     * 上次输出「workerPorts 为空」到 stderr 的时间（节流，避免启动时刷屏）
     */
    private float $lastEmptyWorkerPortsStderrAt = 0.0;
    /** @var array<string, float> */
    private array $maintenanceDecisionLoggedAt = [];
    /** @var array<int, int> */
    private array $requestIngressLogCountByConn = [];
    /** @var array<int, string> */
    private array $lastLoggedHttpRequestLineByConn = [];
    private int $requestIngressLogMaxPerConnection = 3;
    private bool $trafficTraceEnabled = false;
    private $spinWaitTickCallback = null;

    /**
     * 统计信息
     *
     * PHP 8.4 优化：类型化统计数组提升性能
     *
     * @var array{total_connections: int, active_connections: int, cache_routed: int, round_robin_routed: int, failover_routed: int, maintenance_routed: int, saturated_fallback_routed: int, sni_extractions: int, bytes_in: int, bytes_out: int, worker_failures: int, all_workers_down: int, backend_pool_reused: int, backend_pool_released: int, backend_pool_discarded: int}
     */
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'cache_routed' => 0,
        'round_robin_routed' => 0,
        'failover_routed' => 0,
        'maintenance_routed' => 0,
        'saturated_fallback_routed' => 0,
        'sni_extractions' => 0,
        'bytes_in' => 0,
        'bytes_out' => 0,
        'worker_failures' => 0,
        'all_workers_down' => 0,
        'backend_pool_reused' => 0,
        'backend_pool_released' => 0,
        'backend_pool_discarded' => 0,
    ];
    
    /**
     * 构造函数
     *
     * @param string $workerHost Worker 主机地址
     * @param int $workerBasePort Worker 基础端口（仅用于兼容，实际端口由 Master 通知）
     * @param int $workerCount Worker 数量（初始值，实际由动态端口列表决定）
     * @param bool $workerSslEnabled Worker 是否启用 SSL
     */
    public function __construct(string $workerHost, int $workerBasePort, int $workerCount, bool $workerSslEnabled = false)
    {
        $this->workerHost = $workerHost;
        $this->workerBasePort = $workerBasePort;
        $this->workerCount = 0; // 由 workerPorts 长度决定
        $this->workerSslEnabled = $workerSslEnabled;
        $this->routingCache = RoutingCacheService::getInstance();

        // 不再使用临时种子端口池，完全依赖 Master 通过 IPC 发送的权威端口列表。
        // 这确保了多项目部署时不会出现跨项目的 Worker 端口干扰。
        // 启动期间如果 workerPorts 为空，Dispatcher 会进入自旋等待（spinWaitForWorkerPorts）。
    }
    
    /**
     * 配置透传核心
     *
     * @param array $config 配置数组
     */
    public function configure(array $config): void
    {
        if (isset($config['sni_routing_enabled'])) {
            $this->sniRoutingEnabled = (bool) $config['sni_routing_enabled'];
        }
        if (isset($config['learning_mode_enabled'])) {
            $this->learningModeEnabled = (bool) $config['learning_mode_enabled'];
        }
        if (isset($config['read_buffer_size'])) {
            $this->readBufferSize = (int) $config['read_buffer_size'];
        }
        if (isset($config['peek_buffer_size'])) {
            $this->peekBufferSize = (int) $config['peek_buffer_size'];
        }
        if (isset($config['connect_timeout'])) {
            $this->connectTimeout = (int) $config['connect_timeout'];
        }
        if (isset($config['first_byte_timeout_seconds'])) {
            $this->firstByteTimeoutSeconds = \max(0.5, (float)$config['first_byte_timeout_seconds']);
        }
        if (isset($config['worker_busy_penalty_after_ms'])) {
            $requested = ((float)$config['worker_busy_penalty_after_ms']) / 1000.0;
            $this->workerBusyPenaltyAfterSeconds = \max(0.0, \min($requested, 5.0));
        } elseif (isset($config['worker_busy_penalty_after_seconds'])) {
            $requested = (float)$config['worker_busy_penalty_after_seconds'];
            $this->workerBusyPenaltyAfterSeconds = \max(0.0, \min($requested, 5.0));
        }
        if (isset($config['worker_health_connect_timeout_sec'])) {
            $this->workerHealthConnectTimeoutSec = \max(0.3, \min((float)$config['worker_health_connect_timeout_sec'], 10.0));
        }
        if (isset($config['worker_health_response_timeout_sec'])) {
            $this->workerHealthResponseTimeoutSec = \max(0.5, \min((float)$config['worker_health_response_timeout_sec'], 10.0));
        }
        if (isset($config['worker_health_audit_enabled'])) {
            $this->workerHealthAuditEnabled = (bool)$config['worker_health_audit_enabled'];
        }
        if (isset($config['spin_wait_max_seconds'])) {
            $requestedSpinWait = (float) $config['spin_wait_max_seconds'];
            if ($requestedSpinWait <= 0.0 && $this->workerSslEnabled) {
                $requestedSpinWait = self::MIN_SSL_STARTUP_SPIN_WAIT_SECONDS;
            }
            $this->spinWaitMaxSeconds = $requestedSpinWait;
        }
        if (isset($config['spin_wait_interval_ms'])) {
            $this->spinWaitIntervalMs = \max(10, (int) $config['spin_wait_interval_ms']);
        }
        if (isset($config['empty_pool_spin_max_seconds'])) {
            $this->emptyPoolSpinMaxSeconds = \max(0.0, (float) $config['empty_pool_spin_max_seconds']);
        }
        if (isset($config['max_handle_new_connection_spin_budget_sec'])) {
            // 允许 0 => 完全关闭自旋；负值则用默认
            $requested = (float) $config['max_handle_new_connection_spin_budget_sec'];
            $this->maxHandleNewConnectionSpinBudgetSec = $requested >= 0.0 ? $requested : 0.0;
        }
        if (isset($config['worker_connect_select_timeout_sec'])) {
            // 限制到 [0.01s, 2.0s]，防止误配置导致极端阻塞或 connect 几乎立即失败
            $requested = (float) $config['worker_connect_select_timeout_sec'];
            $this->workerConnectSelectTimeoutSec = \max(0.01, \min($requested, 2.0));
        }
        if (isset($config['traffic_trace_enabled'])) {
            $this->trafficTraceEnabled = (bool)$config['traffic_trace_enabled'];
        }
        if (isset($config['homepage_warmup_enabled'])) {
            $this->homepageWarmupEnabled = (bool) $config['homepage_warmup_enabled'];
        }
        if (isset($config['homepage_warmup_hosts'])) {
            $hosts = $this->normalizeHomepageWarmupHosts($config['homepage_warmup_hosts']);
            if ($hosts !== []) {
                $this->homepageWarmupHosts = $hosts;
            }
        }
        if (isset($config['homepage_warmup_paths'])) {
            $paths = $this->normalizeHomepageWarmupPaths($config['homepage_warmup_paths']);
            if ($paths !== []) {
                $this->homepageWarmupPaths = $paths;
            }
        }
        if (isset($config['homepage_warmup_variants'])) {
            $this->homepageWarmupCookies = $this->normalizeHomepageWarmupVariants($config['homepage_warmup_variants']);
        } elseif (isset($config['homepage_warmup_cookies'])) {
            $this->homepageWarmupCookies = $this->normalizeHomepageWarmupCookies($config['homepage_warmup_cookies']);
        }
        if (isset($config['homepage_warmup_route_gate_targets'])) {
            $this->homepageWarmupRouteGateTargets = \max(1, \min((int)$config['homepage_warmup_route_gate_targets'], self::HOMEPAGE_WARMUP_MAX_TARGETS));
        }
        if (isset($config['backend_pool_enabled'])) {
            $this->backendPoolEnabled = (bool)$config['backend_pool_enabled'];
        }
        if (isset($config['backend_pool_max_idle_per_worker'])) {
            $this->backendPoolMaxIdlePerWorker = \max(1, (int)$config['backend_pool_max_idle_per_worker']);
        }
        if (isset($config['backend_pool_idle_ttl'])) {
            $this->backendPoolIdleTtl = \max(1, (int)$config['backend_pool_idle_ttl']);
        }
        if (isset($config['ssl_backend_preconnect_per_worker'])) {
            $this->sslBackendPreconnectPerWorker = \max(0, (int)$config['ssl_backend_preconnect_per_worker']);
        }
        
        // 传递缓存配置
        if (isset($config['cache'])) {
            $this->routingCache->configure($config['cache']);
        }
        
        // HTTP 重定向端口
        if (isset($config['http_redirect_port'])) {
            $this->httpRedirectPort = (int) $config['http_redirect_port'];
        }
        if (!$this->backendPoolEnabled && $this->sslBackendPreconnectPerWorker <= 0) {
            $this->closeAllIdleWorkerSockets();
        }
    }

    public function setSpinWaitTickCallback(?callable $callback): void
    {
        $this->spinWaitTickCallback = $callback;
    }

    public function tickSslBackendPreconnectPool(int $maxCreates = 1): void
    {
        if (!$this->workerSslEnabled || $this->sslBackendPreconnectPerWorker <= 0 || $this->workerPorts === []) {
            return;
        }

        $maxCreates = \max(1, $maxCreates);
        $portCount = \count($this->workerPorts);
        $created = 0;
        $visited = 0;
        while ($visited < $portCount && $created < $maxCreates) {
            $index = $this->sslBackendPreconnectCursor % $portCount;
            $this->sslBackendPreconnectCursor++;
            $visited++;

            $port = (int)$this->workerPorts[$index];
            $created += $this->primeSslIdleWorkerPool($port, $maxCreates - $created);
        }
    }

    /**
     * 设置 HTTP 重定向端口
     */
    public function setHttpRedirectPort(int $port): void
    {
        $this->httpRedirectPort = $port;
    }

    /**
     * 获取 HTTP 重定向端口
     */
    public function getHttpRedirectPort(): int
    {
        return $this->httpRedirectPort;
    }

    /**
     * 处理 HTTP 重定向连接（转发给 http_redirect_worker）
     *
     * 当 Dispatcher 检测到明文 HTTP 请求时，调用此方法将连接转发到 http_redirect_worker。
     *
     * @param resource|\Socket $clientSocket 客户端套接字
     * @param string $clientIp 客户端 IP
     * @return bool 是否成功建立连接
     */
    public function handleHttpRedirectConnection($clientSocket, string $clientIp): bool
    {
        if ($this->httpRedirectPort <= 0) {
            return false;
        }
        
        // 建立到 http_redirect_worker 的连接
        $workerSocket = $this->connectToWorker($this->httpRedirectPort);
        if ($workerSocket === false) {
            return false;
        }
        
        // 注册连接映射（复用现有结构）
        $connId = \spl_object_id($clientSocket);
        $this->connections[$connId] = [
            'worker' => $workerSocket,
            'port' => $this->httpRedirectPort,
            'clientIp' => $clientIp,
            'sni' => '',
            'open_time' => \microtime(true),
            'request_sent_at' => 0.0,
            'last_client_to_worker_at' => 0.0,
            'last_worker_to_client_at' => 0.0,
            'worker_responded' => false,
            'request_line' => '',
            'response_first_line' => '',
            'response_status_line' => '',
        ];
        
        $this->stats['active_connections']++;
        
        return true;
    }
    
    /**
     * 处理新客户端连接
     *
     * 包含故障转移机制：当选中的 Worker 连接失败时，自动尝试其他可用 Worker。
     * 只有所有 Worker 都不可用时才返回 false。
     *
     * @param resource $clientSocket 客户端套接字
     * @param string $clientIp 客户端 IP
     * @return bool 是否成功建立连接
     */
    public function handleNewConnection($clientSocket, string $clientIp): bool
    {
        $this->stats['total_connections']++;
        $this->lastNewConnectionEndedInAllWorkersDown = false;

        $connId = \spl_object_id($clientSocket);
        $sni = '';
        $workerPort = null;
        $fromCache = false;
        $routeSource = 'none';
        
        // 1. 尝试从连接缓存查找路由（Keep-Alive 场景）
        $routeInfo = $this->routingCache->getRouteByConnection($connId);
        if ($routeInfo !== null) {
            $workerPort = $routeInfo['port'];
            $sni = $routeInfo['sni'];
            $fromCache = true;
            $routeSource = 'connection_cache';
        }

        // 2. 提前提取 SNI（若可用），用于避免同 IP 多域名时被 IP 缓存误路由。
        // 典型场景：本机 127.0.0.1 多域名/多站点，IP 一样但 SNI 不同。
        if ($this->sniRoutingEnabled) {
            $peekedSni = $this->extractSniFromSocket($clientSocket);
            if (!empty($peekedSni)) {
                $this->stats['sni_extractions']++;
                $sni = $peekedSni;
            }
        }

        // 3. SNI 缓存优先（比 IP 缓存更精确）
        if ($this->maintenanceRoutingActive) {
            $this->routingCache->removeConnection($connId);
            $this->routingCache->purgeRouteCache($clientIp, $sni);
            $maintenanceSocket = $this->connectToMaintenanceWorkerCandidate();
            if ($maintenanceSocket !== false) {
                return $this->registerConnection(
                    $connId,
                    $clientSocket,
                    $maintenanceSocket['socket'],
                    $maintenanceSocket['port'],
                    $clientIp,
                    $sni
                );
            }

            $this->stats['all_workers_down']++;
            $this->lastNewConnectionEndedInAllWorkersDown = true;
            $this->logMaintenanceDecision(
                'active_maintenance_unavailable',
                'Active maintenance routing has no reachable maintenance worker, ' . $this->formatMaintenanceLogContext(),
                'WARN',
                0.0
            );
            return false;
        }

        if ($workerPort === null && $sni !== '') {
            $sniRoutePort = $this->routingCache->getRouteBySni($sni);
            if ($sniRoutePort !== null) {
                $workerPort = $sniRoutePort;
                $fromCache = true;
                $routeSource = 'sni_cache';
            }
        }

        // 4. IP 缓存兜底：
        // - 仅在 SNI 未命中时使用
        // - 若 IP 缓存里带 SNI，必须与当前 SNI 一致才可复用，避免跨域误路由
        if ($workerPort === null) {
            $routeInfo = $this->routingCache->getRouteByIp($clientIp);
            if ($routeInfo !== null) {
                $ipRouteSni = (string)($routeInfo['sni'] ?? '');
                $sniMismatch = ($sni !== '' && $ipRouteSni !== '' && \strcasecmp($ipRouteSni, $sni) !== 0);
                if (!$sniMismatch) {
                    $workerPort = $routeInfo['port'];
                    if ($sni === '') {
                        $sni = $ipRouteSni;
                    }
                    $fromCache = true;
                    $routeSource = 'ip_cache';
                }
            }
        }

        // 4.5 Keep-Alive / SNI / IP 粘连里的端口若已不在当前 Worker 池（reload 换端口、缩容、路由表变更），
        //    视为失效：清缓存并走池内分配，避免对「已不存在」的端口 connect + recordWorkerFailure 污染健康度。
        if ($workerPort !== null && !$this->isWorkerPortInPool((int)$workerPort)) {
            $this->routingCache->removeConnection($connId);
            $this->routingCache->purgeRouteCache($clientIp, $sni);
            $workerPort = null;
            $fromCache = false;
        }

        // 5. 如果有缓存路由，先尝试连接该 Worker
        if ($workerPort !== null && !$this->shouldReuseCachedWorkerRoute((int) $workerPort)) {
            $this->routingCache->removeConnection($connId);
            $this->routingCache->purgeRouteCache($clientIp, $sni);
            $workerPort = null;
            $fromCache = false;
        }
        if ($workerPort !== null) {
            // 检查缓存路由的 Worker 是否在黑名单中
            if (!$this->isWorkerBlacklisted($workerPort)) {
                $workerSocket = $this->connectToWorker($workerPort);
                if ($workerSocket !== false) {
                    $this->stats['cache_routed']++;
                    return $this->registerConnection($connId, $clientSocket, $workerSocket, $workerPort, $clientIp, $sni);
                }
                // 缓存的 Worker 连接失败，记录失败并继续尝试其他 Worker
                $this->recordWorkerFailure($workerPort, true);
            }
            // 清除缓存路由，让后续请求不再路由到失败的 Worker
            if ($fromCache) {
                $this->routingCache->removeConnection($connId);
            }
        }
        
        // 5.5 维护模式快速通道：若当前只有 1 个 Worker 端口（维护模式），即使在黑名单中也应快速尝试，
        //     避免"已下发维护命令却进不了维护 Worker"的死锁。
        if ($this->workerCount === 1) {
            $maintenancePort = $this->workerPorts[0];
            $maintenanceSocket = $this->connectToWorker($maintenancePort);
            if ($maintenanceSocket !== false) {
                $this->recordWorkerSuccess($maintenancePort);
                $this->logMaintenanceDecision(
                    'single_worker_maintenance_success:' . $maintenancePort,
                    "单 Worker 维护直连成功：port={$maintenancePort}，" . $this->formatMaintenanceLogContext(),
                    'INFO',
                    0.0
                );
                return $this->registerConnection($connId, $clientSocket, $maintenanceSocket, $maintenancePort, $clientIp, $sni);
            }
            $this->recordWorkerFailure($maintenancePort, true);
            $this->logMaintenanceDecision(
                'single_worker_maintenance_failed:' . $maintenancePort,
                "单 Worker 维护直连失败：port={$maintenancePort}，" . $this->formatMaintenanceLogContext(),
                'WARN'
            );
        }
        
        // 6. 故障转移：尝试所有可用 Worker（跳过黑名单中的）
        $workerSocket = $this->connectToAvailableWorker($workerPort, $sni);
        if ($workerSocket !== false) {
            return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
        }

        // 6b. 全部处于「长连接饱和」时，上面一步会跳过所有端口；此处对饱和 Worker 做最后分配尝试，避免误报「全部不可用」
        $workerSocket = $this->connectToSaturatedWorker($workerPort, $sni);
        if ($workerSocket !== false) {
            return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
        }

        // 7. 所有健康 Worker 都失败了，最后尝试黑名单中的 Worker（可能已经恢复）
        $workerSocket = $this->connectToAnyWorker($workerPort);
        if ($workerSocket !== false) {
            return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
        }

        // 8. 可选的单次协作重试（非自旋）：推进 IPC/异步入池 Fiber 后立即再试一轮 connect。
        // 默认关闭（maxHandleNewConnectionSpinBudgetSec=0）；启动兜底由 Dispatcher pending 维护页队列承担。
        if ($this->resolvePostFailureSpinBudgetSeconds() > 0.0) {
            $this->runSpinWaitTick();
            $workerSocket = $this->connectToAvailableWorker($workerPort, $sni);
            if ($workerSocket !== false) {
                $this->stats['failover_routed']++;
                return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
            }
            $workerSocket = $this->connectToSaturatedWorker($workerPort, $sni);
            if ($workerSocket !== false) {
                return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
            }
            $workerSocket = $this->connectToAnyWorker($workerPort);
            if ($workerSocket !== false) {
                $this->stats['failover_routed']++;
                return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
            }
            $maintenanceSocket = $this->connectToMaintenanceWorkerCandidate();
            if ($maintenanceSocket !== false) {
                return $this->registerConnection(
                    $connId,
                    $clientSocket,
                    $maintenanceSocket['socket'],
                    $maintenanceSocket['port'],
                    $clientIp,
                    $sni
                );
            }
        }
        
        // 9. 业务 Worker 池为空（未就绪）或全部失败后，检测是否有维护 Worker 备选
        //    （维护模式或启动阶段）→ 尝试转接到维护 Worker 池以提供友好页面
        $maintenanceSocket = $this->connectToMaintenanceWorkerCandidate();
        if ($maintenanceSocket !== false) {
            return $this->registerConnection(
                $connId,
                $clientSocket,
                $maintenanceSocket['socket'],
                $maintenanceSocket['port'],
                $clientIp,
                $sni
            );
        }

        $this->stats['all_workers_down']++;
        $this->lastNewConnectionEndedInAllWorkersDown = true;
        $this->logMaintenanceDecision(
            'all_workers_down',
            '业务 Worker 与维护 Worker 均不可用，' . $this->formatMaintenanceLogContext(),
            'WARN'
        );
        return false;
    }

    /**
     * 上次 handleNewConnection(false) 是否因业务与维护 Worker 均不可连（含池有端口但全部连接失败）。
     */
    public function lastNewConnectionEndedInAllWorkersDown(): bool
    {
        return $this->lastNewConnectionEndedInAllWorkersDown;
    }

    /**
     * @return array{socket: resource, port: int}|false
     */
    private function connectToMaintenanceWorkerCandidate(): array|false
    {
        if ($this->maintenanceWorkerPorts === []) {
            return false;
        }

        foreach ($this->maintenanceWorkerPorts as $maintenancePort) {
            $maintenanceSocket = $this->connectToWorker($maintenancePort);
            if ($maintenanceSocket !== false) {
                $this->recordWorkerSuccess($maintenancePort);
                $this->stats['maintenance_routed']++;
                $this->logMaintenanceDecision(
                    'maintenance_route_success:' . $maintenancePort,
                    "维护候选接管成功：port={$maintenancePort}，" . $this->formatMaintenanceLogContext(),
                    'INFO',
                    0.0
                );

                return ['socket' => $maintenanceSocket, 'port' => $maintenancePort];
            }

            $this->recordWorkerFailure($maintenancePort, true);
            $this->logMaintenanceDecision(
                'maintenance_route_failed:' . $maintenancePort,
                "维护候选接管失败：port={$maintenancePort}，" . $this->formatMaintenanceLogContext(),
                'WARN'
            );
        }

        return false;
    }

    /**
     * 本轮路由在「已尝试所有 Worker 连接」仍失败后，允许自旋的秒数预算。
     *
     * - 池为空：短窗口 min(spin_wait_max, empty_pool_spin)，避免无端口时长时间占死事件循环。
     * - 池非空：使用完整 spin_wait_max（含「有维护端口但尚未 listen」：此前误判为可分配而不自旋，导致永远进不了维护 Worker）。
     */
    private function shouldReuseCachedWorkerRoute(int $workerPort): bool
    {
        if ($workerPort <= 0) {
            return false;
        }

        if ($this->isWorkerBlacklisted($workerPort)) {
            return false;
        }

        if ($this->isWorkerSaturated($workerPort)) {
            return false;
        }

        if ($this->workerSslEnabled && $this->hasLessLoadedWorker($workerPort)) {
            return false;
        }

        return true;
    }

    private function resolvePostFailureSpinBudgetSeconds(): float
    {
        if ($this->spinWaitMaxSeconds <= 0.0) {
            return 0.0;
        }
        if ($this->workerPorts === [] && $this->maintenanceWorkerPorts === []) {
            return 0.0;
        }

        // P0-1：单个 accept 的同步自旋上限受 maxHandleNewConnectionSpinBudgetSec 硬截断，
        // 防止事件循环被单连接 SSL 冷启动路径阻塞到 15s 级别。
        // maxHandleNewConnectionSpinBudgetSec == 0 则完全关闭自旋（极端低延迟场景）。
        if ($this->maxHandleNewConnectionSpinBudgetSec <= 0.0) {
            return 0.0;
        }

        return \min($this->spinWaitMaxSeconds, $this->maxHandleNewConnectionSpinBudgetSec);
    }

    private function runSpinWaitTick(): void
    {
        if ($this->spinWaitTickCallback === null) {
            return;
        }

        try {
            ($this->spinWaitTickCallback)();
        } catch (\Throwable $e) {
            $this->logWarmup('spin-wait tick failed: ' . $e->getMessage(), 'WARNING');
        }
    }

    /**
     * 当前负载均衡池是否包含该 Worker 端口（用于丢弃 reload 前的路由粘连）。
     */
    private function isWorkerPortInPool(int $port): bool
    {
        return $this->workerPorts !== [] && \in_array($port, $this->workerPorts, true);
    }
    
    /**
     * 注册连接到内部映射
     *
     * @param int $connId 连接 ID
     * @param resource $clientSocket 客户端套接字
     * @param resource $workerSocket Worker 套接字
     * @param int $workerPort Worker 端口
     * @param string $clientIp 客户端 IP
     * @param string $sni SNI
     * @return bool 始终返回 true
     */
    private function registerConnection(int $connId, $clientSocket, $workerSocket, int $workerPort, string $clientIp, string $sni): bool
    {
        $this->connections[$connId] = [
            'worker' => $workerSocket,
            'port' => $workerPort,
            'clientIp' => $clientIp,
            'sni' => $sni,
            'open_time' => \microtime(true),
            'request_sent_at' => 0.0,
            'worker_responded' => false,
            'request_line' => '',
            'response_first_line' => '',
            'response_status_line' => '',
        ];
        
        $this->stats['active_connections']++;
        
        // 缓存连接路由信息
        $this->routingCache->cacheConnectionRoute($connId, $workerPort, $sni);
        
        return true;
    }
    
    /**
     * 尝试连接到任意可用 Worker（跳过黑名单和已尝试的端口）
     *
     * @param int|null $excludePort 已尝试过的端口，跳过
     * @param string $sni SNI（用于日志）
     * @return array{socket: resource, port: int}|false 成功返回 socket 和端口，失败返回 false
     */
    private function connectToAvailableWorker(?int $excludePort, string $sni): array|false
    {
        $excludePort = $this->normalizeExcludePortForWorkerPool($excludePort);

        // 如果没有可用 Worker，直接返回（节流 stderr，避免启动期刷屏）
        if (empty($this->workerPorts)) {
            $now = \microtime(true);
            if ($now - $this->lastEmptyWorkerPortsStderrAt >= 10.0) {
                $this->writeStderr("[PassthroughCore] 没有可用 Worker 端口！workerPorts 为空\n");
                $this->lastEmptyWorkerPortsStderrAt = $now;
            }
            $this->logMaintenanceDecision(
                'empty_worker_pool',
                '业务 Worker 池为空，当前无法分配端口，' . $this->formatMaintenanceLogContext(),
                'WARN'
            );
            return false;
        }

        // 从当前轮询位置开始，遍历所有 Worker
        $count = \count($this->workerPorts);
        $startIndex = $this->connectionCounter % $count;
        $this->connectionCounter++;

        $candidatePorts = $this->workerSslEnabled
            ? $this->orderWorkerPortsByActiveLoad($startIndex)
            : $this->orderWorkerPortsRoundRobin($startIndex);

        foreach ($candidatePorts as $port) {

            // 跳过已尝试的端口
            if ($port === $excludePort) {
                continue;
            }
            if ($this->shouldDeferPendingWarmupWorker((int)$port, $excludePort)) {
                continue;
            }

            // 跳过黑名单中的 Worker
            if ($this->isWorkerBlacklisted($port)) {
                continue;
            }

            // 跳过处于长连接饱和状态的 Worker（优先把新连接分给未饱和实例；若全部饱和则由 connectToSaturatedWorker 兜底）
            if ($this->isWorkerSaturated($port)) {
                continue;
            }
            
            $workerSocket = $this->connectToWorker($port);
            if ($workerSocket !== false) {
                if ($excludePort !== null) {
                    $this->stats['failover_routed']++;
                } else {
                    $this->stats['round_robin_routed']++;
                }
                return ['socket' => $workerSocket, 'port' => $port];
            }
            
            // 连接失败，记录
            $this->recordWorkerFailure($port, true);
        }
        
        return false;
    }

    /**
     * 在全部 Worker 均被标记为长连接饱和时，仍尝试向饱和池分配（优于直接 503 / all_workers_down）。
     *
     * @param int|null $excludePort 已尝试过的端口，跳过
     */
    private function connectToSaturatedWorker(?int $excludePort, string $sni): array|false
    {
        unset($sni);

        $excludePort = $this->normalizeExcludePortForWorkerPool($excludePort);

        if (empty($this->workerPorts)) {
            return false;
        }

        $count = \count($this->workerPorts);
        $startIndex = $this->connectionCounter > 0 ? (($this->connectionCounter - 1) % $count) : 0;

        for ($i = 0; $i < $count; $i++) {
            $index = ($startIndex + $i) % $count;
            $port = $this->workerPorts[$index];

            if ($excludePort !== null && $port === $excludePort) {
                continue;
            }
            if ($this->shouldDeferPendingWarmupWorker((int)$port, $excludePort)) {
                continue;
            }
            if (!$this->isWorkerSaturated((int)$port)) {
                continue;
            }
            if ($this->isWorkerBlacklisted((int)$port)) {
                continue;
            }

            $workerSocket = $this->connectToWorker((int)$port);
            if ($workerSocket !== false) {
                $this->stats['saturated_fallback_routed']++;

                return ['socket' => $workerSocket, 'port' => (int)$port];
            }
            $this->recordWorkerFailure((int)$port, true);
        }

        return false;
    }
    
    /**
     * 最后的尝试：连接到任何 Worker（包括黑名单中的）
     * 当所有"健康"Worker 都失败后调用
     *
     * @param int|null $excludePort 跳过的端口
     * @return array{socket: resource, port: int}|false
     */
    private function connectToAnyWorker(?int $excludePort): array|false
    {
        $excludePort = $this->normalizeExcludePortForWorkerPool($excludePort);

        foreach ($this->workerPorts as $port) {
            if ($port === $excludePort) {
                continue;
            }
            if ($this->shouldDeferPendingWarmupWorker((int)$port, $excludePort)) {
                continue;
            }
            
            // 仅尝试黑名单中的 Worker（非黑名单的已在 connectToAvailableWorker 中尝试过）
            if (!$this->isWorkerBlacklisted($port)) {
                continue;
            }
            
            $workerSocket = $this->connectToWorker($port);
            if ($workerSocket !== false) {
                $this->stats['failover_routed']++;
                return ['socket' => $workerSocket, 'port' => $port];
            }
        }
        
        return false;
    }

    /**
     * 池中仅有一个 Worker 时，不得因 excludePort 跳过该端口。
     *
     * 否则：SNI/IP 缓存先连该端口失败后，connectToAvailableWorker / connectToAnyWorker 会把唯一端口排除在外，
     * 故障转移路径零次重试，瞬断或忙时 accept 延迟会误报「所有 Worker 不可用」，且 healthy 仍可能显示 1/1。
     */
    private function normalizeExcludePortForWorkerPool(?int $excludePort): ?int
    {
        if ($excludePort === null || $this->workerPorts === []) {
            return $excludePort;
        }
        if (\count($this->workerPorts) !== 1) {
            return $excludePort;
        }

        return (int) $this->workerPorts[0] === (int) $excludePort ? null : $excludePort;
    }
    
    /**
     * 从套接字提取 SNI（公开方法）
     *
     * @param resource $socket 客户端套接字
     * @return string SNI 主机名，未找到返回空字符串
     */
    public function extractSniFromSocketPublic($socket): string
    {
        return $this->extractSniFromSocket($socket);
    }
    
    /**
     * 从套接字提取 SNI
     *
     * @param resource $socket 客户端套接字
     * @return string SNI 主机名，未找到返回空字符串
     */
    private function extractSniFromSocket($socket): string
    {
        // 使用 MSG_PEEK 预览数据，不从缓冲区移除
        $data = @\socket_recv($socket, $buffer, $this->peekBufferSize, MSG_PEEK);
        
        if ($data === false || $data <= 0) {
            return '';
        }
        
        // 检查是否是 TLS ClientHello
        if (!SniParser::isClientHello($buffer)) {
            return '';
        }
        
        // 提取 SNI
        $sni = SniParser::extractSNI($buffer);
        
        return $sni ?? '';
    }
    
    /**
     * 建立到 Worker 的连接
     *
     * 使用较短的超时时间（1 秒），以便在故障转移时快速切换到其他 Worker。
     *
     * @param int $workerPort Worker 端口
     * @return resource|false Worker 套接字，失败返回 false
     */
    private function connectToWorker(int $workerPort)
    {
        if ($this->backendPoolEnabled && !$this->workerSslEnabled) {
            $reusedSocket = $this->acquireIdleWorkerSocket($workerPort);
            if ($reusedSocket !== false) {
                $this->stats['backend_pool_reused']++;
                return $reusedSocket;
            }
        }

        if ($this->workerSslEnabled && $this->sslBackendPreconnectPerWorker > 0) {
            $reusedSocket = $this->acquireIdleWorkerSocket($workerPort);
            if ($reusedSocket !== false) {
                $this->stats['backend_pool_reused']++;
                return $reusedSocket;
            }
        }

        return $this->openWorkerSocket($workerPort);
    }

    private function openWorkerSocket(int $workerPort, ?float $connectTimeoutOverride = null)
    {
        $workerSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($workerSocket === false) {
            return false;
        }
        
        // 设置非阻塞
        if (\defined('TCP_NODELAY')) {
            @\socket_set_option($workerSocket, \SOL_TCP, (int)\TCP_NODELAY, 1);
        }

        \socket_set_nonblock($workerSocket);

        // 尝试连接（非阻塞）
        $result = @\socket_connect($workerSocket, $this->workerHost, $workerPort);
        if ($result === true) {
            // 罕见但可能：localhost 上 connect 立即完成（loopback 内核栈），无需 select。
            return $workerSocket;
        }

        $error = \socket_last_error($workerSocket);

        // 非阻塞连接中，EINPROGRESS/EALREADY（POSIX）或 WSAEWOULDBLOCK=10035（Windows）是正常的
        $isPending = $error === SOCKET_EINPROGRESS
            || $error === SOCKET_EALREADY
            || (PHP_OS_FAMILY === 'Windows' && $error === 10035);
        if (!$isPending) {
            \socket_close($workerSocket);
            return false;
        }

        // P0-2：用可配置的 workerConnectSelectTimeoutSec 替代旧 0.3-0.5s 硬上限。
        // 默认 0.02s 对 localhost Worker 绰绰有余；远端后端可通过 worker_connect_select_timeout_sec 覆盖。
        // 高并发失败路径下，单次 connect 的最差阻塞从 500ms → 100ms。
        $failoverTimeout = \max(0.01, \min(
            $connectTimeoutOverride ?? $this->workerConnectSelectTimeoutSec,
            (float) $this->connectTimeout
        ));
        $seconds = (int) $failoverTimeout;
        $microseconds = (int) (($failoverTimeout - $seconds) * 1_000_000);

        $write = [$workerSocket];
        $read = null;
        $except = null;
        $ready = @\socket_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false || $ready === 0) {
            \socket_close($workerSocket);
            return false;
        }

        // 检查连接是否真的成功
        $optval = \socket_get_option($workerSocket, SOL_SOCKET, SO_ERROR);
        if ($optval !== 0) {
            \socket_close($workerSocket);
            return false;
        }

        return $workerSocket;
    }
    
    // ==================== Worker 健康状态管理 ====================
    
    /**
     * 检查 Worker 是否在黑名单中
     *
     * Worker 连续失败 WORKER_FAIL_THRESHOLD 次后会被加入黑名单。
     * 黑名单中的 Worker 在 WORKER_BLACKLIST_RECOVERY_SECONDS 后自动移出，重新尝试。
     *
     * @param int $port Worker 端口
     * @return bool 是否在黑名单中
     */
    private function isWorkerBlacklisted(int $port): bool
    {
        if (!isset($this->workerHealth[$port])) {
            return false;
        }

        $health = $this->workerHealth[$port];

        // 不在黑名单中
        if ($health['blacklisted_at'] <= 0) {
            return false;
        }

        // 检查是否到了恢复时间
        $elapsed = \microtime(true) - $health['blacklisted_at'];
        if ($elapsed >= self::WORKER_BLACKLIST_RECOVERY_SECONDS) {
            // 到恢复时间，自动移出黑名单，重置失败计数
            $this->workerHealth[$port]['blacklisted_at'] = 0.0;
            $this->workerHealth[$port]['failures'] = 0;
            return false;
        }

        return true;
    }
    
    /**
     * 记录 Worker 连接成功
     *
     * @param int $port Worker 端口
     */
    private function recordWorkerSuccess(int $port): void
    {
        if (!isset($this->workerHealth[$port])) {
            $this->workerHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => \microtime(true),
                'total_failures' => 0,
            ];
            return;
        }
        
        $this->workerHealth[$port]['failures'] = 0;
        $this->workerHealth[$port]['blacklisted_at'] = 0.0;
        $this->workerHealth[$port]['last_success'] = \microtime(true);
    }
    
    /**
     * 记录 Worker 连接失败
     *
     * 连续失败次数达到阈值后，将 Worker 加入黑名单。
     *
     * @param int $port Worker 端口
     */
    private function recordWorkerFailure(int $port, bool $debounceRecentSuccess = false): void
    {
        $this->stats['worker_failures']++;
        
        if (!isset($this->workerHealth[$port])) {
            $this->workerHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => 0.0,
                'total_failures' => 0,
            ];
        }

        $this->workerHealth[$port]['total_failures']++;

        if ($debounceRecentSuccess && $this->shouldDebounceRecentConnectFailure($port)) {
            $this->workerHealth[$port]['failures'] = \min(
                ((int)($this->workerHealth[$port]['failures'] ?? 0)) + 1,
                self::WORKER_FAIL_THRESHOLD - 1
            );
            $this->workerHealth[$port]['blacklisted_at'] = 0.0;
            return;
        }

        $this->workerHealth[$port]['failures']++;
        
        // 达到阈值，加入黑名单
        if ($this->workerHealth[$port]['failures'] >= self::WORKER_FAIL_THRESHOLD
            && $this->workerHealth[$port]['blacklisted_at'] <= 0) {
            $this->workerHealth[$port]['blacklisted_at'] = \microtime(true);
        }
    }

    private function shouldDebounceRecentConnectFailure(int $port): bool
    {
        $lastSuccessAt = (float)($this->workerHealth[$port]['last_success'] ?? 0.0);
        if ($lastSuccessAt <= 0.0) {
            return false;
        }

        return (\microtime(true) - $lastSuccessAt) <= self::RECENT_CONNECT_FAILURE_GRACE_SEC;
    }

    /**
     * 外部上报 Worker 失败（如 Dispatcher 检测到首包超时）。
     */
    public function markWorkerFailureByPort(int $port): void
    {
        $this->recordWorkerFailure($port);
    }

    /**
     * 主动探活：尝试连接到所有黑名单中的 Worker
     * 
     * 由 Dispatcher 定期调用，用于提前发现已恢复的 Worker，
     * 而不是等到有新请求时才尝试。
     * 
     * 优化：对于只有 1 个 Worker（维护模式）的情况，立即尝试探活而不等待恢复时间。
     *
     * @return array 恢复的 Worker 端口列表
     */
    public function probeBlacklistedWorkers(): array
    {
        $recovered = [];
        $now = \microtime(true);
        
        // 维护模式快速恢复：若当前只有 1 个 Worker 且在黑名单中，立即探活
        if ($this->workerCount === 1) {
            $maintenancePort = $this->workerPorts[0];
            $health = $this->workerHealth[$maintenancePort] ?? null;
            if ($health !== null && ($health['blacklisted_at'] ?? 0.0) > 0) {
                // 跳过等待时间，立即探活
                if ($this->probeWorkerApplicationHealth($maintenancePort)) {
                    $this->recordWorkerSuccess($maintenancePort);
                    $recovered[] = $maintenancePort;
                }
            }
            return $recovered;
        }

        // 正常多 Worker 场景：只恢复超过等待时间的 Worker
        foreach ($this->workerHealth as $port => $health) {
            if (($health['blacklisted_at'] ?? 0.0) <= 0) {
                continue;
            }
            if (($now - (float)$health['blacklisted_at']) < self::WORKER_BLACKLIST_RECOVERY_SECONDS) {
                continue;
            }
            if ($this->probeWorkerApplicationHealth((int)$port)) {
                $this->recordWorkerSuccess((int)$port);
                $recovered[] = (int)$port;
            }
        }

        return $recovered;
    }

    /**
     * Periodically verify every active business worker in the dispatcher pool.
     *
     * This is intentionally stronger than the transient blacklist path: a
     * worker that cannot pass its own health endpoint should leave the
     * dispatcher pool so Master can recycle the slot instead of letting a bad
     * process keep occupying capacity.
     *
     * @return array{healthy: int[], failed: array<int, string>}
     */
    public function auditWorkerApplicationHealth(): array
    {
        if (!$this->workerHealthAuditEnabled) {
            return [
                'healthy' => \array_values(\array_map('intval', $this->workerPorts)),
                'failed' => [],
            ];
        }

        $healthy = [];
        $failed = [];
        $connectTimeout = $this->workerHealthConnectTimeoutSec;
        $responseTimeout = $this->workerHealthResponseTimeoutSec;

        foreach ($this->workerPorts as $port) {
            $port = (int)$port;
            if ($port <= 0 || $this->isWorkerManuallyBlacklisted($port)) {
                continue;
            }

            $lastSuccessAt = (float)($this->workerHealth[$port]['last_success'] ?? 0.0);
            $probe = $this->requestWorkerHealth($port, $connectTimeout, $responseTimeout);
            if (!empty($probe['success'])) {
                $this->recordWorkerSuccess($port);
                $healthy[] = $port;
                continue;
            }

            $this->recordWorkerFailure($port);
            $probeError = (string)($probe['error'] ?? 'health probe failed');
            if ($this->shouldDebounceHealthAuditFailure($probeError, $lastSuccessAt)) {
                $this->workerHealth[$port]['blacklisted_at'] = 0.0;
                $this->workerHealth[$port]['failures'] = \min(
                    (int)($this->workerHealth[$port]['failures'] ?? 0),
                    self::WORKER_FAIL_THRESHOLD - 1
                );
                continue;
            }

            $failureCount = (int)($this->workerHealth[$port]['failures'] ?? 0);
            if ($failureCount >= self::WORKER_FAIL_THRESHOLD) {
                $failed[$port] = $probeError;
            }
        }

        return [
            'healthy' => $healthy,
            'failed' => $failed,
        ];
    }

    private function shouldDebounceHealthAuditFailure(string $error, float $lastSuccessAt): bool
    {
        if ($lastSuccessAt <= 0.0) {
            return false;
        }
        if ((\microtime(true) - $lastSuccessAt) > self::HEALTH_AUDIT_RECENT_SUCCESS_GRACE_SEC) {
            return false;
        }

        return \str_contains($error, 'timeout')
            || \str_contains($error, 'temporarily unavailable')
            || \str_contains($error, 'would block');
    }

    private function isWorkerManuallyBlacklisted(int $port): bool
    {
        $blacklistedAt = (float)($this->workerHealth[$port]['blacklisted_at'] ?? 0.0);
        if ($blacklistedAt <= 0.0) {
            return false;
        }

        return $blacklistedAt > (\microtime(true) + self::WORKER_BLACKLIST_RECOVERY_SECONDS);
    }

    private function probeWorkerApplicationHealth(int $port): bool
    {
        $probe = $this->requestWorkerHealth(
            $port,
            $this->workerHealthConnectTimeoutSec,
            $this->workerHealthResponseTimeoutSec
        );

        return $probe['success'];
    }
    
    // ========== IPC 控制通道：外部调用接口 ==========
    
    /**
     * 将指定端口加入黑名单（IPC drain 命令）
     *
     * 与 recordWorkerFailure 不同，这是 Master 通过控制通道直接指令，
     * 立即加入黑名单，不等待失败阈值。使用远未来的时间戳防止自动恢复。
     */
    public function blacklistWorker(int $port): void
    {
        if (!isset($this->workerHealth[$port])) {
            $this->workerHealth[$port] = [
                'failures' => self::WORKER_FAIL_THRESHOLD,
                'blacklisted_at' => \microtime(true) + 86400 * 365, // 远未来：不自动恢复，等 undrain 命令
                'last_success' => 0.0,
                'total_failures' => 0,
            ];
        } else {
            // 设置为远未来时间，阻止自动恢复
            $this->workerHealth[$port]['blacklisted_at'] = \microtime(true) + 86400 * 365;
            $this->workerHealth[$port]['failures'] = self::WORKER_FAIL_THRESHOLD;
        }
    }
    
    /**
     * 将指定端口从黑名单移除（IPC undrain 命令）
     */
    public function unblacklistWorker(int $port): void
    {
        if (isset($this->workerHealth[$port])) {
            $this->workerHealth[$port]['blacklisted_at'] = 0.0;
            $this->workerHealth[$port]['failures'] = 0;
        }
    }

    /**
     * 更新 Worker 长连接饱和状态
     *
     * 当 Worker 上报饱和时，将该 Worker 标记为"长连接饱和"，
     * 在饱和解除前，新连接会绕过该 Worker。
     * 与黑名单不同：饱和状态下 Worker 仍可处理现有长连接，只是不再接受新分配。
     *
     * @param int $port Worker 端口
     * @param int $longLivedCount 当前长连接数
     * @param int $longLivedMax 长连接上限
     */
    public function setWorkerSaturation(int $port, int $longLivedCount, int $longLivedMax): void
    {
        $this->workerSaturation[$port] = [
            'long_lived_count' => $longLivedCount,
            'long_lived_max' => $longLivedMax,
            'saturated_at' => \microtime(true),
        ];
    }

    /**
     * 清除 Worker 长连接饱和状态（当 Worker 上报饱和解除时调用）
     *
     * @param int $port Worker 端口
     */
    public function clearWorkerSaturation(int $port): void
    {
        if (isset($this->workerSaturation[$port])) {
            unset($this->workerSaturation[$port]);
        }
    }

    /**
     * 检查 Worker 是否处于长连接饱和状态
     *
     * 饱和状态下，该 Worker 不应被分配新连接（但现有长连接仍正常工作）。
     *
     * @param int $port Worker 端口
     * @return bool
     */
    public function isWorkerSaturated(int $port): bool
    {
        if (!isset($this->workerSaturation[$port])) {
            return false;
        }
        $sat = $this->workerSaturation[$port];
        // 饱和超时：超过 60 秒自动解除（假设 Worker 会重新上报）
        if ($sat['saturated_at'] > 0 && (\microtime(true) - $sat['saturated_at']) > 60.0) {
            unset($this->workerSaturation[$port]);
            return false;
        }
        return true;
    }
    
    /**
     * 动态添加 Worker 端口到负载均衡池（内部探活工具）。
     *
     * @return array{accepted: bool, error: string}
     */
    public function addWorkerPort(int $port): array
    {
        if ($port <= 0) {
            return ['accepted' => false, 'error' => 'invalid worker port'];
        }

        if (\in_array($port, $this->workerPorts, true)) {
            return ['accepted' => true, 'error' => ''];
        }

        $warmup = $this->warmupWorkerTrustingMasterReady($port);
        if (!$warmup['success']) {
            return ['accepted' => false, 'error' => $warmup['error']];
        }

        $this->workerPorts[] = $port;
        $this->workerCount = \count($this->workerPorts);
        $this->workerHealth[$port] = [
            'failures' => 0,
            'blacklisted_at' => 0.0,
            'last_success' => \microtime(true),
            'total_failures' => 0,
        ];
        $this->writeStderr("[PassthroughCore] 添加 Worker 端口: {$port}, 当前列表: " . \implode(',', $this->workerPorts) . "\n");

        return ['accepted' => true, 'error' => ''];
    }

    /**
     * Master has already observed the worker process and received READY.
     * Startup consensus must not wait for an additional in-dispatcher health probe.
     *
     * @return array{accepted: int[], rejected: array<int, string>}
     */
    public function setWorkerPortsFromMasterReady(array $ports): array
    {
        $candidatePorts = \array_values(\array_filter(
            \array_unique(\array_map('intval', $ports)),
            static fn(int $port): bool => $port > 0
        ));
        $acceptedHealth = [];
        $now = \microtime(true);
        foreach ($candidatePorts as $port) {
            $acceptedHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => $now,
                'total_failures' => (int)($this->workerHealth[$port]['total_failures'] ?? 0),
            ];
        }

        $this->applyWorkerPoolTransition($candidatePorts, $acceptedHealth);
        $this->writeStderr(
            '[PassthroughCore] SET_ROUTE_TABLE 信任 Master READY，跳过启动探活，当前列表: '
            . (\implode(',', $this->workerPorts) ?: '(空)') . "\n"
        );

        return [
            'accepted' => $candidatePorts,
            'rejected' => [],
        ];
    }

    /**
     * @return array{accepted: bool, error: string}
     */
    public function addWorkerPortFromMasterReady(int $port): array
    {
        if ($port <= 0) {
            return ['accepted' => false, 'error' => 'invalid worker port'];
        }

        $ports = $this->workerPorts;
        $ports[] = $port;
        $result = $this->setWorkerPortsFromMasterReady($ports);

        return [
            'accepted' => \in_array($port, $result['accepted'], true),
            'error' => '',
        ];
    }

    /**
     * Master has already received READY from these maintenance workers.
     * Do not repeat a startup probe here; a transient early probe failure can
     * prevent the maintenance pool from ever being published.
     *
     * @return array{accepted: int[], rejected: array<int, string>}
     */
    public function setMaintenanceWorkerPortsFromMasterReady(array $ports): array
    {
        $candidatePorts = \array_values(\array_filter(
            \array_unique(\array_map('intval', $ports)),
            static fn(int $port): bool => $port > 0
        ));

        foreach ($this->maintenanceWorkerPorts as $oldPort) {
            if (!\in_array((int) $oldPort, $candidatePorts, true)) {
                $this->closeIdleSocketsByPort((int) $oldPort);
                if (!\in_array((int) $oldPort, $this->workerPorts, true)) {
                    unset($this->workerHealth[(int) $oldPort]);
                }
            }
        }

        $now = \microtime(true);
        foreach ($candidatePorts as $port) {
            $this->workerHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => $now,
                'total_failures' => (int)($this->workerHealth[$port]['total_failures'] ?? 0),
            ];
        }

        $this->maintenanceWorkerPorts = $candidatePorts;
        $this->writeStderr(
            '[PassthroughCore] SET_ROUTE_TABLE trust Master READY maintenance candidates: '
            . (\implode(',', $this->maintenanceWorkerPorts) ?: '(none)') . "\n"
        );
        $this->logMaintenanceDecision(
            'maintenance_pool_master_ready:' . \implode(',', $this->maintenanceWorkerPorts),
            'Maintenance worker pool accepted from Master READY, ' . $this->formatMaintenanceLogContext(),
            'INFO',
            0.0
        );

        return [
            'accepted' => $candidatePorts,
            'rejected' => [],
        ];
    }

    public function setWarmupCooperativeYield(?\Closure $yield): void
    {
        $this->warmupCooperativeYield = $yield;
    }

    private function hasWarmupCooperativeFiber(): bool
    {
        return $this->warmupCooperativeYield !== null && \Fiber::getCurrent() !== null;
    }

    private function warmupYield(): void
    {
        if ($this->hasWarmupCooperativeFiber()) {
            ($this->warmupCooperativeYield)();
        }
    }

    /**
     * 预热阶段延迟：若在 Dispatcher 入池 Fiber 内，走协作让出，避免阻塞主循环；
     * 否则回退为真实微睡眠（兼容历史同步调用路径）。
     */
    private function warmupDelayUsec(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->hasWarmupCooperativeFiber()) {
            $this->warmupYield();
            return;
        }

        SchedulerSystem::usleep($microseconds);
    }

    /**
     * Master 已收到 Worker READY 后的轻量探活（SET_ROUTE_TABLE）。
     * 连接与重试更紧，避免重复等待「Worker 尚未 listen」的长窗口。
     *
     * 入池语义采用双条件：
     * 1) /_wls/health 可用；
     * 2) 首页预热通过（触发真实路由/框架初始化）。
     *
     * 首页预热实现为“非阻塞 I/O + 协作式分片推进”，避免单次阻塞调用占住 Dispatcher 主循环。
     */
    protected function warmupWorkerTrustingMasterReady(int $port): array
    {
        $maxRetries = self::IPC_READY_WARMUP_MAX_ATTEMPTS;
        $retryDelay = self::IPC_READY_WARMUP_RETRY_DELAY_USEC;
        $lastError = 'warmup failed';
        $configured = (float) $this->connectTimeout;
        $connectTimeout = \max(
            self::IPC_READY_WARMUP_CONNECT_MIN,
            \min($configured > 0 ? $configured : self::IPC_READY_WARMUP_CONNECT_MAX, self::IPC_READY_WARMUP_CONNECT_MAX)
        );
        $responseTimeout = self::IPC_READY_WARMUP_RESPONSE_SEC;
        $startedAt = \microtime(true);

        $this->logWarmup(
            "IPC 入池探活 Worker:{$port} path=" . self::WORKER_HEALTH_PATH
            . " protocol=" . ($this->workerSslEnabled ? 'ssl' : 'tcp')
            . " connect_timeout={$connectTimeout}s response_timeout={$responseTimeout}s retries={$maxRetries}",
            'INFO'
        );

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->warmupYield();
            $probe = $this->requestWorkerHealth($port, $connectTimeout, $responseTimeout);
            if ($probe['success']) {
                $elapsed = $probe['elapsed'] ?? 0.0;
                $statusLine = (string)($probe['status_line'] ?? 'HTTP/1.1 200 OK');
                $this->logWarmup(
                    "Worker:{$port} IPC 入池探活成功 (耗时 {$elapsed}s, 尝试 {$attempt}/{$maxRetries}, response=\"{$statusLine}\")",
                    'INFO'
                );
                if (isset($this->workerHealth[$port])) {
                    $this->workerHealth[$port]['last_success'] = \microtime(true);
                }
                if ($this->shouldAdmitWorkerAfterHealthProbeOnly()) {
                    $this->logWarmup(
                        $this->homepageWarmupEnabled
                            ? "Worker:{$port} health probe passed; homepage warmup deferred"
                            : "Worker:{$port} health probe passed; homepage warmup disabled",
                        'INFO'
                    );
                    return ['success' => true, 'error' => ''];
                    $this->logWarmup(
                        "Worker:{$port} IPC 入池健康探活已通过，配置已关闭首页预热",
                        'INFO'
                    );
                    return ['success' => true, 'error' => ''];
                }
                $homeWarmup = $this->warmupWorkerViaHomepage(
                    $port,
                    self::IPC_READY_HOMEPAGE_WARMUP_RETRIES,
                    self::IPC_READY_HOMEPAGE_CONNECT_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_TLS_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_WRITE_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_READ_TIMEOUT_SEC
                );
                if (!$homeWarmup['success']) {
                    $homeErr = (string) ($homeWarmup['error'] ?? 'homepage warmup failed');
                    $this->logWarmup(
                        "Worker:{$port} 首页预热失败，但健康探活已通过，允许入池: {$homeErr}",
                        'WARNING'
                    );
                }
                return ['success' => true, 'error' => ''];
            }

            $lastError = (string)($probe['error'] ?? $lastError);
            $elapsed = $probe['elapsed'] ?? 0.0;
            $shouldRetry = $this->shouldContinueTrustingMasterReadyWarmup($attempt, $startedAt);
            $this->logWarmup(
                "Worker:{$port} IPC 入池探活失败 (尝试 {$attempt}/{$maxRetries}, 耗时 {$elapsed}s): {$lastError}",
                $attempt === $maxRetries ? 'ERROR' : 'WARNING'
            );
            if ($shouldRetry) {
                $this->warmupYield();
                $this->warmupDelayUsec($retryDelay);
                continue;
            }

            break;
        }

        return ['success' => false, 'error' => $lastError];
    }

    protected function shouldAdmitWorkerAfterHealthProbeOnly(): bool
    {
        return true;
    }

    private function shouldContinueTrustingMasterReadyWarmup(int $attempt, float $startedAt): bool
    {
        if ($attempt < self::IPC_READY_WARMUP_MIN_ATTEMPTS) {
            return true;
        }

        if ($attempt >= self::IPC_READY_WARMUP_MAX_ATTEMPTS) {
            return false;
        }

        return (\microtime(true) - $startedAt) < self::IPC_READY_WARMUP_RETRY_GRACE_SEC;
    }

    private function buildWorkerHomepageWarmupRequest(string $host = 'localhost', string $path = '/', string $cookieHeader = ''): string
    {
        $host = \str_replace(["\r", "\n"], '', \trim($host));
        $path = \str_replace(["\r", "\n"], '', \trim($path));
        $cookieHeader = \str_replace(["\r", "\n"], '', \trim($cookieHeader));
        if ($host === '') {
            $host = 'localhost';
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . ($cookieHeader !== '' ? "Cookie: {$cookieHeader}\r\n" : '')
            . InternalRequestLabel::buildHeaderLine(InternalRequestLabel::HOMEPAGE_WARMUP)
            . "Connection: close\r\n\r\n";
    }

    private function normalizeHomepageWarmupHosts(mixed $value): array
    {
        $items = $this->normalizeWarmupList($value);
        $hosts = [];
        foreach ($items as $item) {
            $host = \str_replace(["\r", "\n", "\t"], '', \trim($item));
            if ($host === '') {
                continue;
            }
            $hosts[] = $host;
        }

        return \array_values(\array_unique($hosts));
    }

    private function normalizeHomepageWarmupPaths(mixed $value): array
    {
        $items = $this->normalizeWarmupList($value);
        $paths = [];
        foreach ($items as $item) {
            $path = \str_replace(["\r", "\n", "\t"], '', \trim($item));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $paths[] = $path;
        }

        return \array_values(\array_unique($paths));
    }

    private function normalizeHomepageWarmupCookies(mixed $value): array
    {
        $items = $this->normalizeWarmupList($value);
        $cookies = [''];
        foreach ($items as $item) {
            $cookie = \str_replace(["\r", "\n", "\t"], '', \trim($item));
            if ($cookie === '') {
                continue;
            }
            $cookies[$cookie] = $cookie;
        }

        return \array_values($cookies);
    }

    private function normalizeHomepageWarmupVariants(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            $value = \is_array($decoded) ? $decoded : (\preg_split('/[,\s]+/', $value) ?: []);
        }
        if (!\is_array($value)) {
            return [''];
        }

        $cookies = [''];
        foreach ($value as $variant) {
            $lang = '';
            $currency = '';
            if (\is_array($variant)) {
                $lang = (string)($variant['lang'] ?? $variant['language'] ?? $variant[0] ?? '');
                $currency = (string)($variant['currency'] ?? $variant[1] ?? '');
            } elseif (\is_scalar($variant)) {
                $raw = \trim((string)$variant);
                if (\str_contains($raw, 'WELINE_USER_LANG=') || \str_contains($raw, 'WELINE_USER_CURRENCY=')) {
                    $cookie = \str_replace(["\r", "\n", "\t"], '', $raw);
                    if ($cookie !== '') {
                        $cookies[$cookie] = $cookie;
                    }
                    continue;
                }
                $parts = \preg_split('/[:|\/,]/', $raw, 2) ?: [];
                $lang = (string)($parts[0] ?? '');
                $currency = (string)($parts[1] ?? '');
            }

            $lang = \str_replace('-', '_', \trim($lang));
            $currency = \strtoupper(\trim($currency));
            if ($lang === '' || !\preg_match('/^[a-z]{2}_[A-Za-z0-9_]{2,}$/', $lang)) {
                continue;
            }
            if (!State::isAllowedCurrencyCode($currency)) {
                continue;
            }

            $cookie = "WELINE_USER_LANG={$lang}; WELINE_USER_CURRENCY={$currency}";
            $cookies[$cookie] = $cookie;
        }

        return \array_values($cookies);
    }

    private function normalizeWarmupList(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = \preg_split('/[,\s]+/', $value) ?: [];
            }
        }
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $items[] = (string)$item;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{host: string, path: string, cookie: string}>
     */
    private function homepageWarmupTargets(): array
    {
        $hosts = $this->homepageWarmupHosts !== [] ? $this->homepageWarmupHosts : ['localhost'];
        $paths = $this->homepageWarmupPaths !== [] ? $this->homepageWarmupPaths : ['/'];
        $cookies = $this->homepageWarmupCookies !== [] ? $this->homepageWarmupCookies : [''];
        $targets = [];
        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                $pathContext = $this->parseWarmupPathContext($path);
                foreach ($cookies as $cookie) {
                    if (!$this->warmupPathAcceptsCookieContext($pathContext, $cookie)) {
                        continue;
                    }
                    $targets[] = ['host' => $host, 'path' => $path, 'cookie' => $cookie];
                    if (\count($targets) >= self::HOMEPAGE_WARMUP_MAX_TARGETS) {
                        return $targets;
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * Dispatcher 预热 Worker：验证连通性
     *
     * 预热目的：
     * 1. 验证 Dispatcher → Worker 的 TCP 连通性
     * 2. 确保 Worker 已进入事件循环，可以 accept 连接
     * 3. 避免第一个真实用户请求失败
     *
     * @return array{success: bool, error: string}
     */
    /**
     * @return array{lang: string, currency: string}
     */
    private function parseWarmupPathContext(string $path): array
    {
        $path = '/' . \ltrim($path, '/');

        if (\preg_match('#^/([A-Z]{3})/([A-Za-z]{2}_[A-Za-z0-9_]{2,})(?:/|$)#', $path, $matches)) {
            $currency = \strtoupper((string)$matches[1]);
            if (State::isAllowedCurrencyCode($currency)) {
                return [
                    'currency' => $currency,
                    'lang' => \str_replace('-', '_', (string)$matches[2]),
                ];
            }
        }

        if (\preg_match('#^/([A-Za-z]{2}_[A-Za-z0-9_]{2,})(?:/|$)#', $path, $matches)) {
            return [
                'currency' => '',
                'lang' => \str_replace('-', '_', (string)$matches[1]),
            ];
        }

        if (\preg_match('#^/([A-Z]{3})(?:/|$)#', $path, $matches)) {
            $currency = \strtoupper((string)$matches[1]);
            if (State::isAllowedCurrencyCode($currency)) {
                return [
                    'currency' => $currency,
                    'lang' => '',
                ];
            }
        }

        return ['lang' => '', 'currency' => ''];
    }

    /**
     * @param array{lang: string, currency: string} $pathContext
     */
    private function warmupPathAcceptsCookieContext(array $pathContext, string $cookie): bool
    {
        $cookie = \trim($cookie);
        if ($cookie === '') {
            return true;
        }

        $cookieContext = $this->parseWarmupCookieContext($cookie);
        if ($cookieContext['lang'] === '' && $cookieContext['currency'] === '') {
            return true;
        }

        if ($pathContext['lang'] !== '' && $cookieContext['lang'] !== '' && $pathContext['lang'] !== $cookieContext['lang']) {
            return false;
        }

        if ($pathContext['currency'] !== ''
            && $cookieContext['currency'] !== ''
            && $pathContext['currency'] !== $cookieContext['currency']
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array{lang: string, currency: string}
     */
    private function parseWarmupCookieContext(string $cookie): array
    {
        $context = ['lang' => '', 'currency' => ''];
        foreach (\explode(';', $cookie) as $part) {
            $pair = \explode('=', \trim($part), 2);
            if (\count($pair) !== 2) {
                continue;
            }

            $name = \strtoupper(\trim($pair[0]));
            $value = \trim($pair[1]);
            if ($name === 'WELINE_USER_LANG') {
                $context['lang'] = \str_replace('-', '_', $value);
            } elseif ($name === 'WELINE_USER_CURRENCY') {
                $currency = \strtoupper($value);
                if (State::isAllowedCurrencyCode($currency)) {
                    $context['currency'] = $currency;
                }
            }
        }

        return $context;
    }

    protected function warmupWorkerViaHomepage(
        int $port,
        int $maxRetries = 3,
        float $connectTimeoutSeconds = 5.0,
        float $tlsTimeoutSeconds = 8.0,
        float $writeTimeoutSeconds = 5.0,
        float $readTimeoutSeconds = 60.0,
        ?int $maxTargets = null,
        bool $requireAllTargets = false
    ): array
    {
        $targets = $this->homepageWarmupTargets();
        if ($maxTargets !== null && $maxTargets > 0 && \count($targets) > $maxTargets) {
            $targets = \array_slice($targets, 0, $maxTargets);
        }
        $successCount = 0;
        $failures = [];
        foreach ($targets as $target) {
            $result = $this->warmupWorkerViaHomepageTarget(
                $port,
                (string)$target['host'],
                (string)$target['path'],
                (string)($target['cookie'] ?? ''),
                $maxRetries,
                $connectTimeoutSeconds,
                $tlsTimeoutSeconds,
                $writeTimeoutSeconds,
                $readTimeoutSeconds
            );
            if (!($result['success'] ?? false)) {
                $failures[] = (string)$target['host'] . (string)$target['path'] . ': ' . (string)($result['error'] ?? 'warmup failed');
                continue;
            }

            $successCount++;
        }

        if ($requireAllTargets && $failures !== []) {
            return [
                'success' => false,
                'error' => \implode('; ', \array_slice($failures, 0, 5)) ?: 'homepage warmup incomplete',
            ];
        }

        if ($successCount > 0) {
            if ($failures !== []) {
                $this->logWarmup(
                    "Worker:{$port} homepage warmup partial success {$successCount}/" . \count($targets)
                    . '; failed=' . \implode('; ', \array_slice($failures, 0, 5)),
                    'WARNING'
                );
            }

            return ['success' => true, 'error' => ''];
        }

        if ($targets !== []) {
            return [
                'success' => false,
                'error' => \implode('; ', \array_slice($failures, 0, 5)) ?: 'homepage warmup failed',
            ];
        }

        return ['success' => true, 'error' => ''];
    }

    private function warmupWorkerViaHomepageTarget(
        int $port,
        string $hostHeader,
        string $path,
        string $cookieHeader = '',
        int $maxRetries = 3,
        float $connectTimeoutSeconds = 5.0,
        float $tlsTimeoutSeconds = 8.0,
        float $writeTimeoutSeconds = 5.0,
        float $readTimeoutSeconds = 60.0
    ): array
    {
        $this->writeStderr("[PassthroughCore] 开始预热 Worker:{$port}...\n");

        $retryDelay = 100000; // 100ms in microseconds
        $lastError = 'warmup failed';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $conn = null;
            try {
                // 真异步：非阻塞 connect + 分片 wait（每轮可 warmupYield）
                $context = $this->createWorkerHealthContext();
                $conn = @\stream_socket_client(
                    "tcp://{$this->workerHost}:{$port}",
                    $errno,
                    $errstr,
                    0.0,
                    \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
                    $context
                );

                if (!$conn) {
                    $lastError = "connect failed: {$errstr} (errno={$errno})";
                    $this->writeStderr("[PassthroughCore] Worker:{$port} 预热连接失败 (尝试 {$attempt}/{$maxRetries}): {$errstr} (errno={$errno})\n");
                    if ($attempt < $maxRetries) {
                        $this->warmupYield();
                        $this->warmupDelayUsec($retryDelay);
                        continue;
                    }
                    break;
                }

                \stream_set_blocking($conn, false);

                $connectDeadline = \microtime(true) + \max(0.2, $connectTimeoutSeconds);
                $connected = $this->waitForStreamReady($conn, false, true, $connectDeadline);
                if ($connected === false) {
                    $lastError = 'connect wait select failed';
                    $this->writeStderr("[PassthroughCore] Worker:{$port} 预热连接等待失败 (尝试 {$attempt}/{$maxRetries})\n");
                    if ($attempt < $maxRetries) {
                        $this->warmupYield();
                        $this->warmupDelayUsec($retryDelay);
                        continue;
                    }
                    break;
                }
                if ($connected === 0) {
                    $lastError = 'connect timeout after ' . \max(0.2, $connectTimeoutSeconds) . 's';
                    $this->writeStderr("[PassthroughCore] Worker:{$port} 预热连接超时 (尝试 {$attempt}/{$maxRetries})\n");
                    if ($attempt < $maxRetries) {
                        $this->warmupYield();
                        $this->warmupDelayUsec($retryDelay);
                        continue;
                    }
                    break;
                }

                if ($this->workerSslEnabled) {
                    $tlsDeadline = \microtime(true) + \max(0.3, $tlsTimeoutSeconds);
                    $tlsOk = false;
                    while (\microtime(true) < $tlsDeadline) {
                        $this->warmupYield();
                        $crypto = @\stream_socket_enable_crypto(
                            $conn,
                            true,
                            \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                        );
                        if ($crypto === true) {
                            $tlsOk = true;
                            break;
                        }
                        if ($crypto === false) {
                            $lastError = 'tls handshake failed';
                            break;
                        }
                        $ready = $this->waitForStreamReady($conn, true, true, $tlsDeadline);
                        if ($ready === false) {
                            $lastError = 'tls handshake select failed';
                            break;
                        }
                        if ($ready === 0) {
                            $lastError = 'tls handshake timeout after ' . \max(0.3, $tlsTimeoutSeconds) . 's';
                            break;
                        }
                    }
                    if (!$tlsOk) {
                        $this->writeStderr("[PassthroughCore] Worker:{$port} TLS 握手失败 (尝试 {$attempt}/{$maxRetries}): {$lastError}\n");
                        if ($attempt < $maxRetries) {
                            $this->warmupYield();
                            $this->warmupDelayUsec($retryDelay);
                            continue;
                        }
                        break;
                    }
                }

                // 发送真实的首页请求来触发框架初始化（而不是简单的健康检查）
                // 这样可以预热框架、数据库连接、缓存等，避免第一个用户请求过慢
                $request = $this->buildWorkerHomepageWarmupRequest($hostHeader, $path, $cookieHeader);
                $writeOffset = 0;
                $writeDeadline = \microtime(true) + \max(0.2, $writeTimeoutSeconds);
                while ($writeOffset < \strlen($request)) {
                    $this->warmupYield();
                    $written = @\fwrite($conn, \substr($request, $writeOffset));
                    if (\is_int($written) && $written > 0) {
                        $writeOffset += $written;
                        continue;
                    }
                    $ready = $this->waitForStreamReady($conn, false, true, $writeDeadline);
                    if ($ready === false) {
                        $lastError = 'warmup request write select failed';
                        break;
                    }
                    if ($ready === 0) {
                        $lastError = 'warmup request write timeout after ' . \max(0.2, $writeTimeoutSeconds) . 's';
                        break;
                    }
                }
                if ($writeOffset < \strlen($request)) {
                    $this->writeStderr("[PassthroughCore] Worker:{$port} 预热写入失败 (尝试 {$attempt}/{$maxRetries}): {$lastError}\n");
                    if ($attempt < $maxRetries) {
                        $this->warmupYield();
                        $this->warmupDelayUsec($retryDelay);
                        continue;
                    }
                    break;
                }

                // 读取响应头（检查 HTTP 状态码）
                // 注意：这里只读取响应头，不读取完整的 HTML 内容，以节省时间
                $response = '';
                $startTime = \microtime(true);
                $readDeadline = $startTime + \max(0.3, $readTimeoutSeconds);
                while (!\str_contains($response, "\r\n\r\n") && \microtime(true) < $readDeadline) {
                    $this->warmupYield();
                    $chunk = @\fread($conn, 1024);
                    if (\is_string($chunk) && $chunk !== '') {
                        $response .= $chunk;
                        continue;
                    }
                    if (\feof($conn)) {
                        break;
                    }
                    $ready = $this->waitForStreamReady($conn, true, false, $readDeadline);
                    if ($ready === false) {
                        $lastError = 'warmup response read select failed';
                        break;
                    }
                    if ($ready === 0) {
                        $lastError = 'warmup response timeout after ' . \max(0.3, $readTimeoutSeconds) . 's';
                        break;
                    }
                }

                // 2xx：正常业务；503：维护/过载页仍表示 HTTP 栈与路由可用（维护 Worker 常见）
                if ($response && \preg_match('/HTTP\/1\.[01]\s+(?:2\d{2}|503)\b/', $response)) {
                    $bodyDrainDeadline = \min(
                        $readDeadline,
                        \microtime(true) + self::HOMEPAGE_WARMUP_BODY_DRAIN_SEC
                    );
                    $bodyBytes = $this->drainWarmupResponseBody(
                        $conn,
                        $response,
                        $bodyDrainDeadline,
                        self::HOMEPAGE_WARMUP_BODY_DRAIN_BYTES
                    );
                    $elapsed = \round(\microtime(true) - $startTime, 2);
                    $this->writeStderr("[PassthroughCore] Worker:{$port} 预热成功 ✓ (耗时 {$elapsed}s, 尝试 {$attempt}/{$maxRetries})\n");
                    if (isset($this->workerHealth[$port])) {
                        $this->workerHealth[$port]['last_success'] = \microtime(true);
                    }
                    return ['success' => true, 'error' => ''];
                }

                $preview = \substr($response ?: '', 0, 100);
                $lastError = 'unexpected warmup response: ' . $preview;
                $this->writeStderr("[PassthroughCore] Worker:{$port} 预热响应异常 (尝试 {$attempt}/{$maxRetries}): {$preview}\n");
                if ($attempt < $maxRetries) {
                    $this->warmupYield();
                    $this->warmupDelayUsec($retryDelay);
                    continue;
                }
            } catch (\Throwable $e) {
                $lastError = 'warmup exception: ' . $e->getMessage();
                $this->writeStderr("[PassthroughCore] Worker:{$port} 预热异常 (尝试 {$attempt}/{$maxRetries}): " . $e->getMessage() . "\n");
                if ($attempt < $maxRetries) {
                    $this->warmupYield();
                    $this->warmupDelayUsec($retryDelay);
                    continue;
                }
            } finally {
                if (\is_resource($conn)) {
                    @\fclose($conn);
                }
            }
        }

        return ['success' => false, 'error' => $lastError];
    }

    /**
     * 等待 stream 在截止时间前变为可读/可写。
     *
     * @param resource $stream
     * @return int|false 1=就绪，0=超时，false=select 异常
     */
    /**
     * Drain the warmup response so the worker fully completes page generation
     * and process-local cache population before the port is published.
     *
     * @param resource $conn
     */
    private function drainWarmupResponseBody(
        $conn,
        string $initialResponse,
        float $deadline,
        int $maxBodyBytes = self::HOMEPAGE_WARMUP_BODY_DRAIN_BYTES
    ): int
    {
        $headerEnd = \strpos($initialResponse, "\r\n\r\n");
        $headerBytes = $headerEnd === false ? \strlen($initialResponse) : $headerEnd + 4;
        $bodyBytes = \max(0, \strlen($initialResponse) - $headerBytes);
        if ($maxBodyBytes <= 0 || $bodyBytes >= $maxBodyBytes) {
            return $bodyBytes;
        }
        $contentLength = null;
        if (\preg_match('/\r\nContent-Length:\s*(\d+)/i', $initialResponse, $match) === 1) {
            $contentLength = (int)$match[1];
        }

        while (\is_resource($conn)
            && !\feof($conn)
            && \microtime(true) < $deadline
            && ($contentLength === null || $bodyBytes < $contentLength)
            && $bodyBytes < $maxBodyBytes
        ) {
            $this->warmupYield();
            $chunk = @\fread($conn, \min(8192, $maxBodyBytes - $bodyBytes));
            if (\is_string($chunk) && $chunk !== '') {
                $bodyBytes += \strlen($chunk);
                continue;
            }

            $ready = $this->waitForStreamReady($conn, true, false, $deadline);
            if ($ready !== 1) {
                break;
            }
        }

        return $bodyBytes;
    }

    private function waitForStreamReady($stream, bool $read, bool $write, float $deadline): int|false
    {
        while (true) {
            $this->warmupYield();
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                return 0;
            }

            $slice = \min($remaining, $this->resolveWarmupWaitSliceSeconds());
            $sec = (int)\floor($slice);
            $usec = (int)\max(1000, \round(($slice - $sec) * 1000000));
            $readSet = $read ? [$stream] : [];
            $writeSet = $write ? [$stream] : [];
            $exceptSet = [];
            $changed = @\stream_select($readSet, $writeSet, $exceptSet, $sec, $usec);
            if ($changed === false) {
                return false;
            }
            if ($changed > 0) {
                return 1;
            }
        }
    }

    private function resolveWarmupWaitSliceSeconds(): float
    {
        // 在协作式预热（Dispatcher deferred Fiber）里用更短 slice，
        // 缩短单次阻塞片段，提升维护接管与控制面响应性。
        return $this->hasWarmupCooperativeFiber() ? 0.01 : 0.05;
    }

    /**
     * 获取当前 Worker 端口列表（调试用）
     * @return int[]
     */
    /**
     * @return resource|null
     */
    private function createWorkerHealthContext()
    {
        if (!$this->workerSslEnabled) {
            return null;
        }

        return \stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => false,
                'disable_compression' => true,
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
    }

    /**
     * @return array{success: bool, error: string, status_line?: string, elapsed: float}
     */
    protected function requestWorkerHealth(int $port, float $connectTimeout, float $responseTimeout): array
    {
        $this->warmupYield();
        $target = "tcp://{$this->workerHost}:{$port}";
        $startedAt = \microtime(true);
        $conn = @\stream_socket_client(
            $target,
            $errno,
            $errstr,
            0.0,
            \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            $this->createWorkerHealthContext()
        );

        if (!\is_resource($conn)) {
            $elapsed = \round(\microtime(true) - $startedAt, 2);
            $errorDetail = \trim((string)$errstr);
            if ($errorDetail === '') {
                $errorDetail = $elapsed >= ($connectTimeout - 0.1)
                    ? "connect timeout after {$elapsed}s"
                    : 'stream_socket_client returned no error detail';
            }
            return [
                'success' => false,
                'error' => "connect failed: {$errorDetail} (errno={$errno})",
                'elapsed' => $elapsed,
            ];
        }

        try {
            \stream_set_blocking($conn, false);

            $overallDeadline = $startedAt + \max(0.5, $connectTimeout + $responseTimeout);
            $connectDeadline = \min($overallDeadline, \microtime(true) + \max(0.2, $connectTimeout));
            $connected = $this->waitForStreamReady($conn, false, true, $connectDeadline);
            if ($connected === false) {
                return [
                    'success' => false,
                    'error' => 'health connect select failed',
                    'elapsed' => \round(\microtime(true) - $startedAt, 2),
                ];
            }
            if ($connected === 0) {
                return [
                    'success' => false,
                    'error' => 'health connect timeout after ' . \max(0.2, $connectTimeout) . 's',
                    'elapsed' => \round(\microtime(true) - $startedAt, 2),
                ];
            }

            if ($this->workerSslEnabled) {
                $tlsDeadline = \min($overallDeadline, \microtime(true) + \max(0.3, $responseTimeout));
                $tlsOk = false;
                while (\microtime(true) < $tlsDeadline) {
                    $this->warmupYield();
                    $crypto = @\stream_socket_enable_crypto(
                        $conn,
                        true,
                        \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                    );
                    if ($crypto === true) {
                        $tlsOk = true;
                        break;
                    }
                    // crypto === false 表示握手尚未完成或遇到可恢复错误，继续等待
                    if ($this->isTlsHandshakePending($crypto)) {
                        $ready = $this->waitForStreamReady($conn, true, true, $tlsDeadline);
                        if ($ready === false) {
                            // select 失败，继续循环等待
                            continue;
                        }
                        if ($ready === 0) {
                            // 超时，继续循环等待
                            continue;
                        }
                        // 读写就绪，继续下一次握手尝试
                        continue;
                    }
                }
                if (!$tlsOk) {
                    return [
                        'success' => false,
                        'error' => 'health tls handshake timeout after ' . \round(\microtime(true) - $startedAt, 2) . 's',
                        'elapsed' => \round(\microtime(true) - $startedAt, 2),
                    ];
                }
            }

            $request = $this->buildWorkerHealthRequest();
            $writeOffset = 0;
            $writeDeadline = \min($overallDeadline, \microtime(true) + \max(0.2, $responseTimeout / 2));
            $requestLen = \strlen($request);
            while ($writeOffset < $requestLen) {
                $this->warmupYield();
                $written = @\fwrite($conn, \substr($request, $writeOffset));
                if (\is_int($written) && $written > 0) {
                    $writeOffset += $written;
                    continue;
                }
                $ready = $this->waitForStreamReady($conn, false, true, $writeDeadline);
                if ($ready === false) {
                    return [
                        'success' => false,
                        'error' => 'health request write select failed',
                        'elapsed' => \round(\microtime(true) - $startedAt, 2),
                    ];
                }
                if ($ready === 0) {
                    return [
                        'success' => false,
                        'error' => 'health request write timeout',
                        'elapsed' => \round(\microtime(true) - $startedAt, 2),
                    ];
                }
            }

            $response = '';
            $closedWithoutResponse = false;
            $readDeadline = $overallDeadline;
            while (!\feof($conn) && \strlen($response) < 512 && \microtime(true) < $readDeadline) {
                $this->warmupYield();
                $chunk = @\fread($conn, 256);
                if (\is_string($chunk) && $chunk !== '') {
                    $response .= $chunk;
                    if (\str_contains($response, "\r\n")) {
                        break;
                    }
                    continue;
                }
                if (\feof($conn)) {
                    $closedWithoutResponse = ($response === '');
                    break;
                }
                $ready = $this->waitForStreamReady($conn, true, false, $readDeadline);
                if ($ready === false) {
                    return [
                        'success' => false,
                        'error' => 'health response read select failed',
                        'elapsed' => \round(\microtime(true) - $startedAt, 2),
                    ];
                }
                if ($ready === 0) {
                    break;
                }
            }

            $elapsed = \round(\microtime(true) - $startedAt, 2);
            if ($response === '') {
                return [
                    'success' => false,
                    'error' => $closedWithoutResponse
                        ? "health connection closed before response after {$elapsed}s"
                        : "health response timeout after {$elapsed}s",
                    'elapsed' => $elapsed,
                ];
            }

            $statusLine = \trim((string)(\strtok($response, "\r\n") ?: ''));
            // 503：维护/降级 Worker 仍表示 HTTP 栈已响应，应允许入池（与首页预热语义一致）
            if ($statusLine !== '' && \preg_match('/^HTTP\/1\.[01]\s+(?:2\d{2}|503)\b/', $statusLine)) {
                return [
                    'success' => true,
                    'error' => '',
                    'status_line' => $statusLine,
                    'elapsed' => $elapsed,
                ];
            }

            $preview = \substr(\trim($response), 0, 120);
            if ($preview === '') {
                $preview = 'empty response';
            }

            return [
                'success' => false,
                'error' => 'unexpected health response: ' . $preview,
                'status_line' => $statusLine,
                'elapsed' => $elapsed,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'health probe exception: ' . $e->getMessage(),
                'elapsed' => \round(\microtime(true) - $startedAt, 2),
            ];
        } finally {
            @\fclose($conn);
        }
    }

    private function isTlsHandshakePending(mixed $crypto): bool
    {
        return $crypto === false || $crypto === 0;
    }

    private function buildWorkerHealthRequest(): string
    {
        return "GET " . self::WORKER_HEALTH_PATH . " HTTP/1.1\r\n"
            . "Host: {$this->workerHost}\r\n"
            . InternalRequestLabel::buildHeaderLine(InternalRequestLabel::HEALTH_PROBE)
            . "Connection: close\r\n\r\n";
    }

    private function logWarmup(string $message, string $level = 'INFO'): void
    {
        $message = '[PassthroughCore] ' . $message;
        match (\strtoupper($level)) {
            'DEBUG' => WlsLogger::debug_($message),
            'WARN', 'WARNING' => WlsLogger::warning_($message),
            'ERROR' => WlsLogger::error_($message),
            default => WlsLogger::info_($message),
        };
        $this->writeStderr($message . "\n");
    }

    private function logMaintenanceDecision(
        string $key,
        string $message,
        string $level = 'INFO',
        float $throttleSec = 10.0
    ): void
    {
        $now = \microtime(true);
        $lastLoggedAt = (float) ($this->maintenanceDecisionLoggedAt[$key] ?? 0.0);
        if ($throttleSec > 0.0 && ($now - $lastLoggedAt) < $throttleSec) {
            return;
        }

        $this->maintenanceDecisionLoggedAt[$key] = $now;
        $this->logWarmup($message, $level);
    }

    private function formatMaintenanceLogContext(): string
    {
        $businessPorts = $this->workerPorts;
        $maintenancePorts = $this->maintenanceWorkerPorts;
        \sort($businessPorts, SORT_NUMERIC);
        \sort($maintenancePorts, SORT_NUMERIC);

        return 'business_pool=' . ($businessPorts !== [] ? \implode(',', $businessPorts) : '(empty)')
            . ', maintenance_candidates=' . ($maintenancePorts !== [] ? \implode(',', $maintenancePorts) : '(none)')
            . ', maintenance_port=' . ($this->maintenancePort > 0 ? (string) $this->maintenancePort : '(none)');
    }

    public function getWorkerPorts(): array
    {
        return $this->workerPorts;
    }

    /**
     * 向 STDERR 写入一行，仅在流可写时写入，避免守护进程下 STDERR 关闭导致 Notice (errno=5 EIO)
     */
    private function writeStderr(string $message): void
    {
        if (!\defined('STDERR') || !\is_resource(\STDERR)) {
            return;
        }
        $meta = @\stream_get_meta_data(\STDERR);
        if (empty($meta['mode']) || (!\str_contains($meta['mode'], 'w') && !\str_contains($meta['mode'], 'a'))) {
            return;
        }
        $prev = \set_error_handler(static fn () => true, \E_WARNING | \E_NOTICE);
        try {
            @\fwrite(\STDERR, $message);
        } finally {
            \restore_error_handler();
        }
    }
    
    /**
     * 从负载均衡池移除 Worker 端口。
     *
     * @param int $port Worker 端口
     * @return int[] 受影响的客户端连接 ID 列表（需要在 Dispatcher 层关闭）
     */
    public function removeWorkerPort(int $port): array
    {
        // 从动态端口列表移除
        $key = \array_search($port, $this->workerPorts, true);
        if ($key !== false) {
            \array_splice($this->workerPorts, $key, 1);
            $this->workerCount = \count($this->workerPorts);
        }

        // 清理健康记录
        unset($this->workerHealth[$port]);
        $this->clearJoinedWorkerHomepageWarmupTicket($port);
        $this->closeIdleSocketsByPort($port);

        // 收集所有使用该 Worker 的活跃客户端连接 ID
        $affectedConnIds = [];
        foreach ($this->connections as $connId => $conn) {
            if (($conn['port'] ?? 0) === $port) {
                $affectedConnIds[] = $connId;
            }
        }

        return $affectedConnIds;
    }

    /**
     * SET_ROUTE_TABLE 渐进发布：每预热成功一个端口即更新池，使维护 Worker 不必等待后续业务 Worker。
     *
     * @param int[] $newPorts
     * @param array<int, array{failures: int, blacklisted_at: float, last_success: float, total_failures: int}> $newHealth
     */
    private function applyWorkerPoolTransition(array $newPorts, array $newHealth): void
    {
        $prev = $this->workerPorts;
        foreach ($prev as $p) {
            if (!\in_array($p, $newPorts, true)) {
                $this->clearJoinedWorkerHomepageWarmupTicket((int) $p);
                unset($this->workerHomepageWarmupCompletedAt[(int) $p]);
                $this->closeIdleSocketsByPort((int) $p);
            }
        }

        $this->workerPorts = \array_values($newPorts);
        $this->workerCount = \count($this->workerPorts);
        $this->workerHealth = $newHealth;

        foreach (\array_keys($this->workerSaturation) as $p) {
            if (!\in_array((int) $p, $this->workerPorts, true)) {
                unset($this->workerSaturation[$p]);
            }
        }

    }

    /**
     * Claim one homepage warmup ticket per currently joined worker.
     *
     * @param int[] $ports
     * @return array<int, array{port: int, ticket: int}>
     */
    public function claimJoinedWorkerHomepageWarmup(array $ports): array
    {
        if (!$this->homepageWarmupEnabled) {
            return [];
        }

        $claims = [];
        $candidatePorts = \array_values(\array_filter(
            \array_unique(\array_map('intval', $ports)),
            static fn(int $port): bool => $port > 0
        ));

        foreach ($candidatePorts as $port) {
            if (!\in_array($port, $this->workerPorts, true)) {
                continue;
            }
            if (isset($this->workerHomepageWarmupTickets[$port])) {
                continue;
            }
            $lastWarmedAt = (float)($this->workerHomepageWarmupCompletedAt[$port] ?? 0.0);
            if ($lastWarmedAt > 0.0
                && \microtime(true) - $lastWarmedAt < self::HOMEPAGE_WARMUP_RECENT_SUCCESS_GRACE_SEC
            ) {
                continue;
            }

            $ticket = $this->nextWorkerHomepageWarmupTicket++;
            $this->workerHomepageWarmupTickets[$port] = $ticket;
            $claims[] = ['port' => $port, 'ticket' => $ticket];
        }

        return $claims;
    }

    /**
     * @param array<int, array{port?: int, ticket?: int}> $claims
     * @return array{warmed: int[], failed: array<int, string>, skipped: int[]}
     */
    public function warmupJoinedWorkersViaHomepage(array $claims): array
    {
        $warmed = [];
        $failed = [];
        $skipped = [];

        if (!$this->homepageWarmupEnabled) {
            return ['warmed' => $warmed, 'failed' => $failed, 'skipped' => $skipped];
        }

        foreach ($claims as $claim) {
            $port = (int)($claim['port'] ?? 0);
            $ticket = (int)($claim['ticket'] ?? 0);
            if ($port <= 0 || $ticket <= 0) {
                continue;
            }

            if (($this->workerHomepageWarmupTickets[$port] ?? 0) !== $ticket
                || !\in_array($port, $this->workerPorts, true)
            ) {
                $skipped[] = $port;
                continue;
            }

            $this->activeHomepageWarmupPort = $port;
            try {
                $result = $this->warmupWorkerViaHomepage(
                    $port,
                    self::IPC_READY_HOMEPAGE_WARMUP_RETRIES,
                    self::IPC_READY_HOMEPAGE_CONNECT_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_TLS_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_WRITE_TIMEOUT_SEC,
                    self::IPC_READY_HOMEPAGE_READ_TIMEOUT_SEC,
                    $this->homepageWarmupRouteGateTargets,
                    false
                );
            } finally {
                if ($this->activeHomepageWarmupPort === $port) {
                    $this->activeHomepageWarmupPort = null;
                }
            }
            if (!($result['success'] ?? false)) {
                $failed[$port] = (string)($result['error'] ?? 'homepage warmup failed');
                $this->clearJoinedWorkerHomepageWarmupTicket($port);
                continue;
            }

            $warmed[] = $port;
            $this->workerHomepageWarmupCompletedAt[$port] = \microtime(true);
            $this->clearJoinedWorkerHomepageWarmupTicket($port);
        }

        return ['warmed' => $warmed, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function clearJoinedWorkerHomepageWarmupTicket(int $port): void
    {
        unset($this->workerHomepageWarmupTickets[$port]);
    }

    private function isWorkerHomepageWarmupPending(int $port): bool
    {
        return isset($this->workerHomepageWarmupTickets[$port]);
    }

    private function shouldDeferPendingWarmupWorker(int $port, ?int $excludePort = null): bool
    {
        if (!$this->isWorkerHomepageWarmupPending($port)) {
            return false;
        }

        if ($this->activeHomepageWarmupPort === $port) {
            foreach ($this->workerPorts as $candidatePort) {
                $candidatePort = (int)$candidatePort;
                if ($candidatePort <= 0 || $candidatePort === $port || $candidatePort === $excludePort) {
                    continue;
                }
                if (!$this->isWorkerBlacklisted($candidatePort)
                    && !$this->isWorkerSaturated($candidatePort)
                ) {
                    return true;
                }
            }
        }

        foreach ($this->workerPorts as $candidatePort) {
            $candidatePort = (int)$candidatePort;
            if ($candidatePort <= 0 || $candidatePort === $port || $candidatePort === $excludePort) {
                continue;
            }
            if (!$this->isWorkerHomepageWarmupPending($candidatePort)
                && !$this->isWorkerBlacklisted($candidatePort)
            ) {
                return true;
            }
        }

        return false;
    }

    public function setWorkerPorts(array $ports, bool $preservePreviousOnTotalReject = true): array
    {
        $previousPorts = $this->workerPorts;
        $previousHealth = $this->workerHealth;
        $candidatePorts = \array_values(\array_filter(
            \array_unique(\array_map('intval', $ports)),
            static fn(int $port): bool => $port > 0
        ));
        $acceptedPorts = [];
        $rejectedPorts = [];
        $acceptedHealth = [];

        foreach ($candidatePorts as $port) {
            $this->warmupYield();
            $warmup = $this->warmupWorkerTrustingMasterReady($port);
            if (!$warmup['success']) {
                if (\in_array($port, $previousPorts, true) && isset($previousHealth[$port])) {
                    // 已在当前业务池中的旧端口不应因为一次瞬时探活失败就被踢出池。
                    // 这类失败常见于 Worker 忙于长请求/SSE、listen 窗口抖动等场景。
                    $acceptedPorts[] = $port;
                    $acceptedHealth[$port] = $previousHealth[$port];
                    unset($rejectedPorts[$port]);
                    $this->applyWorkerPoolTransition($acceptedPorts, $acceptedHealth);
                    $this->logWarmup(
                        'SET_ROUTE_TABLE 保留旧池端口: ' . $port . '（瞬时探活失败但此前已在池中） error=' . $warmup['error'],
                        'WARNING'
                    );
                    continue;
                }
                $rejectedPorts[$port] = $warmup['error'];
                continue;
            }

            $acceptedPorts[] = $port;
            $acceptedHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => \microtime(true),
                'total_failures' => 0,
            ];
            // 每成功预热一个端口立即发布池状态，避免「等整表 Worker 全好」才转发；维护 Worker 可第一时间接流
            $this->applyWorkerPoolTransition($acceptedPorts, $acceptedHealth);
        }

        // 兜底：若首轮快速探活未纳入任何端口，再做一轮轻量探活，避免偶发瞬时抖动导致池被清空。
        if ($acceptedPorts === [] && $candidatePorts !== []) {
            $configured = (float) $this->connectTimeout;
            $connectTimeout = \max(
                self::IPC_READY_WARMUP_CONNECT_MIN,
                \min($configured > 0 ? $configured : self::IPC_READY_WARMUP_CONNECT_MAX, self::IPC_READY_WARMUP_CONNECT_MAX)
            );
            $responseTimeout = self::IPC_READY_WARMUP_RESPONSE_SEC;
            foreach ($candidatePorts as $port) {
                $this->warmupYield();
                if (isset($acceptedHealth[$port])) {
                    continue;
                }
                $probe = $this->requestWorkerHealth($port, $connectTimeout, $responseTimeout);
                if (!$probe['success']) {
                    continue;
                }
                $acceptedPorts[] = $port;
                $acceptedHealth[$port] = [
                    'failures' => 0,
                    'blacklisted_at' => 0.0,
                    'last_success' => \microtime(true),
                    'total_failures' => 0,
                ];
                unset($rejectedPorts[$port]);
                $this->applyWorkerPoolTransition($acceptedPorts, $acceptedHealth);
            }
            if ($acceptedPorts !== []) {
                $this->logWarmup(
                    'SET_ROUTE_TABLE 首轮快速探活未通过，已通过兜底探活入池: '
                    . \implode(',', $acceptedPorts),
                    'WARNING'
                );
            }
        }

        // 关键保护：若 Master 下发了新池但所有端口都因瞬时探活失败被拒绝，
        // 不清空现有可用池，避免入口瞬断为 0/0。
        if ($preservePreviousOnTotalReject && $candidatePorts !== [] && $acceptedPorts === [] && $previousPorts !== []) {
            $this->applyWorkerPoolTransition($previousPorts, $previousHealth);
            $this->logWarmup(
                'SET_ROUTE_TABLE 新端口全量预热失败，保留旧池: ' . \implode(',', $previousPorts),
                'WARNING'
            );
        } else {
            $this->applyWorkerPoolTransition($acceptedPorts, $acceptedHealth);
        }
        $this->writeStderr(
            '[PassthroughCore] SET_ROUTE_TABLE 当前列表: ' . (\implode(',', $this->workerPorts) ?: '(空)') . "\n"
        );
        if ($rejectedPorts !== []) {
            $items = [];
            foreach ($rejectedPorts as $port => $reason) {
                $items[] = "{$port}: {$reason}";
            }
            $this->writeStderr(
                '[PassthroughCore] SET_ROUTE_TABLE 预热拒绝端口: ' . \implode('; ', $items) . "\n"
            );
        }

        return [
            'accepted' => $acceptedPorts,
            'rejected' => $rejectedPorts,
        ];
    }
    
    /**
     * 添加维护 Worker 端口
     * 
     * 维护 Worker 以 ROLE_MAINTENANCE 身份向 Dispatcher 注册，
     * 当业务 Worker 不就位时自动转接请求到维护 Worker。
     * 
     * @param int $port 维护 Worker 端口
     * @return array{success: bool, error?: string}
     */
    public function addMaintenanceWorkerPort(int $port): array
    {
        if ($port <= 0) {
            $this->logMaintenanceDecision(
                'maintenance_port_invalid:' . $port,
                '维护 Worker 端口无效: ' . $port . '，' . $this->formatMaintenanceLogContext(),
                'WARN',
                0.0
            );
            return ['success' => false, 'error' => '维护 Worker 端口无效: ' . $port];
        }
        
        if (\in_array($port, $this->maintenanceWorkerPorts, true)) {
            $this->logMaintenanceDecision(
                'maintenance_port_duplicate:' . $port,
                '维护 Worker 端口已存在: ' . $port . '，' . $this->formatMaintenanceLogContext(),
                'INFO',
                0.0
            );
            return ['success' => true, 'message' => '维护 Worker 端口已存在: ' . $port];
        }
        
        $probe = $this->probeWorkerApplicationHealth($port);
        if (!$probe) {
            $this->logMaintenanceDecision(
                'maintenance_port_probe_failed:' . $port,
                "维护 Worker {$port} 探活失败，可能未启动，" . $this->formatMaintenanceLogContext(),
                'WARN',
                0.0
            );
            return ['success' => false, 'error' => "维护 Worker {$port} 探活失败，可能未启动"];
        }
        
        $this->maintenanceWorkerPorts[] = $port;
        $this->writeStderr("[PassthroughCore] 维护 Worker 端口已注册: {$port}\n");
        $this->logMaintenanceDecision(
            'maintenance_port_added:' . $port,
            '维护 Worker 端口已注册: ' . $port . '，' . $this->formatMaintenanceLogContext(),
            'INFO',
            0.0
        );
        
        return ['success' => true, 'message' => '维护 Worker 端口已注册'];
    }
    
    /**
     * 移除维护 Worker 端口
     * 
     * @param int $port 维护 Worker 端口
     */
    public function removeMaintenanceWorkerPort(int $port): void
    {
        $key = \array_search($port, $this->maintenanceWorkerPorts, true);
        if ($key !== false) {
            unset($this->maintenanceWorkerPorts[$key]);
            $this->maintenanceWorkerPorts = \array_values($this->maintenanceWorkerPorts);
            $this->writeStderr("[PassthroughCore] 维护 Worker 端口已移除: {$port}\n");
            $this->logMaintenanceDecision(
                'maintenance_port_removed:' . $port,
                '维护 Worker 端口已移除: ' . $port . '，' . $this->formatMaintenanceLogContext(),
                'INFO',
                0.0
            );
        }
    }
    
    /**
     * 获取维护 Worker 端口列表
     * 
     * @return int[]
     */
    public function getMaintenanceWorkerPorts(): array
    {
        return $this->maintenanceWorkerPorts;
    }

    public function setMaintenanceRoutingActive(bool $active): void
    {
        $this->maintenanceRoutingActive = $active;
        $this->logMaintenanceDecision(
            'maintenance_routing_active:' . ($active ? '1' : '0'),
            'Maintenance routing active=' . ($active ? 'true' : 'false') . ', ' . $this->formatMaintenanceLogContext(),
            $active ? 'WARN' : 'INFO',
            0.0
        );
    }

    public function isMaintenanceRoutingActive(): bool
    {
        return $this->maintenanceRoutingActive;
    }
    
    /**
     * 设置维护 Worker 端口
     * 
     * 当业务 Worker 不就位或全部失败时，Dispatcher 会尝试连接维护 Worker
     * 以提供友好的"启动中"页面或维护提示。
     * 
     * @param int $port 维护 Worker 端口，0 表示禁用
     */
    public function setMaintenancePort(int $port): void
    {
        if ($port < 0) {
            $port = 0;
        }
        $this->maintenancePort = $port;
        if ($port > 0) {
            $this->writeStderr("[PassthroughCore] 维护 Worker 端口已设置: {$port}\n");
        } else {
            $this->writeStderr("[PassthroughCore] 维护 Worker 端口已禁用\n");
        }
        $this->logMaintenanceDecision(
            'maintenance_port_set:' . $port,
            '维护 Worker 端口' . ($port > 0 ? ('已设置: ' . $port) : '已禁用') . '，' . $this->formatMaintenanceLogContext(),
            $port > 0 ? 'INFO' : 'WARN',
            0.0
        );
    }
    
    /**
     * 获取维护 Worker 端口
     */
    public function getMaintenancePort(): int
    {
        return $this->maintenancePort;
    }
    
    /**
     *
     * @return array{healthy: int, blacklisted: int, saturated: int, total: int, details: array}
     */
    public function getWorkerHealthSummary(): array
    {
        $healthy = 0;
        $blacklisted = 0;
        $saturated = 0;
        $details = [];

        foreach ($this->workerHealth as $port => $health) {
            $isBlacklisted = $this->isWorkerBlacklisted($port);
            $isSaturated = $this->isWorkerSaturated($port);

            if ($isBlacklisted) {
                $blacklisted++;
            } elseif ($isSaturated) {
                $saturated++;
            } else {
                $healthy++;
            }

            $satInfo = $this->workerSaturation[$port] ?? null;
            $details[$port] = [
                'status' => $isBlacklisted ? 'blacklisted' : ($isSaturated ? 'saturated' : 'healthy'),
                'failures' => $health['failures'],
                'total_failures' => $health['total_failures'],
                'last_success' => $health['last_success'] > 0
                    ? \round(\microtime(true) - $health['last_success'], 1) . 's ago'
                    : 'never',
            ];
            if ($satInfo !== null) {
                $details[$port]['long_lived_count'] = $satInfo['long_lived_count'];
                $details[$port]['long_lived_max'] = $satInfo['long_lived_max'];
                $details[$port]['saturated_at'] = $satInfo['saturated_at'] > 0
                    ? \round(\microtime(true) - $satInfo['saturated_at'], 1) . 's ago'
                    : 'now';
            }
        }

        return [
            'healthy' => $healthy,
            'blacklisted' => $blacklisted,
            'saturated' => $saturated,
            'total' => $this->workerCount,
            'details' => $details,
        ];
    }
    
    /**
     * 从客户端读取数据并转发到 Worker
     *
     * @param resource $clientSocket 客户端套接字
     * @return int 转发的字节数，-1 表示连接关闭
     */
    public function forwardToWorker($clientSocket): int
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->connections[$connId])) {
            $this->connectionTerminalReasons[$connId] = 'forward_to_worker_missing_connection';
            return -1;
        }
        
        $workerSocket = $this->connections[$connId]['worker'];
        $workerPort = $this->connections[$connId]['port'];

        if (isset($this->workerWriteBuffers[$connId]) && $this->workerWriteBuffers[$connId] !== '') {
            $flushed = $this->flushWorkerBuffer($clientSocket);
            if ($flushed === -1) {
                $this->connectionTerminalReasons[$connId] = 'forward_to_worker_flush_failed';
                return -1;
            }
            if (isset($this->workerWriteBuffers[$connId]) && $this->workerWriteBuffers[$connId] !== '') {
                return 0;
            }
        }
        
        // 读取客户端数据
        $data = @\socket_read($clientSocket, $this->readBufferSize);
        
        // socket_read 返回 false 表示错误（包括 WOULDBLOCK）
        if ($data === false) {
            $errCode = \socket_last_error($clientSocket);
            
            // WOULDBLOCK 系列错误码表示暂无数据，但连接仍然有效
            if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                \socket_clear_error($clientSocket);
                return 0; // 暂无数据，但连接正常
            }
            
            // 真正的错误，关闭连接
            $this->connectionTerminalReasons[$connId] = 'forward_to_worker_client_read_error:' . (string)$errCode;
            return -1;
        }
        
        // socket_read 返回空字符串表示客户端发送了 FIN（上行半关闭）
        if ($data === '') {
            // 半关闭：停止 client->worker，上行不再读取；下行继续发回响应
            $this->connectionTerminalReasons[$connId] = 'forward_to_worker_client_read_eof';
            $this->clientInputClosed[$connId] = true;
            return -2;
        }
        
        $length = \strlen($data);
        $this->stats['bytes_in'] += $length;
        $this->logIncomingRequestIngress($connId, $data);
        
        // 转发到 Worker（完整写入循环，处理部分写入）
        $totalWritten = 0;
        $maxAttempts = 4;
        $attempts = 0;
        
        while ($totalWritten < $length && $attempts < $maxAttempts) {
            $attempts++;
            $remaining = \substr($data, $totalWritten);
            $written = @\socket_write($workerSocket, $remaining);
            
            if ($written === false) {
                // Worker 写入失败（Worker 可能掉线），记录到健康状态
                $errCode = \socket_last_error($workerSocket);
                if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                    \socket_clear_error($workerSocket);
                    break;
                }
                $this->connectionTerminalReasons[$connId] = 'forward_to_worker_worker_write_error:' . (string)$errCode;
                $this->recordWorkerFailure($workerPort, true);
                return $totalWritten > 0 ? $totalWritten : -1;
            }
            
            if ($written === 0) {
                break;
            }
            
            $totalWritten += $written;
        }

        if ($totalWritten > 0 && isset($this->connections[$connId])) {
            $now = \microtime(true);
            $this->connections[$connId]['last_client_to_worker_at'] = $now;
            $requestSentAt = (float)($this->connections[$connId]['request_sent_at'] ?? 0.0);
            if ($requestSentAt <= 0.0) {
                $this->connections[$connId]['request_sent_at'] = $now;
            }
        }

        if ($totalWritten < $length) {
            $remaining = \substr($data, $totalWritten);
            $this->workerWriteBuffers[$connId] = ($this->workerWriteBuffers[$connId] ?? '') . $remaining;
        }
        
        return $length;
    }

    public function flushWorkerBuffer($clientSocket): int
    {
        $connId = \spl_object_id($clientSocket);

        if (!isset($this->workerWriteBuffers[$connId]) || $this->workerWriteBuffers[$connId] === '') {
            return 0;
        }
        if (!isset($this->connections[$connId])) {
            unset($this->workerWriteBuffers[$connId]);
            return -1;
        }

        $workerSocket = $this->connections[$connId]['worker'];
        $workerPort = (int)$this->connections[$connId]['port'];
        $buffer = $this->workerWriteBuffers[$connId];
        $bufferLen = \strlen($buffer);
        $totalWritten = 0;
        $attempts = 0;
        $maxAttempts = 8;

        while ($totalWritten < $bufferLen && $attempts < $maxAttempts) {
            $attempts++;
            $remaining = \substr($buffer, $totalWritten);
            $written = @\socket_write($workerSocket, $remaining);

            if ($written === false) {
                $errCode = \socket_last_error($workerSocket);
                if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                    \socket_clear_error($workerSocket);
                    break;
                }
                $this->connectionTerminalReasons[$connId] = 'flush_worker_buffer_worker_write_error:' . (string)$errCode;
                unset($this->workerWriteBuffers[$connId]);
                $this->recordWorkerFailure($workerPort);
                return -1;
            }

            if ($written === 0) {
                break;
            }

            $totalWritten += $written;
        }

        if ($totalWritten > 0 && isset($this->connections[$connId])) {
            $now = \microtime(true);
            $this->connections[$connId]['last_client_to_worker_at'] = $now;
            $requestSentAt = (float)($this->connections[$connId]['request_sent_at'] ?? 0.0);
            if ($requestSentAt <= 0.0) {
                $this->connections[$connId]['request_sent_at'] = $now;
            }
        }

        if ($totalWritten >= $bufferLen) {
            unset($this->workerWriteBuffers[$connId]);
        } else {
            $this->workerWriteBuffers[$connId] = \substr($buffer, $totalWritten);
        }

        return $totalWritten;
    }
    
    /**
     * 从 Worker 读取数据并转发到客户端
     * 
     * H15: 完全重写 - 使用写缓冲区确保大响应数据不丢失
     * 当客户端 TCP 缓冲区满时，未写入的数据保存到 clientWriteBuffers
     * 后续通过 flushClientBuffer() 继续发送
     *
     * @param resource $clientSocket 客户端套接字
     * @return int 转发的字节数，-1 表示连接关闭，-2 表示 Worker 关闭但仍有缓冲数据
     */
    public function forwardToClient($clientSocket): int
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->connections[$connId])) {
            $this->connectionTerminalReasons[$connId] = 'forward_to_client_missing_connection';
            return -1;
        }
        
        $conn = $this->connections[$connId];
        $workerSocket = $conn['worker'];
        $workerPort = $conn['port'];
        
        // H15: 如果有未发送完的缓冲数据，先尝试刷新
        if (isset($this->clientWriteBuffers[$connId]) && $this->clientWriteBuffers[$connId] !== '') {
            $flushed = $this->flushClientBuffer($clientSocket);
            if ($flushed === -1) {
                $this->connectionTerminalReasons[$connId] = 'forward_to_client_flush_failed';
                return -1; // 写入失败，连接错误
            }
            // 如果缓冲区还有数据，先不读 Worker，等下次再来
            if (isset($this->clientWriteBuffers[$connId]) && $this->clientWriteBuffers[$connId] !== '') {
                return 0;
            }
        }
        
        $totalBytesForwarded = 0;
        $maxReadAttempts = 8;
        $readAttempts = 0;
        $workerEof = false;
        
        while ($readAttempts < $maxReadAttempts) {
            $readAttempts++;
            
            // 读取 Worker 数据
            $data = @\socket_read($workerSocket, $this->readBufferSize);
            
            // socket_read 返回 false 表示错误（包括 WOULDBLOCK）
            if ($data === false) {
                $errCode = \socket_last_error($workerSocket);
                
                // WOULDBLOCK 系列错误码表示暂无数据，但连接仍然有效
                if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                    \socket_clear_error($workerSocket);

                    // 连接已建立但迟迟拿不到首字节：将该 Worker 视为假活跃（hung）
                    // 记录失败并让上层关闭当前连接，后续请求会逐步黑名单该 Worker。
                    if ($this->shouldTreatSilentWorkerAsFailure($conn, $totalBytesForwarded)) {
                        $this->recordWorkerFailure($workerPort, true);
                        $this->connectionTerminalReasons[$connId] = 'forward_to_client_first_byte_timeout';
                        return -1;
                    }
                    break; // 暂无更多数据
                }
                
                // Worker 读取失败（Worker 掉线），记录到健康状态
                $this->recordWorkerFailure($workerPort, true);
                $this->connectionTerminalReasons[$connId] = 'forward_to_client_worker_read_error:' . (string)$errCode;
                return $totalBytesForwarded > 0 ? $totalBytesForwarded : -1;
            }
            
            // socket_read 返回空字符串表示对方关闭了连接（发送了 FIN）
            if ($data === '') {
                $workerEof = true;
                break;
            }
            
            $length = \strlen($data);
            $this->connections[$connId]['last_worker_to_client_at'] = \microtime(true);
            if (!$this->workerSslEnabled) {
                $this->recordWorkerResponseIngress($connId, $data, $workerPort);
            }
            $this->markWorkerResponsive($connId, $workerPort);
            $this->stats['bytes_out'] += $length;
            
            // 写入客户端（尽可能多写）
            $dataLen = \strlen($data);
            $totalWritten = 0;
            $retries = 0;
            $maxRetries = 50;
            
            while ($totalWritten < $dataLen && $retries < $maxRetries) {
                $remaining = \substr($data, $totalWritten);
                $written = @\socket_write($clientSocket, $remaining);
                
                if ($written === false) {
                    $errCode = \socket_last_error($clientSocket);
                    if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                        \socket_clear_error($clientSocket);
                        break;
                    }
                    // 写入错误，连接断开
                    $this->connectionTerminalReasons[$connId] = 'forward_to_client_client_write_error:' . (string)$errCode;
                    return $totalBytesForwarded + $totalWritten > 0 
                        ? $totalBytesForwarded + $totalWritten 
                        : -1;
                }
                
                if ($written === 0) {
                    break;
                }
                
                $totalWritten += $written;
                $retries = 0;
            }
            
            $totalBytesForwarded += $totalWritten;
            
            // H15: 如果写入不完整，将未写入的数据存入缓冲区（不再丢弃！）
            if ($totalWritten < $dataLen) {
                $unwritten = \substr($data, $totalWritten);
                if (isset($this->clientWriteBuffers[$connId])) {
                    $this->clientWriteBuffers[$connId] .= $unwritten;
                } else {
                    $this->clientWriteBuffers[$connId] = $unwritten;
                }
                break; // 客户端忙，暂停读取 Worker
            }
        }
        
        // H15: Worker 关闭了连接
        if ($workerEof) {
            // 如果有缓冲数据，标记为 Worker 已关闭但还需继续发送
            if (isset($this->clientWriteBuffers[$connId]) && $this->clientWriteBuffers[$connId] !== '') {
                $this->workerClosed[$connId] = true;
                return $totalBytesForwarded > 0 ? $totalBytesForwarded : -2;
            }
            // 没有缓冲数据，连接真正结束
            $this->connectionTerminalReasons[$connId] = 'forward_to_client_worker_eof_without_buffer';
            return $totalBytesForwarded > 0 ? $totalBytesForwarded : -1;
        }
        
        return $totalBytesForwarded;
    }

    /**
     * Only start the worker first-byte timeout after some request bytes were
     * actually forwarded upstream. Browser preconnect / idle sockets should not
     * blacklist a healthy worker.
     *
     * Dispatcher stays protocol-agnostic here; worker-side runtime decides
     * whether a request is SSE/streaming.
     *
     * @param array{request_sent_at?: float} $conn
     */
    private function shouldTreatSilentWorkerAsFailure(array $conn, int $totalBytesForwarded): bool
    {
        if ($totalBytesForwarded > 0 || $this->firstByteTimeoutSeconds <= 0) {
            return false;
        }

        $requestSentAt = (float)($conn['request_sent_at'] ?? 0.0);
        if ($requestSentAt <= 0.0) {
            return false;
        }

        return (\microtime(true) - $requestSentAt) >= $this->firstByteTimeoutSeconds;
    }

    private function markWorkerResponsive(int $connId, int $workerPort): void
    {
        if (!isset($this->connections[$connId])) {
            return;
        }

        if (!empty($this->connections[$connId]['worker_responded'])) {
            return;
        }

        $this->connections[$connId]['worker_responded'] = true;
        $this->recordWorkerSuccess($workerPort);
        if (!$this->trafficTraceEnabled) {
            return;
        }

        $requestSentAt = (float)($this->connections[$connId]['request_sent_at'] ?? 0.0);
        $ttfbMs = $requestSentAt > 0.0 ? \round((\microtime(true) - $requestSentAt) * 1000, 1) : null;
        $clientIp = (string)($this->connections[$connId]['clientIp'] ?? '');
        $message = "Worker 开始响应请求: conn={$connId}, client={$clientIp}, worker={$workerPort}";
        if ($ttfbMs !== null) {
            $message .= ", ttfb_ms={$ttfbMs}";
        }
        $this->logWarmup($message, 'INFO');
    }

    private function recordWorkerResponseIngress(int $connId, string $data, int $workerPort): void
    {
        if (!isset($this->connections[$connId])) {
            return;
        }
        if ((string)($this->connections[$connId]['response_first_line'] ?? '') !== '') {
            return;
        }

        $responseLine = $this->extractHttpResponseFirstLine($data);
        if ($responseLine === '') {
            return;
        }

        $this->connections[$connId]['response_first_line'] = $responseLine;
        if ($this->isHttpResponseStatusLine($responseLine)) {
            $this->connections[$connId]['response_status_line'] = $responseLine;
        }

        $clientIp = (string)($this->connections[$connId]['clientIp'] ?? '');
        $requestLine = (string)($this->connections[$connId]['request_line'] ?? '');
        $message = "Worker response head: conn={$connId}, client={$clientIp}, worker={$workerPort}, response=\"{$responseLine}\"";
        if ($requestLine !== '') {
            $message .= ", request=\"{$requestLine}\"";
        }
        if ($this->trafficTraceEnabled) {
            $this->logWarmup($message, 'INFO');
        }
    }

    private function logIncomingRequestIngress(int $connId, string $data): void
    {
        if (!isset($this->connections[$connId])) {
            return;
        }
        if (!$this->trafficTraceEnabled) {
            return;
        }

        $loggedCount = (int)($this->requestIngressLogCountByConn[$connId] ?? 0);
        if ($loggedCount >= $this->requestIngressLogMaxPerConnection) {
            return;
        }

        $requestLine = $this->extractHttpRequestLine($data);
        $isFirstIngressLog = $loggedCount === 0;
        if (!$isFirstIngressLog && $requestLine === '') {
            return;
        }

        $lastRequestLine = (string)($this->lastLoggedHttpRequestLineByConn[$connId] ?? '');
        if (!$isFirstIngressLog && $requestLine !== '' && $requestLine === $lastRequestLine) {
            return;
        }

        $this->requestIngressLogCountByConn[$connId] = $loggedCount + 1;
        if ($requestLine !== '') {
            $this->lastLoggedHttpRequestLineByConn[$connId] = $requestLine;
            $this->connections[$connId]['request_line'] = $requestLine;
        }

        $conn = $this->connections[$connId];
        $workerPort = (int)($conn['port'] ?? 0);
        $clientIp = (string)($conn['clientIp'] ?? '');
        $message = "收到客户端请求数据: conn={$connId}, client={$clientIp}, worker={$workerPort}, bytes=" . \strlen($data);
        if ($requestLine !== '') {
            $message .= ", request=\"{$requestLine}\"";
        } else {
            $message .= ', request=opaque';
        }
        $this->logWarmup($message, 'INFO');
    }

    private function extractHttpRequestLine(string $data): string
    {
        $lineEnd = \strpos($data, "\r\n");
        if ($lineEnd === false) {
            $lineEnd = \strpos($data, "\n");
        }

        $firstLine = $lineEnd === false ? $data : \substr($data, 0, $lineEnd);
        $firstLine = (string)\preg_replace('/\s+/', ' ', \trim($firstLine));
        if ($firstLine === '' || \strlen($firstLine) > 512) {
            return '';
        }

        if (!\preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|TRACE|CONNECT)\s+\S+\s+HTTP\/1\.[01]$/i', $firstLine)) {
            return '';
        }

        return \substr($firstLine, 0, 200);
    }

    private function extractHttpResponseFirstLine(string $data): string
    {
        $lineEnd = \strpos($data, "\r\n");
        if ($lineEnd === false) {
            $lineEnd = \strpos($data, "\n");
        }

        $firstLine = $lineEnd === false ? $data : \substr($data, 0, $lineEnd);
        $firstLine = (string)\preg_replace('/\s+/', ' ', \trim($firstLine));
        if ($firstLine === '' || \strlen($firstLine) > 512) {
            return '';
        }
        if (\preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $firstLine)) {
            return '';
        }

        return \substr($firstLine, 0, 200);
    }

    private function isHttpResponseStatusLine(string $line): bool
    {
        return (bool)\preg_match('/^HTTP\/\d(?:\.\d)?\s+\d{3}\b/i', $line);
    }

    /**
     * H15: 刷新客户端写缓冲区
     * 
     * @param resource $clientSocket 客户端套接字
     * @return int 写入的字节数，-1 表示错误
     */
    public function flushClientBuffer($clientSocket): int
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->clientWriteBuffers[$connId]) || $this->clientWriteBuffers[$connId] === '') {
            return 0;
        }
        
        $buffer = $this->clientWriteBuffers[$connId];
        $bufferLen = \strlen($buffer);
        $totalWritten = 0;
        $maxAttempts = 50;
        $attempts = 0;
        
        while ($totalWritten < $bufferLen && $attempts < $maxAttempts) {
            $remaining = \substr($buffer, $totalWritten);
            $written = @\socket_write($clientSocket, $remaining);
            
            if ($written === false) {
                $errCode = \socket_last_error($clientSocket);
                if (\in_array($errCode, self::WOULDBLOCK_ERRORS, true)) {
                    \socket_clear_error($clientSocket);
                    break;
                }
                // 写入错误
                $this->connectionTerminalReasons[$connId] = 'flush_client_buffer_client_write_error:' . (string)$errCode;
                unset($this->clientWriteBuffers[$connId]);
                return -1;
            }
            
            if ($written === 0) {
                break;
            }
            
            $totalWritten += $written;
            $attempts = 0;
        }
        
        if ($totalWritten >= $bufferLen) {
            unset($this->clientWriteBuffers[$connId]);
            unset($this->workerWriteBuffers[$connId]);
        } else {
            $this->clientWriteBuffers[$connId] = \substr($buffer, $totalWritten);
        }
        
        return $totalWritten;
    }
    
    /**
     * H15: 检查连接是否有待发送的缓冲数据
     */
    public function hasBufferedData($clientSocket): bool
    {
        $connId = \spl_object_id($clientSocket);
        return isset($this->clientWriteBuffers[$connId]) && $this->clientWriteBuffers[$connId] !== '';
    }

    public function hasWorkerBufferedData($clientSocket): bool
    {
        $connId = \spl_object_id($clientSocket);
        return isset($this->workerWriteBuffers[$connId]) && $this->workerWriteBuffers[$connId] !== '';
    }
    
    /**
     * H15: 检查 Worker 是否已关闭但还有缓冲数据
     */
    public function isWorkerClosedWithBuffer($clientSocket): bool
    {
        $connId = \spl_object_id($clientSocket);
        return isset($this->workerClosed[$connId]);
    }
    
    /**
     * 客户端上行是否已半关闭（FIN）。
     */
    public function isClientInputClosed($clientSocket): bool
    {
        $connId = \spl_object_id($clientSocket);
        return !empty($this->clientInputClosed[$connId]);
    }
    
    /**
     * H15: 获取所有有待发送缓冲数据的连接 ID
     * 
     * @return array<int> 连接 ID 列表
     */
    public function getPendingBufferConnIds(): array
    {
        $connIds = [];
        foreach ($this->clientWriteBuffers as $connId => $buffer) {
            if ($buffer !== '') {
                $connIds[] = $connId;
            }
        }
        // 也包括 Worker 已关闭的连接（即使缓冲区空，也需要关闭）
        foreach ($this->workerClosed as $connId => $closed) {
            if ($closed && !\in_array($connId, $connIds, true)) {
                $connIds[] = $connId;
            }
        }
        return $connIds;
    }

    /**
     * @return array<int>
     */
    public function getPendingWorkerBufferConnIds(): array
    {
        $connIds = [];
        foreach ($this->workerWriteBuffers as $connId => $buffer) {
            if ($buffer !== '') {
                $connIds[] = $connId;
            }
        }
        return $connIds;
    }
    
    /**
     * 获取 Worker 套接字
     *
     * @param resource $clientSocket 客户端套接字
     * @return resource|null Worker 套接字
     */
    public function getWorkerSocket($clientSocket)
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->connections[$connId])) {
            return null;
        }
        
        return $this->connections[$connId]['worker'];
    }
    
    /**
     * 获取连接分配到的 Worker 端口
     *
     * @param resource $clientSocket 客户端套接字
     * @return int|null Worker 端口，未找到返回 null
     */
    public function getConnectionWorkerPort($clientSocket): ?int
    {
        $connId = \spl_object_id($clientSocket);
        return $this->connections[$connId]['port'] ?? null;
    }
    
    /**
     * 按 connId 获取并清理终止原因，避免重复读到旧值。
     */
    public function consumeConnectionTerminalReasonByConnId(int $connId): ?string
    {
        if (!isset($this->connectionTerminalReasons[$connId])) {
            return null;
        }
        $reason = $this->connectionTerminalReasons[$connId];
        unset($this->connectionTerminalReasons[$connId]);
        return $reason;
    }
    
    /**
     * 按 connId 读取最近终止原因（不消费）。
     */
    public function peekConnectionTerminalReasonByConnId(int $connId): ?string
    {
        return $this->connectionTerminalReasons[$connId] ?? null;
    }
    
    /**
     * 获取所有活跃的 Worker 套接字
     *
     * @return array Worker 套接字数组
     */
    public function getAllWorkerSockets(): array
    {
        $sockets = [];
        foreach ($this->connections as $conn) {
            $sockets[] = $conn['worker'];
        }
        return $sockets;
    }
    
    /**
     * 关闭连接
     *
     * @param resource $clientSocket 客户端套接字
     */
    public function closeConnection($clientSocket): void
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->connections[$connId])) {
            // H15: 即使没有连接记录也要清理缓冲区
            unset($this->clientWriteBuffers[$connId]);
            unset($this->workerClosed[$connId]);
            unset($this->connectionTerminalReasons[$connId]);
            unset($this->clientInputClosed[$connId]);
            unset($this->requestIngressLogCountByConn[$connId]);
            unset($this->lastLoggedHttpRequestLineByConn[$connId]);
            return;
        }
        
        $workerSocket = $this->connections[$connId]['worker'];
        $workerPort = (int)$this->connections[$connId]['port'];
        $isTlsTunnelLikely = (($this->connections[$connId]['sni'] ?? '') !== '');

        $reusable = $this->backendPoolEnabled
            && !$this->workerSslEnabled
            && !$isTlsTunnelLikely
            && empty($this->clientWriteBuffers[$connId])
            && empty($this->workerWriteBuffers[$connId])
            && empty($this->workerClosed[$connId]);

        if ($reusable && $this->releaseWorkerSocketToPool($workerPort, $workerSocket)) {
            $this->stats['backend_pool_released']++;
        } else {
            $this->discardWorkerSocket($workerSocket);
        }
        
        // 移除连接记录
        unset($this->connections[$connId]);
        
        // H15: 清理写缓冲区
        unset($this->clientWriteBuffers[$connId]);
        unset($this->workerWriteBuffers[$connId]);
        unset($this->workerClosed[$connId]);
        unset($this->connectionTerminalReasons[$connId]);
        unset($this->clientInputClosed[$connId]);
        unset($this->requestIngressLogCountByConn[$connId]);
        unset($this->lastLoggedHttpRequestLineByConn[$connId]);
        
        // 移除连接缓存
        $this->routingCache->removeConnection($connId);
        
        $this->stats['active_connections']--;
    }
    
    /**
     * 关闭所有连接
     */
    public function closeAllConnections(): void
    {
        foreach ($this->connections as $connId => $conn) {
            $this->discardWorkerSocket($conn['worker']);
            $this->routingCache->removeConnection($connId);
        }
        
        $this->connections = [];
        $this->clientWriteBuffers = [];
        $this->workerWriteBuffers = [];
        $this->workerClosed = [];
        $this->clientInputClosed = [];
        $this->requestIngressLogCountByConn = [];
        $this->lastLoggedHttpRequestLineByConn = [];
        $this->stats['active_connections'] = 0;
        $this->closeAllIdleWorkerSockets();
    }

    private function closeAllIdleWorkerSockets(): void
    {
        foreach (\array_keys($this->idleWorkerPool) as $port) {
            $this->closeIdleSocketsByPort((int)$port);
        }
    }

    private function closeIdleSocketsByPort(int $workerPort): void
    {
        if (!isset($this->idleWorkerPool[$workerPort])) {
            return;
        }
        foreach ($this->idleWorkerPool[$workerPort] as $entry) {
            $this->discardWorkerSocket($entry['socket'] ?? null);
        }
        unset($this->idleWorkerPool[$workerPort]);
    }

    private function acquireIdleWorkerSocket(int $workerPort)
    {
        $this->cleanupIdleWorkerSockets($workerPort);
        if (empty($this->idleWorkerPool[$workerPort])) {
            return false;
        }

        while (!empty($this->idleWorkerPool[$workerPort])) {
            $entry = \array_pop($this->idleWorkerPool[$workerPort]);
            $socket = $entry['socket'] ?? null;
            if ($this->canReuseWorkerSocket($socket)) {
                return $socket;
            }
            $this->discardWorkerSocket($socket);
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function orderWorkerPortsRoundRobin(int $startIndex): array
    {
        $count = \count($this->workerPorts);
        if ($count <= 1) {
            return $this->workerPorts;
        }

        $ordered = [];
        for ($i = 0; $i < $count; $i++) {
            $ordered[] = (int)$this->workerPorts[($startIndex + $i) % $count];
        }

        return $ordered;
    }

    /**
     * @return int[]
     */
    private function orderWorkerPortsByActiveLoad(int $startIndex): array
    {
        $ports = $this->orderWorkerPortsRoundRobin($startIndex);
        if (\count($ports) <= 1) {
            return $ports;
        }

        $activeCounts = $this->getWorkerActiveConnectionCounts();
        $pendingResponseCounts = $this->getWorkerPendingResponseCounts();
        $ranked = [];
        foreach ($ports as $rank => $port) {
            $ranked[] = [
                'port' => (int)$port,
                'pending_response' => (int)($pendingResponseCounts[(int)$port] ?? 0),
                'active' => (int)($activeCounts[(int)$port] ?? 0),
                'rank' => (int)$rank,
            ];
        }

        \usort($ranked, static function (array $left, array $right): int {
            $pendingCompare = ((int)$left['pending_response']) <=> ((int)$right['pending_response']);
            if ($pendingCompare !== 0) {
                return $pendingCompare;
            }

            $activeCompare = ((int)$left['active']) <=> ((int)$right['active']);
            if ($activeCompare !== 0) {
                return $activeCompare;
            }

            return ((int)$left['rank']) <=> ((int)$right['rank']);
        });

        return \array_map(static fn(array $entry): int => (int)$entry['port'], $ranked);
    }

    /**
     * @return array<int, int>
     */
    private function getWorkerActiveConnectionCounts(): array
    {
        $counts = [];
        foreach ($this->workerPorts as $port) {
            $counts[(int)$port] = 0;
        }

        foreach ($this->connections as $connection) {
            $port = (int)($connection['port'] ?? 0);
            if ($port > 0 && \array_key_exists($port, $counts)) {
                $counts[$port]++;
            }
        }

        return $counts;
    }

    /**
     * @return array<int, int>
     */
    private function getWorkerPendingResponseCounts(?float $now = null): array
    {
        $counts = [];
        foreach ($this->workerPorts as $port) {
            $counts[(int)$port] = 0;
        }

        $now ??= \microtime(true);
        foreach ($this->connections as $connection) {
            $port = (int)($connection['port'] ?? 0);
            if ($port <= 0 || !\array_key_exists($port, $counts)) {
                continue;
            }
            if ($this->connectionIsWaitingForWorkerBytes($connection, $now)) {
                $counts[$port]++;
            }
        }

        return $counts;
    }

    private function connectionIsWaitingForWorkerBytes(array $connection, float $now): bool
    {
        if ($this->workerBusyPenaltyAfterSeconds <= 0.0) {
            return false;
        }

        $lastClientToWorkerAt = (float)($connection['last_client_to_worker_at'] ?? 0.0);
        if ($lastClientToWorkerAt <= 0.0) {
            return false;
        }

        $lastWorkerToClientAt = (float)($connection['last_worker_to_client_at'] ?? 0.0);
        if ($lastWorkerToClientAt >= $lastClientToWorkerAt) {
            return false;
        }

        return ($now - $lastClientToWorkerAt) >= $this->workerBusyPenaltyAfterSeconds;
    }

    private function hasLessLoadedWorker(int $workerPort): bool
    {
        if ($this->workerPorts === [] || !\in_array($workerPort, $this->workerPorts, true)) {
            return false;
        }

        $activeCounts = $this->getWorkerActiveConnectionCounts();
        $pendingResponseCounts = $this->getWorkerPendingResponseCounts();
        $currentLoad = (int)($activeCounts[$workerPort] ?? 0);
        $currentPending = (int)($pendingResponseCounts[$workerPort] ?? 0);
        $currentScore = ($currentPending * 10000) + $currentLoad;
        if ($currentScore <= 0) {
            return false;
        }

        foreach ($this->workerPorts as $port) {
            $port = (int)$port;
            if ($port === $workerPort
                || $this->isWorkerHomepageWarmupPending($port)
                || $this->isWorkerBlacklisted($port)
                || $this->isWorkerSaturated($port)
            ) {
                continue;
            }
            $candidatePending = (int)($pendingResponseCounts[$port] ?? 0);
            $candidateLoad = (int)($activeCounts[$port] ?? 0);
            $candidateScore = ($candidatePending * 10000) + $candidateLoad;
            if ($candidateScore < $currentScore) {
                return true;
            }
        }

        return false;
    }

    private function primeSslIdleWorkerPool(int $workerPort, ?int $maxCreates = null): int
    {
        if (!$this->workerSslEnabled || $this->sslBackendPreconnectPerWorker <= 0) {
            return 0;
        }
        if (!\in_array($workerPort, $this->workerPorts, true)) {
            return 0;
        }

        $this->cleanupIdleWorkerSockets($workerPort);
        if (!isset($this->idleWorkerPool[$workerPort])) {
            $this->idleWorkerPool[$workerPort] = [];
        }

        $current = \count($this->idleWorkerPool[$workerPort]);
        $ttl = \min(3, $this->backendPoolIdleTtl);
        $created = 0;
        while (
            $current < $this->sslBackendPreconnectPerWorker
            && ($maxCreates === null || $created < $maxCreates)
        ) {
            $socket = $this->openWorkerSocket($workerPort, 0.02);
            if ($socket === false) {
                break;
            }

            $this->idleWorkerPool[$workerPort][] = [
                'socket' => $socket,
                'expires_at' => \microtime(true) + $ttl,
            ];
            $current++;
            $created++;
        }

        return $created;
    }

    private function releaseWorkerSocketToPool(int $workerPort, $socket): bool
    {
        if (!$this->backendPoolEnabled) {
            return false;
        }
        if ($this->workerSslEnabled) {
            // SSL 透传链路禁止复用后端 socket，避免跨连接 TLS 状态污染。
            return false;
        }

        if (!\in_array($workerPort, $this->workerPorts, true)) {
            return false;
        }

        if (!$this->canReuseWorkerSocket($socket)) {
            return false;
        }

        $this->cleanupIdleWorkerSockets($workerPort);
        if (!isset($this->idleWorkerPool[$workerPort])) {
            $this->idleWorkerPool[$workerPort] = [];
        }
        if (\count($this->idleWorkerPool[$workerPort]) >= $this->backendPoolMaxIdlePerWorker) {
            return false;
        }

        $this->idleWorkerPool[$workerPort][] = [
            'socket' => $socket,
            'expires_at' => \microtime(true) + $this->backendPoolIdleTtl,
        ];
        return true;
    }

    private function cleanupIdleWorkerSockets(?int $targetPort = null): void
    {
        $ports = $targetPort === null ? \array_keys($this->idleWorkerPool) : [$targetPort];
        $now = \microtime(true);
        foreach ($ports as $port) {
            $port = (int)$port;
            if (empty($this->idleWorkerPool[$port])) {
                continue;
            }
            $nextPool = [];
            foreach ($this->idleWorkerPool[$port] as $entry) {
                $socket = $entry['socket'] ?? null;
                $expiresAt = (float)($entry['expires_at'] ?? 0.0);
                if ($expiresAt <= $now || !$this->canReuseWorkerSocket($socket)) {
                    $this->discardWorkerSocket($socket);
                    continue;
                }
                $nextPool[] = $entry;
            }
            if ($nextPool === []) {
                unset($this->idleWorkerPool[$port]);
                continue;
            }
            $this->idleWorkerPool[$port] = $nextPool;
        }
    }

    private function canReuseWorkerSocket($socket): bool
    {
        if (!\is_resource($socket) && !($socket instanceof \Socket)) {
            return false;
        }

        $peekBuffer = '';
        $peek = @\socket_recv($socket, $peekBuffer, 1, MSG_PEEK);
        if ($peek === 0) {
            return false;
        }
        if ($peek === false) {
            $err = \socket_last_error($socket);
            if (\in_array($err, self::WOULDBLOCK_ERRORS, true)) {
                \socket_clear_error($socket);
                return true;
            }
            \socket_clear_error($socket);
            return false;
        }

        // 有残留可读数据，说明连接并非干净空闲状态。
        return false;
    }

    private function discardWorkerSocket($socket): void
    {
        if (\is_resource($socket) || $socket instanceof \Socket) {
            @\socket_shutdown($socket, 2);
            @\socket_close($socket);
            $this->stats['backend_pool_discarded']++;
        }
    }
    
    /**
     * 获取统计信息
     *
     * @return array 统计信息
     */
    public function getStats(): array
    {
        return \array_merge(
            $this->stats,
            ['cache_stats' => $this->routingCache->getStats()]
        );
    }
    
    /**
     * 重置统计信息
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_connections' => 0,
            'active_connections' => \count($this->connections),
            'cache_routed' => 0,
            'round_robin_routed' => 0,
            'failover_routed' => 0,
            'saturated_fallback_routed' => 0,
            'sni_extractions' => 0,
            'bytes_in' => 0,
            'bytes_out' => 0,
            'worker_failures' => 0,
            'all_workers_down' => 0,
            'backend_pool_reused' => 0,
            'backend_pool_released' => 0,
            'backend_pool_discarded' => 0,
        ];
    }
    
    /**
     * 检查连接是否活跃
     *
     * @param resource $clientSocket 客户端套接字
     * @return bool 是否活跃
     */
    public function isConnectionActive($clientSocket): bool
    {
        return isset($this->connections[\spl_object_id($clientSocket)]);
    }
    
    /**
     * 获取连接信息
     *
     * @param resource $clientSocket 客户端套接字
     * @return array|null 连接信息
     */
    public function getConnectionInfo($clientSocket): ?array
    {
        $connId = \spl_object_id($clientSocket);
        
        if (!isset($this->connections[$connId])) {
            return null;
        }
        
        $conn = $this->connections[$connId];
        
        return [
            'worker_port' => $conn['port'],
            'client_ip' => $conn['clientIp'],
            'sni' => $conn['sni'],
            'open_time' => $conn['open_time'] ?? 0.0,
            'request_sent_at' => $conn['request_sent_at'] ?? 0.0,
            'worker_responded' => $conn['worker_responded'] ?? false,
            'request_line' => $conn['request_line'] ?? '',
            'response_first_line' => $conn['response_first_line'] ?? '',
            'response_status_line' => $conn['response_status_line'] ?? '',
        ];
    }
    
    /**
     * 获取 Worker 数量
     *
     * @return int Worker 数量
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }
}
