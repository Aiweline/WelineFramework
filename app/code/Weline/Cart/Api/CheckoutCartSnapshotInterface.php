<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Cart-owned authoritative Checkout freeze boundary.
 *
 * Checkout must not rebuild Cart internals or accept browser line facts.
 */
interface CheckoutCartSnapshotInterface
{
    /**
     * Re-resolve every current Cart line against its owning provider.
     *
     * @return array{
     *   scope:array<string,mixed>,
     *   currency:string,
     *   customer_id:?int,
     *   owner_kind:string,
     *   owner_id:string,
     *   lines:list<array<string,mixed>>,
     *   cart_hash:string
     * }
     */
    public function freeze(
        ScopeIdentity $scope,
        ?string $guestToken = null,
        ?int $customerId = null,
    ): array;
}
