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
        return rtrim($this->paths->root(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-leases'
            . DIRECTORY_SEPARATOR . $key->hash() . '.lease';
    }
}
