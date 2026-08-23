<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Server-authoritative identity/catalog boundary supplied by the scope owner.
 *
 * SystemConfig deliberately does not know Website/Store/Channel models.
 */
interface ScopeIdentityCatalogInterface
{
    /** Optional authoritative catalog contributions published by identity-owner modules. */
    public const CONTRIBUTOR_CAPABILITY_PREFIX = 'system_config.scope_identity_catalog.';

    public function websiteIdForCode(string $websiteCode): int;

    /** Resolve and validate all parent/child code claims. */
    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity;

    /**
     * @return list<array{
     *   code:string,
     *   name:string,
     *   website_id:int,
     *   stores:list<array{
     *     id:int,
     *     code:string,
     *     name:string,
     *     store_mode:string,
     *     channels:list<array{id:int,code:string,name:string}>
     *   }>
     * }>
     */
    public function options(): array;
}
