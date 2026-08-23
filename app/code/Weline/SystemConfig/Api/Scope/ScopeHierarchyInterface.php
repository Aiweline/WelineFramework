<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Canonical Global → Website → Store → Channel hierarchy.
 *
 * Storage stays three-segment for compatibility. Identity claims remain typed;
 * callers must resolve an authoritative identity from server-side catalogs
 * before accepting client claims.
 */
interface ScopeHierarchyInterface
{
    public function contextFromIdentity(ScopeIdentity $identity): ScopeContext;

    /**
     * @param array<string, mixed> $claims Serialized ScopeIdentity claims.
     */
    public function contextFromClaims(array $claims, ScopeIdentity $authoritativeIdentity): ScopeContext;

    /** @return list<string> Canonical storage scopes, nearest first. */
    public function chainFromIdentity(ScopeIdentity $identity): array;

    public function parentIdentity(ScopeIdentity $identity): ?ScopeIdentity;

    public function toStorageScope(ScopeIdentity $identity): string;

    /** Legacy values are accepted only at explicit compatibility read boundaries. */
    public function fromStorageScope(string $storageScope, bool $allowLegacy = true): ?ScopeIdentity;

    /** Reject short, legacy or otherwise non-canonical raw scopes on new writes. */
    public function assertWritableRawScope(?string $rawScope): void;
}
