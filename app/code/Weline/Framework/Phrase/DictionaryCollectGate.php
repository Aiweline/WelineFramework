<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Exception;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Static single-flight gate for dictionary collect / DictionaryCompiler::compile.
 *
 * Rules:
 * - one process system-wide at a time (shared generated/language + heavy CSV merge)
 * - same PHP process re-entrant (depth counter)
 * - fail-fast by default (no wait); BUSY → refuse duplicate launch
 * - dead/stale holder: SIGTERM once, then retry flock once before BUSY
 */
final class DictionaryCollectGate
{
    public const BUSY_MARKER = DictionaryCollectBusyException::BUSY_MARKER;

    /** Default: try once; stacked Agent CLI must not idle-wait on each other. */
    public const DEFAULT_WAIT_SECONDS = 0;

    /**
     * Max lock hold age before the holder is treated as stuck.
     * Collect can run 30–60+ minutes on large trees.
     */
    public const STALE_HOLD_SECONDS = 7200;

    private const LOCK_SUBDIR = 'i18n';
    private const LOCK_FILE = 'dictionary-collect.lock';
    private const WAIT_SLICE_SECONDS = 1;
    private const SCOPE_ALL = '__all__';

    private static int $depth = 0;

    /** @var resource|null */
    private static $handle = null;

    private static string $heldScope = self::SCOPE_ALL;

    /**
     * @throws DictionaryCollectBusyException when another collect still holds the lock
     * @throws Exception when the lock directory/file cannot be created or opened
     */
    public static function acquire(?string $moduleName = null, int $maxWaitSeconds = self::DEFAULT_WAIT_SECONDS): void
    {
        $scope = self::normalizeScope($moduleName);
        if (self::$depth > 0) {
            self::$depth++;

            return;
        }

        $maxWaitSeconds = max(0, min(120, $maxWaitSeconds));
        $directory = self::lockDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception((string)__('无法创建词典收集锁目录。'));
        }

        $handle = @fopen(self::lockPath(), 'c+');
        if ($handle === false) {
            throw new Exception((string)__('无法打开词典收集锁文件。'));
        }

        $deadline = microtime(true) + $maxWaitSeconds;
        $staleDisconnectAttempted = false;
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                self::$handle = $handle;
                self::$depth = 1;
                self::$heldScope = $scope;
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'pid' => getmypid(),
                    'scope' => $scope,
                    'acquired_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
                fflush($handle);

                return;
            }

            if (!$staleDisconnectAttempted && self::disconnectStaleHolder()) {
                // Dead/stale holder: flock should free (or SIGTERM soon). Retry
                // instead of throwing BUSY so callers do not stall on fake occupancy.
                $staleDisconnectAttempted = true;
                continue;
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);
                $holder = self::formatHolderHint();
                throw new DictionaryCollectBusyException(
                    self::BUSY_MARKER . ': ' . (string)__(
                        '词典收集进行中%{1}，拒绝重复拉起。请等待当前任务结束后再执行。',
                        [$holder],
                    ),
                );
            }

            SchedulerSystem::sleep(self::WAIT_SLICE_SECONDS);
        }
    }

    public static function release(): void
    {
        if (self::$depth <= 0) {
            return;
        }

        self::$depth--;
        if (self::$depth > 0) {
            return;
        }

        $handle = self::$handle;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        self::$handle = null;
        self::$heldScope = self::SCOPE_ALL;
    }

    public static function isBusyMarker(string $message): bool
    {
        return str_contains($message, self::BUSY_MARKER);
    }

    public static function isBusy(\Throwable $throwable): bool
    {
        return $throwable instanceof DictionaryCollectBusyException
            || self::isBusyMarker($throwable->getMessage());
    }

    /** @internal testing / diagnostics */
    public static function heldScope(): string
    {
        return self::$heldScope;
    }

    /** @internal testing — reset in-process state without touching flock of other processes */
    public static function resetProcessStateForTests(): void
    {
        if (is_resource(self::$handle)) {
            @flock(self::$handle, LOCK_UN);
            @fclose(self::$handle);
        }
        self::$handle = null;
        self::$depth = 0;
        self::$heldScope = self::SCOPE_ALL;
    }

    private static function disconnectStaleHolder(): bool
    {
        $meta = self::readLockMeta();
        if ($meta === null) {
            return false;
        }

        $pid = (int)($meta['pid'] ?? 0);
        $age = self::holdAgeSeconds((string)($meta['acquired_at'] ?? ''));
        if ($pid <= 0 || $pid === getmypid()) {
            return false;
        }

        $alive = self::processExists($pid);
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
     * @return array{pid?:int|string,scope?:string,acquired_at?:string}|null
     */
    private static function readLockMeta(): ?array
    {
        $path = self::lockPath();
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

    private static function formatHolderHint(): string
    {
        $meta = self::readLockMeta();
        if ($meta === null) {
            return '';
        }
        $pid = (int)($meta['pid'] ?? 0);
        $scope = (string)($meta['scope'] ?? self::SCOPE_ALL);
        $scopeLabel = $scope === self::SCOPE_ALL ? (string)__('全部模块') : $scope;
        $parts = [];
        if ($pid > 0) {
            $parts[] = 'pid=' . $pid;
        }
        $parts[] = 'scope=' . $scopeLabel;

        return ' (' . implode(', ', $parts) . ')';
    }

    private static function holdAgeSeconds(string $acquiredAt): ?int
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

    private static function processExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }

        return true;
    }

    private static function normalizeScope(?string $moduleName): string
    {
        $moduleName = is_string($moduleName) ? trim($moduleName) : '';
        if ($moduleName === '') {
            return self::SCOPE_ALL;
        }
        if (!preg_match('/^[A-Za-z0-9_\\\\-]{1,128}$/', $moduleName)) {
            return self::SCOPE_ALL;
        }

        return $moduleName;
    }

    private static function lockDirectory(): string
    {
        $override = getenv('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, "\\/");
        }

        return rtrim((string)\BP, "\\/") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'lock'
            . DIRECTORY_SEPARATOR . self::LOCK_SUBDIR;
    }

    private static function lockPath(): string
    {
        return self::lockDirectory() . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }
}
