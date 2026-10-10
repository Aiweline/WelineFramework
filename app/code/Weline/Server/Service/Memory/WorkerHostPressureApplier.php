<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Worker-side host pressure application + reclaim accounting.
 * All reclaim goes through Guard::compactForHostLevel (shared Facade).
 */
final class WorkerHostPressureApplier
{
    private static string $hostLevel = 'green';
    private static int $reclaimBytes = 0;
    private static int $reclaimSkipCount = 0;
    private static bool $skipKeepWarm = false;

    public static function apply(string $level, int $staggerMs = 0): void
    {
        $level = \strtolower(\trim($level));
        if (!\in_array($level, ['green', 'yellow', 'red', 'critical'], true)) {
            return;
        }
        self::$hostLevel = $level;
        self::$skipKeepWarm = \in_array($level, ['critical'], true);
        if ($staggerMs > 0) {
            $sleepUs = \min(500000, \max(0, $staggerMs) * 1000);
            if ($sleepUs > 0) {
                \usleep($sleepUs);
            }
        }
        self::reclaimForLevel($level);
    }

    public static function getHostLevel(): string
    {
        return self::$hostLevel;
    }

    public static function shouldSkipKeepWarm(): bool
    {
        return self::$skipKeepWarm;
    }

    public static function consumeReclaimBytes(): int
    {
        $bytes = self::$reclaimBytes;
        self::$reclaimBytes = 0;
        return $bytes;
    }

    public static function getReclaimSkipCount(): int
    {
        return self::$reclaimSkipCount;
    }

    public static function reclaimForLevel(string $level): array
    {
        if ($level === 'green') {
            return ['freed_bytes' => 0, 'skipped' => 0];
        }

        $result = WorkerResponseMemoryGuard::compactForHostLevel($level);
        $freed = (int)($result['freed_bytes'] ?? 0);
        $skipped = (int)($result['skipped'] ?? 0);
        if ($freed <= 0 && $skipped > 0) {
            self::$reclaimSkipCount++;
        }
        self::$reclaimBytes += $freed;

        return ['freed_bytes' => $freed, 'skipped' => $skipped];
    }
}
