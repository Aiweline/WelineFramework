<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Cross-request pending lease for one serial_key.
 * Same key: single in-flight; callers coalesce when busy.
 */
final class ThemeLayoutEntitySolidifyLeaseStore
{
    private const TTL_SECONDS = 300;
    private const BATCH_ROOT_PREFIX = 'tu-';

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    public function isPending(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }
        $mtime = @filemtime($path);
        if (!is_int($mtime) || $mtime < 1) {
            return false;
        }
        if ((time() - $mtime) > self::TTL_SECONDS) {
            @unlink($path);

            return false;
        }

        return true;
    }

    /**
     * mtime of the slot lease file (null when there is no lease).
     *
     * Used by the pending-budget check: `queued_at` is refreshed by every gate re-enqueue,
     * so only the lease mtime survives as the "this bake actually started" marker.
     */
    public function leaseStartedAt(ThemeLayoutEntitySolidifySerialKey $key): ?int
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);

        return is_int($mtime) && $mtime > 0 ? $mtime : null;
    }

    /**
     * @return bool true when this caller acquired the lease; false when already pending (coalesce)
     */
    public function tryAcquire(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        if ($this->isPending($key)) {
            return false;
        }
        $path = $this->pathFor($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('theme_layout_solidify_lease_dir_failed');
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('theme_layout_solidify_lease_open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $stat = fstat($handle);
            $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
            if ($size > 0) {
                // Another worker already wrote the lease body.
                return false;
            }
            $body = json_encode([
                'serial_key' => $key->toString(),
                'pid' => getmypid(),
                'acquired_at' => gmdate('c'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $body);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function release(ThemeLayoutEntitySolidifySerialKey $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        $canonical = $this->canonicalLeasePath($key);
        if (is_file($canonical)) {
            return $canonical;
        }
        foreach ($this->batchLeasePaths($key) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $canonical;
    }

    /** 线上规范根的 lease 路径（首次获取默认落这里，语义与改动前一致）。 */
    private function canonicalLeasePath(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return rtrim($this->paths->stateRoot(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-leases'
            . DIRECTORY_SEPARATOR . $key->slotHash() . '.lease';
    }

    /**
     * 批次根（`tu-<ts>-<hash>/<themeId>/`）下的候选 lease 路径，新批次优先。
     *
     * Leases are acquired while a request/worker still runs inside a theme-update batch root,
     * but drain and the pending check resolve paths from stateRoot() (top level). Without this
     * fallback those leases are never seen, so their TTL expiry in isPending() never fires and
     * `lease_busy` blocks the queue forever (measured: 6 leases 1248s..5389s past a 300s TTL).
     *
     * @return list<string>
     */
    private function batchLeasePaths(ThemeLayoutEntitySolidifySerialKey $key): array
    {
        $root = rtrim($this->paths->stateRoot(), '/\\');
        $batches = [];
        foreach (@scandir($root) ?: [] as $name) {
            if (!str_starts_with($name, self::BATCH_ROOT_PREFIX)) {
                continue;
            }
            if (is_dir($root . DIRECTORY_SEPARATOR . $name)) {
                $batches[] = $name;
            }
        }
        rsort($batches, SORT_STRING);
        $out = [];
        $slot = $key->slotHash() . '.lease';
        foreach ($batches as $batch) {
            $dir = $root . DIRECTORY_SEPARATOR . $batch
                . DIRECTORY_SEPARATOR . (string)$key->themeId
                . DIRECTORY_SEPARATOR . '.solidify-leases';
            $out[] = $dir . DIRECTORY_SEPARATOR . $slot;
        }

        return $out;
    }
}
