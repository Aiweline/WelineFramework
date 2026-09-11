<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\MembershipApplicationRecord;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipApplicationService;
use Weline\B2B\Service\MembershipStatusProjection;

final class MembershipStatusProjectionTest extends TestCase
{
    public function testNeedLoginWhenCustomerEmpty(): void
    {
        $proj = $this->projection();
        $snap = $proj->snapshot('', 0);

        self::assertSame(MembershipStatusProjection::UI_NEED_LOGIN, $snap['ui_state']);
        self::assertFalse($snap['should_poll']);
        self::assertFalse($snap['can_submit']);
    }

    public function testPendingPollsAndBlocksSubmit(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $apps = MembershipApplicationService::forTesting($groups);
        $apps->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);

        $snap = (new MembershipStatusProjection($groups, $apps))->snapshot('42', 0);
        self::assertSame(MembershipStatusProjection::UI_PENDING, $snap['ui_state']);
        self::assertTrue($snap['should_poll']);
        self::assertFalse($snap['can_submit']);
        self::assertSame(MembershipApplicationRecord::STATUS_PENDING, $snap['application_status']);
    }

    public function testActiveMembershipWinsOverPendingApplication(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $apps = MembershipApplicationService::forTesting($groups);
        $created = $apps->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
        // Half-success dirty state: membership assigned while application still pending.
        $groups->assignCustomer('42', 'g-dealer');

        $snap = (new MembershipStatusProjection($groups, $apps))->snapshot('42', 0);
        self::assertSame(MembershipStatusProjection::UI_ACTIVE, $snap['ui_state']);
        self::assertFalse($snap['should_poll']);
        self::assertTrue($snap['has_membership']);
        self::assertSame(MembershipApplicationRecord::STATUS_PENDING, $snap['application_status']);
        self::assertSame($created['application_id'], $snap['application_id']);
    }

    public function testRevokedApprovedApplicationBecomesInactiveForResubmit(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $apps = MembershipApplicationService::forTesting($groups);
        $created = $apps->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
        $apps->approve($created['application_id'], 'g-dealer');
        $groups->unassignCustomer('42', 0);

        $snap = (new MembershipStatusProjection($groups, $apps))->snapshot('42', 0);
        self::assertSame(MembershipStatusProjection::UI_INACTIVE, $snap['ui_state']);
        self::assertTrue($snap['can_submit']);
        self::assertFalse($snap['should_poll']);
        self::assertSame(MembershipApplicationRecord::STATUS_APPROVED, $snap['application_status']);
        self::assertSame($created['application_id'], $snap['application_id']);
        self::assertNotSame('approved', $snap['ui_state']);
    }

    public function testDisabledGroupMapsInactive(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dead', 0, 'dead', CustomerGroup::STATUS_DISABLED));
        $groups->assignCustomer('42', 'g-dead');
        $apps = MembershipApplicationService::forTesting($groups);

        $snap = (new MembershipStatusProjection($groups, $apps))->snapshot('42', 0);
        self::assertSame(MembershipStatusProjection::UI_INACTIVE, $snap['ui_state']);
        self::assertTrue($snap['can_submit']);
        self::assertFalse($snap['should_poll']);
    }

    public function testRejectedAllowsResubmit(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $apps = MembershipApplicationService::forTesting($groups);
        $created = $apps->submit([
            'customer_id' => '7',
            'website_id' => 1,
            'company_name' => 'No',
            'contact_phone' => '1',
        ]);
        $apps->reject($created['application_id'], 'no');

        $snap = (new MembershipStatusProjection($groups, $apps))->snapshot('7', 1);
        self::assertSame(MembershipStatusProjection::UI_REJECTED, $snap['ui_state']);
        self::assertTrue($snap['can_submit']);
        self::assertFalse($snap['should_poll']);
    }

    private function projection(): MembershipStatusProjection
    {
        $groups = CustomerGroupStore::forTesting();
        $apps = MembershipApplicationService::forTesting($groups);
        return new MembershipStatusProjection($groups, $apps);
    }
}
