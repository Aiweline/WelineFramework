<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Lock;

/**
 * Upgrade-priority intent sidecar for Setup ↔ Cron coordination.
 *
 * Presence alone is not "busy" — flock on setup_database_access.lock remains
 * authoritative. This file only signals that setup:upgrade wants exclusive
 * database access so Cron can stop claiming work and long tasks can yield.
 *
 * Bootstrap-safe: file I/O only; no Model / Cache / Event / Phrase Parser.
 */
final class SetupUpgradeIntent
{
    public const FILENAME = 'setup_upgrade_intent.json';

    /** Default EarlyDatabaseAccessGate exclusive wait (15 minutes). */
    public const DEFAULT_EXCLUSIVE_WAIT_MS = 900_000;

    private static ?int $testWaitMillisecondsOverride = null;

    public static function path(): string
    {
        return \BP . 'var' . DIRECTORY_SEPARATOR . 'process' . DIRECTORY_SEPARATOR . self::FILENAME;
    }

    /**
     * Test-only override for exclusive wait timeout (null restores default).
     */
    public static function setTestExclusiveWaitMilliseconds(?int $milliseconds): void
    {
        if ($milliseconds !== null && ($milliseconds < 1 || $milliseconds > 3_600_000)) {
            throw new \InvalidArgumentException('Exclusive wait override must be between 1 and 3600000 milliseconds.');
        }
        self::$testWaitMillisecondsOverride = $milliseconds;
    }

    public static function exclusiveWaitMilliseconds(): int
    {
        if (self::$testWaitMillisecondsOverride !== null) {
            return self::$testWaitMillisecondsOverride;
        }
        $env = \getenv('WELINE_SETUP_EXCLUSIVE_WAIT_MS');
        if (is_string($env) && $env !== '' && ctype_digit($env)) {
            $value = (int)$env;
            if ($value >= 1 && $value <= 3_600_000) {
                return $value;
            }
        }

        return self::DEFAULT_EXCLUSIVE_WAIT_MS;
    }

    /**
     * Try to become the sole intent owner. Returns true when this process owns
     * the intent after the call (including renewing same-pid ownership).
     */
    public static function publish(string $command = 'setup:upgrade'): bool
    {
        $command = trim($command) !== '' ? trim($command) : 'setup:upgrade';
        $pid = (int)(getmypid() ?: 0);
        if ($pid < 1) {
            return false;
        }

        self::reclaimIfOwnerDead();

        $path = self::path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create setup upgrade intent directory: ' . $directory);
        }

        $payload = self::encodePayload($pid, $command, microtime(true));

        $handle = @fopen($path, 'x+b');
        if (is_resource($handle)) {
            $ok = self::writeCompletePayload($handle, $payload);
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                return false;
            }

            return true;
        }

        $existing = self::readPayload();
        if ($existing === null) {
            // Lost race or unreadable — reclaim dead and retry create once.
            self::reclaimIfOwnerDead();
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                return self::isOwnedByCurrentProcess();
            }
            $ok = self::writeCompletePayload($handle, $payload);
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                return false;
            }

            return true;
        }

        if ((int)$existing['pid'] === $pid && self::isProcessAlive($pid)) {
            // Renew same-owner intent in place.
            return self::rewriteOwnedPayload($payload);
        }

        if (!self::isProcessAlive((int)$existing['pid'])) {
            @unlink($path);
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                return self::isOwnedByCurrentProcess();
            }
            $ok = self::writeCompletePayload($handle, $payload);
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                return false;
            }

            return true;
        }

        // Another live upgrade owns the intent — do not overwrite.
        return false;
    }

    public static function clearIfOwner(): void
    {
        $pid = (int)(getmypid() ?: 0);
        $existing = self::readPayload();
        if ($existing === null) {
            return;
        }
        if ((int)$existing['pid'] !== $pid) {
            return;
        }
        @unlink(self::path());
    }

    public static function isActive(): bool
    {
        self::reclaimIfOwnerDead();
        $existing = self::readPayload();
        if ($existing === null) {
            return false;
        }

        return self::isProcessAlive((int)$existing['pid']);
    }

    public static function shouldYield(): bool
    {
        return self::isActive();
    }

    public static function isOwnedByCurrentProcess(): bool
    {
        $pid = (int)(getmypid() ?: 0);
        $existing = self::readPayload();
        if ($existing === null || $pid < 1) {
            return false;
        }

        return (int)$existing['pid'] === $pid && self::isProcessAlive($pid);
    }

    /**
     * @return array{pid:int,started_at:float,command:string}|null
     */
    public static function readPayload(): ?array
    {
        $path = self::path();
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $pid = (int)($decoded['pid'] ?? 0);
        $startedAt = (float)($decoded['started_at'] ?? 0);
        $command = trim((string)($decoded['command'] ?? ''));
        if ($pid < 1 || $startedAt <= 0.0 || $command === '') {
            return null;
        }

        return [
            'pid' => $pid,
            'started_at' => $startedAt,
            'command' => $command,
        ];
    }

    private static function reclaimIfOwnerDead(): void
    {
        $existing = self::readPayload();
        if ($existing === null) {
            return;
        }
        if (!self::isProcessAlive((int)$existing['pid'])) {
            @unlink(self::path());
        }
    }

    private static function isProcessAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        // Windows / non-posix: best-effort via /proc or assume alive if file fresh.
        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        return true;
    }

    private static function encodePayload(int $pid, string $command, float $startedAt): string
    {
        $json = json_encode([
            'pid' => $pid,
            'started_at' => $startedAt,
            'command' => $command,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Unable to encode setup upgrade intent payload.');
        }

        return $json . "\n";
    }

    private static function rewriteOwnedPayload(string $payload): bool
    {
        $path = self::path();
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return false;
        }
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            return false;
        }
        $ok = @ftruncate($handle, 0) && self::writeCompletePayload($handle, $payload);
        @flock($handle, LOCK_UN);
        @fclose($handle);

        return $ok;
    }

    /** @param resource $handle */
    private static function writeCompletePayload($handle, string $payload): bool
    {
        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($handle, substr($payload, $written));
            if (!is_int($chunk) || $chunk < 1) {
                return false;
            }
            $written += $chunk;
        }

        return $written === $length && @fflush($handle);
    }

    private function __construct()
    {
    }
}
