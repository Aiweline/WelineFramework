<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Storage\Api\Data\StorageConfigSnapshot;

/** Transactional fence used before persisting a durable reference to a storage object. */
interface StorageConfigSnapshotGuardInterface
{
    /** Must be called inside the same database transaction that writes the durable reference. */
    public function assertWritable(StorageConfigSnapshot $snapshot): void;
}
