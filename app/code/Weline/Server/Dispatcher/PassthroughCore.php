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
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

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
    
    /**
     * Worker 黑名单恢复时间（秒）
     * 黑名单中的 Worker 在此时间后自动尝试恢复
     */
    private const WORKER_BLACKLIST_RECOVERY_SECONDS = 5;
    
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
     * @var int[]
     */
    private array $workerPorts = [];

    /**
     * HTTP 重定向端口（用于明文 HTTP 请求转发到 http_redirect_worker）
     */
    private int $httpRedirectPort = 0;
    
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
     * 格式: [clientSocket => ['worker' => resource, 'port' => int, 'clientIp' => string, 'sni' => string]]
     * @var array<int, array{worker: resource, port: int, clientIp: string, sni: string, open_time: float}>
     */
    private array $connections = [];
    
    /**
     * H15: 客户端写入缓冲区
     * 当客户端 TCP 发送缓冲区满时，暂存未写入的数据
     * 格式: [connId => string]
     * @var array<int, string>
     */
    private array $clientWriteBuffers = [];
    
    /**
     * H15: Worker 已关闭但还有缓冲数据需要发送的连接
     * @var array<int, bool>
     */
    private array $workerClosed = [];
    
    /**
     * Worker 健康状态
     * 格式: [port => ['failures' => int, 'blacklisted_at' => float, 'last_success' => float, 'total_failures' => int]]
     * @var array<int, array{failures: int, blacklisted_at: float, last_success: float, total_failures: int}>
     */
    private array $workerHealth = [];
    
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
     * Worker 全部不可用时的自旋等待总时长（秒）
     * 热重载期间 Worker 可能有短暂空窗，自旋等待可避免请求直接失败
     */
    private float $spinWaitMaxSeconds = 3.0;
    
    /**
     * 自旋等待间隔（毫秒）
     */
    private int $spinWaitIntervalMs = 50;
    
    /**
     * 上次输出「workerPorts 为空」到 stderr 的时间（节流，避免启动时刷屏）
     */
    private float $lastEmptyWorkerPortsStderrAt = 0.0;

    /**
     * 统计信息
     */
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'cache_routed' => 0,
        'round_robin_routed' => 0,
        'failover_routed' => 0,
        'sni_extractions' => 0,
        'bytes_in' => 0,
        'bytes_out' => 0,
        'worker_failures' => 0,
        'all_workers_down' => 0,
    ];
    
    /**
     * 构造函数
     *
     * @param string $workerHost Worker 主机地址
     * @param int $workerBasePort Worker 基础端口（仅用于兼容，实际端口由 Master 通知）
     * @param int $workerCount Worker 数量（初始值，实际由动态端口列表决定）
     */
    public function __construct(string $workerHost, int $workerBasePort, int $workerCount)
    {
        $this->workerHost = $workerHost;
        $this->workerBasePort = $workerBasePort;
        $this->workerCount = 0; // 初始为 0，等待 Master 通知实际端口
        $this->routingCache = RoutingCacheService::getInstance();
        
        // 不再预初始化端口，由 Master 通过 add_worker 消息动态添加
        // Worker 端口将在 addWorkerPort() 中注册
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
        if (isset($config['spin_wait_max_seconds'])) {
            $this->spinWaitMaxSeconds = (float) $config['spin_wait_max_seconds'];
        }
        if (isset($config['spin_wait_interval_ms'])) {
            $this->spinWaitIntervalMs = \max(10, (int) $config['spin_wait_interval_ms']);
        }
        
        // 传递缓存配置
        if (isset($config['cache'])) {
            $this->routingCache->configure($config['cache']);
        }
        
        // HTTP 重定向端口
        if (isset($config['http_redirect_port'])) {
            $this->httpRedirectPort = (int) $config['http_redirect_port'];
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
        
        // 1. 尝试从连接缓存查找路由（Keep-Alive 场景）
        $routeInfo = $this->routingCache->getRouteByConnection($connId);
        if ($routeInfo !== null) {
            $workerPort = $routeInfo['port'];
            $sni = $routeInfo['sni'];
            $fromCache = true;
        }
        
        // 2. 尝试从 IP 缓存查找路由
        if ($workerPort === null) {
            $routeInfo = $this->routingCache->getRouteByIp($clientIp);
            if ($routeInfo !== null) {
                $workerPort = $routeInfo['port'];
                $sni = $routeInfo['sni'];
                $fromCache = true;
            }
        }
        
        // 3. 如果启用 SNI 路由，尝试 Peek ClientHello 提取 SNI
        if ($workerPort === null && $this->sniRoutingEnabled) {
            $sni = $this->extractSniFromSocket($clientSocket);
            if (!empty($sni)) {
                $this->stats['sni_extractions']++;
                $workerPort = $this->routingCache->getRouteBySni($sni);
                if ($workerPort !== null) {
                    $fromCache = true;
                }
            }
        }
        
        // 4. 如果有缓存路由，先尝试连接该 Worker
        if ($workerPort !== null) {
            // 检查缓存路由的 Worker 是否在黑名单中
            if (!$this->isWorkerBlacklisted($workerPort)) {
                $workerSocket = $this->connectToWorker($workerPort);
                if ($workerSocket !== false) {
                    $this->stats['cache_routed']++;
                    $this->recordWorkerSuccess($workerPort);
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
        
        // 5. 故障转移：尝试所有可用 Worker（跳过黑名单中的）
        $workerSocket = $this->connectToAvailableWorker($workerPort, $sni);
        if ($workerSocket !== false) {
            return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
        }
        
        // 6. 所有健康 Worker 都失败了，最后尝试黑名单中的 Worker（可能已经恢复）
        $workerSocket = $this->connectToAnyWorker($workerPort);
        if ($workerSocket !== false) {
            return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
        }
        
        // 7. 自旋等待：热重载期间 Worker 可能有短暂空窗，重试以降低 404/连接拒绝
        if ($this->spinWaitMaxSeconds > 0 && !empty($this->workerPorts)) {
            $deadline = \microtime(true) + $this->spinWaitMaxSeconds;
            while (\microtime(true) < $deadline) {
                \usleep($this->spinWaitIntervalMs * 1000);
                $workerSocket = $this->connectToAvailableWorker($workerPort, $sni);
                if ($workerSocket !== false) {
                    $this->stats['failover_routed']++;
                    return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
                }
                $workerSocket = $this->connectToAnyWorker($workerPort);
                if ($workerSocket !== false) {
                    $this->stats['failover_routed']++;
                    return $this->registerConnection($connId, $clientSocket, $workerSocket['socket'], $workerSocket['port'], $clientIp, $sni);
                }
            }
        }
        
        // 所有 Worker 均不可用（含自旋等待后仍失败）
        $this->stats['all_workers_down']++;
        return false;
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
            
            $workerSocket = $this->connectToWorker($port);
            if ($workerSocket !== false) {
                if ($excludePort !== null) {
                    $this->stats['failover_routed']++;
                } else {
                    $this->stats['round_robin_routed']++;
                }
                $this->recordWorkerSuccess($port);
                return ['socket' => $workerSocket, 'port' => $port];
            }
            
            // 连接失败，记录
            $this->recordWorkerFailure($port);
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
                $this->recordWorkerSuccess($port);
                return ['socket' => $workerSocket, 'port' => $port];
            }
        }
        
        return false;
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
            
            // 使用较短超时（1 秒）以便快速故障转移
            $write = [$workerSocket];
            $read = null;
            $except = null;
            $failoverTimeout = \min($this->connectTimeout, 1);
            
            $ready = @\socket_select($read, $write, $except, $failoverTimeout);
            
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
     * 主动探活：尝试连接到所有黑名单中的 Worker
     * 
     * 由 Dispatcher 定期调用，用于提前发现已恢复的 Worker，
     * 而不是等到有新请求时才尝试。
     *
     * @return array 恢复的 Worker 端口列表
     */
    public function probeBlacklistedWorkers(): array
    {
        $recovered = [];
        
        foreach ($this->workerHealth as $port => $health) {
            if ($health['blacklisted_at'] <= 0) {
                continue; // 不在黑名单中，跳过
            }
            
            // 尝试 TCP 连接探活
            $socket = $this->connectToWorker($port);
            if ($socket !== false) {
                @\socket_close($socket);
                $this->recordWorkerSuccess($port);
                $recovered[] = $port;
            }
        }
        
        return $recovered;
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
     * 动态添加 Worker 端口到负载均衡池（IPC add_worker 命令）
     */
    public function addWorkerPort(int $port): void
    {
        // 添加到动态端口列表
        if (!\in_array($port, $this->workerPorts, true)) {
            $this->workerPorts[] = $port;
            $this->workerCount = \count($this->workerPorts);
            $this->writeStderr("[PassthroughCore] 添加 Worker 端口: {$port}, 当前列表: " . \implode(',', $this->workerPorts) . "\n");
        }

        // 添加或重置健康状态
        if (!isset($this->workerHealth[$port])) {
            $this->workerHealth[$port] = [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => \microtime(true),
                'total_failures' => 0,
            ];
        } else {
            // 已存在则重置为健康状态
            $this->workerHealth[$port]['failures'] = 0;
            $this->workerHealth[$port]['blacklisted_at'] = 0.0;
        }
    }

    /**
     * 获取当前 Worker 端口列表（调试用）
     * @return int[]
     */
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
     */
    public function removeWorkerPort(int $port): void
    {
        // 从动态端口列表移除
        $key = \array_search($port, $this->workerPorts, true);
        if ($key !== false) {
            \array_splice($this->workerPorts, $key, 1);
            $this->workerCount = \count($this->workerPorts);
        }

        // 清理健康记录
        unset($this->workerHealth[$port]);
    }
    
    /**
     * 获取 Worker 健康状态摘要
     *
     * @return array{healthy: int, blacklisted: int, total: int, details: array}
     */
    public function getWorkerHealthSummary(): array
    {
        $healthy = 0;
        $blacklisted = 0;
        $details = [];
        
        foreach ($this->workerHealth as $port => $health) {
            $isBlacklisted = $this->isWorkerBlacklisted($port);
            
            if ($isBlacklisted) {
                $blacklisted++;
            } else {
                $healthy++;
            }
            
            $details[$port] = [
                'status' => $isBlacklisted ? 'blacklisted' : 'healthy',
                'failures' => $health['failures'],
                'total_failures' => $health['total_failures'],
                'last_success' => $health['last_success'] > 0 
                    ? \round(\microtime(true) - $health['last_success'], 1) . 's ago' 
                    : 'never',
            ];
        }
        
        return [
            'healthy' => $healthy,
            'blacklisted' => $blacklisted,
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
            return -1;
        }
        
        // socket_read 返回空字符串表示对方关闭了连接（发送了 FIN）
        if ($data === '') {
            // 连接已关闭
            return -1;
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
                $this->recordWorkerFailure($workerPort);
                return $totalWritten > 0 ? $totalWritten : -1;
            }
            
            if ($written === 0) {
                \usleep(1000);
                $retries++;
                continue;
            }
            
            $totalWritten += $written;
            $retries = 0;
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
            return -1;
        }
        
        $conn = $this->connections[$connId];
        $workerSocket = $conn['worker'];
        $workerPort = $conn['port'];
        
        // H15: 如果有未发送完的缓冲数据，先尝试刷新
        if (isset($this->clientWriteBuffers[$connId]) && $this->clientWriteBuffers[$connId] !== '') {
            $flushed = $this->flushClientBuffer($clientSocket);
            if ($flushed === -1) {
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
                    break; // 暂无更多数据
                }
                
                // Worker 读取失败（Worker 掉线），记录到健康状态
                $this->recordWorkerFailure($workerPort);
                return $totalBytesForwarded > 0 ? $totalBytesForwarded : -1;
            }
            
            // socket_read 返回空字符串表示对方关闭了连接（发送了 FIN）
            if ($data === '') {
                $workerEof = true;
                break;
            }
            
            $length = \strlen($data);
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
                        \usleep(1000);
                        $retries++;
                        continue;
                    }
                    // 写入错误，连接断开
                    return $totalBytesForwarded + $totalWritten > 0 
                        ? $totalBytesForwarded + $totalWritten 
                        : -1;
                }
                
                if ($written === 0) {
                    \usleep(1000);
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
            return $totalBytesForwarded > 0 ? $totalBytesForwarded : -1;
        }
        
        return $totalBytesForwarded;
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
                    \usleep(1000);
                    continue;
                }
                // 写入错误
                unset($this->clientWriteBuffers[$connId]);
                return -1;
            }
            
            if ($written === 0) {
                $attempts++;
                \usleep(1000);
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
            return;
        }
        
        $workerSocket = $this->connections[$connId]['worker'];
        
        // 关闭 Worker 连接
        if (\is_resource($workerSocket)) {
            @\socket_close($workerSocket);
        }
        
        // 移除连接记录
        unset($this->connections[$connId]);
        
        // H15: 清理写缓冲区
        unset($this->clientWriteBuffers[$connId]);
        unset($this->workerClosed[$connId]);
        
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
            if (\is_resource($conn['worker'])) {
                @\socket_close($conn['worker']);
            }
            $this->routingCache->removeConnection($connId);
        }
        
        $this->connections = [];
        $this->stats['active_connections'] = 0;
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
            'sni_extractions' => 0,
            'bytes_in' => 0,
            'bytes_out' => 0,
            'worker_failures' => 0,
            'all_workers_down' => 0,
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
