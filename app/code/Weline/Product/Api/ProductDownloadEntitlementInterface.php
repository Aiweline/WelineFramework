<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Framework\Runtime\ScopeIdentity;

interface ProductDownloadEntitlementInterface
{
    /**
     * Grant idempotent entitlements from immutable Order line snapshots.
     *
     * @return list<array<string,mixed>>
     */
    public function grantForPaidOrder(string $orderUuid): array;

    /**
     * Consume one customer-owned entitlement and return a short-lived URL.
     *
     * @return array<string,mixed>
     */
    public function consume(
        string $entitlementUuid,
        int $customerId,
        ScopeIdentity $scope,
        string $localeCode = '',
    ): array;

    /** @return list<array<string,mixed>> */
    public function listMine(int $customerId, int $websiteId, int $limit = 100): array;
}
