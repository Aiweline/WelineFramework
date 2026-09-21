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
    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

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
            'deadline' => self::monotonicSeconds() + $delaySeconds,
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
    public function addReadableWaiter(\Fiber $fiber, mixed $stream, float $timeoutSec, ?\stdClass $ioTiming = null): void
    {
        $this->addIoWaiter($fiber, $stream, 'read', $timeoutSec, $ioTiming);
    }

    /**
     * @param resource $stream
     */
    public function addWritableWaiter(\Fiber $fiber, mixed $stream, float $timeoutSec, ?\stdClass $ioTiming = null): void
    {
        $this->addIoWaiter($fiber, $stream, 'write', $timeoutSec, $ioTiming);
    }

    /**
     * @param resource $stream
     */
    private function addIoWaiter(\Fiber $fiber, mixed $stream, string $direction, float $timeoutSec, ?\stdClass $ioTiming = null): void
    {
        $direction = $direction === 'write' ? 'write' : 'read';

        foreach ($this->ioWaiters as $id => $waiter) {
            if ($waiter['fiber'] === $fiber
                && $waiter['stream'] === $stream
                && $waiter['direction'] === $direction
            ) {
                $this->ioWaiters[$id]['deadline'] = self::monotonicSeconds() + \max(0.0, $timeoutSec);
                $this->ioWaiters[$id]['result'] = null;
                self::attachIoTiming($this->ioWaiters[$id], $ioTiming);
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
                'deadline' => self::monotonicSeconds(),
                'result' => false,
            ];
            self::attachIoTiming($this->ioWaiters[$id], $ioTiming);
            return;
        }

        $this->ioWaiters[$id] = [
            'fiber' => $fiber,
            'stream' => $stream,
            'direction' => $direction,
            'deadline' => self::monotonicSeconds() + \max(0.0, $timeoutSec),
            'result' => null,
        ];
        self::attachIoTiming($this->ioWaiters[$id], $ioTiming);
    }

    /** Attach only caller-owned diagnostics; waiter state remains authoritative. */
    private static function attachIoTiming(array &$waiter, ?\stdClass $ioTiming): void
    {
        unset($waiter['io_timing']);
        if ($ioTiming === null) {
            return;
        }

        $registeredNs = \hrtime(true);
        $ioTiming->registered_ns = $registeredNs;
        $ioTiming->deadline_ns = (int) \round($waiter['deadline'] * 1_000_000_000);
        $ioTiming->yield_return_ns = null;
        $ioTiming->after_resume_start_ns = null;
        $ioTiming->after_resume_end_ns = null;
        $ioTiming->guard_start_ns = null;
        $ioTiming->guard_end_ns = null;
        $ioTiming->collect_seen_ns = null;
        $ioTiming->first_poll_start_ns = null;
        $ioTiming->first_poll_end_ns = null;
        $ioTiming->resolved_ns = $waiter['result'] === false ? $registeredNs : null;
        $ioTiming->resolution = $waiter['result'] === false ? 'invalid_stream' : null;
        $ioTiming->before_resume_start_ns = null;
        $ioTiming->before_resume_end_ns = null;
        $ioTiming->resume_call_ns = null;
        $waiter['io_timing'] = $ioTiming;
    }

    /** Observe the existing Worker guard only for waits not yet collected or polled. */
    public function observePendingIoGuard(callable $guard): bool
    {
        $pending = [];
        foreach ($this->ioWaiters as $waiter) {
            if ($waiter['result'] !== null || !isset($waiter['io_timing'])) {
                continue;
            }
            $timing = $waiter['io_timing'];
            if ($timing->collect_seen_ns !== null
                || $timing->first_poll_start_ns !== null
                || $timing->guard_start_ns !== null
            ) {
                continue;
            }
            $pending[] = [$timing, $timing->registered_ns];
        }
        if ($pending === []) {
            return $guard();
        }

        $startNs = \hrtime(true);
        try {
            return $guard();
        } finally {
            $endNs = \hrtime(true);
            foreach ($pending as [$timing, $registrationNs]) {
                // Do not attribute an old guard to a re-registered or newly observed wait.
                if ($timing->registered_ns === $registrationNs && $timing->guard_start_ns === null) {
                    $timing->guard_start_ns = $startNs;
                    $timing->guard_end_ns = $endNs;
                }
            }
        }
    }


    /**
     * Merge pending I/O waiter sockets into Worker EventLoop sets.
     *
     * @param array<int|string, resource> $read
     * @param array<int|string, resource> $write
     */
    public function collectIoWaitStreams(array &$read, array &$write, ?array &$ioTimings = null): void
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
            if ($ioTimings !== null
                && isset($waiter['io_timing'])
                && $waiter['io_timing']->first_poll_start_ns === null
            ) {
                // First observation in the existing collection; not method-entry time.
                $waiter['io_timing']->collect_seen_ns ??= \hrtime(true);
                // Snapshot only this poll's unresolved, included waiter carriers.
                $ioTimings[] = $waiter['io_timing'];
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
                if (isset($waiter['io_timing'])) {
                    $waiter['io_timing']->resolved_ns = \hrtime(true);
                    $waiter['io_timing']->resolution = 'invalid_stream';
                }
                continue;
            }
            if ($waiter['direction'] === 'write') {
                if (\in_array($stream, $readyWrite, true)) {
                    $this->ioWaiters[$id]['result'] = true;
                    if (isset($waiter['io_timing'])) {
                        $waiter['io_timing']->resolved_ns = \hrtime(true);
                        $waiter['io_timing']->resolution = 'ready';
                    }
                }
            } elseif (\in_array($stream, $readyRead, true)) {
                $this->ioWaiters[$id]['result'] = true;
                if (isset($waiter['io_timing'])) {
                    $waiter['io_timing']->resolved_ns = \hrtime(true);
                    $waiter['io_timing']->resolution = 'ready';
                }
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
        $now = self::monotonicSeconds();

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
    public function tick(
        ?callable $beforeResume = null,
        ?float $maxExecutionMs = null,
        ?callable $afterResume = null,
        ?callable $onResumeFailure = null,
    ): void
    {
        $startAt = $maxExecutionMs !== null ? self::monotonicSeconds() : 0.0;
        $this->resumeDueIoWaiters(
            $beforeResume,
            $maxExecutionMs,
            $afterResume,
            $onResumeFailure,
            $startAt,
        );

        if (empty($this->timers)) {
            return;
        }

        $now = self::monotonicSeconds();

        /** @var array<int, array{deadline: float, fiber: \Fiber}> */
        $expired = [];
        foreach ($this->timers as $id => $timer) {
            if ($timer['deadline'] <= $now) {
                $expired[$id] = $timer;
            }
        }

        foreach ($expired as $id => $timer) {
            if ($maxExecutionMs !== null) {
                $elapsedMs = (self::monotonicSeconds() - $startAt) * 1000;
                if ($elapsedMs >= $maxExecutionMs) {
                    break;
                }
            }

            unset($this->timers[$id]);

            /** @var \Fiber $fiber */
            $fiber = $timer['fiber'];
            $this->resumeFiber($fiber, null, $beforeResume, $afterResume, $onResumeFailure);
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
        ?callable $onResumeFailure,
        float $startAt
    ): void {
        if ($this->ioWaiters === []) {
            return;
        }

        $now = self::monotonicSeconds();
        foreach (\array_keys($this->ioWaiters) as $id) {
            if (!isset($this->ioWaiters[$id])) {
                continue;
            }
            $waiter = $this->ioWaiters[$id];
            if ($waiter['result'] === null) {
                if (!\is_resource($waiter['stream'])) {
                    $waiter['result'] = false;
                    if (isset($waiter['io_timing'])) {
                        $waiter['io_timing']->resolved_ns = \hrtime(true);
                        $waiter['io_timing']->resolution = 'invalid_stream';
                    }
                } elseif ($waiter['deadline'] <= $now) {
                    $waiter['result'] = false;
                    if (isset($waiter['io_timing'])) {
                        $waiter['io_timing']->resolved_ns = \hrtime(true);
                        $waiter['io_timing']->resolution = 'timeout';
                    }
                } else {
                    continue;
                }
            }

            if ($maxExecutionMs !== null) {
                $elapsedMs = (self::monotonicSeconds() - $startAt) * 1000;
                if ($elapsedMs >= $maxExecutionMs) {
                    // Keep resolved waiter for the next tick; do not lose the result.
                    $this->ioWaiters[$id] = $waiter;
                    break;
                }
            }

            unset($this->ioWaiters[$id]);
            $this->resumeFiber(
                $waiter['fiber'],
                $waiter['result'] === true,
                $beforeResume,
                $afterResume,
                $onResumeFailure,
                $waiter['io_timing'] ?? null,
            );
        }
    }

    private function resumeFiber(
        \Fiber $fiber,
        mixed $resumeValue,
        ?callable $beforeResume,
        ?callable $afterResume,
        ?callable $onResumeFailure,
        ?\stdClass $ioTiming = null,
    ): void {
        if (!$fiber->isSuspended()) {
            return;
        }

        try {
            if ($ioTiming === null) {
                if ($beforeResume !== null) {
                    $beforeResume($fiber);
                }
            } else {
                $ioTiming->before_resume_start_ns = \hrtime(true);
                try {
                    if ($beforeResume !== null) {
                        $beforeResume($fiber);
                    }
                } finally {
                    $ioTiming->before_resume_end_ns = \hrtime(true);
                }
                // resume() returns after more business execution, outside this await.
                $ioTiming->resume_call_ns = \hrtime(true);
            }
            $fiber->resume($resumeValue);
            // A new await may have suspended during resume(). The previous carrier
            // belongs to the already returned await and must not receive these stamps.
            $nextIoTiming = null;
            if ($fiber->isSuspended()) {
                foreach ($this->ioWaiters as $pendingWaiter) {
                    if (isset($pendingWaiter['io_timing']) && $pendingWaiter['fiber'] === $fiber) {
                        $nextIoTiming = $pendingWaiter['io_timing'];
                        break;
                    }
                }
            }
            if ($nextIoTiming === null) {
                if ($afterResume !== null) {
                    $afterResume($fiber);
                }
            } else {
                $registrationNs = $nextIoTiming->registered_ns;
                $nextIoTiming->yield_return_ns = \hrtime(true);
                $nextIoTiming->after_resume_start_ns = \hrtime(true);
                try {
                    if ($afterResume !== null) {
                        $afterResume($fiber);
                    }
                } finally {
                    // A callback may re-register this carrier; do not finish an old interval.
                    if ($nextIoTiming->registered_ns === $registrationNs) {
                        $nextIoTiming->after_resume_end_ns = \hrtime(true);
                    }
                }
            }
        } catch (RequestExitException) {
            // Fiber ended via request-exit path.
        } catch (\Throwable $e) {
            if ($onResumeFailure !== null) {
                try {
                    $onResumeFailure($fiber, $e);
                } catch (\Throwable) {
                    // Failure reporting must not mask the original scheduler error.
                }
            }
            \Weline\Framework\App\Env::log_error(
                'wls/fiber_scheduler',
                'FiberScheduler::tick resume error: ' . $e->getMessage()
            );
        }
    }

    public function hasWaker(\Fiber $fiber): bool
    {
        foreach ($this->timers as $timer) {
            if ($timer['fiber'] === $fiber) {
                return true;
            }
        }
        foreach ($this->ioWaiters as $waiter) {
            if ($waiter['fiber'] === $fiber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resume a suspended Fiber that lost its timer or I/O waiter.
     *
     * @param callable|null $beforeResume
     * @param callable|null $afterResume
     * @param callable|null $onResumeFailure
     */
    public function resumeOrphan(\Fiber $fiber, ?callable $beforeResume, ?callable $afterResume, ?callable $onResumeFailure): void
    {
        $this->resumeFiber($fiber, null, $beforeResume, $afterResume, $onResumeFailure);
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
