<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\AccountSidebarContentGate;

final class AccountSidebarContentGateTest extends TestCase
{
    protected function tearDown(): void
    {
        AccountSidebarContentGate::setRequestedSection(null);
        parent::tearDown();
    }

    public function testAcceptsMatchingSectionOnlyWhenRequested(): void
    {
        AccountSidebarContentGate::setRequestedSection('orders');

        $this->assertTrue(AccountSidebarContentGate::accepts('orders'));
        $this->assertFalse(AccountSidebarContentGate::accepts('returns'));
    }

    public function testRejectsAllSectionsWhenRequestMissing(): void
    {
        $this->assertSame('', AccountSidebarContentGate::requestedSection());
        $this->assertFalse(AccountSidebarContentGate::accepts('orders'));
    }
}
