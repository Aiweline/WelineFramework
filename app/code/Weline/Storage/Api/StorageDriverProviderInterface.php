<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;

interface StorageDriverProviderInterface
{
    /** Two canonical disk-code segments, for example local::filesystem. */
    public function providerCode(): string;

    public function createDriver(
        StorageConfigSnapshot $snapshot,
        StorageRequestResourceRegistryInterface $resources,
    ): StorageDriverInterface;

    public function createUrlAdapter(
        StorageConfigSnapshot $snapshot,
        StorageRequestResourceRegistryInterface $resources,
    ): StorageUrlAdapterInterface;
}
