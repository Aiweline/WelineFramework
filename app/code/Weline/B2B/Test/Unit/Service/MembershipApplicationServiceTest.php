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

    public function testSubmitRejectsDuplicatePending(): void
    {
        $svc = MembershipApplicationService::forTesting();
        $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('已有待审核的批发身份申请');
        $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme 2',
            'contact_phone' => '10087',
        ]);
    }

    public function testSubmitRejectsWhenAlreadyActiveMember(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $groups->assignCustomer('42', 'g-dealer');
        $svc = MembershipApplicationService::forTesting($groups);

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('已开通批发身份');
        $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
    }

    public function testLatestForCustomerAndUnassign(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $svc = MembershipApplicationService::forTesting($groups);
        $created = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
        $svc->approve($created['application_id'], 'g-dealer');
        self::assertSame(
            MembershipApplicationRecord::STATUS_APPROVED,
            $svc->latestForCustomer('42', 0)['status'] ?? null,
        );
        self::assertTrue($groups->unassignCustomer('42', 0));
        self::assertNull($groups->groupForCustomer('42', 0));
        self::assertFalse($groups->unassignCustomer('42', 0));
    }

    public function testSubmitAfterRevokeUpdatesSameApplication(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $svc = MembershipApplicationService::forTesting($groups);
        $created = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
        $svc->approve($created['application_id'], 'g-dealer');
        self::assertTrue($groups->unassignCustomer('42', 0));

        $again = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme Updated',
            'contact_phone' => '10087',
            'notes' => 'please restore',
        ]);

        self::assertSame($created['application_id'], $again['application_id']);
        self::assertSame(MembershipApplicationRecord::STATUS_PENDING, $again['status']);
        self::assertSame('Acme Updated', $again['company_name']);
        self::assertSame('10087', $again['contact_phone']);
        self::assertNull($again['assigned_group_id']);
        self::assertCount(1, $svc->listPending(0));
        self::assertSame($created['application_id'], $svc->latestForCustomer('42', 0)['application_id'] ?? null);
    }

    public function testSubmitAfterRejectUpdatesSameApplication(): void
    {
        $svc = MembershipApplicationService::forTesting();
        $created = $svc->submit([
            'customer_id' => '7',
            'website_id' => 1,
            'company_name' => 'No Corp',
            'contact_phone' => '123',
        ]);
        $svc->reject($created['application_id'], 'incomplete docs');

        $again = $svc->submit([
            'customer_id' => '7',
            'website_id' => 1,
            'company_name' => 'No Corp 2',
            'contact_phone' => '456',
        ]);

        self::assertSame($created['application_id'], $again['application_id']);
        self::assertSame(MembershipApplicationRecord::STATUS_PENDING, $again['status']);
        self::assertSame('No Corp 2', $again['company_name']);
        self::assertCount(1, $svc->listPending(1));
    }

    public function testReauthorizeAfterRevokeAssignsGroupAndKeepsApproved(): void
    {
        $groups = CustomerGroupStore::forTesting();
        $groups->put(new CustomerGroup('g-dealer', 0, 'dealer', CustomerGroup::STATUS_ACTIVE));
        $groups->put(new CustomerGroup('g-vip0', 0, 'vip0', CustomerGroup::STATUS_ACTIVE));
        $svc = MembershipApplicationService::forTesting($groups);
        $created = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);
        $approved = $svc->approve($created['application_id'], 'g-dealer');
        self::assertSame(MembershipApplicationRecord::STATUS_APPROVED, $approved['status']);
        self::assertTrue($groups->unassignCustomer('42', 0));

        $restored = $svc->reauthorize($created['application_id'], 'g-vip0');
        self::assertSame(MembershipApplicationRecord::STATUS_APPROVED, $restored['status']);
        self::assertSame('g-vip0', $restored['assigned_group_id']);
        self::assertSame('g-vip0', $groups->groupForCustomer('42', 0)?->groupId);

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('已开通批发身份');
        $svc->reauthorize($created['application_id'], 'g-dealer');
    }

    public function testDeleteRemovesApplicationRow(): void
    {
        $svc = MembershipApplicationService::forTesting();
        $created = $svc->submit([
            'customer_id' => '42',
            'website_id' => 0,
            'company_name' => 'Acme',
            'contact_phone' => '10086',
        ]);

        $deleted = $svc->delete($created['application_id']);
        self::assertTrue($deleted['deleted']);
        self::assertSame($created['application_id'], $deleted['application_id']);
        self::assertNull($svc->latestForCustomer('42', 0));
        self::assertSame([], $svc->listPending(0));

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('申请单不存在');
        $svc->delete($created['application_id']);
    }
}
