<?php

declare(strict_types=1);

namespace Weline\Storage\Driver;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageReadHandle;
use Weline\Storage\Api\StorageWriteHandle;
use Weline\Storage\Service\StorageTemporaryFile;

final class LocalFilesystemDriver implements StorageDriverInterface
{
    private const MAX_DIRECTORY_ITEMS = 20000;
    private string $rootPath;

    public function __construct(
        private readonly string $diskCode,
        string $rootPath,
        private readonly StorageRequestResourceRegistryInterface $resources,
        private readonly int $maxObjectBytes = StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES,
    ) {
        if ($maxObjectBytes < 1 || $maxObjectBytes > StorageWriteHandle::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException((string)__('本地存储单对象字节上限无效。'));
        }
        $rootPath = rtrim($rootPath, '/\\');
        if ($rootPath === '') {
            throw new \InvalidArgumentException((string)__('本地存储根目录不能为空。'));
        }
        if (!is_dir($rootPath) && !mkdir($rootPath, 0755, true) && !is_dir($rootPath)) {
            throw new \RuntimeException((string)__('无法创建本地存储根目录。'));
        }
        $real = realpath($rootPath);
        if ($real === false) {
            throw new \RuntimeException((string)__('无法解析本地存储根目录。'));
        }
        if (rtrim($real, DIRECTORY_SEPARATOR) === '' || dirname($real) === $real) {
            throw new \InvalidArgumentException((string)__('本地存储根目录不能是文件系统根目录。'));
        }
        $this->rootPath = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function openRead(string $objectKey): StorageReadHandle
    {
        $path = $this->existingFile($objectKey);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法打开存储对象读取流。'));
        }
        return new StorageReadHandle($stream, $this->resources);
    }

    public function openWrite(string $objectKey, array $options = []): StorageWriteHandle
    {
        $destination = $this->prepareWritePath($objectKey);
        $overwrite = (bool)($options['overwrite'] ?? false);
        if (!$overwrite && file_exists($destination)) {
            throw new \RuntimeException((string)__('目标存储对象已存在。'));
        }
        $directory = dirname($destination);
        $temporary = StorageTemporaryFile::create($directory, '.weline-upload-', $this->resources);
        $stream = fopen($temporary->path(), 'wb');
        if ($stream === false) {
            $temporary->close();
            throw new \RuntimeException((string)__('无法打开上传临时文件。'));
        }

        return new StorageWriteHandle(
            $stream,
            function () use ($temporary, $destination, $objectKey, $overwrite): StorageObjectStat {
                $committed = false;
                try {
                    $this->assertDestinationParent($destination);
                    if (!@chmod($temporary->path(), 0644)) {
                        throw new \RuntimeException((string)__('设置本地存储对象权限失败。'));
                    }
                    // Build the immutable result before the atomic filesystem
                    // commit. Once overwrite rename(2) succeeds the previous
                    // object cannot be reconstructed, so no fallible stat/hash
                    // work may remain after that point.
                    $result = $this->statPath($temporary->path(), $objectKey);
                    if ($overwrite) {
                        if (!@rename($temporary->path(), $destination)) {
                            throw new \RuntimeException((string)__('提交本地存储对象失败。'));
                        }
                    } elseif (!@link($temporary->path(), $destination)) {
                        // link(2) creates the destination only when it does not
                        // already exist. The temp file lives in the same
                        // directory, so this is an atomic no-overwrite commit.
                        throw new \RuntimeException((string)__('目标存储对象已存在或无法原子提交。'));
                    }
                    $committed = true;
                    // Complete temporary-file ownership transfer before the
                    // callback can report success. A failed unlink on the
                    // no-overwrite hard-link path is therefore still able to
                    // roll back the newly linked destination in the catch.
                    if ($overwrite) {
                        $temporary->close();
                    } else {
                        $temporary->releaseAfterHardLinkCommit($destination);
                    }
                    return $result;
                } catch (\Throwable $throwable) {
                    if ($committed && !$overwrite && is_file($destination)) {
                        @unlink($destination);
                    }
                    throw $throwable;
                } finally {
                    $temporary->close();
                }
            },
            static fn () => $temporary->close(),
            $this->resources,
            $this->maxWriteBytes($options),
        );
    }

    private function assertDestinationParent(string $destination): void
    {
        $parent = realpath(dirname($destination));
        if ($parent === false || !$this->isInsideRoot($parent) || is_link(dirname($destination))) {
            throw new \InvalidArgumentException((string)__('本地存储目标目录无效。'));
        }
        if (is_link($destination)) {
            throw new \InvalidArgumentException((string)__('本地存储路径不允许符号链接。'));
        }
    }

