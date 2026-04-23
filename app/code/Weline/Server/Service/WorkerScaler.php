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

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
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
     * 扩容：启动 N 个新 Worker（P0-4：并发启动 + 并发等待注册）
     *
     * 旧行为：逐个启动 → 逐个等待 READY，最坏 N × START_TIMEOUT。
     * 新行为：
     *   1) 先批量派发 startInstance（无阻塞），得到候选集合；
     *   2) 共享 START_TIMEOUT 窗口，轮询多实例 state 变化；
     *   3) 超时未 READY 的实例被清理，已 READY 的继续服役。
     *
     * 与旧版保持的语义：
     *   - 若 startInstance 返回 null（进程未起），立即返回，保留已起实例（由调用方负责后续治理）。
     *   - 返回 added_pids 反映最终成功注册的 Worker。
     *
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

        $currentWorkers = $this->orchestrator->getInstancesByRole('worker');
        $nextInstanceId = \count($currentWorkers) + 1;

        /** @var array<int, ServiceInstance> $startedInstances instanceId => instance */
        $startedInstances = [];

        // Phase 1：批量启动（不等待 READY）
        for ($i = 0; $i < $count; $i++) {
            $instanceId = $nextInstanceId + $i;
            try {
                $command = $this->workerProvider->buildCommand($instanceId, $context);
                $instance = $this->orchestrator->startInstance(
                    'worker',
                    $instanceId,
                    $command,
                    $context
                );

                if ($instance === null) {
                    $addedPids = \array_map(static fn(ServiceInstance $inst): int => $inst->pid, \array_values($startedInstances));
                    return [
                        'success' => false,
                        'added_pids' => $addedPids,
                        'message' => "Failed to start Worker #{$instanceId}",
                    ];
                }

                $startedInstances[$instanceId] = $instance;
            } catch (\Throwable $e) {
                $addedPids = \array_map(static fn(ServiceInstance $inst): int => $inst->pid, \array_values($startedInstances));
                return [
                    'success' => false,
                    'added_pids' => $addedPids,
                    'message' => "Failed to start Worker #{$instanceId}: " . $e->getMessage(),
                ];
            }
        }

        // Phase 2：共享 START_TIMEOUT 并发等待所有候选进入 READY
        $stillPending = $this->waitForWorkersReady($startedInstances, self::START_TIMEOUT);

        // Phase 3：超时未就绪的清理，已就绪的保留
        foreach ($stillPending as $instance) {
            try {
                $this->orchestrator->stopInstance($instance);
            } catch (\Throwable) {
                // best effort
            }
        }

        $addedPids = [];
        foreach ($startedInstances as $id => $instance) {
            if (!isset($stillPending[$id])) {
                $addedPids[] = $instance->pid;
            }
        }

        if ($stillPending !== []) {
            $readyCount = \count($startedInstances) - \count($stillPending);
            return [
                'success' => false,
                'added_pids' => $addedPids,
                'message' => \sprintf(
                    'Only %d/%d Worker(s) became ready within %ds',
                    $readyCount,
                    \count($startedInstances),
                    self::START_TIMEOUT
                ),
            ];
        }

        return [
            'success' => true,
            'added_pids' => $addedPids,
            'message' => "Successfully started {$count} Worker(s)",
        ];
    }

    /**
     * 缩容：停止 N 个 Worker（P0-4：并发发出优雅关闭 + 并发等待退出）
     *
     * 旧行为：逐个发送 graceful → 逐个等待退出，最坏 N × STOP_TIMEOUT。
     * 新行为：
     *   1) 先批量发送 gracefulShutdown；
     *   2) 共享 STOP_TIMEOUT 窗口，轮询多实例存活状态；
     *   3) 超时仍在运行的执行强制 stop（SIGKILL 等价）。
     *
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

        \usort($workers, fn($a, $b) => $b->instanceId <=> $a->instanceId);
        $toStop = \array_slice($workers, 0, \min($count, \count($workers)));

        /** @var array<int, ServiceInstance> $shutdownCandidates */
        $shutdownCandidates = [];

        // Phase 1：批量发送 gracefulShutdown
        foreach ($toStop as $worker) {
            try {
                $this->orchestrator->sendMessageToInstance(
                    $worker,
                    ControlMessage::gracefulShutdown(self::STOP_TIMEOUT)
                );
                $shutdownCandidates[$worker->instanceId] = $worker;
            } catch (\Throwable $e) {
                $removedPids = \array_map(static fn(ServiceInstance $w): int => $w->pid, \array_values($shutdownCandidates));
                return [
                    'success' => false,
                    'removed_pids' => $removedPids,
                    'message' => "Failed to stop Worker #{$worker->instanceId}: " . $e->getMessage(),
                ];
            }
        }

        // Phase 2：并发等待所有目标退出
        $stillAlive = $this->waitForWorkersExit($shutdownCandidates, self::STOP_TIMEOUT);

        // Phase 3：超时未退出的强制 kill
        foreach ($stillAlive as $worker) {
            try {
                $this->orchestrator->stopInstance($worker, true);
            } catch (\Throwable) {
                // best effort
            }
        }

        $removedPids = \array_map(static fn(ServiceInstance $w): int => $w->pid, \array_values($shutdownCandidates));
        return [
            'success' => true,
            'removed_pids' => $removedPids,
            'message' => "Successfully stopped " . \count($removedPids) . " Worker(s)",
        ];
    }

    /**
     * P0-4：并发等待多个 Worker 进入 READY，共享超时窗口。
     *
     * 返回超时仍未就绪的实例，调用方负责清理。
     * 进程在等待过程中死亡的实例会尽快退出重试循环（下一次 tick 检查到 pid 不存活即标记为失败）。
     *
     * @param array<int, ServiceInstance> $instances instanceId => instance
     * @return array<int, ServiceInstance>           仍未 READY 的实例（timeout 或 process dead）
     */
    private function waitForWorkersReady(array $instances, int $timeoutSec): array
    {
        $pending = $instances;
        $deadline = \microtime(true) + $timeoutSec;

        while ($pending !== [] && \microtime(true) < $deadline) {
            foreach ($pending as $id => $instance) {
                if ($instance->state === ServiceInstance::STATE_READY) {
                    unset($pending[$id]);
                    continue;
                }
                if (!$this->isProcessAlive($this->getTrackingPid($instance))) {
                    // 进程已死 → 视为失败，保留在 pending 中由调用方清理
                    // （无需在此 unset，外层会 stopInstance 做兜底）
                    continue;
                }
            }
            if ($pending === []) {
                break;
            }
            SchedulerSystem::usleep(100000); // 100ms
        }

        return $pending;
    }

    /**
     * P0-4：并发等待多个 Worker 退出，共享超时窗口。
     *
     * @param array<int, ServiceInstance> $instances instanceId => instance
     * @return array<int, ServiceInstance>           仍存活需要强制 kill 的实例
     */
    private function waitForWorkersExit(array $instances, int $timeoutSec): array
    {
        $pending = $instances;
        $deadline = \microtime(true) + $timeoutSec;

        while ($pending !== [] && \microtime(true) < $deadline) {
            foreach ($pending as $id => $instance) {
                if (!$this->isProcessAlive($this->getTrackingPid($instance))) {
                    unset($pending[$id]);
                }
            }
            if ($pending === []) {
                break;
            }
            SchedulerSystem::usleep(100000);
        }

        return $pending;
    }

    /**
     * 健康检查：检查 Worker 是否健康
     *
     * @param int $pid Worker PID
     * @param float $timeoutSec IPC ping 超时时间（秒）
     * @return bool 是否健康
     */
    public function checkHealth(int $pid, float $timeoutSec = 2.0): bool
    {
        $instance = $this->findInstanceByPid($pid);
        $pid = $instance?->getTrackingPid() ?? $pid;

        // 检查进程是否存在
        if (!$this->isProcessAlive($pid)) {
            return false;
        }

        // 通过 IPC ping/pong 机制检查进程响应性
        $instance = $this->findInstanceByPid($pid);
        if ($instance === null) {
            // 找不到实例信息，降级为进程存在性检查
            return true;
        }

        // 获取 Master 控制服务器实例
        $controlServer = $this->orchestrator->getControlServer();
        if ($controlServer === null) {
            // Master 控制服务器未启动，降级为进程存在性检查
            return true;
        }

        // 发送 ping 消息
        $pingTimestamp = \microtime(true);
        $pingMsg = \Weline\Server\IPC\ControlMessage::ping($pingTimestamp);

        try {
            $sent = $controlServer->sendToInstance($instance->launchId, $pingMsg);
            if (!$sent) {
                return false;
            }

            // 等待 pong 响应（简化实现：检查最近的 pong 时间戳）
            $deadline = \microtime(true) + $timeoutSec;
            while (\microtime(true) < $deadline) {
                $lastPong = $controlServer->getLastPongTime($instance->launchId);
                if ($lastPong !== null && $lastPong >= $pingTimestamp) {
                    // 收到有效的 pong 响应
                    return true;
                }
                SchedulerSystem::usleep(50000); // 50ms
            }

            // 超时未收到 pong
            return false;
        } catch (\Throwable $e) {
            // IPC 异常，降级为进程存在性检查
            return true;
        }
    }

    /**
     * 根据 PID 查找实例
     */
    private function findInstanceByPid(int $pid): ?ServiceInstance
    {
        foreach ($this->orchestrator->getInstancesByRole('worker') as $instance) {
            if ($instance->matchesManagedPid($pid)) {
                return $instance;
            }
        }
        return null;
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

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return Processer::isRunningByPid($pid);
    }

    private function getTrackingPid(ServiceInstance $instance): int
    {
        return $instance->getTrackingPid();
    }
}
