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

    /** @var array<int, array{fiber: \Fiber, deadline: float}> */
    private array $waits = [];

    private bool $ownsScheduler = false;

    public function __construct(
        private readonly int $defaultConcurrency = self::DEFAULT_CONCURRENCY,
        private readonly bool $preserveContext = true
    ) {
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
        if ($concurrency <= 1 || !\class_exists(\Fiber::class) || SchedulerSystem::isSchedulerActive()) {
            return $this->runSequentially($tasks);
        }

        $contextSnapshot = $this->captureContextSnapshot();
        $this->startLocalScheduler();

        try {
            return $this->runWithFibers($tasks, $concurrency, $contextSnapshot);
        } finally {
            $this->stopLocalScheduler();
            $this->waits = [];
        }
    }

    public static function yield(mixed $value = null): mixed
    {
        if (!\class_exists(\Fiber::class) || \Fiber::getCurrent() === null) {
            return null;
        }

        return \Fiber::suspend($value);
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

            if (!$madeProgress && $running !== []) {
                \usleep($this->idleSleepMicroseconds());
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
}
