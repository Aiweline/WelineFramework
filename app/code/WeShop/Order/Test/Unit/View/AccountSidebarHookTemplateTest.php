<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testOrderModuleBridgesSidebarAndContentThroughCanonicalHooks(): void
    {
        $sidebarTemplate = __DIR__ . '/../../../view/hooks/account.sidebar.phtml';
        $contentTemplate = __DIR__ . '/../../../view/hooks/account.sidebar.content.phtml';

        $this->assertFileExists($sidebarTemplate);
        $this->assertFileExists($contentTemplate);

        $sidebar = (string) file_get_contents($sidebarTemplate);
        $content = (string) file_get_contents($contentTemplate);

        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="orders"', $sidebar);
        $this->assertStringContainsString('account-hook-nav-link', $sidebar);
        $this->assertStringNotContainsString('data-account-nav-parent', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-group', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-title', $sidebar);
        $this->assertStringNotContainsString('订单与服务', $sidebar);

        $this->assertStringContainsString('data-account-section="orders"', $content);
        $this->assertStringContainsString('id="orders-section"', $content);
        $this->assertStringContainsString('Weline_Customer::frontend::account::index::orders', $content);
    }
}
