<?php
declare(strict_types=1);

namespace Weline\Server\Scheduler;

use Weline\Framework\Runtime\RequestExitException;

/**
 * Fiber 协作式调度器
 *
 * 由 Worker 在每轮事件循环中调用 tick()，处理到期定时器 / I/O waiter 并 resume 对应 Fiber。
 * Worker 通过 getNextTimerDelay() 得到 stream_select 的 timeout；
 * CoroutineRuntime::wait() 通过 collectIoWaitStreams/markIoReady 合并 socket waiter。
 */
class FiberScheduler
{
    /**
     * @var array<int, array{deadline: float, fiber: \Fiber}>
     */
    private array $timers = [];

    /**
     * Socket I/O waiters. result is set by markIoReady / timeout before resume.
     *
     * @var array<int, array{
     *   fiber: \Fiber,
     *   stream: resource,
     *   direction: 'read'|'write',
     *   deadline: float,
     *   result: ?bool
     * }>
     */
    private array $ioWaiters = [];

    private int $nextTimerId = 0;
    private int $nextIoWaiterId = 0;
    private int $activeFiberCount = 0;

    public function addSleepTimer(\Fiber $fiber, int $seconds): void
    {
        $this->addTimer($fiber, (float) $seconds);
    }

    public function addUsleepTimer(\Fiber $fiber, int $microseconds): void
    {
        $this->addTimer($fiber, $microseconds / 1_000_000.0);
    }

