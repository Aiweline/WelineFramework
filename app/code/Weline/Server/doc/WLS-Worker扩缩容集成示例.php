<?php
/**
 * WLS Worker 扩缩容集成示例代码
 *
 * 本文件展示如何将扩缩容功能集成到现有的 WLS 架构中。
 * 这些代码片段需要添加到对应的文件中。
 */

// ============================================================
// 1. MasterControlServer 集成 (IPC 消息处理)
// 文件：app/code/Weline/Server/IPC/MasterControlServer.php
// ============================================================

/**
 * 在 MasterControlServer 构造函数中初始化扩缩容组件
 */
class MasterControlServer
{
    private ?\Weline\Server\Service\LoadMonitor $loadMonitor = null;
    private ?\Weline\Server\Service\ScalingController $scalingController = null;

    public function __construct()
    {
        // ... 现有代码 ...

        // 初始化负载监控器
        $this->loadMonitor = new \Weline\Server\Service\LoadMonitor();
    }

    /**
     * 设置扩缩容控制器（由 ServiceOrchestrator 注入）
     */
    public function setScalingController(\Weline\Server\Service\ScalingController $controller): void
    {
        $this->scalingController = $controller;
    }

    /**
     * 在消息处理回调中添加扩缩容消息处理
     */
    private function handleMessage(array $msg, int $clientId): void
    {
        $type = $msg['type'] ?? '';

        // 处理负载上报消息
        if ($type === ControlMessage::TYPE_LOAD_REPORT && $this->loadMonitor !== null) {
            $this->loadMonitor->updateMetrics(
                $msg['worker_id'] ?? 0,
                $msg['cpu_usage'] ?? 0.0,
                $msg['memory_usage'] ?? 0,
                $msg['queue_length'] ?? 0,
                $msg['avg_response_time'] ?? 0.0,
                $msg['active_connections'] ?? 0
            );
            return;
        }

        // 处理 CLI 命令
        if ($type === ControlMessage::TYPE_COMMAND) {
            $action = $msg['action'] ?? '';

            // 手动扩缩容命令
            if ($action === ControlMessage::ACTION_SCALE_WORKERS && $this->scalingController !== null) {
                $targetWorkers = (int)($msg['target_workers'] ?? 0);
                $result = $this->scalingController->handleScaleCommand($targetWorkers);

                $response = ControlMessage::workerScaled(
                    $result['success'],
                    $result['current_workers'],
                    $result['target_workers'],
                    $result['added_pids'],
                    $result['removed_pids'],
                    $result['message']
                );

                $this->sendToClient($clientId, $response);
                return;
            }

            // 查询扩缩容状态
            if ($action === ControlMessage::ACTION_SCALING_STATUS && $this->scalingController !== null) {
                $status = $this->scalingController->getStatus();
                $response = ControlMessage::commandResult(true, $status);
                $this->sendToClient($clientId, $response);
                return;
            }
        }

        // ... 现有消息处理逻辑 ...
    }

    /**
     * 获取负载监控器（供 ServiceOrchestrator 使用）
     */
    public function getLoadMonitor(): ?\Weline\Server\Service\LoadMonitor
    {
        return $this->loadMonitor;
    }
}

// ============================================================
// 2. ServiceOrchestrator 集成 (定期自动扩缩容)
// 文件：app/code/Weline/Server/Service/ServiceOrchestrator.php
// ============================================================

class ServiceOrchestrator
{
    private ?\Weline\Server\Service\ScalingController $scalingController = null;
    private float $lastAutoScaleTime = 0.0;

    /**
     * 在 ServiceOrchestrator 构造函数中初始化扩缩容控制器
     */
    public function __construct(ServiceContext $context, MasterControlServer $controlServer)
    {
        // ... 现有代码 ...

        // 初始化扩缩容组件
        $loadMonitor = $controlServer->getLoadMonitor();
        if ($loadMonitor !== null) {
            $decider = new \Weline\Server\Service\ScalingDecider();
            $workerProvider = $this->getProviderByRole('worker');
            $scaler = new \Weline\Server\Service\WorkerScaler($this, $workerProvider);

            $this->scalingController = new \Weline\Server\Service\ScalingController(
                $loadMonitor,
                $decider,
                $scaler,
                $context
            );

            // 注入到 MasterControlServer
            $controlServer->setScalingController($this->scalingController);
        }
    }

