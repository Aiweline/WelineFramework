<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Framework\Runtime\FullPageCacheReclaimableAdapter;
use Weline\Framework\Runtime\MemoryReclaimableRegistry;
use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Worker-side host pressure application + reclaim accounting.
 */
final class WorkerHostPressureApplier
{
    private static string $hostLevel = 'green';
    private static int $reclaimBytes = 0;
    private static int $reclaimSkipCount = 0;
    private static ?MemoryReclaimableRegistry $registry = null;
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
            // Cooperative delay without blocking the event loop forever: best-effort usleep cap.
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
        $before = \memory_get_usage(true);
        $result = ['freed_bytes' => 0, 'skipped' => 0];
        if ($level === 'green') {
            return $result;
        }

        $compaction = WorkerResponseMemoryGuard::compact();
        $registry = self::registry();
        if ($level === 'yellow') {
            $result = $registry->compactAll();
        } elseif ($level === 'red') {
            $result = $registry->evictBytes(8 * 1048576, false);
            $compact = $registry->compactAll();
            $result['freed_bytes'] += (int)($compact['freed_bytes'] ?? 0);
            $result['skipped'] += (int)($compact['skipped'] ?? 0);
        } else { // critical
            $result = $registry->evictBytes(16 * 1048576, true);
            $compact = $registry->compactAll();
            $result['freed_bytes'] += (int)($compact['freed_bytes'] ?? 0);
            $result['skipped'] += (int)($compact['skipped'] ?? 0);
            // Ensure FPC last-resort even when estimate was zero.
            $fpc = $registry->evictBytes(1, true);
            $result['freed_bytes'] += (int)($fpc['freed_bytes'] ?? 0);
        }

        $after = \memory_get_usage(true);
        $observed = \max(0, $before - $after);
        $freed = \max((int)($result['freed_bytes'] ?? 0), $observed);
        if ($freed <= 0 && (int)($result['skipped'] ?? 0) > 0) {
            self::$reclaimSkipCount++;
        }
        self::$reclaimBytes += $freed;
        unset($compaction);

        return ['freed_bytes' => $freed, 'skipped' => (int)($result['skipped'] ?? 0)];
    }

    private static function registry(): MemoryReclaimableRegistry
    {
        if (self::$registry instanceof MemoryReclaimableRegistry) {
            return self::$registry;
        }
        $registry = new MemoryReclaimableRegistry();
        $registry->register(new FullPageCacheReclaimableAdapter());
        self::$registry = $registry;

        return self::$registry;
    }
}