    /** @param array<string,mixed> $options */
    private function maxWriteBytes(array $options): int
    {
        if (!array_key_exists('max_bytes', $options)) {
            return $this->maxObjectBytes;
        }
        $requested = filter_var($options['max_bytes'], FILTER_VALIDATE_INT);
        if ($requested === false || $requested < 1) {
            throw new \InvalidArgumentException((string)__('存储写入字节上限无效。'));
        }
        return min($this->maxObjectBytes, (int)$requested);
    }

    public function exists(string $objectKey): bool
    {
        try {
            return is_file($this->readPath($objectKey));
        } catch (\Throwable) {
            return false;
        }
    }

    public function stat(string $objectKey): StorageObjectStat
    {
        $path = $this->existingFile($objectKey);
        return $this->statPath($path, $objectKey);
    }

    private function statPath(string $path, string $objectKey): StorageObjectStat
    {
        $bytes = filesize($path);
        $modified = filemtime($path);
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        if ($bytes === false) {
            throw new \RuntimeException((string)__('无法读取本地存储对象统计。'));
        }
        $sha256 = $this->sha256Path($path);
        return new StorageObjectStat(
            new StorageObjectReference($this->diskCode, $this->normalizeKey($objectKey)),
            $bytes,
            is_string($mime) ? $mime : null,
            $modified === false ? null : $modified,
            $sha256,
        );
    }

    public function delete(string $objectKey): bool
    {
        $path = $this->readPath($objectKey);
        return !file_exists($path) || (is_file($path) && @unlink($path));
    }