    /**
     * 在主循环中添加自动扩缩容检查
     */
    public function run(): void
    {
        while ($this->running) {
            // ... 现有逻辑 ...

            // 每 30 秒检查一次自动扩缩容
            $now = \microtime(true);
            if ($this->scalingController !== null && ($now - $this->lastAutoScaleTime) >= 30.0) {
                $result = $this->scalingController->autoScale();
                if ($result !== null) {
                    $this->logger->info(
                        "Auto-scaling: {$result['message']} " .
                        "(current: {$result['current_workers']}, target: {$result['target_workers']})"
                    );
                }
                $this->lastAutoScaleTime = $now;
            }

            // ... 现有逻辑 ...
        }
    }

    /**
     * 启动单个实例（供 WorkerScaler 调用）
     */
    public function startInstance(
        string $role,
        int $instanceId,
        ServiceCommand $command,
        ServiceContext $context
    ): ?ServiceInstance {
        // ... 现有启动逻辑 ...
        // 返回 ServiceInstance 对象
    }

    /**
     * 停止单个实例（供 WorkerScaler 调用）
     */
    public function stopInstance(ServiceInstance $instance, bool $force = false): void
    {
        // ... 现有停止逻辑 ...
    }

    /**
     * 发送消息到实例（供 WorkerScaler 调用）
     */
    public function sendMessageToInstance(ServiceInstance $instance, string $message): void
    {
        // ... 现有发送逻辑 ...
    }

    /**
     * 获取指定角色的所有实例（供 WorkerScaler 调用）
     */
    public function getInstancesByRole(string $role): array
    {
        // ... 现有逻辑 ...
    }
}

// ============================================================
// 3. WorkerProvider 集成 (负载指标上报)
// 文件：app/code/Weline/Server/bin/worker.php 或 worker_ssl.php
// ============================================================

/**
 * Worker 主循环中添加负载指标上报
 */
class WorkerProcess
{
    private float $lastReportTime = 0.0;
    private array $responseTimes = [];
    private int $requestCount = 0;

    /**
     * 主循环
     */
    public function run(): void
    {
        while ($this->running) {
            // ... 处理请求 ...

            // 每 10 秒上报一次负载指标
            $now = \microtime(true);
            if (($now - $this->lastReportTime) >= 10.0) {
                $this->reportLoadMetrics();
                $this->lastReportTime = $now;
            }

            // ... 现有逻辑 ...
        }
    }

    /**
     * 上报负载指标
     */
    private function reportLoadMetrics(): void
    {
        // 获取 CPU 使用率（简化实现：使用系统负载）
        $loadAvg = \sys_getloadavg();
        $cpuUsage = ($loadAvg[0] ?? 0.0) * 100.0 / $this->getCpuCores();

        // 获取内存使用量
        $memoryUsage = \memory_get_usage(true);

        // 获取请求队列长度（简化实现：当前连接数）
        $queueLength = $this->getActiveConnectionCount();

        // 计算平均响应时间
        $avgResponseTime = 0.0;
        if (!empty($this->responseTimes)) {
            $avgResponseTime = \array_sum($this->responseTimes) / \count($this->responseTimes);
            // 清空历史数据，避免内存泄漏
            $this->responseTimes = [];
        }

        // 获取活跃连接数
        $activeConnections = $this->getActiveConnectionCount();

        // 构建并发送消息
        $message = ControlMessage::loadReport(
            $this->workerId,
            $cpuUsage,
            $memoryUsage,
            $queueLength,
            $avgResponseTime,
            $activeConnections
        );

        $this->sendToMaster($message);
    }

    /**
     * 记录请求响应时间
     */
    private function recordResponseTime(float $responseTime): void
    {
        $this->responseTimes[] = $responseTime;
        // 限制数组大小，避免内存泄漏
        if (\count($this->responseTimes) > 1000) {
            $this->responseTimes = \array_slice($this->responseTimes, -1000);
        }
    }

