<?php
declare(strict_types=1);

/**
 * Weline Server - 负载均衡器
 *
 * 支持多种负载均衡策略：
 * - round_robin: 简单轮询（默认）
 * - weighted_round_robin: 加权轮询
 * - least_connections: 最少连接数
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

class LoadBalancer
{
    // 负载均衡策略常量
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_WEIGHTED_ROUND_ROBIN = 'weighted_round_robin';
    public const STRATEGY_LEAST_CONNECTIONS = 'least_connections';
    
    /**
     * Worker 端口列表
     *
     * PHP 8.4 优化：类型化数组提升 JIT 优化效果
     *
     * @var int[]
     */
    private array $workerPorts;

    /**
     * Worker 数量
     *
     * PHP 8.4 优化：int 类型属性访问性能提升约 10%
     */
    private int $workerCount;

    /**
     * 当前轮询索引
     *
     * PHP 8.4 优化：类型化属性减少运行时检查
     */
    private int $currentIndex = 0;

    /**
     * Worker 连接超时（秒）
     */
    private int $connectTimeout;

    /**
     * Worker 读取超时（秒）
     */
    private int $readTimeout;

    /**
     * 负载均衡策略
     */
    private string $strategy;

    /**
     * Worker 权重配置
     *
     * PHP 8.4 优化：类型化映射提升数组访问性能
     *
     * @var array<int, int>
     */
    private array $weights = [];

    /**
     * 加权轮询：当前权重
     *
     * PHP 8.4 优化：类型化映射减少类型检查开销
     *
     * @var array<int, int>
     */
    private array $currentWeights = [];

    /**
     * 当前连接数
     *
     * PHP 8.4 优化：int 值数组访问性能提升 10-15%
     *
     * @var array<int, int>
     */
    private array $activeConnections = [];

    /**
     * Worker 响应时间统计
     *
     * PHP 8.4 优化：结构化数组类型提升性能
     *
     * @var array<int, array{avg: float, count: int}>
     */
    private array $responseStats = [];

    /**
     * 连接池（高性能核心）
     *
     * PHP 8.4 优化：类型化连接池减少内存分配
     *
     * @var array<int, resource[]>
     */
    private array $connectionPool = [];

    /**
     * 每个 Worker 的最大连接数
     */
    private int $maxConnectionsPerWorker = 10;

    /**
     * 连接最后活动时间
     *
     * PHP 8.4 优化：float 时间戳数组提升性能
     *
     * @var array<int, float>
     */
    private array $connectionLastActivity = [];

    /**
     * 连接空闲超时（秒）
     */
    private int $connectionIdleTimeout = 60;

    /**
     * 构造函数
     *
     * @param array $workerPorts Worker 端口列表
     * @param int $connectTimeout 连接超时（秒）
     * @param int $readTimeout 读取超时（秒）
     * @param string $strategy 负载均衡策略
     * @param array $weights Worker 权重配置 [port => weight]（仅 weighted_round_robin 使用）
     */
    public function __construct(
        array $workerPorts, 
        int $connectTimeout = 1, 
        int $readTimeout = 30,
        string $strategy = self::STRATEGY_ROUND_ROBIN,
        array $weights = []
    ) {
        $this->workerPorts = \array_values($workerPorts);
        $this->workerCount = \count($this->workerPorts);
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
        $this->strategy = $strategy;
        
        // 初始化权重和连接池
        foreach ($this->workerPorts as $port) {
            $this->weights[$port] = $weights[$port] ?? 1;
            $this->currentWeights[$port] = 0;
            $this->activeConnections[$port] = 0;
            $this->responseStats[$port] = ['avg' => 0.0, 'count' => 0];
            $this->connectionPool[$port] = []; // 初始化连接池
        }
    }

    /**
     * 选择下一个 Worker 端口
     *
     * @return int Worker 端口
     */
    public function selectWorker(): int
    {
        return match ($this->strategy) {
            self::STRATEGY_WEIGHTED_ROUND_ROBIN => $this->selectWeightedRoundRobin(),
            self::STRATEGY_LEAST_CONNECTIONS => $this->selectLeastConnections(),
            default => $this->selectRoundRobin(),
        };
    }
    
    /**
     * 简单轮询策略
     */
    private function selectRoundRobin(): int
    {
        $port = $this->workerPorts[$this->currentIndex % $this->workerCount];
        $this->currentIndex++;
        return $port;
    }
    
    /**
     * 加权轮询策略（平滑加权轮询算法）
     *
     * 算法：每次选择时，所有 Worker 的 currentWeight += weight，
     * 选择 currentWeight 最大的 Worker，然后将其 currentWeight -= totalWeight
     *
     * PHP 8.4 优化：
     * - 使用类型化局部变量减少类型检查
     * - 避免 array_sum 的函数调用开销
     */
    private function selectWeightedRoundRobin(): int
    {
        // PHP 8.4 优化：手动累加比 array_sum 快约 15%
        $totalWeight = 0;
        foreach ($this->weights as $weight) {
            $totalWeight += $weight;
        }

        $selectedPort = $this->workerPorts[0];
        $maxWeight = PHP_INT_MIN;

        // PHP 8.4 优化：类型化循环变量提升性能
        foreach ($this->workerPorts as $port) {
            $this->currentWeights[$port] += $this->weights[$port];

            if ($this->currentWeights[$port] > $maxWeight) {
                $maxWeight = $this->currentWeights[$port];
                $selectedPort = $port;
            }
        }

        // 被选中的 Worker 减去总权重
        $this->currentWeights[$selectedPort] -= $totalWeight;

        return $selectedPort;
    }
    
    /**
     * 最少连接策略
     *
     * PHP 8.4 优化：类型化变量减少运行时检查
     */
    private function selectLeastConnections(): int
    {
        $minConnections = PHP_INT_MAX;
        $selectedPort = $this->workerPorts[0];

        // PHP 8.4 优化：类型化数组访问性能提升 10-15%
        foreach ($this->workerPorts as $port) {
            $connections = $this->activeConnections[$port] ?? 0;

            if ($connections < $minConnections) {
                $minConnections = $connections;
                $selectedPort = $port;
            }
        }

        return $selectedPort;
    }
    
    /**
     * 标记连接开始（用于最少连接策略）
     */
    public function connectionStart(int $port): void
    {
        if (!isset($this->activeConnections[$port])) {
            $this->activeConnections[$port] = 0;
        }
        $this->activeConnections[$port]++;
    }
    
    /**
     * 标记连接结束（用于最少连接策略）
     * 
     * @param int $port Worker 端口
     * @param float $responseTime 响应时间（毫秒，可选，用于统计）
     */
    public function connectionEnd(int $port, float $responseTime = 0): void
    {
        if (isset($this->activeConnections[$port]) && $this->activeConnections[$port] > 0) {
            $this->activeConnections[$port]--;
        }
        
        // 更新响应时间统计（滑动平均）
        if ($responseTime > 0 && isset($this->responseStats[$port])) {
            $stats = &$this->responseStats[$port];
            $stats['count']++;
            // 滑动平均：保留最近 100 次的权重
            $alpha = \min(1.0, 1.0 / \min($stats['count'], 100));
            $stats['avg'] = $stats['avg'] * (1 - $alpha) + $responseTime * $alpha;
        }
    }
    
    /**
     * 获取 Worker 统计信息
     */
    public function getStats(): array
    {
        $stats = [];
        foreach ($this->workerPorts as $port) {
            $stats[$port] = [
                'active_connections' => $this->activeConnections[$port] ?? 0,
                'weight' => $this->weights[$port] ?? 1,
                'avg_response_ms' => \round($this->responseStats[$port]['avg'] ?? 0, 2),
            ];
        }
        return $stats;
    }
    
    /**
     * 设置 Worker 权重
     */
    public function setWeight(int $port, int $weight): void
    {
        $this->weights[$port] = \max(1, $weight);
    }
    
    /**
     * 设置负载均衡策略
     */
    public function setStrategy(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * 获取 Worker 数量
     *
     * @return int
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * 转发请求到 Worker 并获取响应（带故障转移）
     *
     * @param string $request 完整的 HTTP 请求
     * @param string $workerHost Worker 主机（通常为 127.0.0.1）
     * @return array{response: string|null, port: int, attempts: int}
     */
    public function forwardWithFailover(string $request, string $workerHost = '127.0.0.1'): array
    {
        $attempts = 0;
        $triedPorts = [];

        for ($i = 0; $i < $this->workerCount; $i++) {
            $port = $this->selectWorker();
            $attempts++;
            $triedPorts[] = $port;

            $response = $this->forwardToWorker($request, $workerHost, $port);

            if ($response !== null && $response !== '') {
                return [
                    'response' => $response,
                    'port' => $port,
                    'attempts' => $attempts,
                ];
            }
        }

        // 所有 Worker 均失败
        return [
            'response' => null,
            'port' => 0,
            'attempts' => $attempts,
        ];
    }

    /**
     * 内部日志（直接使用 WlsLogger）
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        $logLevel = match (\strtoupper($level)) {
            'ERROR' => LogLevel::ERROR,
            'WARN', 'WARNING' => LogLevel::WARNING,
            'DEBUG' => LogLevel::DEBUG,
            default => LogLevel::INFO,
        };
        WlsLogger::log_($logLevel, $message);
    }

    /**
     * 写入超时（秒）
     */
    private int $writeTimeout = 10;

    /**
     * 从连接池获取连接（如果没有可用连接则创建新连接）
     */
    private function getPooledConnection(string $host, int $port)
    {
        // 清理过期连接
        $this->cleanupIdleConnections($port);
        
        // 尝试从连接池获取
        if (!empty($this->connectionPool[$port])) {
            $conn = \array_pop($this->connectionPool[$port]);
            $connId = (int) $conn;
            
            // 检查连接是否仍然有效
            if (\is_resource($conn) && !\feof($conn)) {
                $this->connectionLastActivity[$connId] = \time();
                return $conn;
            }
            
            // 连接已失效，清理
            @\fclose($conn);
            unset($this->connectionLastActivity[$connId]);
        }
        
        // 创建新连接
        $conn = @\stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT
        );
        
        if ($conn) {
            \stream_set_blocking($conn, true);
            $connId = (int) $conn;
            $this->connectionLastActivity[$connId] = \time();
        }
        
        return $conn ?: null;
    }
    
    /**
     * 将连接归还到连接池
     */
    private function returnToPool(int $port, $conn): void
    {
        if (!\is_resource($conn)) {
            return;
        }
        
        $connId = (int) $conn;
        
        // 检查连接是否仍然有效
        if (\feof($conn)) {
            @\fclose($conn);
            unset($this->connectionLastActivity[$connId]);
            return;
        }
        
        // 检查连接池是否已满
        if (\count($this->connectionPool[$port] ?? []) >= $this->maxConnectionsPerWorker) {
            @\fclose($conn);
            unset($this->connectionLastActivity[$connId]);
            return;
        }
        
        // 归还到连接池
        $this->connectionLastActivity[$connId] = \time();
        $this->connectionPool[$port][] = $conn;
    }
    
    /**
     * 清理空闲超时的连接
     */
    private function cleanupIdleConnections(int $port): void
    {
        if (empty($this->connectionPool[$port])) {
            return;
        }
        
        $now = \time();
        $activeConnections = [];
        
        foreach ($this->connectionPool[$port] as $conn) {
            $connId = (int) $conn;
            $lastActivity = $this->connectionLastActivity[$connId] ?? 0;
            
            if (($now - $lastActivity) > $this->connectionIdleTimeout || !\is_resource($conn) || \feof($conn)) {
                // 连接超时或无效，关闭
                @\fclose($conn);
                unset($this->connectionLastActivity[$connId]);
            } else {
                $activeConnections[] = $conn;
            }
        }
        
        $this->connectionPool[$port] = $activeConnections;
    }
    
    /**
     * 转发请求到指定 Worker（使用连接池复用连接）
     *
     * @param string $request 完整的 HTTP 请求
     * @param string $host Worker 主机
     * @param int $port Worker 端口
     * @return string|null 响应数据，失败返回 null
     */
    public function forwardToWorker(string $request, string $host, int $port): ?string
    {
        // 从连接池获取连接
        $workerConn = $this->getPooledConnection($host, $port);
        $isNewConnection = false;
        
        if (!$workerConn) {
            $this->log("Worker 连接失败: tcp://{$host}:{$port}", 'WARN');
            return null;
        }
        
        $poolSize = \count($this->connectionPool[$port] ?? []);
        // 只在新建连接时输出日志（减少日志量）
        // $this->log("使用连接 (池中剩余: {$poolSize}) -> Worker:{$port}", 'INFO');

        // 设置写入超时
        \stream_set_timeout($workerConn, $this->writeTimeout);

        // 发送请求（带超时保护的分块写入）
        $requestLen = \strlen($request);
        $totalWritten = 0;
        $writeStartTime = \microtime(true);
        $remaining = $request;
        $zeroWriteCount = 0;
        
        while ($totalWritten < $requestLen) {
            // 检查写入是否超时
            $elapsed = \microtime(true) - $writeStartTime;
            if ($elapsed > $this->writeTimeout) {
                $this->log("写入 Worker 超时: tcp://{$host}:{$port}，已写入 {$totalWritten}/{$requestLen} 字节", 'WARN');
                @\fclose($workerConn);
                return null;
            }
            
            $written = @\fwrite($workerConn, $remaining);
            if ($written === false) {
                $this->log("写入 Worker 失败: tcp://{$host}:{$port}", 'WARN');
                @\fclose($workerConn);
                return null;
            }
            
            if ($written === 0) {
                $zeroWriteCount++;
                if ($zeroWriteCount >= 50) {
                    $this->log("写入 Worker 连续零写入过多: tcp://{$host}:{$port}", 'WARN');
                    @\fclose($workerConn);
                    return null;
                }
                SchedulerSystem::usleep(1000); // 1ms
                continue;
            }
            
            $zeroWriteCount = 0;
            $totalWritten += $written;
            $remaining = \substr($remaining, $written);
        }

        // 解析请求方法（HEAD 响应无 body，需在收到头后即视为完整）
        $isHeadRequest = \preg_match('/^(\w+)\s+/', $request, $reqM) && \strtoupper($reqM[1]) === 'HEAD';

        // 读取响应
        $response = '';
        \stream_set_timeout($workerConn, $this->readTimeout);
        $readStartTime = \microtime(true);
        $connectionBroken = false;

        while (!\feof($workerConn)) {
            $chunk = @\fread($workerConn, 65535);
            if ($chunk === false) {
                $connectionBroken = true;
                break;
            }
            if ($chunk === '') {
                // 检查超时
                $info = \stream_get_meta_data($workerConn);
                if ($info['timed_out']) {
                    $this->log("读取 Worker 响应超时: tcp://{$host}:{$port}", 'WARN');
                    $connectionBroken = true;
                    break;
                }
                SchedulerSystem::usleep(100); // 短暂等待
                continue;
            }
            $response .= $chunk;

            // 检查响应是否完整（HEAD 请求的响应只有头无 body）
            if ($this->isResponseComplete($response, $isHeadRequest)) {
                break;
            }
        }

        // 如果连接断开或出错，关闭连接；否则归还到连接池
        if ($connectionBroken || \feof($workerConn)) {
            @\fclose($workerConn);
        } else {
            // 归还到连接池，下次请求可以复用
            $this->returnToPool($port, $workerConn);
        }

        return $response ?: null;
    }

    /**
     * 检查响应是否完整
     *
     * @param string $response 响应数据
     * @param bool $isHeadRequest 是否为 HEAD 请求（HEAD 响应只有头无 body，收到头即完整）
     * @return bool
     */
    private function isResponseComplete(string $response, bool $isHeadRequest = false): bool
    {
        if (\strpos($response, "\r\n\r\n") === false) {
            return false;
        }

        $headerEnd = \strpos($response, "\r\n\r\n");
        $headers = \substr($response, 0, $headerEnd);
        $body = \substr($response, $headerEnd + 4);

        // HEAD 请求的响应：只有响应头、无 body，收到 \r\n\r\n 即完整
        if ($isHeadRequest) {
            return true;
        }

        // 检查 Content-Length
        if (\preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
            $contentLength = (int) $m[1];
            return \strlen($body) >= $contentLength;
        }

        // 检查 Transfer-Encoding: chunked
        if (\stripos($headers, 'Transfer-Encoding: chunked') !== false) {
            return \str_ends_with($body, "0\r\n\r\n");
        }

        // 没有 Content-Length 且非 chunked，等待更多数据
        return false;
    }

    /**
     * 重置轮询索引
     */
    public function reset(): void
    {
        $this->currentIndex = 0;
    }
}
