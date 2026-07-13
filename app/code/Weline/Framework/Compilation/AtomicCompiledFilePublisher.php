<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

/**
 * Publishes immutable control-plane artifacts without exposing partial files.
 *
 * The directory lock is retained for one complete FrameworkCompiler session,
 * then explicitly released from its finally block. Shutdown release remains a
 * fatal-error safety net, so two compilers cannot interleave generations and
 * no lock descriptor is intentionally inherited by a later Master/Worker.
 */
final class AtomicCompiledFilePublisher
{
    private const DEFAULT_LOCK_TIMEOUT_MILLISECONDS = 10_000;
    private const LOCK_FILE = '.framework-compile.lock';

    /**
     * @var array<string, array{pid:int, handle:resource}>
     */
    private static array $directoryLocks = [];

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly int $lockTimeoutMilliseconds = self::DEFAULT_LOCK_TIMEOUT_MILLISECONDS,
    ) {
        if ($this->lockTimeoutMilliseconds < 1) {
            throw new \InvalidArgumentException('Compiled artifact lock timeout must be at least 1ms.');
        }
    }

    public function publish(string $target, string $content): void
    {
        if (\trim($target) === '') {
            throw new \InvalidArgumentException('Compiled artifact target must not be empty.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException("Compiled artifact content must not be empty: {$target}.");
        }

        $directory = \dirname($target);
        $this->ensureDirectory($directory);
        $this->acquireCompileLock($directory);

        if ($this->isWindows()) {
            $this->recoverInterruptedWindowsPublish($target);
        }

        $temporary = $this->writeCompleteTemporaryFile($target, $content);
        try {
            if ($this->isWindows()) {
                $this->publishOnWindows($temporary, $target);
            } elseif (!@\rename($temporary, $target)) {
                throw new \RuntimeException("Unable to atomically publish compiled artifact: {$target}.");
            }
        } finally {
            if ($this->pathExists($temporary)) {
                @\unlink($temporary);
            }
        }

        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($target, true);
        }
        \clearstatcache(true, $target);
    }

    /**
     * Hold the same directory lock used by publish() across a caller-owned
     * multi-file promotion. The caller must release it with
     * releaseProcessLocks() from a finally block.
     */
    public function acquireDirectoryLock(string $directory): void
    {
        if (\trim($directory) === '') {
            throw new \InvalidArgumentException('Compiled artifact lock directory must not be empty.');
        }

        $this->ensureDirectory($directory);
        $this->acquireCompileLock($directory);
    }

    /**
     * Release locks owned by the current PID.
     *
     * A forked child receives a copy of the static registry and file
     * descriptors. It must close that duplicate without LOCK_UN, because an
     * explicit unlock could release the parent process' shared open-file lock.
     */
    public static function releaseProcessLocks(): void
    {
        $pid = (int)(\getmypid() ?: 0);
        foreach (self::$directoryLocks as $key => $lock) {
            $handle = $lock['handle'];
            if (!\is_resource($handle)) {
                unset(self::$directoryLocks[$key]);
                continue;
            }

            if ($lock['pid'] === $pid) {
                @\flock($handle, \LOCK_UN);
            }
            @\fclose($handle);
            unset(self::$directoryLocks[$key]);
        }
    }

    public static function releaseAll(): void
    {
        self::releaseProcessLocks();
    }

    private function ensureDirectory(string $directory): void
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Unable to create compiled artifact directory: {$directory}.");
        }
    }

    private function acquireCompileLock(string $directory): void
    {
        $resolvedDirectory = \realpath($directory) ?: $directory;
        $key = $this->isWindows() ? \strtolower($resolvedDirectory) : $resolvedDirectory;
        $pid = (int)(\getmypid() ?: 0);
        $existing = self::$directoryLocks[$key] ?? null;
        if ($existing !== null) {
            if ($existing['pid'] !== $pid || !\is_resource($existing['handle'])) {
                throw new \RuntimeException(
                    "Compiled artifact publisher cannot reuse an inherited or invalid lock: {$resolvedDirectory}.",
                );
            }
            return;
        }

        $lockFile = $resolvedDirectory . \DIRECTORY_SEPARATOR . self::LOCK_FILE;
        // The POSIX `e` flag applies close-on-exec. Some Windows/filesystem
        // builds do not accept it, so retain a cross-platform fallback.
        $handle = @\fopen($lockFile, 'c+be');
        if (!\is_resource($handle)) {
            $handle = @\fopen($lockFile, 'c+b');
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException("Unable to open framework compile lock: {$lockFile}.");
        }

        $deadline = \hrtime(true) + ($this->lockTimeoutMilliseconds * 1_000_000);
        do {
            if (@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                self::$directoryLocks[$key] = ['pid' => $pid, 'handle' => $handle];
                $this->registerShutdownRelease();
                @\ftruncate($handle, 0);
                @\rewind($handle);
                @\fwrite($handle, "pid={$pid}\nacquired_at=" . \date(\DATE_ATOM) . "\n");
                @\fflush($handle);
                return;
            }

            $remainingNanoseconds = $deadline - \hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }
            @\usleep((int)\min(10_000, \max(1_000, \intdiv($remainingNanoseconds, 1_000))));
        } while (true);

        @\fclose($handle);
        throw new \RuntimeException(
            "Timed out after {$this->lockTimeoutMilliseconds}ms waiting for framework compile lock: {$lockFile}.",
        );
    }

    private function registerShutdownRelease(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        \register_shutdown_function(static function (): void {
            self::releaseProcessLocks();
        });
    }

    private function writeCompleteTemporaryFile(string $target, string $content): string
    {
        $temporary = '';
        $handle = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $temporary = $target
                . '.compile-tmp-'
                . (string)(\getmypid() ?: 0)
                . '-'
                . \bin2hex(\random_bytes(6));
            $handle = @\fopen($temporary, 'x+b');
            if (\is_resource($handle)) {
                break;
            }
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException("Unable to create compiled artifact temporary file: {$target}.");
        }

        try {
            $length = \strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = @\fwrite($handle, \substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Unable to write complete compiled artifact: {$target}.");
                }
                $offset += $written;
            }
            if (!@\fflush($handle)) {
                throw new \RuntimeException("Unable to flush compiled artifact temporary file: {$target}.");
            }
            if (\function_exists('fsync')) {
                @\fsync($handle);
            }
        } catch (\Throwable $throwable) {
            @\fclose($handle);
            @\unlink($temporary);
            throw $throwable;
        }
        @\fclose($handle);

        \clearstatcache(true, $temporary);
        if (!\is_file($temporary) || \filesize($temporary) !== \strlen($content)) {
            @\unlink($temporary);
            throw new \RuntimeException("Compiled artifact temporary file is incomplete: {$target}.");
        }

        $mode = \is_file($target) ? ((int)\fileperms($target) & 0777) : 0664;
        @\chmod($temporary, $mode > 0 ? $mode : 0664);
        return $temporary;
    }

    private function publishOnWindows(string $temporary, string $target): void
    {
        if (!$this->pathExists($target)) {
            if (!@\rename($temporary, $target)) {
                throw new \RuntimeException("Unable to publish compiled artifact on Windows: {$target}.");
            }
            return;
        }
        if (!\is_file($target) && !\is_link($target)) {
            throw new \RuntimeException("Compiled artifact target is not a file: {$target}.");
        }

        $backup = $this->windowsBackupPath($target);
        if (!@\rename($target, $backup)) {
            throw new \RuntimeException("Unable to stage existing compiled artifact on Windows: {$target}.");
        }

        if (!@\rename($temporary, $target)) {
            if (!@\rename($backup, $target)) {
                throw new \RuntimeException(
                    "Unable to publish or restore compiled artifact on Windows; valid backup retained at {$backup}.",
                );
            }
            throw new \RuntimeException("Unable to publish compiled artifact on Windows; previous target restored: {$target}.");
        }

        if (!@\unlink($backup) && $this->pathExists($backup)) {
            throw new \RuntimeException(
                "Compiled artifact was published but its Windows backup could not be removed: {$backup}.",
            );
        }
    }

    private function recoverInterruptedWindowsPublish(string $target): void
    {
        $backup = $this->windowsBackupPath($target);
        if (!$this->pathExists($backup)) {
            return;
        }
        if (!\is_file($backup) && !\is_link($backup)) {
            throw new \RuntimeException("Compiled artifact recovery path is not a file: {$backup}.");
        }

        if (!$this->pathExists($target)) {
            if (!@\rename($backup, $target)) {
                throw new \RuntimeException("Unable to restore interrupted Windows artifact publish: {$target}.");
            }
            return;
        }

        if (!@\unlink($backup) && $this->pathExists($backup)) {
            throw new \RuntimeException("Unable to clean interrupted Windows artifact backup: {$backup}.");
        }
    }

    private function windowsBackupPath(string $target): string
    {
        return $target . '.compile-backup';
    }

    private function pathExists(string $path): bool
    {
        return \file_exists($path) || \is_link($path);
    }

    private function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }
}