    /**
     * 获取 CPU 核心数
     */
    private function getCpuCores(): int
    {
        static $cores = null;
        if ($cores === null) {
            if (\function_exists('shell_exec')) {
                // Linux
                $cores = (int)\shell_exec('nproc 2>/dev/null');
                if ($cores > 0) {
                    return $cores;
                }
                // macOS
                $cores = (int)\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
                if ($cores > 0) {
                    return $cores;
                }
            }
            $cores = 1; // 默认
        }
        return $cores;
    }

    /**
     * 获取活跃连接数（需要根据实际实现调整）
     */
    private function getActiveConnectionCount(): int
    {
        // 示例：返回当前处理中的请求数
        return $this->activeRequestCount ?? 0;
    }

    /**
     * 处理请求（添加响应时间记录）
     */
    private function handleRequest($connection): void
    {
        $startTime = \microtime(true);

        // ... 现有请求处理逻辑 ...

        $endTime = \microtime(true);
        $responseTime = ($endTime - $startTime) * 1000.0; // 转换为毫秒
        $this->recordResponseTime($responseTime);
    }

    /**
     * 处理优雅关闭消息
     */
    private function handleGracefulShutdown(array $msg): void
    {
        $timeoutSec = $msg['timeout_sec'] ?? 30;
        $this->logger->info("Received graceful shutdown, timeout: {$timeoutSec}s");

        // 停止接受新连接
        $this->acceptingConnections = false;

        // 等待现有请求处理完成
        $deadline = \microtime(true) + $timeoutSec;
        while ($this->getActiveConnectionCount() > 0 && \microtime(true) < $deadline) {
            \usleep(100000); // 100ms
        }

        // 退出
        $this->running = false;
    }
}

// ============================================================
// 4. DispatcherProvider 集成 (动态 Worker 池更新)
// 文件：app/code/Weline/Server/Service/Provider/DispatcherProvider.php
// ============================================================

class DispatcherProvider
{
    /**
     * 处理 Worker 注册消息
     */
    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool
    {
        $type = $message['type'] ?? '';

        // Worker 注册时添加到路由池
        if ($type === ControlMessage::TYPE_REGISTER && $message['role'] === 'worker') {
            $port = $message['port'] ?? 0;
            if ($port > 0) {
                $this->addWorkerToPool($port);
                $this->logger->info("Added Worker port {$port} to routing pool");
            }
            return true;
        }

        // Worker 就绪时确认在路由池中
        if ($type === ControlMessage::TYPE_READY && $message['role'] === 'worker') {
            $port = $message['port'] ?? 0;
            if ($port > 0 && !$this->isWorkerInPool($port)) {
                $this->addWorkerToPool($port);
            }
            return true;
        }

        // Worker 退出时从路由池移除
        if ($type === ControlMessage::TYPE_EXITED && $message['role'] === 'worker') {
            $port = $message['port'] ?? 0;
            if ($port > 0) {
                $this->removeWorkerFromPool($port);
                $this->logger->info("Removed Worker port {$port} from routing pool");
            }
            return true;
        }

        // 集成到真实 Dispatcher 子类时：return parent::handleMessage($message, $instance, $orchestrator);
        return false;
    }

    /**
     * 添加 Worker 到路由池
     */
    private function addWorkerToPool(int $port): void
    {
        // 线程安全：使用原子操作
        if (!\in_array($port, $this->workerPorts, true)) {
            $this->workerPorts[] = $port;
        }
    }

    /**
     * 从路由池移除 Worker
     */
    private function removeWorkerFromPool(int $port): void
    {
        $key = \array_search($port, $this->workerPorts, true);
        if ($key !== false) {
            unset($this->workerPorts[$key]);
            $this->workerPorts = \array_values($this->workerPorts); // 重新索引
        }
    }

    /**
     * 检查 Worker 是否在路由池中
     */
    private function isWorkerInPool(int $port): bool
    {
        return \in_array($port, $this->workerPorts, true);
    }
}
