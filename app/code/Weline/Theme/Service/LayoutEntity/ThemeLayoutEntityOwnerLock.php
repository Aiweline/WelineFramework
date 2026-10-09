<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/** Short source reads and complete saves share this owner lock across Workers. */
final class ThemeLayoutEntityOwnerLock
{
    /** @var array<string,array<string,array{handle:resource,pid:int,mode:int}>> */
    private static array $held = [];

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
        try {
            while (!flock($handle, $mode | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('theme_layout_owner_lock_timeout');
                }
                if ($fiber !== null && !SchedulerSystem::isSchedulerActive()) {
                    throw new \RuntimeException('theme_layout_owner_lock_cooperative_wait_unavailable');
                }
                SchedulerSystem::yieldDelay(1);
            }
            $waitMs = (hrtime(true) - $waitStarted) / 1_000_000;
            // Timing dig: theme.source.capture often = owner lock wait, not disk I/O.
            if ($waitMs >= 5.0) {
                try {
                    if (RequestLifecycleTrace::isEnabled()) {
                        RequestLifecycleTrace::recordPhase(
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
            fclose($handle);
        }
    }
}
