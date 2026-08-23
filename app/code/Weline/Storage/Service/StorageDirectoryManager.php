<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class StorageDirectoryManager implements StorageDirectoryManagerInterface
{
    private const MAX_DIRECTORY_MOVE_ITEMS = 20000;

    private const CAPABILITIES = [
        'browse' => true,
        'create_directory' => true,
        'rename_directory' => true,
        'delete_directory' => true,
        'rename_file' => true,
        'delete_file' => true,
        'upload' => true,
        'download' => true,
        'preview' => true,
        'copy_url' => true,
    ];

    public function __construct(private readonly StorageManagerInterface $storageManager)
    {
    }

    public function capabilities(string $storage): array
    {
        $this->disk($storage);

        return self::CAPABILITIES;
    }

    public function list(string $storage, string $directory = '', bool $recursive = false): array
    {
        $directory = $this->normalizePath($directory, true);
        $items = $this->disk($storage)->list($directory, $recursive);
        $result = [];
        foreach ($items as $item) {
            try {
                $path = $this->normalizePath($item->object->objectKey, false);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!$this->belongsToDirectory($path, $directory, $recursive)) {
                continue;
            }
            $type = ($item->metadata['type'] ?? '') === 'directory' ? 'directory' : 'file';
            $name = \basename($path);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $modified = $item->lastModified;
            $result[] = [
                'path' => $path,
                'name' => $name,
                'type' => $type,
                'size' => $type === 'file' ? \max(0, $item->bytes) : 0,
                'last_modified' => \is_numeric($modified) ? (int)$modified : null,
                'mime_type' => $type === 'file' ? $item->mimeType : 'application/x-directory',
            ];
        }

        return $result;
    }

    public function makeDirectory(string $storage, string $path): bool
    {
        $path = $this->normalizePath($path, false);
        if ($this->findEntry($storage, $path) !== null) {
            return false;
        }

        return $this->disk($storage)->makeDirectory($path);
    }

    public function move(string $storage, string $from, string $to): bool
    {
        $from = $this->normalizePath($from, false);
        $to = $this->normalizePath($to, false);
        if ($from === $to || \str_starts_with($to . '/', $from . '/')) {
            return false;
        }
        $entry = $this->findEntry($storage, $from);
        if ($entry === null || $this->findEntry($storage, $to) !== null) {
            return false;
        }

        $disk = $this->disk($storage);
        if ($entry['type'] !== 'directory') {
            $disk->move($from, $to);
            return true;
        }
        return $this->moveDirectory($disk, $from, $to);
    }

    public function delete(string $storage, string $path): bool
    {
        $path = $this->normalizePath($path, false);
        $entry = $this->findEntry($storage, $path);
        if ($entry === null) {
            return false;
        }
        $disk = $this->disk($storage);

        return $entry['type'] === 'directory'
            ? $disk->deleteDirectory($path)
            : $disk->delete($path);
    }

    private function disk(string $storage): StorageDiskInterface
    {
        $storage = \trim($storage);
        if ($storage === '') {
            throw new \InvalidArgumentException((string)__('存储源名称不能为空'));
        }
        if (!\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$/', $storage)) {
            throw new \InvalidArgumentException((string)__('存储源名称无效'));
        }
        return $this->storageManager->disk($storage);
    }

    /**
     * @return array{path:string,name:string,type:string,size:int,last_modified:?int}|null
     */
    private function findEntry(string $storage, string $path): ?array
    {
        $parent = \trim(\dirname($path), '/.');
        foreach ($this->list($storage, $parent, false) as $item) {
            if ($item['path'] === $path) {
                return $item;
            }
        }

        return null;
    }

    private function moveDirectory(StorageDiskInterface $disk, string $from, string $to): bool
    {
        $items = $disk->list($from, true);
        if (count($items) > self::MAX_DIRECTORY_MOVE_ITEMS) {
            throw new \RuntimeException((string)__('存储目录移动超过条目上限。'));
        }
        if (!$disk->makeDirectory($to)) {
            return false;
        }
        $copied = [];
        foreach ($items as $item) {
            try {
                $source = $this->normalizePath($item->object->objectKey, false);
            } catch (\InvalidArgumentException) {
                $this->rollbackCopies($disk, $copied, $to);
                return false;
            }
            if ($source !== $from && !\str_starts_with($source, $from . '/')) {
                continue;
            }
            $suffix = \ltrim(\substr($source, \strlen($from)), '/');
            $target = $suffix === '' ? $to : $to . '/' . $suffix;
            if (($item->metadata['type'] ?? '') === 'directory') {
                if ($source !== $from && !$disk->makeDirectory($target)) {
                    $this->rollbackCopies($disk, $copied, $to);
                    return false;
                }
                continue;
            }
            if (($item->metadata['type'] ?? 'file') !== 'file') {
                continue;
            }
            try {
                $disk->copy($source, $target);
            } catch (\Throwable) {
                $this->rollbackCopies($disk, $copied, $to);
                return false;
            }
            $copied[] = $target;
        }
        // A cross-provider directory move cannot be one atomic operation. Once
        // every target object is present, the target is the authoritative,
        // complete side of the move. Source cleanup is retried a bounded number
        // of times; a partial source must never make callers keep FileAsset rows
        // pointing at an incomplete source tree.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                if ($disk->deleteDirectory($from)) {
                    return true;
                }
            } catch (\Throwable) {
            }
            SchedulerSystem::yield();
        }
        StorageRuntimeDiagnostics::operationResidue('directory_move_source_cleanup_incomplete');
        if (\function_exists('w_log_warning')) {
            \w_log_warning(
                '[Storage] Directory move completed with a source cleanup residue.',
                ['disk_code' => $disk->diskCode()],
                'storage',
            );
        }

        return true;
    }

    /** @param list<string> $paths */
    private function rollbackCopies(StorageDiskInterface $disk, array $paths, string $targetDirectory): void
    {
        foreach ($paths as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
            }
        }
        try {
            if ($disk->deleteDirectory($targetDirectory)) {
                return;
            }
        } catch (\Throwable) {
        }
        StorageRuntimeDiagnostics::operationResidue('directory_move_target_rollback_incomplete');
        throw new \RuntimeException((string)__('目录移动失败，且目标副本无法完整回收。'));
    }

    private function normalizePath(string $path, bool $allowRoot): string
    {
        $path = \trim(\str_replace('\\', '/', $path), '/');
        if ($path === '') {
            if ($allowRoot) {
                return '';
            }
            throw new \InvalidArgumentException((string)__('不允许操作存储根目录'));
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException((string)__('存储路径无效'));
        }
        $segments = \explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException((string)__('存储路径无效'));
            }
        }

        return \implode('/', $segments);
    }

    private function belongsToDirectory(string $path, string $directory, bool $recursive): bool
    {
        $parent = \trim(\dirname($path), '/.');
        if (!$recursive) {
            return $parent === $directory;
        }

        return $directory === '' || \str_starts_with($path, $directory . '/');
    }
}
