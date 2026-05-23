<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\AccountSidebarContentGate;

final class AccountSidebarContentGateTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__weline_account_sidebar_content_section']);
        parent::tearDown();
    }

    public function testAcceptsMatchingSectionOnlyWhenRequested(): void
    {
        $GLOBALS['__weline_account_sidebar_content_section'] = 'orders';

        $this->assertTrue(AccountSidebarContentGate::accepts('orders'));
        $this->assertFalse(AccountSidebarContentGate::accepts('returns'));
    }

    public function testRejectsAllSectionsWhenRequestMissing(): void
    {
        unset($GLOBALS['__weline_account_sidebar_content_section']);

        $this->assertSame('', AccountSidebarContentGate::requestedSection());
        $this->assertFalse(AccountSidebarContentGate::accepts('orders'));
    }
}
