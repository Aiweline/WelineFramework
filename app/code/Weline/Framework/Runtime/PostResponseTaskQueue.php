<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Context;

final class PostResponseTaskQueue
{
    /** @var array<string, array{task: callable, context: ?WlsFiberContext}> */
    private static array $tasks = [];

    public static function enqueue(string $key, callable $task): void
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
        ];
    }

    public static function drain(float $budgetMs = 8.0): int
    {
        if (self::$tasks === []) {
            return 0;
        }

        $startedAt = \microtime(true);
        $processed = 0;
        foreach (\array_keys(self::$tasks) as $key) {
            $task = self::$tasks[$key] ?? null;
            unset(self::$tasks[$key]);
            if (!\is_array($task) || !\is_callable($task['task'] ?? null)) {
                continue;
            }

            try {
                $context = $task['context'] ?? null;
                if ($context instanceof WlsFiberContext) {
                    $context->restore(false);
                }
                ($task['task'])();
            } catch (\Throwable $throwable) {
                Env::log_error('runtime/post_response_task', $throwable->getMessage());
            } finally {
                $processed++;
                self::cleanupRestoredContext();
            }

            if ($budgetMs > 0 && ((\microtime(true) - $startedAt) * 1000) >= $budgetMs) {
                break;
            }
        }

        return $processed;
    }

    public static function pendingCount(): int
    {
        return \count(self::$tasks);
    }

    private static function cleanupRestoredContext(): void
    {
        try {
            StateManager::reset();
        } catch (\Throwable) {
        }

        try {
            Context::leave();
        } catch (\Throwable) {
        }
    }
}
