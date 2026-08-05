<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Cart V2 snapshot SPI（REQ-009 / MOD-P2E-001）.
 */
interface CartItemSnapshotProviderV2Interface
{
    public function getProviderCode(): string;

    /**
     * @param array<string, scalar|null> $selection
     * Return null when this provider does not own the offer.
     */
    public function resolveCartItemSnapshot(
        OfferIdentity $offer,
        ScopeIdentity $scope,
        array $selection = [],
    ): ?CartItemSnapshot;
}
