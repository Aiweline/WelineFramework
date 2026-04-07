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
     * Master 已通过 IPC 确认 Worker READY 后的入池探活：短超时、少重试。
     * 避免与「监听已就绪」重复采用 2~4s 级连接窗口，拖慢首流量入池。
     */
    private const IPC_READY_WARMUP_CONNECT_MIN = 0.5;
    private const IPC_READY_WARMUP_CONNECT_MAX = 1.5;
    private const IPC_READY_WARMUP_RESPONSE_SEC = 2.0;
    private const IPC_READY_WARMUP_RETRIES = 2;
    private const IPC_READY_WARMUP_RETRY_DELAY_USEC = 50000;
    /**
     * 首页预热仅用于“提前点燃”应用栈，不参与入池成败判定；因此采用更短预算，避免拖慢维护接流。
     */
    private const IPC_READY_HOMEPAGE_WARMUP_RETRIES = 1;
    private const IPC_READY_HOMEPAGE_CONNECT_TIMEOUT_SEC = 2.0;
    private const IPC_READY_HOMEPAGE_TLS_TIMEOUT_SEC = 3.0;
    private const IPC_READY_HOMEPAGE_WRITE_TIMEOUT_SEC = 2.0;
    private const IPC_READY_HOMEPAGE_READ_TIMEOUT_SEC = 3.0;
    
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
    private const MIN_SSL_STARTUP_SPIN_WAIT_SECONDS = 15.0;
    
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

    /**
     * 可选：探活/预热过程中协作式让出（仅应在 Dispatcher 的入池 Fiber 内注册为 Fiber::suspend）。
     */
    private ?\Closure $warmupCooperativeYield = null;

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
     * @var array<int, array{worker: resource, port: int, clientIp: string, sni: string, open_time: float}>
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
     * H15: 客户端写入缓冲区
     * 当客户端 TCP 发送缓冲区满时，暂存未写入的数据
     *
     * PHP 8.4 优化：string 值数组提升性能
     *
     * @var array<int, string>
     */
    private array $clientWriteBuffers = [];

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
    private float $firstByteTimeoutSeconds = 2.5;

    /**
     * Worker 全部不可用时的自旋等待总时长（秒）
     * 热重载期间 Worker 可能有短暂空窗，自旋等待可避免请求直接失败
     */
    private float $spinWaitMaxSeconds = 3.0;

    /**
     * workerPorts 为空时的自旋上限（秒）。避免 SSL 模式下 15s 级自旋 × 大量连接拖死 Dispatcher、放大重试风暴。
     */
    private float $emptyPoolSpinMaxSeconds = 0.5;

    /**
     * 自旋等待间隔（毫秒）
     */
    private int $spinWaitIntervalMs = 50;

    /**
     * 上次输出「workerPorts 为空」到 stderr 的时间（节流，避免启动时刷屏）
     */
    private float $lastEmptyWorkerPortsStderrAt = 0.0;
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
        if (isset($config['backend_pool_enabled'])) {
            $this->backendPoolEnabled = (bool)$config['backend_pool_enabled'];
        }
        if (isset($config['backend_pool_max_idle_per_worker'])) {
            $this->backendPoolMaxIdlePerWorker = \max(1, (int)$config['backend_pool_max_idle_per_worker']);
        }
        if (isset($config['backend_pool_idle_ttl'])) {
            $this->backendPoolIdleTtl = \max(1, (int)$config['backend_pool_idle_ttl']);
        }
        
        // 传递缓存配置
        if (isset($config['cache'])) {
            $this->routingCache->configure($config['cache']);
        }
        
        // HTTP 重定向端口
        if (isset($config['http_redirect_port'])) {
            $this->httpRedirectPort = (int) $config['http_redirect_port'];
        }
        if (!$this->backendPoolEnabled) {
            $this->closeAllIdleWorkerSockets();
        }
    }

    public function setSpinWaitTickCallback(?callable $callback): void
    {
        $this->spinWaitTickCallback = $callback;
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
            'worker_responded' => false,
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

        // 4.5 Keep-Alive / SNI / IP 粘连里的端口若已不在当前 Worker 池（reload 换端口、缩容、SET_WORKER_POOL 变更），
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
                $this->recordWorkerFailure($workerPort);
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
                // 维护 Worker 成功，记录成功并直接注册连接
                $this->recordWorkerSuccess($maintenancePort);
                return $this->registerConnection($connId, $clientSocket, $maintenanceSocket, $maintenancePort, $clientIp, $sni);
            }
            // 维护 Worker 连接失败（极端情况：维护 Worker 尚未启动完成），记录失败但继续自旋等待
            $this->recordWorkerFailure($maintenancePort);
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

        // 8. 自旋等待：池为空（短窗口）、池内端口暂不可连（维护/业务 Worker 仍在 listen）、或全部黑名单/饱和时，
        //    在预算内重试并 runSpinWaitTick→抽 IPC（异步入池 Fiber 能推进），避免维护 Worker 已下发但尚未 accept 时直接失败。
        $spinBudget = $this->resolvePostFailureSpinBudgetSeconds();
        if ($spinBudget > 0.0) {
            $deadline = \microtime(true) + $spinBudget;
            while (\microtime(true) < $deadline) {
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
                SchedulerSystem::usleep((int)($this->spinWaitIntervalMs * 1000));
            }
        }
        
        // 9. 业务 Worker 池为空（未就绪）或全部失败后，检测是否有维护 Worker 备选
        //    （维护模式或启动阶段）→ 尝试转接到维护 Worker 池以提供友好页面
        if (!empty($this->maintenanceWorkerPorts)) {
            foreach ($this->maintenanceWorkerPorts as $maintenancePort) {
                $maintenanceSocket = $this->connectToWorker($maintenancePort);
                if ($maintenanceSocket !== false) {
                    $this->recordWorkerSuccess($maintenancePort);
                    $this->stats['maintenance_routed']++;
                    return $this->registerConnection($connId, $clientSocket, $maintenanceSocket, $maintenancePort, $clientIp, $sni);
                }
                // 维护 Worker 连接失败，尝试下一个
                $this->recordWorkerFailure($maintenancePort);
            }
        }
        
        // 所有 Worker 均不可用（业务 Worker 全失败，维护 Worker 也无可用）
        $this->stats['all_workers_down']++;
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

        return true;
    }

    private function resolvePostFailureSpinBudgetSeconds(): float
    {
        if ($this->spinWaitMaxSeconds <= 0.0) {
            return 0.0;
        }
        if ($this->workerPorts === []) {
            return 0.0;
        }

        return $this->spinWaitMaxSeconds;
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
            return false;
        }

        // 从当前轮询位置开始，遍历所有 Worker
        $count = \count($this->workerPorts);
        $startIndex = $this->connectionCounter % $count;
        $this->connectionCounter++;
        
        for ($i = 0; $i < $count; $i++) {
            $index = ($startIndex + $i) % $count;
            $port = $this->workerPorts[$index];

            // 跳过已尝试的端口
            if ($port === $excludePort) {
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
            $this->recordWorkerFailure($port);
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
            $this->recordWorkerFailure((int)$port);
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

        $workerSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($workerSocket === false) {
            return false;
        }
        
        // 设置非阻塞
        \socket_set_nonblock($workerSocket);

        // 尝试连接
        $result = @\socket_connect($workerSocket, $this->workerHost, $workerPort);
        
        if ($result === false) {
            $error = \socket_last_error($workerSocket);
            
            // 非阻塞连接中，EINPROGRESS 是正常的
            if ($error !== SOCKET_EINPROGRESS && $error !== SOCKET_EALREADY) {
                // Windows 上可能返回 WSAEWOULDBLOCK
                if (PHP_OS_FAMILY !== 'Windows' || $error !== 10035) {
                    \socket_close($workerSocket);
                    return false;
                }
            }
            
            // 连接超时不能太长，否则高并发下会因等待导致级联超时。限制在 0.3~0.5s 内，
            // 失败则依赖连接池的指数退避策略在后续请求恢复。
            $write = [$workerSocket];
            $read = null;
            $except = null;
            $failoverTimeout = \max(0.3, \min($this->connectTimeout, 0.5));
            
            // socket_select 第4参数需要整数秒，第5参数为微秒
            $seconds = (int)$failoverTimeout;
            $microseconds = (int)(($failoverTimeout - $seconds) * 1000000);
            $ready = @\socket_select($read, $write, $except, $seconds, $microseconds);
            
            if ($ready <= 0) {
                \socket_close($workerSocket);
                return false;
            }
            
            // 检查连接是否成功（修复：正确获取 socket_get_option 返回值）
            $optval = \socket_get_option($workerSocket, SOL_SOCKET, SO_ERROR);
            if ($optval !== 0) {
                \socket_close($workerSocket);
                return false;
            }
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
    private function recordWorkerFailure(int $port): void
    {
        $this->stats['worker_failures']++;
        
        if (!isset($this->workerHealth[$port])) {
            $this->workerHealth[$port] = [
                'failures' => 1,
                'blacklisted_at' => 0.0,
                'last_success' => 0.0,
                'total_failures' => 1,
            ];
        } else {
            $this->workerHealth[$port]['failures']++;
            $this->workerHealth[$port]['total_failures']++;
        }
        
        // 达到阈值，加入黑名单
        if ($this->workerHealth[$port]['failures'] >= self::WORKER_FAIL_THRESHOLD
            && $this->workerHealth[$port]['blacklisted_at'] <= 0) {
            $this->workerHealth[$port]['blacklisted_at'] = \microtime(true);
        }
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

    private function probeWorkerApplicationHealth(int $port): bool
    {
        $timeout = \max(0.3, \min((float)$this->connectTimeout, 0.8));
        $probe = $this->requestWorkerHealth($port, $timeout, 0.5);

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
     * 动态添加 Worker 端口到负载均衡池（IPC add_worker 命令）
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
     * Master 已收到 Worker READY 后的轻量探活（ADD_WORKER / SET_WORKER_POOL）。
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
        $maxRetries = self::IPC_READY_WARMUP_RETRIES;
        $retryDelay = self::IPC_READY_WARMUP_RETRY_DELAY_USEC;
        $lastError = 'warmup failed';
        $configured = (float) $this->connectTimeout;
        $connectTimeout = \max(
            self::IPC_READY_WARMUP_CONNECT_MIN,
            \min($configured > 0 ? $configured : self::IPC_READY_WARMUP_CONNECT_MAX, self::IPC_READY_WARMUP_CONNECT_MAX)
        );
        $responseTimeout = self::IPC_READY_WARMUP_RESPONSE_SEC;

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
                $this->warmupYield();
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
            $this->logWarmup(
                "Worker:{$port} IPC 入池探活失败 (尝试 {$attempt}/{$maxRetries}, 耗时 {$elapsed}s): {$lastError}",
                $attempt === $maxRetries ? 'ERROR' : 'WARNING'
            );
            if ($attempt < $maxRetries) {
                $this->warmupYield();
                $this->warmupDelayUsec($retryDelay);
            }
        }

        return ['success' => false, 'error' => $lastError];
    }

    private function buildWorkerHomepageWarmupRequest(): string
    {
        return "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . InternalRequestLabel::buildHeaderLine(InternalRequestLabel::HOMEPAGE_WARMUP)
            . "Connection: close\r\n\r\n";
    }

    /**
     * 严格预热（较长连接/响应窗口，多次重试）。供非 IPC 就绪语义场景或子类测试桩覆盖。
     */
    protected function warmupWorker(int $port): array
    {
        $maxRetries = 3;
        $retryDelay = 100000; // 100ms in microseconds
        $lastError = 'warmup failed';
        $connectTimeout = \max(2.0, \min((float)$this->connectTimeout, 4.0));
        $responseTimeout = 4.0;

        $this->logWarmup(
            "开始预热 Worker:{$port} path=" . self::WORKER_HEALTH_PATH
            . " protocol=" . ($this->workerSslEnabled ? 'ssl' : 'tcp')
            . " connect_timeout={$connectTimeout}s response_timeout={$responseTimeout}s",
            'INFO'
        );

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->warmupYield();
            $probe = $this->requestWorkerHealth($port, $connectTimeout, $responseTimeout);
            if ($probe['success']) {
                $elapsed = $probe['elapsed'] ?? 0.0;
                $statusLine = (string)($probe['status_line'] ?? 'HTTP/1.1 200 OK');
                $this->logWarmup(
                    "Worker:{$port} 预热成功 (耗时 {$elapsed}s, 尝试 {$attempt}/{$maxRetries}, response=\"{$statusLine}\")",
                    'INFO'
                );
                if (isset($this->workerHealth[$port])) {
                    $this->workerHealth[$port]['last_success'] = \microtime(true);
                }
                return ['success' => true, 'error' => ''];
            }

            $lastError = (string)($probe['error'] ?? $lastError);
            $elapsed = $probe['elapsed'] ?? 0.0;
            $this->logWarmup(
                "Worker:{$port} 预热失败 (尝试 {$attempt}/{$maxRetries}, 耗时 {$elapsed}s): {$lastError}",
                $attempt === $maxRetries ? 'ERROR' : 'WARNING'
            );
            if ($attempt < $maxRetries) {
                $this->warmupYield();
                $this->warmupDelayUsec($retryDelay);
                continue;
            }
        }

        return ['success' => false, 'error' => $lastError];
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
    private function warmupWorkerViaHomepage(
        int $port,
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
                $request = $this->buildWorkerHomepageWarmupRequest();
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
    private function requestWorkerHealth(int $port, float $connectTimeout, float $responseTimeout): array
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

            $connectDeadline = \microtime(true) + \max(0.2, $connectTimeout);
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
                $tlsDeadline = \microtime(true) + \max(0.5, \min($responseTimeout, 2.5));
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
                        return [
                            'success' => false,
                            'error' => 'health tls handshake failed',
                            'elapsed' => \round(\microtime(true) - $startedAt, 2),
                        ];
                    }
                    $ready = $this->waitForStreamReady($conn, true, true, $tlsDeadline);
                    if ($ready === false) {
                        return [
                            'success' => false,
                            'error' => 'health tls handshake select failed',
                            'elapsed' => \round(\microtime(true) - $startedAt, 2),
                        ];
                    }
                    if ($ready === 0) {
                        return [
                            'success' => false,
                            'error' => 'health tls handshake timeout',
                            'elapsed' => \round(\microtime(true) - $startedAt, 2),
                        ];
                    }
                }
                if (!$tlsOk) {
                    return [
                        'success' => false,
                        'error' => 'health tls handshake timeout',
                        'elapsed' => \round(\microtime(true) - $startedAt, 2),
                    ];
                }
            }

            $request = $this->buildWorkerHealthRequest();
            $writeOffset = 0;
            $writeDeadline = \microtime(true) + \max(0.3, \min($responseTimeout, 2.0));
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
            $readDeadline = \microtime(true) + \max(0.5, $responseTimeout);
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
                    'error' => "health response timeout after {$elapsed}s",
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
     * 从负载均衡池移除 Worker 端口（IPC remove_worker 命令）
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
     * SET_WORKER_POOL 渐进发布：每预热成功一个端口即更新池，使维护 Worker 不必等待后续业务 Worker。
     *
     * @param int[] $newPorts
     * @param array<int, array{failures: int, blacklisted_at: float, last_success: float, total_failures: int}> $newHealth
     */
    private function applyWorkerPoolTransition(array $newPorts, array $newHealth): void
    {
        $prev = $this->workerPorts;
        foreach ($prev as $p) {
            if (!\in_array($p, $newPorts, true)) {
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
     * 替换整个 Worker 端口池（维护模式：仅维护 Worker / 恢复业务 Worker）
     *
     * @param int[] $ports
     * @return array{accepted: int[], rejected: array<int, string>}
     */
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
                    'SET_WORKER_POOL 首轮快速探活未通过，已通过兜底探活入池: '
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
                'SET_WORKER_POOL 新端口全量预热失败，保留旧池: ' . \implode(',', $previousPorts),
                'WARNING'
            );
        } else {
            $this->applyWorkerPoolTransition($acceptedPorts, $acceptedHealth);
        }
        $this->writeStderr(
            '[PassthroughCore] SET_WORKER_POOL 当前列表: ' . (\implode(',', $this->workerPorts) ?: '(空)') . "\n"
        );
        if ($rejectedPorts !== []) {
            $items = [];
            foreach ($rejectedPorts as $port => $reason) {
                $items[] = "{$port}: {$reason}";
            }
            $this->writeStderr(
                '[PassthroughCore] SET_WORKER_POOL 预热拒绝端口: ' . \implode('; ', $items) . "\n"
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
            return ['success' => false, 'error' => '维护 Worker 端口无效: ' . $port];
        }
        
        if (\in_array($port, $this->maintenanceWorkerPorts, true)) {
            return ['success' => true, 'message' => '维护 Worker 端口已存在: ' . $port];
        }
        
        // 快速探活，确认维护 Worker 已启动
        $probe = $this->probeWorkerApplicationHealth($port);
        if (!$probe) {
            return ['success' => false, 'error' => "维护 Worker {$port} 探活失败，可能未启动"];
        }
        
        $this->maintenanceWorkerPorts[] = $port;
        $this->writeStderr("[PassthroughCore] 维护 Worker 端口已注册: {$port}\n");
        
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
        
        // 转发到 Worker（完整写入循环，处理部分写入）
        $totalWritten = 0;
        $maxRetries = 100;
        $retries = 0;
        
        while ($totalWritten < $length && $retries < $maxRetries) {
            $remaining = \substr($data, $totalWritten);
            $written = @\socket_write($workerSocket, $remaining);
            
            if ($written === false) {
                // Worker 写入失败（Worker 可能掉线），记录到健康状态
                $errCode = \socket_last_error($workerSocket);
                $this->connectionTerminalReasons[$connId] = 'forward_to_worker_worker_write_error:' . (string)$errCode;
                $this->recordWorkerFailure($workerPort);
                return $totalWritten > 0 ? $totalWritten : -1;
            }
            
            if ($written === 0) {
                SchedulerSystem::usleep(1000);
                $retries++;
                continue;
            }
            
            $totalWritten += $written;
            $retries = 0;
        }

        if ($totalWritten > 0 && isset($this->connections[$connId])) {
            $requestSentAt = (float)($this->connections[$connId]['request_sent_at'] ?? 0.0);
            if ($requestSentAt <= 0.0) {
                $this->connections[$connId]['request_sent_at'] = \microtime(true);
            }
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
        $maxReadAttempts = 100; // 增加到 100 次
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
                        $this->recordWorkerFailure($workerPort);
                        $this->connectionTerminalReasons[$connId] = 'forward_to_client_first_byte_timeout';
                        return -1;
                    }
                    break; // 暂无更多数据
                }
                
                // Worker 读取失败（Worker 掉线），记录到健康状态
                $this->recordWorkerFailure($workerPort);
                $this->connectionTerminalReasons[$connId] = 'forward_to_client_worker_read_error:' . (string)$errCode;
                return $totalBytesForwarded > 0 ? $totalBytesForwarded : -1;
            }
            
            // socket_read 返回空字符串表示对方关闭了连接（发送了 FIN）
            if ($data === '') {
                $workerEof = true;
                break;
            }
            
            $length = \strlen($data);
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
                        SchedulerSystem::usleep(1000);
                        $retries++;
                        continue;
                    }
                    // 写入错误，连接断开
                    $this->connectionTerminalReasons[$connId] = 'forward_to_client_client_write_error:' . (string)$errCode;
                    return $totalBytesForwarded + $totalWritten > 0 
                        ? $totalBytesForwarded + $totalWritten 
                        : -1;
                }
                
                if ($written === 0) {
                    SchedulerSystem::usleep(1000);
                    $retries++;
                    continue;
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
                    $attempts++;
                    SchedulerSystem::usleep(1000);
                    continue;
                }
                // 写入错误
                $this->connectionTerminalReasons[$connId] = 'flush_client_buffer_client_write_error:' . (string)$errCode;
                unset($this->clientWriteBuffers[$connId]);
                return -1;
            }
            
            if ($written === 0) {
                $attempts++;
                SchedulerSystem::usleep(1000);
                continue;
            }
            
            $totalWritten += $written;
            $attempts = 0;
        }
        
        if ($totalWritten >= $bufferLen) {
            unset($this->clientWriteBuffers[$connId]);
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
            return;
        }
        
        $workerSocket = $this->connections[$connId]['worker'];
        $workerPort = (int)$this->connections[$connId]['port'];
        $isTlsTunnelLikely = (($this->connections[$connId]['sni'] ?? '') !== '');

        $reusable = $this->backendPoolEnabled
            && !$this->workerSslEnabled
            && !$isTlsTunnelLikely
            && empty($this->clientWriteBuffers[$connId])
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
        unset($this->workerClosed[$connId]);
        unset($this->connectionTerminalReasons[$connId]);
        unset($this->clientInputClosed[$connId]);
        
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
        $this->clientInputClosed = [];
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
