<?php

declare(strict_types=1);

namespace Weline\Meta\Api;

use Weline\Meta\Api\Data\MetadataIdentity;
use Weline\Meta\Api\Data\MetadataRecord;
use Weline\Meta\Api\Data\MetadataSearch;
use Weline\Meta\Api\Data\MetadataWrite;

interface MetadataRepositoryInterface
{
    /** @return list<MetadataRecord> */
    public function search(MetadataSearch $search): array;

    public function resolve(MetadataIdentity $identity): ?MetadataRecord;

    public function upsert(MetadataWrite $metadata): MetadataRecord;

    /**
     * Execute grouped reads and writes; results stay aligned with input order.
     *
     * @param list<MetadataWrite> $metadata
     * @return list<MetadataRecord>
     */
    public function upsertBatch(array $metadata): array;

    public function delete(MetadataIdentity $identity): bool;
}
