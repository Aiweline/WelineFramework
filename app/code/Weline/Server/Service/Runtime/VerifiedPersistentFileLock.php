<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Open and retain one persistent lock inode without following links.
 *
 * Callers own the returned resource until their transaction completes. They
 * must unlock/close it, but must never unlink the stable lock path.
 */
final class VerifiedPersistentFileLock
{
    private const MAX_PAYLOAD_BYTES = 16_384;

    /**
     * @param \Closure():array<string,mixed> $payloadBuilder
     * @return resource|false
     */
    public static function acquire(
        string $path,
        float $timeout,
        \Closure $payloadBuilder,
    ) {
        if ($path === ''
            || \str_contains($path, "\0")
            || !\is_finite($timeout)
            || $timeout <= 0.0
            || $timeout > 300.0
        ) {
            return false;
        }
        $directory = \dirname($path);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0755, true)
            && !\is_dir($directory)
        ) {
            return false;
        }
        $directoryStatus = @\lstat($directory);
        if (!\is_array($directoryStatus)
            || \is_link($directory)
            || !self::safeDirectoryStatus($directoryStatus)
        ) {
            return false;
        }

        $before = false;
        $created = false;
        $handle = false;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $before = @\lstat($path);
            $created = false;
            if (\is_array($before)) {
                if (!self::safeStatus($before) || \is_link($path)) {
                    return false;
                }
                $handle = @\fopen($path, 'r+b');
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    return false;
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }
            if (\is_resource($handle)) {
                break;
            }
            SchedulerSystem::usleep(2_000);
        }
        if (!\is_resource($handle)) {
            return false;
        }

        $locked = false;
        $successful = false;
        try {
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($path);
            $directoryAfterOpen = @\lstat($directory);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !\is_array($directoryAfterOpen)
                || !self::safeStatus($opened)
                || !self::safeStatus($pathStatus)
                || (!$created && (!\is_array($before)
                    || !self::sameIdentity($before, $opened)))
                || !self::sameIdentity($opened, $pathStatus)
                || !self::sameDirectoryIdentity($directoryStatus, $directoryAfterOpen)
                || \is_link($directory)
                || \is_link($path)
            ) {
                return false;
            }
            if (\PHP_OS_FAMILY !== 'Windows'
                && !(
                    \function_exists('fchmod')
                        ? @\fchmod($handle, 0600)
                        : ($path !== '' && @\chmod($path, 0600))
                )
            ) {
                return false;
            }
            $sealedStatus = @\fstat($handle);
            $sealedPathStatus = @\lstat($path);
            $directoryAfterSeal = @\lstat($directory);
            if (!\is_array($sealedStatus)
                || !\is_array($sealedPathStatus)
                || !\is_array($directoryAfterSeal)
                || !self::safeStatus($sealedStatus)
                || !self::safeStatus($sealedPathStatus)
                || !self::sameIdentity($sealedStatus, $sealedPathStatus)
                || !self::sameDirectoryIdentity($directoryStatus, $directoryAfterSeal)
                || \is_link($directory)
                || \is_link($path)
            ) {
                return false;
            }
            $deadline = self::monotonicSeconds() + $timeout;
            do {
                if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                $remainingSeconds = $deadline - self::monotonicSeconds();
                if ($remainingSeconds <= 0.0) {
                    break;
                }
                SchedulerSystem::usleep((int)\max(
                    1,
                    \min(100_000, \ceil($remainingSeconds * 1_000_000)),
                ));
                if (self::monotonicSeconds() >= $deadline) {
                    break;
                }
            } while (true);
            if (!$locked) {
                return false;
            }
            $lockedStatus = @\fstat($handle);
            $lockedPathStatus = @\lstat($path);
            $lockedDirectoryStatus = @\lstat($directory);
            if (!\is_array($lockedStatus)
                || !\is_array($lockedPathStatus)
                || !\is_array($lockedDirectoryStatus)
                || !self::safeStatus($lockedStatus)
                || !self::safeStatus($lockedPathStatus)
                || !self::sameIdentity($sealedStatus, $lockedStatus)
                || !self::sameIdentity($lockedStatus, $lockedPathStatus)
                || !self::sameDirectoryIdentity($directoryStatus, $lockedDirectoryStatus)
                || \is_link($directory)
                || \is_link($path)
            ) {
                return false;
            }
            $payload = \json_encode(
                $payloadBuilder(),
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR,
            );
            if (\strlen($payload) > self::MAX_PAYLOAD_BYTES
                || !self::lockIdentityRemainsStable(
                    $handle,
                    $path,
                    $directory,
                    $lockedStatus,
                    $directoryStatus,
                )
                || !@\ftruncate($handle, 0)
                || @\fseek($handle, 0, SEEK_SET) !== 0
                || !self::writeAll($handle, $payload)
                || !@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                return false;
            }
            $published = @\fstat($handle);
            $publishedPath = @\lstat($path);
            $publishedDirectory = @\lstat($directory);
            if (!\is_array($published)
                || !\is_array($publishedPath)
                || !\is_array($publishedDirectory)
                || !self::safeStatus($published)
                || !self::safeStatus($publishedPath)
                || !self::sameIdentity($published, $publishedPath)
                || !self::sameDirectoryIdentity($directoryStatus, $publishedDirectory)
                || \is_link($directory)
                || \is_link($path)
                || (int)($published['size'] ?? -1) !== \strlen($payload)
            ) {
                return false;
            }
            if ($created) {
                GatewayProjectStateFilesystem::syncDirectory($directory);
            }

            $successful = true;
            return $handle;
        } catch (\Throwable) {
            return false;
        } finally {
            if ($locked && !$successful) {
                @\flock($handle, LOCK_UN);
            }
            if (!$successful) {
                @\fclose($handle);
            }
        }
    }

    /**
     * Probe an existing lock without creating, rewriting, or unlinking it.
     *
     * @return bool|null true=held, false=missing/free, null=unsafe/unknown
     */
    public static function isHeld(string $path): ?bool
    {
        if ($path === '' || \str_contains($path, "\0")) {
            return null;
        }
        $before = @\lstat($path);
        if (!\is_array($before)) {
            return (\file_exists($path) || \is_link($path)) ? null : false;
        }
        if (!self::safeStatus($before) || \is_link($path)) {
            return null;
        }
        $directory = \dirname($path);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || !self::safeDirectoryStatus($directoryBefore)
            || \is_link($directory)
        ) {
            return null;
        }
        $handle = @\fopen($path, 'r+b');
        if (!\is_resource($handle)) {
            return null;
        }
        $locked = false;
        try {
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($path);
            $directoryAfterOpen = @\lstat($directory);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !\is_array($directoryAfterOpen)
                || !self::safeStatus($opened)
                || !self::safeStatus($pathStatus)
                || !self::sameIdentity($before, $opened)
                || !self::sameIdentity($opened, $pathStatus)
                || !self::sameDirectoryIdentity($directoryBefore, $directoryAfterOpen)
                || \is_link($directory)
                || \is_link($path)
            ) {
                return null;
            }
            $locked = @\flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) {
                return self::lockIdentityRemainsStable(
                    $handle,
                    $path,
                    $directory,
                    $opened,
                    $directoryBefore,
                ) ? true : null;
            }
            $lockedStatus = @\fstat($handle);
            $lockedPathStatus = @\lstat($path);
            $lockedDirectoryStatus = @\lstat($directory);
            if (!\is_array($lockedStatus)
                || !\is_array($lockedPathStatus)
                || !\is_array($lockedDirectoryStatus)
                || !self::safeStatus($lockedStatus)
                || !self::safeStatus($lockedPathStatus)
                || !self::sameIdentity($opened, $lockedStatus)
                || !self::sameIdentity($lockedStatus, $lockedPathStatus)
                || !self::sameDirectoryIdentity($directoryBefore, $lockedDirectoryStatus)
                || \is_link($directory)
                || \is_link($path)
            ) {
                return null;
            }

            return false;
        } finally {
            if ($locked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $status */
    private static function safeStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
            && (int)($status['nlink'] ?? 0) === 1;
    }

    /** @param array<string|int,mixed> $status */
    private static function safeDirectoryStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0040000);
    }

    /**
     * @param resource $handle
     * @param array<string|int,mixed> $expectedFile
     * @param array<string|int,mixed> $expectedDirectory
     */
    private static function lockIdentityRemainsStable(
        $handle,
        string $path,
        string $directory,
        array $expectedFile,
        array $expectedDirectory,
    ): bool {
        $opened = @\fstat($handle);
        $pathStatus = @\lstat($path);
        $directoryStatus = @\lstat($directory);
        return \is_array($opened)
            && \is_array($pathStatus)
            && \is_array($directoryStatus)
            && self::safeStatus($opened)
            && self::safeStatus($pathStatus)
            && self::sameIdentity($expectedFile, $opened)
            && self::sameIdentity($opened, $pathStatus)
            && self::sameDirectoryIdentity($expectedDirectory, $directoryStatus)
            && !\is_link($directory)
            && !\is_link($path);
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($after[$field] ?? -2)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameDirectoryIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($after[$field] ?? -2)) {
                return false;
            }
        }

        return true;
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $payload): bool
    {
        $length = \strlen($payload);
        $offset = 0;
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($payload, $offset));
            if (!\is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private static function monotonicSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS persistent lock monotonic clock is invalid.');
        }

        return $now;
    }
}
