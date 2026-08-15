<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional exact physical table snapshot/restore capability. */
interface PhysicalTableSnapshotInterface
{
    /** @return array<string, mixed> */
    public function capturePhysicalTableSnapshot(PhysicalTableIdentity $identity): array;

    /** @param array<string, mixed> $snapshot */
    public function restorePhysicalTableSnapshot(
        PhysicalTableIdentity $identity,
        array $snapshot,
    ): void;

    /**
     * Insert rows while honoring generated/identity catalog semantics.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $snapshot
     */
    public function insertPhysicalTableSnapshotRows(
        PhysicalTableIdentity $identity,
        array $rows,
        array $snapshot,
    ): void;

    /** @param array<string, mixed> $snapshot */
    public function finalizePhysicalTableSnapshotRestore(
        PhysicalTableIdentity $identity,
        array $snapshot,
    ): void;

    public function physicalTableCatalogFingerprint(PhysicalTableIdentity $identity): string;
}
