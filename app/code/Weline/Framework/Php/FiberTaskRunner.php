<?php

declare(strict_types=1);

namespace Weline\Framework\Php;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Runs independent tasks in a small cooperative Fiber pool.
 *
 * PHP Fibers are cooperative, not preemptive. This runner makes existing
 * SchedulerSystem::sleep/usleep/yieldDelay calls suspend the current Fiber in
 * CLI/system flows, allowing other ready tasks to continue.
 */
final class FiberTaskRunner
{
    private const DEFAULT_CONCURRENCY = 4;
    private const IDLE_SLEEP_US = 1_000;
    private const MAX_SLEEP_US = 10_000;
    private const PUMP_BLOCKING_TICK_SECONDS = 0.05;

    /** @var array<int, array{fiber: \Fiber, deadline: float}> */
    private array $waits = [];

    private bool $ownsScheduler = false;

    private ?CurlStreamPump $pump = null;

    /**
     * 当前正在 run() 中的 runner 持有的 pump；嵌套 run 时按栈顺序保存/恢复。
     */
    private static ?CurlStreamPump $activePump = null;

    public function __construct(
        private readonly int $defaultConcurrency = self::DEFAULT_CONCURRENCY,
        private readonly bool $preserveContext = true
    ) {
    }

    /**
     * 当前 Fiber 上下文里可用的多路 cURL 调度器。
     * 仅在 {@see self::run()} 的并发分支期间非空；外部（控制器、Provider 老路径）
     * 应据此判断走串行还是并行分支。
     */
    public static function currentPump(): ?CurlStreamPump
    {
        return self::$activePump;
    }

    /**
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @return array<string|int, mixed>
     */
    public function run(array $tasks, ?int $concurrency = null): array
    {
        if ($tasks === []) {
            return [];
        }

        $concurrency = $this->normalizeConcurrency($concurrency);
        if ($concurrency <= 1 || !\class_exists(\Fiber::class)) {
            return $this->runSequentially($tasks);
        }

        $runPool = function () use ($tasks, $concurrency): array {
            $contextSnapshot = $this->captureContextSnapshot();
            $this->startLocalScheduler();
            $previousPump = self::$activePump;
            $this->pump = new CurlStreamPump();
            self::$activePump = $this->pump;

            try {
                return $this->runWithFibers($tasks, $concurrency, $contextSnapshot);
            } finally {
                self::$activePump = $previousPump;
                $this->pump = null;
                $this->stopLocalScheduler();
                $this->waits = [];
            }
        };

        return SchedulerSystem::isSchedulerActive()
            ? SchedulerSystem::runWithoutGlobalScheduler($runPool)
            : $runPool();
    }

    public static function yield(mixed $value = null): mixed
    {
        if (!\class_exists(\Fiber::class) || \Fiber::getCurrent() === null) {
            return null;
        }

        return \Fiber::suspend($value);
    }

    /**
     * 并发版本的 Promise.allSettled：边完成边产出，永不抛出。
     *
     * 与 {@see self::run()} 不同，runEvents 把每个任务的成功/失败都包装成
     * `['status' => 'fulfilled', 'result' => mixed]` 或
     * `['status' => 'rejected', 'error' => Throwable]`，且按完成顺序流式 yield。
     *
     * 串行回退（concurrency<=1 / 无 Fiber）走与并发分支相同的协议；WLS 全局调度开启时
     * 将临时抑制外层标记以便走 Fiber 池（见 {@see SchedulerSystem::suppressGlobalSchedulerMomentarily()}）。
     *
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @return \Generator<string|int, array{status:string, result?:mixed, error?:\Throwable}>
     */
    public function runEvents(array $tasks, ?int $concurrency = null): \Generator
    {
        if ($tasks === []) {
            return;
        }

        $concurrency = $this->normalizeConcurrency($concurrency);
        if ($concurrency <= 1 || !\class_exists(\Fiber::class)) {
            foreach ($tasks as $key => $task) {
                if (!\is_callable($task)) {
                    yield $key => [
                        'status' => 'rejected',
                        'error' => new \InvalidArgumentException('Fiber task must be callable.'),
                    ];
                    continue;
                }

                try {
                    yield $key => [
                        'status' => 'fulfilled',
                        'result' => $task($key),
                    ];
                } catch (\Throwable $throwable) {
                    yield $key => [
                        'status' => 'rejected',
                        'error' => $throwable,
                    ];
                }
            }

            return;
        }

        $restoreOuterScheduler = SchedulerSystem::suppressGlobalSchedulerMomentarily();

        try {
            $contextSnapshot = $this->captureContextSnapshot();
            $this->startLocalScheduler();
            $previousPump = self::$activePump;
            $this->pump = new CurlStreamPump();
            self::$activePump = $this->pump;

            try {
                yield from $this->runEventsWithFibers($tasks, $concurrency, $contextSnapshot);
            } finally {
                self::$activePump = $previousPump;
                $this->pump = null;
                $this->stopLocalScheduler();
                $this->waits = [];
            }
        } finally {
            $restoreOuterScheduler();
        }
    }