    public function copy(string $fromObjectKey, string $toObjectKey): StorageObjectReference
    {
        $read = $this->openRead($fromObjectKey);
        $write = null;
        $emptyReads = 0;
        try {
            // Use the same request-registered, atomic temporary-file path as a
            // normal upload. Besides avoiding a partially visible destination,
            // this gives long local copies cooperative WLS scheduling points.
            $write = $this->openWrite($toObjectKey, ['overwrite' => false]);
            while (!$read->eof()) {
                $chunk = $read->read(1024 * 1024);
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('复制存储对象时连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                $write->write($chunk);
                SchedulerSystem::yield();
            }
            // Close every read-side resource before committing the destination;
            // a close failure must still be able to abort the pending write.
            $read->close();
            return $write->complete()->object;
        } catch (\Throwable $throwable) {
            if ($write instanceof StorageWriteHandle && !$write->isClosed()) {
                try {
                    $write->abort();
                } catch (\Throwable) {
                    // The registry has retained the cleanup failure and the
                    // request resetter will drain this worker at final cleanup.
                }
            }
            throw $throwable;
        } finally {
            if (!$read->isClosed()) {
                $read->close();
            }
        }
    }

    public function move(string $fromObjectKey, string $toObjectKey): StorageObjectReference
    {
        $from = $this->existingFile($fromObjectKey);
        $to = $this->prepareWritePath($toObjectKey);
        $this->assertDestinationParent($to);
        if (!@link($from, $to)) {
            throw new \RuntimeException((string)__('目标存储对象已存在或无法原子移动。'));
        }
        if (!@unlink($from)) {
            if (!@unlink($to)) {
                throw new \RuntimeException((string)__('移动源对象失败，且无法回滚目标副本。'));
            }
            throw new \RuntimeException((string)__('移动存储对象失败。'));
        }
        return new StorageObjectReference($this->diskCode, $this->normalizeKey($toObjectKey));
    }

    public function makeDirectory(string $objectKey): bool
    {
        $key = $this->normalizeKey($objectKey);
        $this->ensureDirectorySegments(explode('/', $key));
        return true;
    }

    public function deleteDirectory(string $objectKey): bool
    {
        $path = $this->readPath($objectKey);
        if (!file_exists($path)) {
            return true;
        }
        if (!is_dir($path) || !$this->isInsideRoot($path)) {
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        $pending = [];
        foreach ($iterator as $item) {
            if (count($pending) >= self::MAX_DIRECTORY_ITEMS) {
                throw new \RuntimeException((string)__('本地存储目录删除超过条目上限。'));
            }
            $itemPath = $item->getPathname();
            if (!$this->isInsideRoot($itemPath)) {
                throw new \RuntimeException((string)__('本地存储目录包含越界路径。'));
            }
            $pending[] = [$itemPath, $item->isDir() && !$item->isLink()];
            if (count($pending) % 256 === 0) {
                SchedulerSystem::yield();
            }
        }
        foreach ($pending as $index => [$itemPath, $isDirectory]) {
            $deleted = $isDirectory ? @rmdir($itemPath) : @unlink($itemPath);
            if (!$deleted && file_exists($itemPath)) {
                throw new \RuntimeException((string)__('删除本地存储目录内容失败。'));
            }
            if (($index + 1) % 256 === 0) {
                SchedulerSystem::yield();
            }
        }
        return @rmdir($path);
    }

    public function list(string $prefix = '', bool $recursive = false): array
    {
        $directory = $prefix === '' ? rtrim($this->rootPath, DIRECTORY_SEPARATOR) : $this->readPath($prefix);
        if (!is_dir($directory)) {
            return [];
        }
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
            : new \IteratorIterator(new \DirectoryIterator($directory));
        $items = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }
            if ($file->isLink()) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false || !$this->isInsideRoot($real)) {
                continue;
            }
            if (count($items) >= self::MAX_DIRECTORY_ITEMS) {
                throw new \RuntimeException((string)__('本地存储目录枚举超过条目上限。'));
            }
            $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($this->rootPath)));
            $isDirectory = $file->isDir();
            $items[] = new StorageObjectStat(
                new StorageObjectReference($this->diskCode, $key),
                $isDirectory ? 0 : max(0, (int)$file->getSize()),
                $isDirectory ? 'application/x-directory' : null,
                $file->getMTime(),
                null,
                ['type' => $isDirectory ? 'directory' : 'file'],
            );
            if (count($items) % 256 === 0) {
                SchedulerSystem::yield();
            }
        }
        return $items;
    }

    private function existingFile(string $objectKey): string
    {
        $path = $this->readPath($objectKey);
        if (!is_file($path)) {
            throw new \RuntimeException((string)__('存储对象不存在。'));
        }
        return $path;
    }

    private function readPath(string $objectKey): string
    {
        $key = $this->normalizeKey($objectKey);
        $this->assertNoSymlinkSegments($key, true);
        $candidate = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $key);
        $real = realpath($candidate);
        if ($real !== false && !$this->isInsideRoot($real)) {
            throw new \InvalidArgumentException((string)__('存储对象路径越过根目录。'));
        }
        return $real === false ? $candidate : $real;
    }

    private function prepareWritePath(string $objectKey): string
    {
        $key = $this->normalizeKey($objectKey);
        $segments = explode('/', $key);
        $leaf = array_pop($segments);
        $this->ensureDirectorySegments($segments);
        $this->assertNoSymlinkSegments($key, true);
        $path = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $key);
        if (file_exists($path)) {
            $real = realpath($path);
            if ($real === false || !$this->isInsideRoot($real)) {
                throw new \InvalidArgumentException((string)__('存储对象路径越过根目录。'));
            }
        }
        if ($leaf === null || $leaf === '') {
            throw new \InvalidArgumentException((string)__('对象键必须是非空相对路径。'));
        }
        return $path;
    }

    private function normalizeKey(string $objectKey): string
    {
        $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
        StorageObjectReference::assertObjectKey($objectKey);
        return $objectKey;
    }

    private function isInsideRoot(string $path): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        return $path === rtrim($this->rootPath, DIRECTORY_SEPARATOR)
            || str_starts_with($path . DIRECTORY_SEPARATOR, $this->rootPath);
    }

    /** @param list<string> $segments */
    private function ensureDirectorySegments(array $segments): void
    {
        $current = rtrim($this->rootPath, DIRECTORY_SEPARATOR);
        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \InvalidArgumentException((string)__('本地存储路径不允许符号链接。'));
            }
            if (file_exists($current) && !is_dir($current)) {
                throw new \RuntimeException((string)__('存储对象父路径不是目录。'));
            }
            if (!file_exists($current) && !mkdir($current, 0755) && !is_dir($current)) {
                throw new \RuntimeException((string)__('无法创建存储对象目录。'));
            }
            $real = realpath($current);
            if ($real === false || !$this->isInsideRoot($real)) {
                throw new \InvalidArgumentException((string)__('存储对象路径越过根目录。'));
            }
        }
    }

    private function assertNoSymlinkSegments(string $key, bool $includeLeaf): void
    {
        $segments = explode('/', $key);
        if (!$includeLeaf) {
            array_pop($segments);
        }
        $current = rtrim($this->rootPath, DIRECTORY_SEPARATOR);
        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \InvalidArgumentException((string)__('本地存储路径不允许符号链接。'));
            }
        }
    }

    private function sha256Path(string $path): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法打开存储对象读取流。'));
        }
        $handle = new StorageReadHandle($stream, $this->resources);
        $hash = hash_init('sha256');
        $emptyReads = 0;
        try {
            while (!$handle->eof()) {
                $chunk = $handle->read(1024 * 1024);
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('读取本地存储对象时连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                hash_update($hash, $chunk);
                SchedulerSystem::yield();
            }
            return hash_final($hash);
        } finally {
            $handle->close();
        }
    }
}
