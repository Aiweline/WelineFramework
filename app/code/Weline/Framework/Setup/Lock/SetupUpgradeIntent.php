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

    private const CORRUPT_RECLAIM_AFTER_SEC = 60;
    private const STALE_SIBLING_RETENTION_SEC = 3600;

    private static ?int $testWaitMillisecondsOverride = null;

    public static function path(): string
    {
        return \VAR_DIR . 'process' . DIRECTORY_SEPARATOR . self::FILENAME;
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
            if (is_file($path)) {
                self::retireFile($path);
            }
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

        if ((int)$existing['pid'] === $pid && self::isProcessAlive($pid, (float)$existing['started_at'])) {
            // Renew same-owner intent in place.
            return self::rewriteOwnedPayload($payload);
        }

        if (!self::isProcessAlive((int)$existing['pid'], (float)$existing['started_at'])) {
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

        return self::isProcessAlive((int)$existing['pid'], (float)$existing['started_at']);
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

        return (int)$existing['pid'] === $pid && self::isProcessAlive($pid, (float)$existing['started_at']);
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
        $path = self::path();
        if (!is_file($path)) {
            self::cleanupStaleSiblings();
            return;
        }
        $existing = self::readPayload();
        if ($existing === null) {
            $mtime = @filemtime($path);
            if (is_int($mtime) && time() - $mtime > self::CORRUPT_RECLAIM_AFTER_SEC) {
                self::retireFile($path);
            }
            self::cleanupStaleSiblings();
            return;
        }
        if (!self::isProcessAlive((int)$existing['pid'], (float)$existing['started_at'])) {
            self::retireFile($path);
        }
        self::cleanupStaleSiblings();
    }

    private static function retireFile(string $path): void
    {
        $target = $path . '.stale.' . time() . '.' . getmypid();
        if (!@rename($path, $target)) {
            @unlink($path);
        }
    }

    private static function cleanupStaleSiblings(): void
    {
        $paths = glob(self::path() . '.stale.*') ?: [];
        foreach ($paths as $path) {
            $mtime = @filemtime($path);
            if (is_int($mtime) && time() - $mtime > self::STALE_SIBLING_RETENTION_SEC) {
                @unlink($path);
            }
        }
    }

    private static function processExists(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            return self::processExistsViaPs($pid) || self::processExistsViaTasklist($pid) || is_dir('/proc/' . $pid);
        }

        return self::processExistsViaTasklist($pid);
    }

    private static function processStartedAt(int $pid): ?float
    {
        if ($pid < 1) {
            return null;
        }
        if (is_dir('/proc/' . $pid)) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if (!is_string($stat)) {
                return null;
            }
            $close = strrpos($stat, ')');
            if ($close === false) {
                return null;
            }
            $fields = explode(' ', substr($stat, $close + 2));
            if (!isset($fields[19]) || (float)$fields[19] <= 0.0) {
                return null;
            }
            $boot = @file_get_contents('/proc/stat');
            if (!is_string($boot) || !preg_match('/^btime\s+(\d+)/m', $boot, $matches)) {
                return null;
            }
            $hz = 100.0;
            if (\function_exists('shell_exec')) {
                $clockTick = @shell_exec('getconf CLK_TCK');
                if (is_string($clockTick) && (float)trim($clockTick) > 0.0) {
                    $hz = (float)trim($clockTick);
                }
            }

            return (float)$matches[1] + ((float)$fields[19] / $hz);
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            if (!\function_exists('exec')) {
                return null;
            }
            $out = [];
            exec('ps -p ' . $pid . ' -o etime= 2>/dev/null', $out, $code);
            if ($code !== 0 || $out === []) {
                return null;
            }
            $elapsed = self::parsePsElapsed(trim(implode('', $out)));
            if ($elapsed === null) {
                return null;
            }

            return (float)(time() - $elapsed);
        }

        return null;
    }

    private static function parsePsElapsed(string $text): ?int
    {
        if ($text === '') {
            return null;
        }
        $days = 0;
        if (str_contains($text, '-')) {
            [$dayPart, $text] = explode('-', $text, 2);
            $days = (int)$dayPart;
        }
        $parts = array_map('intval', explode(':', $text));
        if (count($parts) === 3) {
            [$hours, $minutes, $seconds] = $parts;
        } elseif (count($parts) === 2) {
            $hours = 0;
            [$minutes, $seconds] = $parts;
        } else {
            return null;
        }

        return $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;
    }

    private static function isProcessAlive(int $pid, float $startedAt = 0.0): bool
    {
        if (!self::processExists($pid)) {
            return false;
        }
        if ($startedAt > 0.0) {
            $procStart = self::processStartedAt($pid);
            if ($procStart !== null && $procStart > $startedAt + 2.0) {
                return false;
            }
        }

        return true;
    }

    private static function processExistsViaPs(int $pid): bool
    {
        if (!\function_exists('exec')) {
            return true;
        }
        exec('ps -p ' . $pid . ' -o pid= 2>/dev/null', $output, $code);
        if ($code === 0 && trim(implode('', $output)) !== '') {
            return true;
        }

        exec('launchctl print pid/' . $pid . ' 2>/dev/null', $launchctlOutput, $launchctlCode);

        if ($launchctlCode !== 0 || trim(implode('', $launchctlOutput)) === '') {
            return false;
        }

        return !str_contains(implode('', $launchctlOutput), 'properties = slain');
    }

    private static function processExistsViaTasklist(int $pid): bool
    {
        if (!\function_exists('exec')) {
            return true;
        }
        exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $output, $code);

        return $code === 0 && str_contains(implode('', $output), (string)$pid);
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
