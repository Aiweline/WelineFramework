<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Single-flight gate for local/machine translation calls.
 *
 * Ollama often runs with -np 1: multiple PHP workers can connect, but only one
 * inference runs. Extra clients block in curl and look "stuck". This gate:
 * - allows one process into the model call at a time
 * - default fail-fast (no wait): timed cron jobs stop and let the next round
 *   pick up when the model is free — do not idle-spin competing workers
 * - optional maxWaitSeconds>0 still waits via SchedulerSystem (WLS Fiber /
 *   CLI native sleep) for callers that explicitly want a short retry window
 * - fails with AI_TRANSLATION_BUSY so queues exit without immediate requeue
 * - disconnects a stale/dead lock holder (SIGTERM) then returns BUSY for this
 *   round instead of spinning until the stuck process finishes
 *
 * Re-entrant within the same PHP process (batchTranslate → translate fallback).
 */
final class TranslationConcurrencyGate
{
    public const BUSY_MARKER = 'AI_TRANSLATION_BUSY';

    /** Default: try once; timed translation jobs must not idle-wait. */
    public const DEFAULT_WAIT_SECONDS = 0;

    /**
     * Max lock hold age before the holder is treated as stuck.
     * Keep >= TranslationService request timeout (+ small grace).
     */
    public const STALE_HOLD_SECONDS = 210;

    private const LOCK_SUBDIR = 'ai-translation';
    private const LOCK_FILE = 'machine-translate.lock';
    private const WAIT_SLICE_SECONDS = 1;

    private static int $depth = 0;

    /** @var resource|null */
    private static $handle = null;

    /**
     * @throws Exception when another worker still holds the lock after waiting
     */
    public function acquire(int $maxWaitSeconds = self::DEFAULT_WAIT_SECONDS): void
    {
        if (self::$depth > 0) {
            self::$depth++;

            return;
        }

        $maxWaitSeconds = max(0, min(120, $maxWaitSeconds));
        $directory = $this->lockDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception((string)__('无法创建 AI 翻译锁目录。'));
        }

        $handle = @fopen($this->lockPath(), 'c+');
        if ($handle === false) {
            throw new Exception((string)__('无法打开 AI 翻译锁文件。'));
        }

        $deadline = microtime(true) + $maxWaitSeconds;
        $staleDisconnectAttempted = false;
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                self::$handle = $handle;
                self::$depth = 1;
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'pid' => getmypid(),
                    'acquired_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
                fflush($handle);

                return;
            }

            // Stuck/dead holder: disconnect once, then fail this round (no idle wait).
            if (!$staleDisconnectAttempted && $this->disconnectStaleHolder()) {
                $staleDisconnectAttempted = true;
                fclose($handle);
                throw new Exception(
                    self::BUSY_MARKER . ': ' . (string)__('AI翻译繁忙：已断开僵死占用进程，请稍后重试。')
                );
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new Exception(
                    self::BUSY_MARKER . ': ' . (string)__('AI翻译繁忙：本地模型正被其他任务占用，请稍后重试。')
                );
            }

            // Environment-aware wait: WLS yields Fiber; FPM/CLI uses native sleep.
            // Never call PHP sleep/usleep directly in request-reachable module code.
            SchedulerSystem::sleep(self::WAIT_SLICE_SECONDS);
        }
    }

    public function release(): void
    {
        if (self::$depth <= 0) {
            return;
        }

        self::$depth--;
        if (self::$depth > 0) {
            return;
        }

        if (is_resource(self::$handle)) {
            @flock(self::$handle, LOCK_UN);
            @fclose(self::$handle);
        }
        self::$handle = null;
    }

    public function isBusyMarker(string $message): bool
    {
        return str_contains($message, self::BUSY_MARKER);
    }

    /**
     * Disconnect a dead or over-age lock holder so the next cron/queue round
     * can proceed. Does not wait for the holder to exit.
     */
    private function disconnectStaleHolder(): bool
    {
        $meta = $this->readLockMeta();
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
            // Process already gone; flock should free with it. Signal caller to stop
            // this round rather than spinning — next attempt after OS cleanup.
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
     * @return array{pid?:int|string,acquired_at?:string}|null
     */
    private function readLockMeta(): ?array
    {
        $path = $this->lockPath();
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

        // Best-effort without posix: treat unknown as alive to avoid false kills.
        return true;
    }

    private function lockDirectory(): string
    {
        return rtrim((string)BP, "\\/") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'lock'
            . DIRECTORY_SEPARATOR . self::LOCK_SUBDIR;
    }

    private function lockPath(): string
    {
        return $this->lockDirectory() . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }
}
