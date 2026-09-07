<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;
use Weline\Cart\Api\CommerceTypeMembershipCheckerInterface;

/** B2B-backed membership checker for Cart SellingTypeResolver / Gate. */
final class CommerceTypeMembershipChecker implements CommerceTypeMembershipCheckerInterface
{
    public function __construct(private readonly CustomerGroupStore $groups)
    {
    }

    public static function forTesting(?CustomerGroupStore $groups = null): self
    {
        return new self($groups ?? CustomerGroupStore::forTesting());
    }

    public function hasMembership(string $typeCode, int $customerId, int $websiteId): bool
    {
        $code = strtolower(trim($typeCode));
        if ($code === '' || $code === 'toc') {
            return true;
        }
        if ($code !== 'tob' || $customerId <= 0 || $websiteId < 0) {
            return false;
        }

        $group = $this->groups->groupForCustomer((string)$customerId, $websiteId);
        return $group !== null && $group->status === CustomerGroup::STATUS_ACTIVE;
    }
}
