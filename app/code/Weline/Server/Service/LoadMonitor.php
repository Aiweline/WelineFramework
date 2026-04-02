<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Worker 负载监控器
 *
 * 收集和聚合 Worker 负载指标，用于扩缩容决策
 */
class LoadMonitor
{
    /**
     * @var array<int, array<string, mixed>> Worker 负载指标 [worker_id => metrics]
     */
    private array $workerMetrics = [];

    /**
     * @var array<string, mixed> 聚合指标
     */
    private array $aggregatedMetrics = [];

    private float $lastUpdateTime = 0.0;

    /**
     * 更新单个 Worker 的负载指标
     *
     * @param int $workerId Worker ID
     * @param array<string, mixed> $metrics 指标数据
     */
    public function updateWorkerMetrics(int $workerId, array $metrics): void
    {
        $this->workerMetrics[$workerId] = \array_merge($metrics, [
            'updated_at' => \microtime(true),
        ]);

        $this->lastUpdateTime = \microtime(true);
        $this->aggregateMetrics();
    }

    /**
     * 移除 Worker 指标（Worker 停止时）
     */
    public function removeWorkerMetrics(int $workerId): void
    {
        unset($this->workerMetrics[$workerId]);
        $this->aggregateMetrics();
    }

    /**
     * 获取聚合指标
     *
     * @return array<string, mixed>
     */
    public function getAggregatedMetrics(): array
    {
        return $this->aggregatedMetrics;
    }

    /**
     * 获取所有 Worker 指标
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllWorkerMetrics(): array
    {
        return $this->workerMetrics;
    }

    /**
     * 聚合所有 Worker 的指标
     */
    private function aggregateMetrics(): void
    {
        if (empty($this->workerMetrics)) {
            $this->aggregatedMetrics = [
                'worker_count' => 0,
                'avg_cpu' => 0.0,
                'avg_memory' => 0.0,
                'total_active_requests' => 0,
                'total_queue_length' => 0,
                'avg_response_time' => 0.0,
                'total_connections' => 0,
                'max_cpu' => 0.0,
                'max_memory' => 0.0,
                'updated_at' => \microtime(true),
            ];
            return;
        }

        $count = \count($this->workerMetrics);
        $totalCpu = 0.0;
        $totalMemory = 0.0;
        $totalActiveRequests = 0;
        $totalQueueLength = 0;
        $totalResponseTime = 0.0;
        $totalConnections = 0;
        $maxCpu = 0.0;
        $maxMemory = 0.0;

        foreach ($this->workerMetrics as $metrics) {
            $cpu = (float) ($metrics['cpu'] ?? 0.0);
            $memory = (float) ($metrics['memory'] ?? 0.0);
            $activeRequests = (int) ($metrics['active_requests'] ?? 0);
            $queueLength = (int) ($metrics['queue_length'] ?? 0);
            $responseTime = (float) ($metrics['avg_response_time'] ?? 0.0);
            $connections = (int) ($metrics['connections'] ?? 0);

            $totalCpu += $cpu;
            $totalMemory += $memory;
            $totalActiveRequests += $activeRequests;
            $totalQueueLength += $queueLength;
            $totalResponseTime += $responseTime;
            $totalConnections += $connections;

            $maxCpu = \max($maxCpu, $cpu);
            $maxMemory = \max($maxMemory, $memory);
        }

        $this->aggregatedMetrics = [
            'worker_count' => $count,
            'avg_cpu' => $totalCpu / $count,
            'avg_memory' => $totalMemory / $count,
            'total_active_requests' => $totalActiveRequests,
            'total_queue_length' => $totalQueueLength,
            'avg_response_time' => $totalResponseTime / $count,
            'total_connections' => $totalConnections,
            'max_cpu' => $maxCpu,
            'max_memory' => $maxMemory,
            'avg_requests_per_worker' => $totalActiveRequests / $count,
            'updated_at' => \microtime(true),
        ];
    }

    /**
     * 判断是否应该扩容
     *
     * @param array<string, mixed> $config 扩容配置
     */
    public function shouldScaleUp(array $config): bool
    {
        $metrics = $this->aggregatedMetrics;
        if (empty($metrics) || $metrics['worker_count'] === 0) {
            return false;
        }

        // CPU 阈值检查
        $cpuThreshold = (float) ($config['scale_up_cpu_threshold'] ?? 80.0);
        if ($metrics['avg_cpu'] > $cpuThreshold) {
            return true;
        }

        // 每 Worker 请求数阈值检查
        $requestsPerWorkerThreshold = (int) ($config['scale_up_requests_per_worker'] ?? 10);
        if ($metrics['avg_requests_per_worker'] > $requestsPerWorkerThreshold) {
            return true;
        }

        // 响应时间阈值检查
        $responseTimeThreshold = (float) ($config['scale_up_response_time_ms'] ?? 1000.0);
        if ($metrics['avg_response_time'] > $responseTimeThreshold) {
            return true;
        }

        // 队列长度阈值检查
        $queueThreshold = (int) ($config['scale_up_queue_threshold'] ?? 50);
        if ($metrics['total_queue_length'] > $queueThreshold) {
            return true;
        }

        return false;
    }

    /**
     * 判断是否应该缩容
     *
     * @param array<string, mixed> $config 缩容配置
     */
    public function shouldScaleDown(array $config): bool
    {
        $metrics = $this->aggregatedMetrics;
        if (empty($metrics) || $metrics['worker_count'] === 0) {
            return false;
        }

        // 所有条件必须同时满足才缩容

        // CPU 阈值检查
        $cpuThreshold = (float) ($config['scale_down_cpu_threshold'] ?? 30.0);
        if ($metrics['avg_cpu'] > $cpuThreshold) {
            return false;
        }

        // 每 Worker 请求数阈值检查
        $requestsPerWorkerThreshold = (int) ($config['scale_down_requests_per_worker'] ?? 2);
        if ($metrics['avg_requests_per_worker'] > $requestsPerWorkerThreshold) {
            return false;
        }

        // 响应时间阈值检查
        $responseTimeThreshold = (float) ($config['scale_down_response_time_ms'] ?? 200.0);
        if ($metrics['avg_response_time'] > $responseTimeThreshold) {
            return false;
        }

        return true;
    }

    /**
     * 清理过期的 Worker 指标（超过 60 秒未更新）
     */
    public function cleanupStaleMetrics(): void
    {
        $now = \microtime(true);
        $timeout = 60.0;

        foreach ($this->workerMetrics as $workerId => $metrics) {
            $updatedAt = (float) ($metrics['updated_at'] ?? 0.0);
            if ($now - $updatedAt > $timeout) {
                unset($this->workerMetrics[$workerId]);
            }
        }

        if (!empty($this->workerMetrics)) {
            $this->aggregateMetrics();
        }
    }
}
