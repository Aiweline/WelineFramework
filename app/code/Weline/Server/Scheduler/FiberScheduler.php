<?php
declare(strict_types=1);

namespace Weline\Server\Scheduler;

use Weline\Framework\Runtime\RequestExitException;

/**
 * Fiber 协作式调度器
 *
 * 由 Worker 在每轮事件循环中调用 tick()，处理到期定时器并 resume 对应 Fiber。
 * Worker 通过 getNextTimerDelay() 得到 stream_select 的 timeout。
 */
class FiberScheduler
{
    /**
     * 定时器队列
     * @var array<int, array{deadline: float, fiber: \Fiber}>
     */
    private array $timers = [];

    private int $nextTimerId = 0;

    /**
     * 活跃 Fiber 计数
     */
    private int $activeFiberCount = 0;

    /**
     * 注册 sleep 定时器
     */
    public function addSleepTimer(\Fiber $fiber, int $seconds): void
    {
        $this->addTimer($fiber, (float) $seconds);
    }

    /**
     * 注册 usleep 定时器
     */
    public function addUsleepTimer(\Fiber $fiber, int $microseconds): void
    {
        $this->addTimer($fiber, $microseconds / 1_000_000.0);
    }

    /**
     * 注册通用定时器
     */
    private function addTimer(\Fiber $fiber, float $delaySeconds): void
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            'deadline' => \microtime(true) + $delaySeconds,
            'fiber' => $fiber,
        ];
    }

    /**
     * 注册 yield 定时器（让出控制权，下一轮事件循环立即 resume）
     *
     * 通过设置极小的延迟（约 0.000001 秒 = 1 微秒），确保在下一轮事件循环中
     * getNextTimerDelay() 返回接近 0 的值，使 stream_select 几乎立即返回，
     * 从而让 Worker 可以处理其他请求后再 resume 该 Fiber。
     */
    public function addYieldTimer(\Fiber $fiber): void
    {
        $this->addTimer($fiber, 0.000001);  // 1 微秒延迟，确保下一轮 tick
    }

    /**
     * 注册 yield_delay 定时器（让出控制权并注册延迟）
     *
     * @param \Fiber $fiber 要恢复的 Fiber
     * @param int $milliseconds 延迟毫秒数
     */
    public function addYieldDelayTimer(\Fiber $fiber, int $milliseconds): void
    {
        $this->addTimer($fiber, $milliseconds / 1000.0);
    }

    /**
     * 注册一个新的请求 Fiber
     */
    public function registerFiber(): void
    {
        $this->activeFiberCount++;
    }

    /**
     * 取消注册一个请求 Fiber
     */
    public function unregisterFiber(): void
    {
        $this->activeFiberCount = \max(0, $this->activeFiberCount - 1);
    }

    /**
     * 获取活跃 Fiber 数
     */
    public function getActiveFiberCount(): int
    {
        return $this->activeFiberCount;
    }

    /**
     * 获取下一个到期定时器的剩余秒数
     *
     * 若无定时器返回 null（表示无限等待）。
     * 若定时器已到期返回 0。
     */
    public function getNextTimerDelay(): ?float
    {
        if (empty($this->timers)) {
            return null;
        }

        $now = \microtime(true);
        $minDelay = PHP_FLOAT_MAX;

        foreach ($this->timers as $timer) {
            $remaining = $timer['deadline'] - $now;
            if ($remaining <= 0) {
                return 0.0;
            }
            $minDelay = \min($minDelay, $remaining);
        }

        return $minDelay;
    }

    /**
     * 处理到期定时器，resume 对应 Fiber
     *
     * 由 Worker 在每轮事件循环中调用。
     *
     * @param callable|null $beforeResume 在 resume 前调用，接收 Fiber 参数，
     *                                    由 Worker 负责恢复该 Fiber 的请求级上下文。
     */
    public function tick(?callable $beforeResume = null): void
    {
        if (empty($this->timers)) {
            return;
        }

        $now = \microtime(true);
        $expired = [];

        foreach ($this->timers as $id => $timer) {
            if ($timer['deadline'] <= $now) {
                $expired[$id] = $timer;
            }
        }

        foreach ($expired as $id => $timer) {
            unset($this->timers[$id]);
            $fiber = $timer['fiber'];

            if ($fiber->isSuspended()) {
                try {
                    if ($beforeResume !== null) {
                        $beforeResume($fiber);
                    }
                    $fiber->resume();
                } catch (RequestExitException) {
                    // Fiber 中抛出退出异常，正常结束该 Fiber
                } catch (\Throwable $e) {
                    \Weline\Framework\App\Env::log_error(
                        'wls/fiber_scheduler',
                        'FiberScheduler::tick resume error: ' . $e->getMessage()
                    );
                }
            }
        }
    }

    /**
     * 取消指定 Fiber 的所有定时器（释放闲置 Fiber 时调用，避免后续 tick 再次 resume）
     */
    public function cancelTimersForFiber(\Fiber $fiber): void
    {
        foreach ($this->timers as $id => $timer) {
            if ($timer['fiber'] === $fiber) {
                unset($this->timers[$id]);
            }
        }
    }

    /**
     * 是否有待处理的定时器
     */
    public function hasPendingTimers(): bool
    {
        return !empty($this->timers);
    }

    /**
     * 重置调度器状态（进程级不需要跨请求重置，但提供方法以备用）
     */
    public function reset(): void
    {
        $this->timers = [];
        $this->activeFiberCount = 0;
    }
}
