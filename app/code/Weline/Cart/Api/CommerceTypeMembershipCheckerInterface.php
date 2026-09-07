<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Optional membership checker for non-toc commerce types.
 * Cart never imports Weline_B2B; providers register via module provides.
 */
interface CommerceTypeMembershipCheckerInterface
{
    public function hasMembership(string $typeCode, int $customerId, int $websiteId): bool;
}
