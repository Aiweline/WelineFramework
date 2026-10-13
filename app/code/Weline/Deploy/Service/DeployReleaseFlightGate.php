<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Single-flight gate for deploy:release on one deploy root.
 * Prevents concurrent Git fetch / auto-rollback races when two webhooks or CLI releases overlap.
 */
class DeployReleaseFlightGate
{
    public const BUSY_MESSAGE = 'release_busy';

    /** @var resource|null */
    private $handle = null;

    public function lockPath(?string $deployRoot = null): string
    {
        $root = rtrim($deployRoot !== null && $deployRoot !== '' ? $deployRoot : BP, "/\\");

        return $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'release.lock';
    }

    /**
     * Non-blocking exclusive lock. Caller MUST release() in finally.
     */
    public function tryAcquire(?string $deployRoot = null): bool
    {
        $path = $this->lockPath($deployRoot);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }
        $this->handle = $handle;
        @fwrite($handle, (string)getmypid() . ' ' . date('c') . "\n");
        @fflush($handle);

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Probe whether another process currently holds the release lock.
     */
    public function isLocked(?string $deployRoot = null): bool
    {
        $path = $this->lockPath($deployRoot);
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        $busy = !@flock($handle, LOCK_EX | LOCK_NB);
        if (!$busy) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);

        return $busy;
    }
}
