<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Runtime\RequestContext;
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
        $deadline = hrtime(true) + 10_000_000_000;
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
