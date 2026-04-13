<?php
declare(strict_types=1);

namespace Weline\Server\Runtime;

final class WorkerFiberContextTracker
{
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

            $fiberData['context']->restore();
            return;
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $activeFibers
     * @param callable():mixed $captureContext
     * @return array<int|string, array<string, mixed>>
     */
    public static function capture(array $activeFibers, \Fiber $fiber, callable $captureContext, ?int $timestamp = null): array
    {
        if (!$fiber->isSuspended()) {
            return $activeFibers;
        }

        $capturedAt = $timestamp ?? \time();
        foreach ($activeFibers as $connectionId => $fiberData) {
            if (($fiberData['fiber'] ?? null) !== $fiber) {
                continue;
            }

            $fiberData['context'] = $captureContext();
            $fiberData['suspended_at'] = $capturedAt;
            $fiberData['last_activity'] = $capturedAt;
            $activeFibers[$connectionId] = $fiberData;
            break;
        }

        return $activeFibers;
    }
}
