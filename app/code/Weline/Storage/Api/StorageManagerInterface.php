<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

interface StorageManagerInterface
{
    public function disk(string $diskCode): StorageDiskInterface;

    public function defaultDisk(): StorageDiskInterface;

    public function canonicalizeDiskCode(string $diskCode): string;

    /** @return list<array<string,mixed>> */
    public function catalog(): array;
}
