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
     * Read every scope of a resolution chain in one query.
     *
     * The search DTO owns namespace, owner, key and locale filters; its scope
     * value is ignored in favour of $scopes. Empty $scopes returns [] without
     * touching the database. Rows keep the same ordering as search():
     * config_key ASC, locale ASC, id ASC.
     *
     * @param list<string> $scopes
     * @return list<MetaConfigRecord>
     */
    public function searchScopes(MetaConfigSearch $search, array $scopes): array;

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
