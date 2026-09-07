<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Context;

final class PostResponseTaskQueue
{
    /** @var array<string, array{task: callable, context: ?WlsFiberContext, not_before: float}> */
    private static array $tasks = [];
    private static bool $draining = false;

    public static function enqueue(string $key, callable $task, float $notBefore = 0.0): void
    {
        if ($key === '' || isset(self::$tasks[$key])) {
            return;
        }

        $context = null;
        if (Runtime::isPersistent()) {
            try {
                $context = WlsFiberContext::capture();
            } catch (\Throwable) {
                $context = null;
            }
        }

        self::$tasks[$key] = [
            'task' => $task,
            'context' => $context,
            'not_before' => max(0.0, $notBefore),
        ];
    }

    public static function drain(float $budgetMs = 8.0, ?int $maxTasks = null): int
    {
        if (self::$tasks === [] || self::$draining) {
            return 0;
        }

        self::$draining = true;
        try {
            $startedAt = \microtime(true);
            $slowTaskMs = (float)(Env::get('wls.post_response_task_slow_ms', \max(25.0, $budgetMs)) ?: \max(25.0, $budgetMs));
            $taskLimit = $maxTasks === null ? PHP_INT_MAX : \max(1, $maxTasks);
            $processed = 0;
            foreach (\array_keys(self::$tasks) as $key) {
                $task = self::$tasks[$key] ?? null;
                if (\is_array($task) && (float)($task['not_before'] ?? 0.0) > \microtime(true)) {
                    continue;
                }
                unset(self::$tasks[$key]);
                if (!\is_array($task) || !\is_callable($task['task'] ?? null)) {
                    continue;
                }

                try {
                    $context = $task['context'] ?? null;
                    if ($context instanceof WlsFiberContext) {
                        $context->restore(false);
                    }
                    $taskStartedAt = \microtime(true);
                    ($task['task'])();
                    $taskElapsedMs = (\microtime(true) - $taskStartedAt) * 1000;
                    if ($slowTaskMs > 0 && $taskElapsedMs >= $slowTaskMs) {
                        Env::log_warning(
                            'runtime/post_response_task',
                            'Slow post-response task: key=' . self::formatTaskKey($key)
                            . ' elapsed_ms=' . \round($taskElapsedMs, 2)
                        );
                    }
                } catch (\Throwable $throwable) {
                    Env::log_error('runtime/post_response_task', $throwable->getMessage());
                } finally {
                    $processed++;
                    self::cleanupRestoredContext();
                }

                if ($processed >= $taskLimit) {
                    break;
                }

                if ($budgetMs > 0 && ((\microtime(true) - $startedAt) * 1000) >= $budgetMs) {
                    break;
                }
            }

            return $processed;
        } finally {
            self::$draining = false;
        }
    }

    public static function pendingCount(): int
    {
        return \count(self::$tasks);
    }

    /**
     * Report whether the queue is currently executing post-response work.
     *
     * Cache writers use this to execute a refresh write immediately while a
     * deferred task is draining, instead of enqueueing the same write again.
     */
    public static function isDraining(): bool
    {
        return self::$draining;
    }

    private static function formatTaskKey(string $key): string
    {
        if (\strlen($key) <= 160) {
            return $key;
        }

        return \substr($key, 0, 157) . '...';
    }

    private static function cleanupRestoredContext(): void
    {
        $failures = [];
        try {
            StateManager::reset();
        } catch (\Throwable $e) {
            RequestResetException::append($failures, 'state_manager', $e);
        }

        try {
            Context::leave();
        } catch (\Throwable $e) {
            RequestResetException::append($failures, 'context_leave', $e);
        }

        if ($failures !== []) {
            throw new RequestResetException('post_response_task', $failures);
        }
    }
}
