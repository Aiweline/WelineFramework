<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Storefront layout reads/saves run unlocked (2026-11-04, user decision).
 *
 * This class used to be a cross-Worker flock gate for one owner tuple
 * (theme + scope + store mode + area). It is kept as a pass-through so the
 * ~30 call sites stay untouched and the module contract stays stable; `read()`
 * and `write()` now differ only in name.
 */
final class ThemeLayoutEntityOwnerLock
{
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
        // Owner-lock disabled (2026-11-04, user decision).
        //
        // The cross-worker flock gate serialized every storefront read against
        // solidify/save siblings on the same owner tuple. On production it
        // turned into an outright hang: `.exwait` intent files are named
        // "<pid>-<random>" and the old intent probe parsed them with
        // `(int)$name`, so a crash-left intent whose PID was later reused (or
        // simply any stale intent) read back as a live exclusive waiter. Shared
        // readers then yielded every 1ms until the gate's own deadline.
        // Measured on changanhanfu: 47 of 47 intent files belonged to dead
        // PIDs, and a cold homepage pierce spent 38,693ms waiting for the lock
        // (mode=ex) inside ControllerFetchFileBefore — 71% of a 54,591ms
        // request that rendered with L2/L3/L4 phases all at 0ms.
        //
        // Correctness no longer depends on this gate: solidified layout bytes are
        // immutable per identity (cacheKey carries V/mode/R) and bake publishes
        // via atomic rename, so a reader either sees the previous complete
        // artifact or the next complete artifact. Cross-worker serialization is
        // the DB transaction's job, not a request-path flock.
        return $callback();
    }
}