    private function addTimer(\Fiber $fiber, float $delaySeconds): void
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            'deadline' => \microtime(true) + $delaySeconds,
            'fiber' => $fiber,
        ];
    }

    public function addYieldTimer(\Fiber $fiber): void
    {
        $this->addTimer($fiber, 0.0);
    }

    public function addYieldDelayTimer(\Fiber $fiber, int $milliseconds): void
    {
        $this->addTimer($fiber, $milliseconds / 1000.0);
    }

    /**
     * @param resource $stream
     */
    public function addReadableWaiter(\Fiber $fiber, mixed $stream, float $timeoutSec): void
    {
        $this->addIoWaiter($fiber, $stream, 'read', $timeoutSec);
    }

    /**
     * @param resource $stream
     */
    public function addWritableWaiter(\Fiber $fiber, mixed $stream, float $timeoutSec): void
    {
        $this->addIoWaiter($fiber, $stream, 'write', $timeoutSec);
    }

    /**
     * @param resource $stream
     */
    private function addIoWaiter(\Fiber $fiber, mixed $stream, string $direction, float $timeoutSec): void
    {
        $direction = $direction === 'write' ? 'write' : 'read';

        foreach ($this->ioWaiters as $id => $waiter) {
            if ($waiter['fiber'] === $fiber
                && $waiter['stream'] === $stream
                && $waiter['direction'] === $direction
            ) {
                $this->ioWaiters[$id]['deadline'] = \microtime(true) + \max(0.0, $timeoutSec);
                $this->ioWaiters[$id]['result'] = null;
                return;
            }
            if (\is_resource($stream)
                && $waiter['stream'] === $stream
                && $waiter['direction'] === $direction
                && $waiter['fiber'] !== $fiber
            ) {
                throw new \RuntimeException('Duplicate I/O waiter on shared stream');
            }
        }

        $id = $this->nextIoWaiterId++;
        if (!\is_resource($stream)) {
            // Resolve on next tick with false so the Fiber never hangs.
            $this->ioWaiters[$id] = [
                'fiber' => $fiber,
                'stream' => $stream,
                'direction' => $direction,
                'deadline' => \microtime(true),
                'result' => false,
            ];
            return;
        }

        $this->ioWaiters[$id] = [
            'fiber' => $fiber,
            'stream' => $stream,
            'direction' => $direction,
            'deadline' => \microtime(true) + \max(0.0, $timeoutSec),
            'result' => null,
        ];
    }

    /**
     * Merge pending I/O waiter sockets into Worker EventLoop sets.
     *
     * @param array<int|string, resource> $read
     * @param array<int|string, resource> $write
     */
    public function collectIoWaitStreams(array &$read, array &$write): void
    {
        foreach ($this->ioWaiters as $waiter) {
            if ($waiter['result'] !== null) {
                continue;
            }
            $stream = $waiter['stream'];
            if (!\is_resource($stream)) {
                continue;
            }
            if ($waiter['direction'] === 'write') {
                if (!\in_array($stream, $write, true)) {
                    $write[] = $stream;
                }
            } elseif (!\in_array($stream, $read, true)) {
                $read[] = $stream;
            }
        }
    }

    /**
     * Mark waiters whose streams became ready in the last EventLoop wait.
     *
     * @param array<int|string, resource> $readyRead
     * @param array<int|string, resource> $readyWrite
     */
    public function markIoReady(array $readyRead, array $readyWrite): void
    {
        if ($this->ioWaiters === []) {
            return;
        }

        foreach ($this->ioWaiters as $id => $waiter) {
            if ($waiter['result'] !== null) {
                continue;
            }
            $stream = $waiter['stream'];
            if (!\is_resource($stream)) {
                $this->ioWaiters[$id]['result'] = false;
                continue;
            }
            if ($waiter['direction'] === 'write') {
                if (\in_array($stream, $readyWrite, true)) {
                    $this->ioWaiters[$id]['result'] = true;
                }
            } elseif (\in_array($stream, $readyRead, true)) {
                $this->ioWaiters[$id]['result'] = true;
            }
        }
    }

    public function registerFiber(): void
    {
        $this->activeFiberCount++;
    }

    public function unregisterFiber(): void
    {
        $this->activeFiberCount = \max(0, $this->activeFiberCount - 1);
    }

    public function getActiveFiberCount(): int
    {
        return $this->activeFiberCount;
    }

    /**
     * Remaining seconds until next timer or I/O waiter deadline.
     * null = no pending waiters (infinite). 0 = already due.
     */
    public function getNextTimerDelay(): ?float
    {
        $minDelay = null;
        $now = \microtime(true);

        foreach ($this->timers as $timer) {
            $remaining = $timer['deadline'] - $now;
            if ($remaining <= 0.0) {
                return 0.0;
            }
            if ($minDelay === null || $remaining < $minDelay) {
                $minDelay = $remaining;
            }
        }

        foreach ($this->ioWaiters as $waiter) {
            if ($waiter['result'] !== null) {
                return 0.0;
            }
            $remaining = $waiter['deadline'] - $now;
            if ($remaining <= 0.0) {
                return 0.0;
            }
            if ($minDelay === null || $remaining < $minDelay) {
                $minDelay = $remaining;
            }
        }

        return $minDelay;
    }

    /**
     * @param callable|null $beforeResume 在 resume 前调用，接收 Fiber 参数
     */
    public function tick(?callable $beforeResume = null, ?float $maxExecutionMs = null, ?callable $afterResume = null): void
    {
        $startAt = $maxExecutionMs !== null ? \microtime(true) : 0.0;
        $this->resumeDueIoWaiters($beforeResume, $maxExecutionMs, $afterResume, $startAt);

        if (empty($this->timers)) {
            return;
        }

        $now = \microtime(true);

        /** @var array<int, array{deadline: float, fiber: \Fiber}> */
        $expired = [];
        foreach ($this->timers as $id => $timer) {
            if ($timer['deadline'] <= $now) {
                $expired[$id] = $timer;
            }
        }

        foreach ($expired as $id => $timer) {
            if ($maxExecutionMs !== null) {
                $elapsedMs = (\microtime(true) - $startAt) * 1000;
                if ($elapsedMs >= $maxExecutionMs) {
                    break;
                }
            }

            unset($this->timers[$id]);

            /** @var \Fiber $fiber */
            $fiber = $timer['fiber'];
            $this->resumeFiber($fiber, null, $beforeResume, $afterResume);
        }
    }

    /**
     * @param callable|null $beforeResume
     * @param callable|null $afterResume
     */
    private function resumeDueIoWaiters(
        ?callable $beforeResume,
        ?float $maxExecutionMs,
        ?callable $afterResume,
        float $startAt
    ): void {
        if ($this->ioWaiters === []) {
            return;
        }

        $now = \microtime(true);
        foreach (\array_keys($this->ioWaiters) as $id) {
            if (!isset($this->ioWaiters[$id])) {
                continue;
            }
            $waiter = $this->ioWaiters[$id];
            if ($waiter['result'] === null) {
                if (!\is_resource($waiter['stream'])) {
                    $waiter['result'] = false;
                } elseif ($waiter['deadline'] <= $now) {
                    $waiter['result'] = false;
                } else {
                    continue;
                }
            }

            if ($maxExecutionMs !== null) {
                $elapsedMs = (\microtime(true) - $startAt) * 1000;
                if ($elapsedMs >= $maxExecutionMs) {
                    // Keep resolved waiter for the next tick; do not lose the result.
                    $this->ioWaiters[$id] = $waiter;
                    break;
                }
            }

            unset($this->ioWaiters[$id]);
            $this->resumeFiber($waiter['fiber'], $waiter['result'] === true, $beforeResume, $afterResume);
        }
    }

    private function resumeFiber(
        \Fiber $fiber,
        mixed $resumeValue,
        ?callable $beforeResume,
        ?callable $afterResume
    ): void {
        if (!$fiber->isSuspended()) {
            return;
        }

        try {
            if ($beforeResume !== null) {
                $beforeResume($fiber);
            }
            $fiber->resume($resumeValue);
            if ($afterResume !== null) {
                $afterResume($fiber);
            }
        } catch (RequestExitException) {
            // Fiber ended via request-exit path.
        } catch (\Throwable $e) {
            \Weline\Framework\App\Env::log_error(
                'wls/fiber_scheduler',
                'FiberScheduler::tick resume error: ' . $e->getMessage()
            );
        }
    }

    public function cancelTimersForFiber(\Fiber $fiber): void
    {
        foreach ($this->timers as $id => $timer) {
            if ($timer['fiber'] === $fiber) {
                unset($this->timers[$id]);
            }
        }
        foreach ($this->ioWaiters as $id => $waiter) {
            if ($waiter['fiber'] === $fiber) {
                unset($this->ioWaiters[$id]);
            }
        }
    }

    public function hasPendingTimers(): bool
    {
        return $this->timers !== [] || $this->ioWaiters !== [];
    }

    public function hasPendingIoWaiters(): bool
    {
        return $this->ioWaiters !== [];
    }

    public function reset(): void
    {
        $this->timers = [];
        $this->ioWaiters = [];
        $this->activeFiberCount = 0;
    }
}
