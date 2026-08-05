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
            || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
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
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !self::safeStatus($opened)
                || (!$created && (!\is_array($before)
                    || !self::sameIdentity($before, $opened)))
                || !self::sameIdentity($opened, $pathStatus)
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
            $deadline = self::monotonicSeconds() + $timeout;
            do {
                if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                if (self::monotonicSeconds() >= $deadline) {
                    break;
                }
                SchedulerSystem::usleep(100_000);
            } while (true);
            if (!$locked) {
                return false;
            }
            $lockedStatus = @\fstat($handle);
            $lockedPathStatus = @\lstat($path);
            if (!\is_array($lockedStatus)
                || !\is_array($lockedPathStatus)
                || !self::sameIdentity($lockedStatus, $lockedPathStatus)
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
            if (!\is_array($published)
                || !\is_array($publishedPath)
                || !self::sameIdentity($published, $publishedPath)
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
        $handle = @\fopen($path, 'r+b');
        if (!\is_resource($handle)) {
            return null;
        }
        $locked = false;
        try {
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !self::sameIdentity($before, $opened)
                || !self::sameIdentity($opened, $pathStatus)
            ) {
                return null;
            }
            $locked = @\flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) {
                return true;
            }
            $lockedStatus = @\fstat($handle);
            $lockedPathStatus = @\lstat($path);
            if (!\is_array($lockedStatus)
                || !\is_array($lockedPathStatus)
                || !self::sameIdentity($lockedStatus, $lockedPathStatus)
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
