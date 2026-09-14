<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Exception\TranslationBusyException;
use Weline\Framework\App\Exception;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Single-flight gate for local/machine translation calls — **per product lane**.
 *
 * Why lanes: dictionary / LocalModel / Meta / FileManager / docs are different
 * queues. Sharing one flock let an idle or empty type hold the only channel and
 * starve types that still had words (AI_TRANSLATION_BUSY).
 *
 * Rules:
 * - one process per lane at a time (same-lane single-flight)
 * - different lanes never block each other at the app lock layer
 * - callers must not acquire when there is no non-empty text to send
 * - default fail-fast (no wait); BUSY → exit round, next cron continues
 * - stale/dead holder: SIGTERM once, then BUSY this round
 *
 * Re-entrant within the same PHP process and same lane.
 */
final class TranslationConcurrencyGate
{
    public const BUSY_MARKER = 'AI_TRANSLATION_BUSY';

    /** @deprecated Prefer LANE_DICTIONARY; kept as alias for older callers. */
    public const LANE_DEFAULT = 'dictionary';

    public const LANE_DICTIONARY = 'dictionary';
    public const LANE_LOCAL_MODEL = 'local-model';
    public const LANE_META = 'meta';
    public const LANE_FILE_ASSET = 'file-asset';
    public const LANE_DOCUMENT = 'document';
    public const LANE_EAV = 'eav';
    public const LANE_INQUIRY = 'inquiry';
    public const LANE_BLOG = 'blog';
    public const LANE_TAGLIB = 'taglib';

    /** Default: try once; timed translation jobs must not idle-wait. */
    public const DEFAULT_WAIT_SECONDS = 0;

    /**
     * Max lock hold age before the holder is treated as stuck.
     * Keep >= TranslationService request timeout (+ small grace).
     */
    public const STALE_HOLD_SECONDS = 960;

    private const LOCK_SUBDIR = 'ai-translation';
    private const WAIT_SLICE_SECONDS = 1;

    /** Legacy shared lock file → dictionary lane (migration). */
    private const LEGACY_DEFAULT_LOCK = 'machine-translate.lock';

    /** @var array<string, int> */
    private static array $depthByLane = [];

    /** @var array<string, resource> */
    private static array $handleByLane = [];

    /**
     * @throws TranslationBusyException when another worker still holds the lane lock after waiting
     * @throws Exception when the lock directory/file cannot be created or opened
     */
    public function acquire(
        int $maxWaitSeconds = self::DEFAULT_WAIT_SECONDS,
        string $lane = self::LANE_DICTIONARY,
    ): void {
        $lane = $this->normalizeLane($lane);
        $depth = self::$depthByLane[$lane] ?? 0;
        if ($depth > 0) {
            self::$depthByLane[$lane] = $depth + 1;

            return;
        }

        $maxWaitSeconds = max(0, min(120, $maxWaitSeconds));
        $directory = $this->lockDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception((string)__('无法创建 AI 翻译锁目录。'));
        }

        $handle = @fopen($this->lockPath($lane), 'c+');
        if ($handle === false) {
            throw new Exception((string)__('无法打开 AI 翻译锁文件。'));
        }

        $deadline = microtime(true) + $maxWaitSeconds;
        $staleDisconnectAttempted = false;
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                self::$handleByLane[$lane] = $handle;
                self::$depthByLane[$lane] = 1;
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'pid' => getmypid(),
                    'lane' => $lane,
                    'acquired_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
                fflush($handle);

                return;
            }

            if (!$staleDisconnectAttempted && $this->disconnectStaleHolder($lane)) {
                // Dead/stale holder: flock should free (or SIGTERM soon). Retry
                // instead of throwing BUSY so callers do not stall on fake occupancy.
                $staleDisconnectAttempted = true;
                continue;
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);
                // RuntimeException: do not auto-write exception.log on construct.
                throw new TranslationBusyException(
                    self::BUSY_MARKER . ': ' . (string)__('AI翻译繁忙：本地模型正被其他任务占用，请稍后重试。')
                );
            }

            SchedulerSystem::sleep(self::WAIT_SLICE_SECONDS);
        }
    }

    public function release(string $lane = self::LANE_DICTIONARY): void
    {
        $lane = $this->normalizeLane($lane);
        $depth = self::$depthByLane[$lane] ?? 0;
        if ($depth <= 0) {
            return;
        }

        $depth--;
        self::$depthByLane[$lane] = $depth;
        if ($depth > 0) {
            return;
        }

        $handle = self::$handleByLane[$lane] ?? null;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        unset(self::$handleByLane[$lane], self::$depthByLane[$lane]);
    }

    public function isBusyMarker(string $message): bool
    {
        return str_contains($message, self::BUSY_MARKER);
    }

    public function isBusy(\Throwable $throwable): bool
    {
        return $throwable instanceof TranslationBusyException
            || $this->isBusyMarker($throwable->getMessage());
    }

    private function disconnectStaleHolder(string $lane): bool
    {
        $meta = $this->readLockMeta($lane);
        if ($meta === null) {
            return false;
        }

        $pid = (int)($meta['pid'] ?? 0);
        $age = $this->holdAgeSeconds((string)($meta['acquired_at'] ?? ''));
        if ($pid <= 0 || $pid === getmypid()) {
            return false;
        }

        $alive = $this->processExists($pid);
        $stale = $age !== null && $age >= self::STALE_HOLD_SECONDS;
        if (!$alive) {
            return true;
        }
        if (!$stale) {
            return false;
        }

        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, \defined('SIGTERM') ? \SIGTERM : 15);
        }

        return true;
    }

    /**
     * @return array{pid?:int|string,lane?:string,acquired_at?:string}|null
     */
    private function readLockMeta(string $lane): ?array
    {
        $path = $this->lockPath($lane);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function holdAgeSeconds(string $acquiredAt): ?int
    {
        $acquiredAt = trim($acquiredAt);
        if ($acquiredAt === '') {
            return null;
        }
        $ts = strtotime($acquiredAt);
        if ($ts === false) {
            return null;
        }

        return max(0, time() - $ts);
    }

    private function processExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }

        return true;
    }

    private function normalizeLane(string $lane): string
    {
        $lane = strtolower(trim($lane));
        // Historical shared lock name → dictionary lane.
        if ($lane === '' || $lane === 'machine' || $lane === 'default') {
            return self::LANE_DICTIONARY;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $lane)) {
            return self::LANE_DICTIONARY;
        }

        return $lane;
    }

    private function lockDirectory(): string
    {
        return rtrim((string)BP, "\\/") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'lock'
            . DIRECTORY_SEPARATOR . self::LOCK_SUBDIR;
    }

    private function lockPath(string $lane): string
    {
        // Keep a stable file for dictionary; other lanes use "{lane}-translate.lock".
        $file = $lane === self::LANE_DICTIONARY
            ? self::LEGACY_DEFAULT_LOCK
            : $lane . '-translate.lock';

        return $this->lockDirectory() . DIRECTORY_SEPARATOR . $file;
    }
}
