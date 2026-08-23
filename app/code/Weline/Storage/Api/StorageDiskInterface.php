<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\Data\StorageUrlOptions;

interface StorageDiskInterface
{
    public function diskCode(): string;

    public function snapshot(): StorageConfigSnapshot;

    public function openRead(string $objectKey): StorageReadHandle;

    /** @param array<string,mixed> $options */
    public function openWrite(string $objectKey, array $options = []): StorageWriteHandle;

    /** @param resource $source */
    public function writeStream(string $objectKey, mixed $source, array $options = []): StorageObjectStat;

    public function exists(string $objectKey): bool;

    public function stat(string $objectKey): StorageObjectStat;

    public function delete(string $objectKey): bool;

    public function copy(string $fromObjectKey, string $toObjectKey): StorageObjectReference;

    public function move(string $fromObjectKey, string $toObjectKey): StorageObjectReference;

    public function makeDirectory(string $objectKey): bool;

    public function deleteDirectory(string $objectKey): bool;

    /** @return list<StorageObjectStat> */
    public function list(string $prefix = '', bool $recursive = false): array;

    public function urlAdapter(): StorageUrlAdapterInterface;

    public function resolveUrl(string $objectKey, ?StorageUrlOptions $options = null): ResolvedStorageUrl;
}
