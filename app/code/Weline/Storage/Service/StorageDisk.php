<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageReadHandle;
use Weline\Storage\Api\StorageUrlAdapterInterface;
use Weline\Storage\Api\StorageWriteHandle;

final readonly class StorageDisk implements StorageDiskInterface
{
    public function __construct(
        private StorageConfigSnapshot $configSnapshot,
        private StorageDriverInterface $driver,
        private StorageUrlAdapterInterface $urls,
    ) {
    }

    public function diskCode(): string { return $this->configSnapshot->diskCode; }
    public function snapshot(): StorageConfigSnapshot { return $this->configSnapshot; }
    public function openRead(string $objectKey): StorageReadHandle { return $this->driver->openRead($objectKey); }
    public function openWrite(string $objectKey, array $options = []): StorageWriteHandle { return $this->driver->openWrite($objectKey, $options); }
    public function exists(string $objectKey): bool { return $this->driver->exists($objectKey); }
    public function stat(string $objectKey): StorageObjectStat { return $this->driver->stat($objectKey); }
    public function delete(string $objectKey): bool { return $this->driver->delete($objectKey); }
    public function copy(string $fromObjectKey, string $toObjectKey): StorageObjectReference { return $this->driver->copy($fromObjectKey, $toObjectKey); }
    public function move(string $fromObjectKey, string $toObjectKey): StorageObjectReference { return $this->driver->move($fromObjectKey, $toObjectKey); }
    public function makeDirectory(string $objectKey): bool { return $this->driver->makeDirectory($objectKey); }
    public function deleteDirectory(string $objectKey): bool { return $this->driver->deleteDirectory($objectKey); }
    public function list(string $prefix = '', bool $recursive = false): array { return $this->driver->list($prefix, $recursive); }
    public function urlAdapter(): StorageUrlAdapterInterface { return $this->urls; }

    public function writeStream(string $objectKey, mixed $source, array $options = []): StorageObjectStat
    {
        if (!is_resource($source)) {
            throw new \InvalidArgumentException((string)__('上传源必须是可读取流。'));
        }
        $handle = $this->openWrite($objectKey, $options);
        $emptyReads = 0;
        try {
            while (!feof($source)) {
                if (function_exists('connection_aborted') && connection_aborted()) {
                    throw new \RuntimeException((string)__('客户端已断开，上传已取消。'));
                }
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('读取上传流失败。'));
                }
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('读取上传流时连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                $handle->write($chunk);
                SchedulerSystem::yield();
            }
            return $handle->complete();
        } catch (\Throwable $throwable) {
            try {
                $handle->abort();
            } catch (\Throwable) {
                // StorageWriteHandle records cleanup debt; request reset will
                // fail closed and quarantine the WLS Worker.
            }
            throw $throwable;
        }
    }

    public function resolveUrl(string $objectKey, ?StorageUrlOptions $options = null): ResolvedStorageUrl
    {
        $options ??= new StorageUrlOptions();
        $object = new StorageObjectReference($this->diskCode(), $objectKey);
        $resolved = match ($options->kind) {
            StorageUrlOptions::KIND_TEMPORARY => $this->urls->temporaryUrl($object, $options),
            StorageUrlOptions::KIND_IMAGE_VARIANT => $this->urls->imageVariantUrl($object, $options),
            default => $this->urls->publicUrl($object, $options),
        };
        if (!hash_equals($options->kind, $resolved->kind)) {
            throw new \RuntimeException((string)__('存储 URL 适配器返回的类型与请求不一致。'));
        }
        return $resolved;
    }
}
