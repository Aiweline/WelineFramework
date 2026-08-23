<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

/** Optional cross-module guard invoked before a disk configuration is deleted. */
interface StorageDiskUsageGuardInterface
{
    /** Throw a domain exception when the disk is still referenced. */
    public function assertCanDelete(string $diskCode): void;
}
