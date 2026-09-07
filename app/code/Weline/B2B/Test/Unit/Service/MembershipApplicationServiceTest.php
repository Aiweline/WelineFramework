<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\MembershipApplicationRecord;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\CommerceTypeMembershipChecker;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipApplicationService;

final class MembershipApplicationServiceTest extends TestCase
{
    public function testSubmitRejectsClientGroupId(): void
    {
        $svc = MembershipApplicationService::forTesting();

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('申请单不得由客户端指定客户组');
        $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
            'group_id' => 'g-hack',
        ]);
    }

    public function testSubmitListApproveAssignsMembership(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $svc = MembershipApplicationService::forTesting($groups);

        $created = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme Trading',
            'contact_phone' => '+86-10086',
            'notes' => 'please approve',
        ]);

        self::assertSame(MembershipApplicationRecord::STATUS_PENDING, $created['status']);
        self::assertNull($created['assigned_group_id']);
        self::assertCount(1, $svc->listPending(0));

        $approved = $svc->approve($created['application_id'], 'g-dealer');
        self::assertSame(MembershipApplicationRecord::STATUS_APPROVED, $approved['status']);
        self::assertSame('g-dealer', $approved['assigned_group_id']);
        self::assertSame([], $svc->listPending(0));

        $membership = $groups->groupForCustomer('42', 0);
        self::assertNotNull($membership);
        self::assertSame('g-dealer', $membership->groupId);

        $checker = CommerceTypeMembershipChecker::forTesting($groups);
        self::assertTrue($checker->hasMembership('tob', 42, 0));
        self::assertTrue($checker->hasMembership('toc', 42, 0));
        self::assertFalse($checker->hasMembership('tob', 99, 0));
    }

    public function testRejectLeavesNoMembership(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $svc = MembershipApplicationService::forTesting($groups);

        $created = $svc->submit([
            'customer_id' => '7',
            'website_id' => 1,
            'company_name' => 'No Corp',
            'contact_phone' => '123',
        ]);
        $rejected = $svc->reject($created['application_id'], 'incomplete docs');

        self::assertSame(MembershipApplicationRecord::STATUS_REJECTED, $rejected['status']);
        self::assertNull($groups->groupForCustomer('7', 1));
        self::assertStringContainsString('incomplete docs', (string)$rejected['notes']);
    }
}
