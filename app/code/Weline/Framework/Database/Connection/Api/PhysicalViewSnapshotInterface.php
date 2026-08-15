<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional exact physical view catalog snapshot/restore capability. */
interface PhysicalViewSnapshotInterface
{
    /** @return array<string, mixed> */
    public function capturePhysicalViewSnapshot(PhysicalViewIdentity $identity): array;

    /** @param array<string, mixed> $snapshot */
    public function restorePhysicalViewSnapshot(
        PhysicalViewIdentity $identity,
        array $snapshot,
        bool $currentlyExists,
    ): void;
}
