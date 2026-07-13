<?php

declare(strict_types=1);

namespace Weline\Meta\Api;

use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;

interface MetaConfigRepositoryInterface
{
    /** @return list<MetaConfigRecord> */
    public function search(MetaConfigSearch $search): array;

    /**
     * Locale resolution is deterministic: requested locale, zh_Hans_CN, then NULL.
     */
    public function resolve(MetaConfigIdentity $identity): ?MetaConfigRecord;

    /**
     * Execute one batched read and return results aligned with the input order.
     *
     * @param list<MetaConfigIdentity> $identities
     * @return list<MetaConfigRecord|null>
     */
    public function resolveBatch(array $identities): array;

    /** @return list<string> Sorted, unique scopes owned by the exact context. */
    public function listScopes(MetaConfigScopeSearch $search): array;

    public function upsert(MetaConfigWrite $config): MetaConfigRecord;

    /** Delete only the exact locale represented by the identity, including NULL. */
    public function delete(MetaConfigIdentity $identity): bool;
}
