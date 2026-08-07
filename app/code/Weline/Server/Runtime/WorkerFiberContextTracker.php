<?php
declare(strict_types=1);

namespace Weline\Server\Runtime;

final class WorkerFiberContextTracker
{
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * @param array<int|string, array<string, mixed>> $activeFibers
     */
    public static function restore(array $activeFibers, \Fiber $fiber): void
    {
        foreach ($activeFibers as $fiberData) {
            if (($fiberData['fiber'] ?? null) !== $fiber) {
                continue;
            }
            if (!isset($fiberData['context'])) {
                return;
            }

            $fiberData['context']->restoreForFiber($fiber);
            return;
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $activeFibers
     * @param callable(\Fiber):mixed $captureContext
     * @return array<int|string, array<string, mixed>>
     */
    public static function capture(
        array $activeFibers,
        \Fiber $fiber,
        callable $captureContext,
        ?int $timestamp = null,
        int|float|null $monotonicTimestampNs = null,
    ): array
    {
        if (!$fiber->isSuspended()) {
            return $activeFibers;
        }

        $capturedAt = $timestamp ?? \time();
        $capturedAtMonotonicNs = $monotonicTimestampNs ?? self::monotonicNowNs();
        foreach ($activeFibers as $connectionId => $fiberData) {
            if (($fiberData['fiber'] ?? null) !== $fiber) {
                continue;
            }

            $fiberData['context'] = $captureContext($fiber);
            $fiberData['suspended_at'] = $capturedAt;
            $fiberData['last_activity'] = $capturedAt;
            $fiberData['suspended_at_monotonic_ns'] = $capturedAtMonotonicNs;
            $fiberData['last_activity_monotonic_ns'] = $capturedAtMonotonicNs;
            $activeFibers[$connectionId] = $fiberData;
            break;
        }

        return $activeFibers;
    }

    public static function monotonicNowNs(): int|float
    {
        return \hrtime(true);
    }

    public static function deadlineAfterSeconds(
        float $seconds,
        int|float|null $nowMonotonicNs = null,
    ): int|float
    {
        $now = $nowMonotonicNs ?? self::monotonicNowNs();
        $durationNs = \round(\max(0.0, $seconds) * self::NANOSECONDS_PER_SECOND);
        if (!\is_finite($durationNs)) {
            return \is_int($now) ? \PHP_INT_MAX : \PHP_FLOAT_MAX;
        }
        if (\is_int($now)) {
            if ($durationNs > \PHP_INT_MAX || (int)$durationNs > \PHP_INT_MAX - $now) {
                return \PHP_INT_MAX;
            }

            return $now + (int)$durationNs;
        }

        return $now + (float)$durationNs;
    }

    public static function deadlineReached(
        int|float $deadlineMonotonicNs,
        int|float|null $nowMonotonicNs = null,
    ): bool
    {
        return ($nowMonotonicNs ?? self::monotonicNowNs()) >= $deadlineMonotonicNs;
    }

    public static function monotonicElapsedSeconds(
        int|float $startedMonotonicNs,
        int|float|null $nowMonotonicNs = null,
    ): float
    {
        $now = $nowMonotonicNs ?? self::monotonicNowNs();

        return \max(0, $now - $startedMonotonicNs) / self::NANOSECONDS_PER_SECOND;
    }

    public static function normalizeMonotonicStartSeconds(mixed $startedAt, float $finishedAt): float
    {
        if (!\is_finite($finishedAt) || $finishedAt <= 0.0) {
            return 0.0;
        }
        if ((!\is_int($startedAt) && !\is_float($startedAt))
            || !\is_finite((float)$startedAt)
            || (float)$startedAt <= 0.0
            || (float)$startedAt > $finishedAt
        ) {
            return $finishedAt;
        }

        return (float)$startedAt;
    }

    /**
     * Decide idle retirement in one clock domain. Wall timestamps stay in the
     * state for audit compatibility but are deliberately ignored here.
     *
     * @param array<string,mixed> $fiberData
     * @return array{release:bool,reason:string,inactive_seconds:float,suspended_seconds:float}
     */
    public static function idleReleaseDecision(
        array $fiberData,
        int|float $nowMonotonicNs,
        int $heartbeatTimeoutSeconds,
        int $idleTtlSeconds,
    ): array
    {
        $isLongLived = ($fiberData['is_long_lived'] ?? false) === true;
        $lastActivityNs = self::validMonotonicTimestamp(
            $fiberData['last_activity_monotonic_ns'] ?? null,
            $nowMonotonicNs,
        );
        $suspendedAtNs = self::validMonotonicTimestamp(
            $fiberData['suspended_at_monotonic_ns'] ?? null,
            $lastActivityNs,
        );
        $inactiveSeconds = (float)(\max(0, $nowMonotonicNs - $lastActivityNs) / self::NANOSECONDS_PER_SECOND);
        $suspendedSeconds = (float)(\max(0, $nowMonotonicNs - $suspendedAtNs) / self::NANOSECONDS_PER_SECOND);

        if ($isLongLived) {
            return [
                'release' => false,
                'reason' => 'long_lived',
                'inactive_seconds' => $inactiveSeconds,
                'suspended_seconds' => $suspendedSeconds,
            ];
        }
        if ($heartbeatTimeoutSeconds > 0 && $inactiveSeconds >= $heartbeatTimeoutSeconds) {
            return [
                'release' => true,
                'reason' => 'heartbeat_timeout',
                'inactive_seconds' => $inactiveSeconds,
                'suspended_seconds' => $suspendedSeconds,
            ];
        }
        if ($idleTtlSeconds > 0 && $suspendedSeconds >= $idleTtlSeconds) {
            return [
                'release' => true,
                'reason' => 'idle_timeout',
                'inactive_seconds' => $inactiveSeconds,
                'suspended_seconds' => $suspendedSeconds,
            ];
        }

        return [
            'release' => false,
            'reason' => 'active',
            'inactive_seconds' => $inactiveSeconds,
            'suspended_seconds' => $suspendedSeconds,
        ];
    }

    private static function validMonotonicTimestamp(mixed $value, int|float $fallback): int|float
    {
        if ((!\is_int($value) && !\is_float($value))
            || !\is_finite((float)$value)
            || $value <= 0
        ) {
            return $fallback;
        }

        return $value;
    }
}
