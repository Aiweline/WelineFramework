<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

/**
 * 组 ACL：只有当前组成员可签发/提交对应 B2B quote。
 */
final class B2BAclGuard
{
    public const ERROR_NOT_MEMBER = 'b2b_acl_customer_not_in_group';
    public const ERROR_CUSTOMER_MISMATCH = 'b2b_acl_customer_mismatch';
    public const ERROR_WEBSITE_MISMATCH = 'b2b_acl_website_mismatch';

    public function __construct(private readonly CustomerGroupStore $groups)
    {
    }

    public function assertCustomerOwnsQuote(string $customerId, string $quoteCustomerId): void
    {
        if ($customerId !== $quoteCustomerId) {
            throw new B2BConflictException(self::ERROR_CUSTOMER_MISMATCH, 'customer mismatch', [
                'customer_id' => $customerId,
                'quote_customer_id' => $quoteCustomerId,
            ]);
        }
    }

    public function assertWebsiteOwnsQuote(int $websiteId, int $quoteWebsiteId): void
    {
        if ($websiteId < 0 || $websiteId !== $quoteWebsiteId) {
            throw new B2BConflictException(self::ERROR_WEBSITE_MISMATCH, 'Website mismatch', [
                'website_id' => $websiteId,
                'quote_website_id' => $quoteWebsiteId,
            ]);
        }
    }

    public function assertGroupMembership(
        string $customerId,
        int $websiteId,
        ?string $expectedGroupId,
    ): void
    {
        if ($expectedGroupId === null || $expectedGroupId === '') {
            return;
        }
        $group = $this->groups->groupForCustomer($customerId, $websiteId);
        if ($group === null || $group->groupId !== $expectedGroupId) {
            throw new B2BConflictException(self::ERROR_NOT_MEMBER, 'group membership changed', [
                'customer_id' => $customerId,
                'expected_group_id' => $expectedGroupId,
                'actual_group_id' => $group?->groupId,
            ]);
        }
    }
}
