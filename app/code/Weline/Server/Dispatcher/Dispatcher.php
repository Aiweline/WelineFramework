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

use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterResurrector;
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
     * 日志函数
     */
    private ?\Closure $logFunction = null;
    
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
        $this->passthroughCore = new PassthroughCore($workerHost, $workerBasePort, $workerCount);
        $this->attackDetector = AttackDetector::getInstance()->setInstanceName($instanceName);
        $this->instanceName = $instanceName;
        $this->processName = $processName;
        $this->port = $port;
        $this->httpsEnabled = $this->detectHttpsEnabled($instanceName);
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
            . "Retry-After: 30\r\n"
            . "Connection: close\r\n\r\n"
            . "<!DOCTYPE html><html><head><meta charset='UTF-8'>"
            . "<title>System Maintenance</title></head><body>"
            . "<h1 style='text-align:center;margin-top:20vh'>"
            . "System Under Maintenance</h1>"
            . "<p style='text-align:center'>We are upgrading. "
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
        if (!\is_file($instanceFile)) {
            return false;
        }
        $instData = @\json_decode((string)\file_get_contents($instanceFile), true);
        return \is_array($instData) && !empty($instData['ssl_enabled']);
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
        
        if (isset($config['attack_detection_enabled'])) {
            $this->attackDetectionEnabled = (bool) $config['attack_detection_enabled'];
        }
        
        // 传递攻击探测规则配置
        if (isset($config['attack_rules'])) {
            $this->attackDetector->updateRules($config['attack_rules']);
        }
    }
    
    /**
     * 设置日志函数
     *
     * @param callable $logFunction 日志函数
     */
    public function setLogFunction(callable $logFunction): void
    {
        $this->logFunction = $logFunction instanceof \Closure ? $logFunction : \Closure::fromCallable($logFunction);
    }
    
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
     * 内部日志方法
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logFunction !== null) {
            ($this->logFunction)($message, $level);
        }
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
        $this->ipcClient->setLogger(function (string $line) {
            $this->log($line, 'IPC');
        });
        // DEV 模式下输出详细 IPC SEND/RECV 明细
        $this->ipcClient->setVerboseLog($this->isDevMode);
        if (!$this->ipcClient->connect('127.0.0.1', $this->controlPort)) {
            $this->log("IPC 控制通道连接失败 (端口: {$this->controlPort})", 'WARN');
            $this->ipcClient = null;
            return;
        }
        
        $this->ipcClient->register(ControlMessage::ROLE_DISPATCHER, \getmypid(), $this->port);
        $this->log("IPC 控制通道已连接 (端口: {$this->controlPort})", 'INFO');
        
        // 设置消息处理器
        $this->ipcClient->onMessage(function (array $msg, ControlClient $client) {
            $this->handleIpcMessage($msg);
        });
        
        // 设置断开处理器
        $this->ipcClient->onDisconnect(function (bool $receivedShutdown, ControlClient $client) {
            if ($receivedShutdown) {
                $this->log('Master 连接断开（已收到 shutdown，不复活）', 'INFO');
                return;
            }
            $this->log('Master 连接意外断开，尝试复活...', 'WARN');
            
            $resurrector = new MasterResurrector(
                ControlMessage::RESURRECTION_DISPATCHER,
                $this->instanceName,
                '127.0.0.1',
                $this->controlPort
            );
            if ($resurrector->shouldResurrect($receivedShutdown)) {
                $resurrector->attemptResurrect();
            }
            $client->tryReconnect();
        });
        
        // 上报就绪
        $this->ipcClient->sendReady(ControlMessage::ROLE_DISPATCHER, 0, $this->port);
    }
    
    /**
     * 处理 IPC 控制消息
     */
    private function handleIpcMessage(array $msg): void
    {
        $type = $msg['type'] ?? '';
        
        switch ($type) {
            case ControlMessage::TYPE_DRAIN:
                // 将指定端口加入黑名单
                $ports = $msg['ports'] ?? [];
                foreach ($ports as $port) {
                    $this->passthroughCore->blacklistWorker((int)$port);
                }
                $this->log('Drain: 端口 ' . \implode(',', $ports) . ' 已加入黑名单', 'DRAIN');
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
                // 动态添加 Worker 端口到负载均衡池
                $ports = $msg['ports'] ?? [];
                foreach ($ports as $port) {
                    $this->passthroughCore->addWorkerPort((int)$port);
                }
                $this->log('添加 Worker 端口: ' . \implode(',', $ports), 'INFO');
                break;
                
            case ControlMessage::TYPE_REMOVE_WORKER:
                // 从负载均衡池移除端口
                $ports = $msg['ports'] ?? [];
                foreach ($ports as $port) {
                    $this->passthroughCore->removeWorkerPort((int)$port);
                }
                $this->log('移除 Worker 端口: ' . \implode(',', $ports), 'WARN');
                break;
                
            case ControlMessage::TYPE_SHUTDOWN:
                $this->log('收到 shutdown 命令', 'WARN');
                $this->ipcReceivedShutdown = true;
                $this->running = false;
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
        
        $loopCount = 0;
        while ($this->running) {
            $loopCount++;
            // 信号处理
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
            
            // IPC 控制通道：处理消息（非阻塞读取）
            if ($this->ipcClient) {
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
                } elseif (!$this->ipcReceivedShutdown) {
                    $this->ipcClient->tryReconnect();
                }
            }
            
            // Master 心跳检查（保留文件方式作为兜底，IPC 断开时使用）
            if (!$this->ipcClient || !$this->ipcClient->isConnected()) {
                $this->checkMasterHeartbeat();
            }
            
            // 孤儿检测：定期检查 Master PID 是否存活
            $this->checkMasterPidAlive();
            
            // Worker 健康探活（定期检查黑名单中的 Worker 是否已恢复）
            $this->probeWorkerHealth();
            
            // 连接超时清理
            $this->cleanupExpiredConnections();
            
            // 事件处理
            $this->selectAndProcess();
            
            // 定期统计
            $this->printStats();
        }
        
        $this->shutdown();
    }
    
    /**
     * 检查 Master 心跳
     */
    private function checkMasterHeartbeat(): void
    {
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
     * 定期探活黑名单中的 Worker
     *
     * 当有 Worker 在黑名单中时，Dispatcher 会主动尝试连接它们，
     * 以便在 Worker 恢复后尽快重新纳入负载均衡。
     */
    private function probeWorkerHealth(): void
    {
        $now = \microtime(true);
        if ($now - $this->lastWorkerProbeTime < $this->workerProbeInterval) {
            return;
        }
        $this->lastWorkerProbeTime = $now;
        
        $recovered = $this->passthroughCore->probeBlacklistedWorkers();
        
        if (!empty($recovered)) {
            $ports = \implode(', ', $recovered);
            $this->log("Worker 恢复: 端口 {$ports} 已重新加入负载均衡", 'HEALTH');
        }
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
            if (($nowMicro - $lastActivity) > $this->connectionTimeout) {
                $this->closeConnection($connId);
                $closedCount++;
            }
        }
        
        if ($closedCount > 0) {
            $this->log("清理超时连接: {$closedCount} 个", 'HEALTH');
        }
    }
    
    /**
     * socket_select 事件处理
     */
    private function selectAndProcess(): void
    {
        // H15: 先刷新所有有缓冲数据的客户端连接
        $this->flushPendingBuffers();
        
        // 准备 socket 列表
        $readSockets = [$this->serverSocket];
        $workerSockets = [];
        
        // 添加所有客户端连接
        foreach ($this->clientConnections as $connId => $clientSocket) {
            $readSockets[] = $clientSocket;
            
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
        $timeout = $hasBuffers ? 0 : 0;
        $microTimeout = $hasBuffers ? 5000 : 50000; // 有缓冲数据时 5ms，否则 50ms
        
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
                $this->closeConnection($connId);
                continue;
            }
            
            if ($flushed > 0) {
                $this->connectionLastActivity[$connId] = \microtime(true);
                $this->bytesCount['out'] += $flushed;
            }
            
            // 如果缓冲区已空且 Worker 已关闭，现在可以安全关闭连接
            if (!$this->passthroughCore->hasBufferedData($clientSocket) 
                && $this->passthroughCore->isWorkerClosedWithBuffer($clientSocket)) {
                $this->closeConnection($connId);
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
                $this->log("SSL 封禁拦截: {$clientIp} (connId: {$connId}) — IP 因频繁 SSL 握手失败被封禁", 'BAN');
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
                $this->clientConnections[$connId] = $clientSocket;
                $this->connectionAcceptTime[$connId] = \microtime(true);
                $this->connectionLastActivity[$connId] = \microtime(true);
                $this->requestCount++;
                
                $workerPort = $this->passthroughCore->getConnectionWorkerPort($clientSocket);
                if ($this->isDevMode) {
                    $this->log("新连接: {$clientIp} (connId: {$connId}) → Worker:{$workerPort}", 'ROUTE');
                }
            } else {
                // 所有 Worker 均不可用
                $healthSummary = $this->passthroughCore->getWorkerHealthSummary();
                $this->log("所有 Worker 不可用! {$clientIp} (connId: {$connId}), "
                    . "healthy: {$healthSummary['healthy']}/{$healthSummary['total']}", 'ERROR');
                @\socket_close($clientSocket);
            }
            
            $accepted++;
        } while ($accepted < $maxAcceptPerLoop);
    }

    /**
     * HTTPS 模式下识别并处理明文 HTTP 请求，返回同端口 https 301
     *
     * @return bool true=已处理并关闭连接；false=非明文 HTTP，继续走 TCP 透传
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

        $redirectHost = "127.0.0.1:{$this->port}";
        if (\preg_match('/\r\nHost:\s*([^\r\n]+)/i', $raw, $h)) {
            $redirectHost = \trim((string)$h[1]);
        }
        if (!\str_contains($redirectHost, ':') && $this->port !== 443) {
            $redirectHost .= ':' . $this->port;
        }
        $redirectUrl = "https://{$redirectHost}{$target}";

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
        $this->log("HTTP->HTTPS 301: {$clientIp} (connId: {$connId}) => {$redirectUrl}", 'ROUTE');
        return true;
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
        
        if ($result === -1) {
            // 连接真正关闭或错误
            if ($this->isDevMode) {
                $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket);
                $clientIp = $connInfo['client_ip'] ?? 'unknown';
                $this->log("连接关闭(客户端→Worker): {$clientIp} (connId: {$connId})", 'CLOSE');
            }
            $this->closeConnection($connId);
            return;
        }
        
        // result === 0: 暂无数据（WOULDBLOCK），连接正常，不做任何操作
        
        if ($result > 0) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            $this->bytesCount['in'] += $result;
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
            $this->closeConnection($connId);
            return;
        }
        
        // H15: result === -2: Worker 关闭但有缓冲数据待发送
        // 不关闭客户端连接，等待缓冲区数据发送完成
        
        if ($result > 0 || $result === -2) {
            $this->connectionLastActivity[$connId] = \microtime(true);
            if ($result > 0) {
                $this->bytesCount['out'] += $result;
            }
        }
    }
    
    /**
     * 关闭连接
     *
     * @param int $connId 连接 ID
     */
    private function closeConnection(int $connId): void
    {
        if (isset($this->clientConnections[$connId])) {
            $clientSocket = $this->clientConnections[$connId];
            
            // 快速关闭检测：在关闭连接前获取信息并检测疑似 SSL 握手失败
            $this->detectSuspectSslFailure($connId, $clientSocket);
            
            // 关闭透传核心中的连接
            $this->passthroughCore->closeConnection($clientSocket);
            
            // 关闭客户端连接
            @\socket_close($clientSocket);
            
            unset($this->clientConnections[$connId]);
        }
        
        unset(
            $this->connectionAcceptTime[$connId],
            $this->connectionLastActivity[$connId]
        );
    }
    
    /**
     * 检测疑似 SSL 握手失败（快速关闭模式）
     *
     * 连接在极短时间内关闭（< 阈值秒），且几乎无数据交换，视为疑似 SSL 握手失败。
     * 典型场景：客户端拒绝自签名证书 → 发送 SSL alert → 立即断开连接。
     *
     * @param int      $connId       连接 ID
     * @param resource $clientSocket 客户端 socket
     */
    private function detectSuspectSslFailure(int $connId, $clientSocket): void
    {
        if (!isset($this->connectionAcceptTime[$connId])) {
            return;
        }
        
        $connInfo = $this->passthroughCore->getConnectionInfo($clientSocket);
        if ($connInfo === null) {
            return;
        }
        
        $clientIp = $connInfo['client_ip'] ?? 'unknown';
        $duration = \microtime(true) - $this->connectionAcceptTime[$connId];
        $threshold = $this->attackDetector->getSslFastCloseThreshold();
        
        // 连接存活时长 < 阈值 → 疑似 SSL 握手失败
        if ($duration >= $threshold) {
            return;
        }
        
        $durationStr = \round($duration, 1);
        
        // 本地和 CDN 回源白名单：只记录日志，不封禁
        $isLocalIp = $this->isTrustedSourceIp($clientIp);
        
        if ($isLocalIp) {
            // 本地 IP：仅记录，不追踪封禁
            // 开发环境下自签名证书导致的 SSL 失败属于正常现象
            return;
        }
        
        // 非本地 IP：记录到攻击检测器并追踪
        $result = $this->attackDetector->recordSslFailure($clientIp, $duration);
        
        if ($result['banned']) {
            // 触发封禁 → 红色告警
            $this->log(
                "SSL 握手失败频繁: {$clientIp} ({$result['threshold']}次/{$result['ban_duration']}秒窗口内) → 已封禁 {$result['ban_duration']} 秒",
                'BAN'
            );
        } else {
            // 未触发封禁 → 红色警告（累计进度）
            $this->log(
                "疑似 SSL 握手失败: {$clientIp} (connId: {$connId}, 存活 {$durationStr}秒) [累计: {$result['count']}/{$result['threshold']}]",
                'SSL_FAIL'
            );
        }
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
        $allWorkersDown = $coreStats['all_workers_down'] ?? 0;
        
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
        
        // 如果发生过"所有 Worker 均不可用"的情况，发出警告
        if ($allWorkersDown > 0) {
            $this->log("{$allWorkersDown} requests failed - all workers were unavailable!", 'ERROR');
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
     * 注册信号处理
     */
    private function registerSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        
        \pcntl_signal(SIGTERM, function () {
            $this->log('收到 SIGTERM 信号', 'WARN');
            $this->running = false;
        });
        
        \pcntl_signal(SIGINT, function () {
            $this->log('收到 SIGINT 信号', 'WARN');
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
        
        // 关闭 IPC 控制客户端
        if ($this->ipcClient) {
            $this->ipcClient->close();
            $this->ipcClient = null;
        }
        
        // 关闭所有连接
        foreach ($this->clientConnections as $connId => $socket) {
            $this->closeConnection($connId);
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
