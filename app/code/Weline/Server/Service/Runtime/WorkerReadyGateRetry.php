<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Database\Exception\DatabaseRetryTimeoutException;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Bounded retry for transient database contention during READY-gate warmup.
 */
final class WorkerReadyGateRetry
{
    private const MAX_ATTEMPTS = 5;
    private const BASE_DELAY_MICROSECONDS = 100_000;
    private const MAX_DELAY_MICROSECONDS = 800_000;

    /**
     * @template TResult
     * @param callable():TResult $operation
     * @param null|callable(int,\Throwable,int):void $onRetry
     * @param null|callable(int):void $wait
     * @return TResult
     */
    public static function run(
        callable $operation,
        int $workerId,
        ?callable $onRetry = null,
        ?callable $wait = null,
    ): mixed {
        $wait ??= static fn (int $microseconds): mixed
            => SchedulerSystem::usleep($microseconds);
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $throwable) {
                if (!self::isTransientDatabaseContention($throwable)
                    || $attempt >= self::MAX_ATTEMPTS
                ) {
                    throw $throwable;
                }
                $delay = \min(
                    self::MAX_DELAY_MICROSECONDS,
                    self::BASE_DELAY_MICROSECONDS * $attempt
                        + (\max(0, $workerId) % 8) * 10_000,
                );
                $onRetry?->__invoke($attempt, $throwable, $delay);
                $wait($delay);
            }
        }

        throw new \LogicException('READY-gate retry loop exhausted without a result.');
    }

    public static function isTransientDatabaseContention(\Throwable $throwable): bool
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DatabaseRetryTimeoutException) {
                return true;
            }
            $message = \strtolower($current->getMessage());
            if (\str_contains($message, 'database is locked')
                || \str_contains($message, 'database busy')
                || \str_contains($message, '数据库繁忙重试失败')
            ) {
                return true;
            }
        }
        return false;
    }
}
