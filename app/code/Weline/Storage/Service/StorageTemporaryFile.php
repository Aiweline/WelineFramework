<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\Runtime\StorageTemporaryFileInterface;

/** Request-scoped temporary file whose path is never exposed to diagnostics. */
final class StorageTemporaryFile implements StorageTemporaryFileInterface
{
    private bool $closed = false;

    private function __construct(
        private readonly string $path,
        private readonly int $device,
        private readonly int $inode,
        private readonly StorageRequestResourceRegistryInterface $registry,
    ) {
        $this->registry->register($this);
    }

    public static function create(
        string $directory,
        string $prefix,
        StorageRequestResourceRegistryInterface $registry,
    ): self {
        if (\preg_match('/\A[A-Za-z0-9._-]{1,48}\z/D', $prefix) !== 1) {
            throw new \InvalidArgumentException((string)__('存储临时文件前缀无效。'));
        }
        $realDirectory = \realpath($directory);
        if ($realDirectory === false
            || !\is_dir($realDirectory)
            || !\is_writable($realDirectory)
        ) {
            // tempnam() silently falls back to the system temporary directory
            // when its target is unavailable. Storage callers must never lose
            // their explicitly selected ownership/atomic-rename boundary.
            throw new \InvalidArgumentException((string)__('存储临时文件目录无效或不可写。'));
        }

        $path = '';
        $stream = false;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $path = $realDirectory . \DIRECTORY_SEPARATOR . $prefix . \bin2hex(\random_bytes(16));
            $stream = @\fopen($path, 'x+b');
            if ($stream !== false) {
                break;
            }
        }
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法创建存储临时文件。'));
        }
        $stat = @\fstat($stream);
        $permissionsSet = @\chmod($path, 0600);
        $pathStat = @\lstat($path);
        $valid = $permissionsSet
            && \is_array($stat)
            && \is_array($pathStat)
            && !\is_link($path)
            && (((int)($stat['mode'] ?? 0)) & 0170000) === 0100000
            && (((int)($pathStat['mode'] ?? 0)) & 0170000) === 0100000
            && (((int)($pathStat['mode'] ?? 0)) & 0777) === 0600
            && (int)($stat['nlink'] ?? 0) === 1
            && (int)($pathStat['nlink'] ?? 0) === 1
            && (int)($pathStat['dev'] ?? -2) === (int)($stat['dev'] ?? -1)
            && (int)($pathStat['ino'] ?? -2) === (int)($stat['ino'] ?? -1);
        $closed = @\fclose($stream);
        if (!$valid || !$closed) {
            $cleaned = self::unlinkCreatedIdentity($path, $stat);
            if (!$closed || !$cleaned) {
                $registry->deferCleanupFailure(new \RuntimeException(
                    (string)__('存储临时文件创建失败且资源清理不完整。'),
                ));
            }
            throw new \RuntimeException((string)__('存储临时文件未创建在指定目录。'));
        }

        try {
            return new self(
                $path,
                (int)($stat['dev'] ?? -1),
                (int)($stat['ino'] ?? -1),
                $registry,
            );
        } catch (\Throwable $registrationFailure) {
            if (!self::unlinkCreatedIdentity($path, $stat)) {
                $registry->deferCleanupFailure(new \RuntimeException(
                    (string)__('Storage 临时文件注册失败且无法删除。'),
                ));
            }
            throw $registrationFailure;
        }
    }

    public function path(): string
    {
        if ($this->closed) {
            throw new \RuntimeException((string)__('存储临时文件已释放。'));
        }
        return $this->path;
    }

    public function resourceKind(): string
    {
        return 'storage.temporary_file';
    }

    /** Transfer deletion ownership to another request-final response object. */
    public function detach(): string
    {
        if ($this->closed) {
            throw new \RuntimeException((string)__('存储临时文件已释放。'));
        }
        $this->assertCurrentIdentity();
        $this->closed = true;
        $this->registry->release($this);

        return $this->path;
    }

    /**
     * Finish an atomic no-overwrite commit created with link(2).
     *
     * Both directory entries must still identify the exact inode created by
     * this request. Removing the temporary entry leaves the destination as the
     * sole owner and releases the request cleanup registration without ever
     * exposing a partially written destination.
     */
    public function releaseAfterHardLinkCommit(string $destination): void
    {
        if ($this->closed) {
            throw new \RuntimeException((string)__('存储临时文件已释放。'));
        }
        $source = @lstat($this->path);
        $target = @lstat($destination);
        if (!is_array($source)
            || !is_array($target)
            || is_link($this->path)
            || is_link($destination)
            || (((int)($source['mode'] ?? 0)) & 0170000) !== 0100000
            || (((int)($target['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($source['nlink'] ?? 0) !== 2
            || (int)($target['nlink'] ?? 0) !== 2
            || (int)($source['dev'] ?? -2) !== $this->device
            || (int)($source['ino'] ?? -2) !== $this->inode
            || (int)($target['dev'] ?? -3) !== $this->device
            || (int)($target['ino'] ?? -3) !== $this->inode
        ) {
            throw new \RuntimeException((string)__('存储临时文件身份已发生变化。'));
        }
        if (!@unlink($this->path)) {
            throw new \RuntimeException((string)__('删除存储临时文件失败。'));
        }
        $this->closed = true;
        $this->registry->release($this);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            if (file_exists($this->path) || is_link($this->path)) {
                $this->assertCurrentIdentity();
                if (!@unlink($this->path)) {
                    throw new \RuntimeException((string)__('删除存储临时文件失败。'));
                }
            }
            $this->closed = true;
            $this->registry->release($this);
        } catch (\Throwable $throwable) {
            $this->registry->deferCleanupFailure($throwable);
            throw $throwable;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertCurrentIdentity(): void
    {
        $stat = @lstat($this->path);
        if (!is_array($stat)
            || is_link($this->path)
            || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($stat['nlink'] ?? 0) !== 1
            || (int)($stat['dev'] ?? -2) !== $this->device
            || (int)($stat['ino'] ?? -2) !== $this->inode
        ) {
            throw new \RuntimeException((string)__('存储临时文件身份已发生变化。'));
        }
    }

    /** @param array<string|int,mixed>|false $opened */
    private static function unlinkCreatedIdentity(string $path, array|false $opened): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        $current = @lstat($path);
        if (!is_array($opened)
            || !is_array($current)
            || is_link($path)
            || (((int)($current['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($current['nlink'] ?? 0) !== 1
            || (int)($current['dev'] ?? -1) !== (int)($opened['dev'] ?? -2)
            || (int)($current['ino'] ?? -1) !== (int)($opened['ino'] ?? -2)
        ) {
            return false;
        }
        return @unlink($path);
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
