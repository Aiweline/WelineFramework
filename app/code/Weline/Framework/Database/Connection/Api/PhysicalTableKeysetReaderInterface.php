<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional exact-table deterministic keyset reader used by physical backups. */
interface PhysicalTableKeysetReaderInterface
{
    /**
     * @param non-empty-list<string> $primaryKeyColumns
     * @param array<string, mixed>|null $afterPrimaryKey
     * @return list<array<string, mixed>>
     */
    public function readPhysicalTableKeysetChunk(
        PhysicalTableIdentity $identity,
        array $primaryKeyColumns,
        ?array $afterPrimaryKey,
        int $limit,
    ): array;
}
