<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/**
 * Forward-only reader for one exact column plus its primary key.
 * Schema column backup streams it so a wide TEXT column is never materialized
 * as one result set.
 */
interface PhysicalColumnKeysetReaderInterface
{
    /**
     * One ordered scan. The caller must not run another statement on this
     * connection until the generator is finished or closed: MySQL leaves the
     * connection unbuffered for the life of the cursor.
     *
     * @param non-empty-list<string> $primaryKeyColumns
     * @param array<string, mixed>|null $afterPrimaryKey
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamPhysicalColumnKeyset(
        PhysicalTableIdentity $identity,
        array $primaryKeyColumns,
        string $columnName,
        ?array $afterPrimaryKey,
    ): \Generator;
}
