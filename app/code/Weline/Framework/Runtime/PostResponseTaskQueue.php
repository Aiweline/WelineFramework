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
        $memoryBeforeCapture = \memory_get_usage(true);
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
        if ((string)\getenv('WELINE_DIAG_MEMORY') === '1') {
            \error_log('[MemoryProbe] ' . \json_encode([
                'component' => 'PostResponseTaskQueue',
                'phase' => 'enqueue',
                'key' => \substr($key, 0, 120),
                'pending' => \count(self::$tasks),
                'context_captured' => $context instanceof WlsFiberContext,
                'capture_delta' => \memory_get_usage(true) - $memoryBeforeCapture,
                'usage' => \memory_get_usage(true),
                'peak' => \memory_get_peak_usage(true),
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
    }

    public static function drain(float $budgetMs = 8.0, ?int $maxTasks = null): int
    {
        if (self::$tasks === [] || self::$draining) {
            return 0;
        }

        self::$draining = true;
        try {
            self::memoryProbe('drain_start', null, null);
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
                    self::memoryProbe('task_start', $key, \count(self::$tasks));
                    $context = $task['context'] ?? null;
                    if ($context instanceof WlsFiberContext) {
                        $context->restore(false);
                    }
                    $taskStartedAt = \microtime(true);
                    ($task['task'])();
                    $taskElapsedMs = (\microtime(true) - $taskStartedAt) * 1000;
                    self::memoryProbe('task_after_callable', $key, \count(self::$tasks));
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
                    self::memoryProbe('task_after_cleanup', $key, \count(self::$tasks));
                }

                if ($processed >= $taskLimit) {
                    break;
                }

                if ($budgetMs > 0 && ((\microtime(true) - $startedAt) * 1000) >= $budgetMs) {
                    break;
                }
            }

            self::memoryProbe('drain_end', null, \count(self::$tasks));
            return $processed;
        } finally {
            self::$draining = false;
        }
    }

    private static function memoryProbe(string $phase, ?string $key, ?int $pending): void
    {
        if ((string)\getenv('WELINE_DIAG_MEMORY') !== '1') {
            return;
        }

        \error_log('[MemoryProbe] ' . \json_encode([
            'component' => 'PostResponseTaskQueue',
            'phase' => $phase,
            'key' => $key === null ? null : \substr($key, 0, 120),
            'pending' => $pending,
            'usage' => \memory_get_usage(true),
            'peak' => \memory_get_peak_usage(true),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    public static function pendingCount(): int
    {
        return \count(self::$tasks);
    }
    /**
     * 只读汇总到期状态，不暴露任务键、回调或请求上下文。
     *
     * next_due_ms：空队列为 null；已有到期任务为 0；否则为最近到期的剩余毫秒。
     * @return array{pending:int,ready:int,delayed:int,next_due_ms:?float,draining:bool}
     */
    public static function getRuntimeDiagnostics(): array
    {
        $now = \microtime(true);
        $ready = 0;
        $delayed = 0;
        $nextDue = null;
        foreach (self::$tasks as $task) {
            $notBefore = (float)($task['not_before'] ?? 0.0);
            if ($notBefore > $now) {
                $delayed++;
            } else {
                $ready++;
            }
            $nextDue = $nextDue === null ? $notBefore : \min($nextDue, $notBefore);
        }

        return [
            'pending' => \count(self::$tasks),
            'ready' => $ready,
            'delayed' => $delayed,
            'next_due_ms' => $nextDue === null ? null : \round(\max(0.0, ($nextDue - $now) * 1000), 3),
            'draining' => self::$draining,
        ];
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
