<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Short source reads and complete saves share this owner lock across Workers.
 * Exclusive waiters publish an intent first. Shared callers that only poll
 * LOCK_NB never sit in the kernel queue, so live page reads can renew shared
 * locks without a gap and starve solidify until theme_layout_owner_lock_timeout.
 */
final class ThemeLayoutEntityOwnerLock
{
    /** @var array<string,array<string,array{handle:resource,pid:int,mode:int}>> */
    private static array $held = [];

    /** @var array<string,true> Intent files this process is still waiting on. */
    private static array $liveIntents = [];

    public static function read(ThemeVersionIdentity $identity, callable $callback): mixed
    {
        return self::withLock($identity, LOCK_SH, $callback);
    }

    public static function write(ThemeVersionIdentity $identity, callable $callback): mixed
    {
        return self::withLock($identity, LOCK_EX, $callback);
    }

    private static function withLock(ThemeVersionIdentity $identity, int $mode, callable $callback): mixed
    {
        $pid = (int)getmypid();
        $fiber = \Fiber::getCurrent();
        $execution = $pid . ':' . ($fiber ? spl_object_id($fiber) : 'main') . ':' . (RequestContext::getId() ?? '');
        $key = hash('sha256', (defined('BP') ? (string)BP : __DIR__) . "\0" . $identity->ownerKey());
        $existing = self::$held[$key][$execution] ?? null;
        if ($existing !== null) {
            if ($existing['mode'] !== LOCK_EX && $mode === LOCK_EX) {
                throw new \LogicException('theme_layout_owner_read_lock_cannot_upgrade');
            }
            return $callback();
        }
        // A fork inherits handles but is a different execution owner. Closing
        // its copy must not unlock the parent's open-file-description lock.
        foreach (self::$held[$key] ?? [] as $owner => $entry) {
            if ($entry['pid'] !== $pid) {
                fclose($entry['handle']);
                unset(self::$held[$key][$owner]);
            }
        }
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/weline-theme-owner-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('theme_layout_owner_lock_directory_failed');
        }
        $handle = @fopen($directory . '/' . $key . '.lock', 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('theme_layout_owner_lock_open_failed');
        }
        // Pipeline may serialize same-owner draft/formal across processes; 10s was too tight
        // when a sibling promote is still writing a large identity tree.
        $deadline = hrtime(true) + 120_000_000_000;
        $waitStarted = hrtime(true);
        $intentFile = null;
        try {
            if ($mode === LOCK_EX) {
                $intentFile = self::publishExclusiveIntent($directory, $key);
            }
            while (true) {
                $sharedYields = $mode === LOCK_SH && self::exclusiveIntentPending($directory, $key);
                if (!$sharedYields && flock($handle, $mode | LOCK_NB)) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('theme_layout_owner_lock_timeout');
                }
                if ($fiber !== null && !SchedulerSystem::isSchedulerActive()) {
                    throw new \RuntimeException('theme_layout_owner_lock_cooperative_wait_unavailable');
                }
                SchedulerSystem::yieldDelay(1);
            }
            if ($intentFile !== null) {
                self::clearExclusiveIntent($intentFile);
                $intentFile = null;
            }
            $waitMs = (hrtime(true) - $waitStarted) / 1_000_000;
            // Timing dig: theme.source.capture often = owner lock wait, not disk I/O.
            if ($waitMs >= 5.0) {
                try {
                    if (TraceGate::isArmed()) {
                        TraceGate::recordPhase(
                            'theme.layout.owner_lock_wait',
                            $waitMs,
                            [
                                'mode' => $mode === LOCK_EX ? 'ex' : 'sh',
                                'owner_key_hash' => substr($key, 0, 16),
                            ],
                        );
                    }
                } catch (\Throwable) {
                    // Tracing must never block lock acquisition.
                }
            }
            self::$held[$key][$execution] = ['handle' => $handle, 'pid' => $pid, 'mode' => $mode];
            try {
                return $callback();
            } finally {
                unset(self::$held[$key][$execution]);
                if (self::$held[$key] === []) { unset(self::$held[$key]); }
                flock($handle, LOCK_UN);
            }
        } finally {
            if ($intentFile !== null) {
                self::clearExclusiveIntent($intentFile);
            }
            fclose($handle);
        }
    }

    private static function publishExclusiveIntent(string $directory, string $key): string
    {
        $intentDirectory = $directory . '/' . $key . '.exwait';
        if (!is_dir($intentDirectory) && !@mkdir($intentDirectory, 0775, true) && !is_dir($intentDirectory)) {
            throw new \RuntimeException('theme_layout_owner_lock_directory_failed');
        }
        $path = $intentDirectory . '/' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (file_put_contents($path, '') === false) {
            throw new \RuntimeException('theme_layout_owner_lock_directory_failed');
        }
        self::$liveIntents[$path] = true;

        return $path;
    }

    private static function clearExclusiveIntent(string $path): void
    {
        unset(self::$liveIntents[$path]);
        if (is_file($path)) {
            @unlink($path);
        }
        $intentDirectory = dirname($path);
        if (is_dir($intentDirectory)) {
            @rmdir($intentDirectory);
        }
    }

    private static function exclusiveIntentPending(string $directory, string $key): bool
    {
        $intentDirectory = $directory . '/' . $key . '.exwait';
        if (!is_dir($intentDirectory)) {
            return false;
        }
        $self = (int)getmypid();
        $pending = false;
        foreach (scandir($intentDirectory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $intentDirectory . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $pid = (int)$name;
            if ($pid === $self) {
                if (isset(self::$liveIntents[$path])) {
                    $pending = true;
                    continue;
                }
                @unlink($path);
                continue;
            }
            if (!self::processAlive($pid)) {
                @unlink($path);
                continue;
            }
            $pending = true;
        }

        return $pending;
    }

    private static function processAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }
            $errno = \function_exists('posix_get_last_error') ? (int)posix_get_last_error() : 0;

            return $errno === 1;
        }

        return \DIRECTORY_SEPARATOR === '/' && is_dir('/proc/' . $pid);
    }
}