    /**
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @param array<string, mixed>|null $contextSnapshot
     * @return \Generator<string|int, array{status:string, result?:mixed, error?:\Throwable}>
     */
    private function runEventsWithFibers(array $tasks, int $concurrency, ?array $contextSnapshot): \Generator
    {
        $taskKeys = \array_keys($tasks);
        $pendingIndex = 0;
        $totalTasks = \count($taskKeys);
        /** @var array<string|int, \Fiber> $running */
        $running = [];
        /** @var array<string|int, array{status:string, result?:mixed, error?:\Throwable}> $pendingEvents */
        $pendingEvents = [];

        $spawn = function (string|int $taskKey) use ($tasks, $contextSnapshot, &$running, &$pendingEvents): void {
            $task = $tasks[$taskKey];
            if (!\is_callable($task)) {
                $pendingEvents[$taskKey] = [
                    'status' => 'rejected',
                    'error' => new \InvalidArgumentException('Fiber task must be callable.'),
                ];
                return;
            }

            $fiber = new \Fiber(fn(): mixed => $this->runTaskWithContext($task, $taskKey, $contextSnapshot));
            $running[$taskKey] = $fiber;

            try {
                $fiber->start();
            } catch (\Throwable $throwable) {
                unset($running[$taskKey], $this->waits[\spl_object_id($fiber)]);
                $pendingEvents[$taskKey] = [
                    'status' => 'rejected',
                    'error' => $throwable,
                ];
                return;
            }

            $this->collectTerminatedEvent($taskKey, $fiber, $running, $pendingEvents);
        };

        while ($pendingIndex < $totalTasks || $running !== [] || $pendingEvents !== []) {
            while ($pendingIndex < $totalTasks && \count($running) < $concurrency) {
                $spawn($taskKeys[$pendingIndex++]);
            }

            $madeProgress = false;
            foreach ($running as $taskKey => $fiber) {
                $this->collectTerminatedEvent($taskKey, $fiber, $running, $pendingEvents);
                if (!isset($running[$taskKey])) {
                    $madeProgress = true;
                    continue;
                }

                if (!$fiber->isSuspended() || !$this->fiberReadyToResume($fiber)) {
                    continue;
                }

                try {
                    $fiber->resume();
                    $madeProgress = true;
                } catch (\Throwable $throwable) {
                    unset($running[$taskKey], $this->waits[\spl_object_id($fiber)]);
                    $pendingEvents[$taskKey] = [
                        'status' => 'rejected',
                        'error' => $throwable,
                    ];
                    continue;
                }

                $this->collectTerminatedEvent($taskKey, $fiber, $running, $pendingEvents);
            }

            // 优先把已完成事件 flush 给消费者，触发后续 spawn。
            if ($pendingEvents !== []) {
                foreach ($pendingEvents as $key => $event) {
                    yield $key => $event;
                }
                $pendingEvents = [];
                $madeProgress = true;
            }

            if ($this->pump?->hasActiveHandles() && $this->pump->tick(0.0)) {
                $madeProgress = true;
            }

            if (!$madeProgress && $running !== []) {
                if ($this->pump?->hasActiveHandles()) {
                    $this->pump->tick(self::PUMP_BLOCKING_TICK_SECONDS);
                } else {
                    $this->waitForFiberProgress();
                }
            }
        }
    }

