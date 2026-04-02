<?php
declare(strict_types=1);

/**
 * WLS Worker 扩缩容控制器
 *
 * 协调负载监控、扩容决策和 Worker 生命周期管理：
 * - 处理手动扩缩容命令（CLI → Master）
 * - 自动扩缩容（定期检查负载并决策）
 * - 并发安全（文件锁防止多个扩容命令同时执行）
 *
 * @author Aiweline
 */

namespace Weline\Server\Service;

use Weline\Server\Service\Contract\ServiceContext;

class ScalingController
{
    private LoadMonitor $loadMonitor;
    private ScalingDecider $decider;
    private WorkerScaler $scaler;
    private ServiceContext $context;

    /**
     * 扩缩容锁文件路径
     */
    private const LOCK_FILE = 'var/wls/scaling.lock';

    /**
     * 锁文件句柄
     * @var resource|null
     */
    private $lockHandle = null;

    public function __construct(
        LoadMonitor $loadMonitor,
        ScalingDecider $decider,
        WorkerScaler $scaler,
        ServiceContext $context
    ) {
        $this->loadMonitor = $loadMonitor;
        $this->decider = $decider;
        $this->scaler = $scaler;
        $this->context = $context;
    }

    /**
     * 处理手动扩缩容命令
     *
     * @param int $targetWorkers 目标 Worker 数量
     * @return array{success: bool, current_workers: int, target_workers: int, added_pids: int[], removed_pids: int[], message: string}
     */
    public function handleScaleCommand(int $targetWorkers): array
    {
        // 获取锁
        if (!$this->acquireLock()) {
            return [
                'success' => false,
                'current_workers' => $this->scaler->getCurrentWorkerCount(),
                'target_workers' => $targetWorkers,
                'added_pids' => [],
                'removed_pids' => [],
                'message' => 'Another scaling operation is in progress',
            ];
        }

        try {
            return $this->doScale($targetWorkers);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * 自动扩缩容（定期调用）
     *
     * @return array|null 扩缩容结果，null 表示不需要扩缩容
     */
    public function autoScale(): ?array
    {
        // 未启用自动扩缩容
        if (!$this->decider->isEnabled()) {
            return null;
        }

        // 获取负载指标
        $metrics = $this->loadMonitor->getAggregatedMetrics();
        $currentWorkers = $this->scaler->getCurrentWorkerCount();

        // 决策目标 Worker 数
        $targetWorkers = $this->decider->decide($metrics, $currentWorkers);
        if ($targetWorkers === null) {
            return null;
        }

        // 尝试获取锁（非阻塞）
        if (!$this->acquireLock(false)) {
            return null;
        }

        try {
            return $this->doScale($targetWorkers);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * 获取扩缩容状态
     *
     * @return array{
     *     enabled: bool,
     *     current_workers: int,
     *     min_workers: int,
     *     max_workers: int,
     *     metrics: array,
     *     locked: bool
     * }
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->decider->isEnabled(),
            'current_workers' => $this->scaler->getCurrentWorkerCount(),
            'min_workers' => $this->decider->getMinWorkers(),
            'max_workers' => $this->decider->getMaxWorkers(),
            'metrics' => $this->loadMonitor->getAggregatedMetrics(),
            'locked' => $this->isLocked(),
        ];
    }

    /**
     * 执行扩缩容
     *
     * @param int $targetWorkers 目标 Worker 数量
     * @return array{success: bool, current_workers: int, target_workers: int, added_pids: int[], removed_pids: int[], message: string}
     */
    private function doScale(int $targetWorkers): array
    {
        $currentWorkers = $this->scaler->getCurrentWorkerCount();

        // 检查边界
        $minWorkers = $this->decider->getMinWorkers();
        $maxWorkers = $this->decider->getMaxWorkers();

        if ($targetWorkers < $minWorkers) {
            return [
                'success' => false,
                'current_workers' => $currentWorkers,
                'target_workers' => $targetWorkers,
                'added_pids' => [],
                'removed_pids' => [],
                'message' => "Target workers ({$targetWorkers}) is less than min workers ({$minWorkers})",
            ];
        }

        if ($targetWorkers > $maxWorkers) {
            return [
                'success' => false,
                'current_workers' => $currentWorkers,
                'target_workers' => $targetWorkers,
                'added_pids' => [],
                'removed_pids' => [],
                'message' => "Target workers ({$targetWorkers}) exceeds max workers ({$maxWorkers})",
            ];
        }

        // 无需扩缩容
        if ($targetWorkers === $currentWorkers) {
            return [
                'success' => true,
                'current_workers' => $currentWorkers,
                'target_workers' => $targetWorkers,
                'added_pids' => [],
                'removed_pids' => [],
                'message' => 'Already at target worker count',
            ];
        }

        // 扩容
        if ($targetWorkers > $currentWorkers) {
            $count = $targetWorkers - $currentWorkers;
            $result = $this->scaler->scaleUp($count, $this->context);
            return [
                'success' => $result['success'],
                'current_workers' => $this->scaler->getCurrentWorkerCount(),
                'target_workers' => $targetWorkers,
                'added_pids' => $result['added_pids'],
                'removed_pids' => [],
                'message' => $result['message'],
            ];
        }

        // 缩容
        $count = $currentWorkers - $targetWorkers;
        $result = $this->scaler->scaleDown($count);
        return [
            'success' => $result['success'],
            'current_workers' => $this->scaler->getCurrentWorkerCount(),
            'target_workers' => $targetWorkers,
            'added_pids' => [],
            'removed_pids' => $result['removed_pids'],
            'message' => $result['message'],
        ];
    }

    /**
     * 获取锁
     *
     * @param bool $blocking 是否阻塞等待
     * @return bool 是否成功获取锁
     */
    private function acquireLock(bool $blocking = true): bool
    {
        $lockFile = BP . self::LOCK_FILE;
        $lockDir = \dirname($lockFile);

        // 确保目录存在
        if (!\is_dir($lockDir)) {
            \mkdir($lockDir, 0755, true);
        }

        $this->lockHandle = \fopen($lockFile, 'c');
        if ($this->lockHandle === false) {
            return false;
        }

        $operation = $blocking ? \LOCK_EX : (\LOCK_EX | \LOCK_NB);
        return \flock($this->lockHandle, $operation);
    }

    /**
     * 释放锁
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            \flock($this->lockHandle, \LOCK_UN);
            \fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * 检查是否有其他进程持有锁
     *
     * @return bool
     */
    private function isLocked(): bool
    {
        $lockFile = BP . self::LOCK_FILE;
        if (!\file_exists($lockFile)) {
            return false;
        }

        $handle = \fopen($lockFile, 'r');
        if ($handle === false) {
            return false;
        }

        $locked = !\flock($handle, \LOCK_EX | \LOCK_NB);
        \flock($handle, \LOCK_UN);
        \fclose($handle);

        return $locked;
    }

    /**
     * 析构函数：确保释放锁
     */
    public function __destruct()
    {
        $this->releaseLock();
    }
}
