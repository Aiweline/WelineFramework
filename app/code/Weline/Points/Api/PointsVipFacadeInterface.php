<?php

declare(strict_types=1);

namespace Weline\Points\Api;

/**
 * Server-side read boundary for growth, VIP level and benefits.
 *
 * Spendable balance is not exposed here; use CustomerAsset asset_code=points.
 */
interface PointsVipFacadeInterface
{
    /** @return array<string, mixed> */
    public function getCustomerState(int $customerId, int $websiteId = 0, string $namespace = 'live'): array;

    /** @return array<string, mixed> */
    public function getBenefits(int $customerId, int $websiteId = 0, string $namespace = 'live'): array;

    /** @return list<array<string, mixed>> */
    public function listLevels(): array;
}