    /**
     * @param array<string|int, \Fiber> $running
     * @param array<string|int, array{status:string, result?:mixed, error?:\Throwable}> $pendingEvents
     */
    private function collectTerminatedEvent(
        string|int $taskKey,
        \Fiber $fiber,
        array &$running,
        array &$pendingEvents
    ): void {
        if (!$fiber->isTerminated()) {
            return;
        }

        unset($running[$taskKey], $this->waits[\spl_object_id($fiber)]);

        try {
            $pendingEvents[$taskKey] = [
                'status' => 'fulfilled',
                'result' => $fiber->getReturn(),
            ];
        } catch (\Throwable $throwable) {
            $pendingEvents[$taskKey] = [
                'status' => 'rejected',
                'error' => $throwable,
            ];
        }
    }

    private function normalizeConcurrency(?int $concurrency): int
    {
        $value = $concurrency ?? $this->defaultConcurrency;
        return \max(1, $value);
    }

    /**
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @return array<string|int, mixed>
     */
    private function runSequentially(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task($key);
        }

        return $results;
    }

    /**
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @param array<string, mixed>|null $contextSnapshot
     * @return array<string|int, mixed>
     */
    private function runWithFibers(array $tasks, int $concurrency, ?array $contextSnapshot): array
    {
        $taskKeys = \array_keys($tasks);
        $nextIndex = 0;
        $running = [];
        $results = [];
        $errors = [];

        while ($nextIndex < \count($taskKeys) || $running !== []) {
            while ($nextIndex < \count($taskKeys) && \count($running) < $concurrency) {
                $taskKey = $taskKeys[$nextIndex++];
                $task = $tasks[$taskKey];
                if (!\is_callable($task)) {
                    throw new \InvalidArgumentException('Fiber task must be callable.');
                }

                $fiber = new \Fiber(fn(): mixed => $this->runTaskWithContext($task, $taskKey, $contextSnapshot));
                $running[$taskKey] = $fiber;
                try {
                    $fiber->start();
                } catch (\Throwable $e) {
                    $errors[$taskKey] = $e;
                }
                $this->collectTerminatedFiber($taskKey, $fiber, $running, $results, $errors);

                if ($errors !== []) {
                    break 2;
                }
            }

            $madeProgress = false;
            foreach ($running as $taskKey => $fiber) {
                $this->collectTerminatedFiber($taskKey, $fiber, $running, $results, $errors);
                if (!isset($running[$taskKey]) || $errors !== []) {
                    $madeProgress = true;
                    continue;
                }

                if (!$fiber->isSuspended() || !$this->fiberReadyToResume($fiber)) {
                    continue;
                }

                try {
                    $fiber->resume();
                    $madeProgress = true;
                } catch (\Throwable $e) {
                    $errors[$taskKey] = $e;
                }
                $this->collectTerminatedFiber($taskKey, $fiber, $running, $results, $errors);

                if ($errors !== []) {
                    break;
                }
            }

            if ($errors !== []) {
                break;
            }

            // 推进 cURL multi（非阻塞），让流式 Fiber 拿到新 chunk。
            if ($this->pump?->hasActiveHandles() && $this->pump->tick(0.0)) {
                $madeProgress = true;
            }

            if (!$madeProgress && $running !== []) {
                if ($this->pump?->hasActiveHandles()) {
                    // 既无可推进 Fiber 也无新 chunk：阻塞等 I/O，避免空跑 CPU。
                    $this->pump->tick(self::PUMP_BLOCKING_TICK_SECONDS);
                } else {
                    $this->waitForFiberProgress();
                }
            }
        }

        if ($errors !== []) {
            throw \reset($errors);
        }

        $ordered = [];
        foreach ($taskKeys as $taskKey) {
            if (\array_key_exists($taskKey, $results)) {
                $ordered[$taskKey] = $results[$taskKey];
            }
        }

        return $ordered;
    }

    private function runTaskWithContext(callable $task, string|int $taskKey, ?array $contextSnapshot): mixed
    {
        if ($contextSnapshot !== null) {
            WelineEnv::getInstance()->restore($contextSnapshot);
        }

        return $task($taskKey);
    }

    /**
     * @param array<string|int, \Fiber> $running
     * @param array<string|int, mixed> $results
     * @param array<string|int, \Throwable> $errors
     */
    private function collectTerminatedFiber(
        string|int $taskKey,
        \Fiber $fiber,
        array &$running,
        array &$results,
        array &$errors
    ): void {
        if (!$fiber->isTerminated()) {
            return;
        }

        unset($running[$taskKey], $this->waits[\spl_object_id($fiber)]);
        if (isset($errors[$taskKey])) {
            return;
        }

        try {
            $results[$taskKey] = $fiber->getReturn();
        } catch (\Throwable $e) {
            $errors[$taskKey] = $e;
        }
    }

    private function startLocalScheduler(): void
    {
        if (SchedulerSystem::isSchedulerActive()) {
            return;
        }

        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(function (string $type, array $params): void {
            $fiber = \Fiber::getCurrent();
            if (!$fiber instanceof \Fiber) {
                return;
            }

            $this->waits[\spl_object_id($fiber)] = [
                'fiber' => $fiber,
                'deadline' => \microtime(true) + $this->delaySeconds($type, $params),
            ];
        });
        $this->ownsScheduler = true;
    }

    private function stopLocalScheduler(): void
    {
        if (!$this->ownsScheduler) {
            return;
        }

        SchedulerSystem::disableScheduler();
        $this->ownsScheduler = false;
    }

    private function delaySeconds(string $type, array $params): float
    {
        return match ($type) {
            'sleep' => \max(0, (int)($params['seconds'] ?? 0)),
            'usleep' => \max(0, (int)($params['microseconds'] ?? 0)) / 1_000_000.0,
            'yield_delay' => \max(0, (int)($params['milliseconds'] ?? 0)) / 1_000.0,
            default => 0.0,
        };
    }

    private function fiberReadyToResume(\Fiber $fiber): bool
    {
        $waitKey = \spl_object_id($fiber);
        if (!isset($this->waits[$waitKey])) {
            return true;
        }

        if ($this->waits[$waitKey]['deadline'] > \microtime(true)) {
            return false;
        }

        unset($this->waits[$waitKey]);
        return true;
    }

    private function idleSleepMicroseconds(): int
    {
        $delay = $this->nextTimerDelaySeconds();
        if ($delay === null) {
            return self::IDLE_SLEEP_US;
        }

        return \max(0, \min(self::MAX_SLEEP_US, (int)\ceil($delay * 1_000_000)));
    }

    private function nextTimerDelaySeconds(): ?float
    {
        if ($this->waits === []) {
            return null;
        }

        $now = \microtime(true);
        $minDelay = null;
        foreach ($this->waits as $wait) {
            $delay = \max(0.0, $wait['deadline'] - $now);
            if ($minDelay === null || $delay < $minDelay) {
                $minDelay = $delay;
            }
        }

        return $minDelay;
    }

    /** @return array<string, mixed>|null */
    private function captureContextSnapshot(): ?array
    {
        if (!$this->preserveContext) {
            return null;
        }

        return WelineEnv::getInstance()->capture();
    }

    /**
     * 空闲等待：嵌套在 WLS SSE 等已启用 Scheduler 的 Fiber 内时，必须 yield 回 Worker，
     * 否则 SSE 写队列无法刷到客户端，长连接会被浏览器/代理静默断开。
     */
    private function waitForFiberProgress(): void
    {
        if (
            !$this->ownsScheduler
            && SchedulerSystem::isSchedulerActive()
            && \class_exists(\Fiber::class)
            && \Fiber::getCurrent() !== null
        ) {
            SchedulerSystem::yield();
            return;
        }

        \usleep($this->idleSleepMicroseconds());
    }
}
