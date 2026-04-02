<?php
declare(strict_types=1);

/**
 * WLS Worker 生命周期管理器
 *
 * 负责 Worker 的平滑启动和优雅关闭：
 * - 扩容：启动新 Worker 并等待注册
 * - 缩容：选择空闲 Worker 优雅关闭
 * - 健康检查：通过 IPC ping/pong 机制
 *
 * @author Aiweline
 */

namespace Weline\Server\Service;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\WorkerProvider;

class WorkerScaler
{
    private ServiceOrchestrator $orchestrator;
    private WorkerProvider $workerProvider;

    /**
     * Worker 启动超时（秒）
     */
    private const START_TIMEOUT = 10;

    /**
     * Worker 停止超时（秒）
     */
    private const STOP_TIMEOUT = 30;

    public function __construct(
        ServiceOrchestrator $orchestrator,
        WorkerProvider $workerProvider
    ) {
        $this->orchestrator = $orchestrator;
        $this->workerProvider = $workerProvider;
    }

    /**
     * 扩容：启动 N 个新 Worker
     *
     * @param int $count 要启动的 Worker 数量
     * @param ServiceContext $context 服务上下文
     * @return array{success: bool, added_pids: int[], message: string}
     */
    public function scaleUp(int $count, ServiceContext $context): array
    {
        if ($count <= 0) {
            return [
                'success' => false,
                'added_pids' => [],
                'message' => 'Invalid count: ' . $count,
            ];
        }

        $addedPids = [];
        $currentWorkers = $this->orchestrator->getInstancesByRole('worker');
        $nextInstanceId = \count($currentWorkers) + 1;

        for ($i = 0; $i < $count; $i++) {
            $instanceId = $nextInstanceId + $i;

            try {
                // 构建启动命令
                $command = $this->workerProvider->buildCommand($instanceId, $context);

                // 启动 Worker 进程
                $instance = $this->orchestrator->startInstance(
                    'worker',
                    $instanceId,
                    $command,
                    $context
                );

                if ($instance === null) {
                    return [
                        'success' => false,
                        'added_pids' => $addedPids,
                        'message' => "Failed to start Worker #{$instanceId}",
                    ];
                }

                // 等待 Worker 注册（超时 10 秒）
                $registered = $this->waitForWorkerReady($instance, self::START_TIMEOUT);
                if (!$registered) {
                    // 启动失败，清理进程
                    $this->orchestrator->stopInstance($instance);
                    return [
                        'success' => false,
                        'added_pids' => $addedPids,
                        'message' => "Worker #{$instanceId} failed to register within " . self::START_TIMEOUT . " seconds",
                    ];
                }

                $addedPids[] = $instance->pid;
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'added_pids' => $addedPids,
                    'message' => "Failed to start Worker #{$instanceId}: " . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'added_pids' => $addedPids,
            'message' => "Successfully started {$count} Worker(s)",
        ];
    }

    /**
     * 缩容：停止 N 个 Worker
     *
     * @param int $count 要停止的 Worker 数量
     * @return array{success: bool, removed_pids: int[], message: string}
     */
    public function scaleDown(int $count): array
    {
        if ($count <= 0) {
            return [
                'success' => false,
                'removed_pids' => [],
                'message' => 'Invalid count: ' . $count,
            ];
        }

        $workers = $this->orchestrator->getInstancesByRole('worker');
        if (empty($workers)) {
            return [
                'success' => false,
                'removed_pids' => [],
                'message' => 'No workers to scale down',
            ];
        }

        // 选择要停止的 Worker（优先选择 ID 最大的）
        \usort($workers, fn($a, $b) => $b->instanceId <=> $a->instanceId);
        $toStop = \array_slice($workers, 0, \min($count, \count($workers)));

        $removedPids = [];
        foreach ($toStop as $worker) {
            try {
                // 发送优雅关闭消息
                $this->orchestrator->sendMessageToInstance(
                    $worker,
                    ControlMessage::gracefulShutdown(self::STOP_TIMEOUT)
                );

                // 等待 Worker 退出（超时 30 秒）
                $exited = $this->waitForWorkerExit($worker, self::STOP_TIMEOUT);
                if (!$exited) {
                    // 超时，强制 kill
                    $this->orchestrator->stopInstance($worker, true);
                }

                $removedPids[] = $worker->pid;
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'removed_pids' => $removedPids,
                    'message' => "Failed to stop Worker #{$worker->instanceId}: " . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'removed_pids' => $removedPids,
            'message' => "Successfully stopped " . \count($removedPids) . " Worker(s)",
        ];
    }

    /**
     * 等待 Worker 就绪（注册到 Master）
     *
     * @param ServiceInstance $instance Worker 实例
     * @param int $timeoutSec 超时时间（秒）
     * @return bool 是否就绪
     */
    private function waitForWorkerReady(ServiceInstance $instance, int $timeoutSec): bool
    {
        $deadline = \microtime(true) + $timeoutSec;

        while (\microtime(true) < $deadline) {
            // 检查 Worker 状态
            if ($instance->state === ServiceInstance::STATE_READY) {
                return true;
            }

            // 检查进程是否还活着
            if (!\posix_kill($instance->pid, 0)) {
                return false;
            }

            \usleep(100000); // 100ms
        }

        return false;
    }

    /**
     * 等待 Worker 退出
     *
     * @param ServiceInstance $instance Worker 实例
     * @param int $timeoutSec 超时时间（秒）
     * @return bool 是否已退出
     */
    private function waitForWorkerExit(ServiceInstance $instance, int $timeoutSec): bool
    {
        $deadline = \microtime(true) + $timeoutSec;

        while (\microtime(true) < $deadline) {
            // 检查进程是否还活着
            if (!\posix_kill($instance->pid, 0)) {
                return true;
            }

            \usleep(100000); // 100ms
        }

        return false;
    }

    /**
     * 健康检查：检查 Worker 是否健康
     *
     * @param int $pid Worker PID
     * @return bool 是否健康
     */
    public function checkHealth(int $pid): bool
    {
        // 检查进程是否存在
        if (!\posix_kill($pid, 0)) {
            return false;
        }

        // TODO: 通过 IPC ping/pong 机制检查
        // 当前简化实现：只检查进程是否存在

        return true;
    }

    /**
     * 获取当前 Worker 数量
     *
     * @return int
     */
    public function getCurrentWorkerCount(): int
    {
        return \count($this->orchestrator->getInstancesByRole('worker'));
    }

    /**
     * 获取所有 Worker 实例
     *
     * @return ServiceInstance[]
     */
    public function getAllWorkers(): array
    {
        return $this->orchestrator->getInstancesByRole('worker');
    }
}
