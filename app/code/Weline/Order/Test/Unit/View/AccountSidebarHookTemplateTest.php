<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testOrderModuleProvidesCanonicalSidebarAndContentHooks(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $sidebarTemplate = $moduleRoot . '/view/hooks/account.sidebar.phtml';
        $contentTemplate = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';
        $headerTemplate = $moduleRoot . '/view/hooks/header-orders.phtml';

        $this->assertFileExists($sidebarTemplate);
        $this->assertFileExists($contentTemplate);
        $this->assertFileExists($headerTemplate);

        $sidebar = (string) file_get_contents($sidebarTemplate);
        $content = (string) file_get_contents($contentTemplate);
        $header = (string) file_get_contents($headerTemplate);

        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="orders"', $sidebar);
        $this->assertStringContainsString('#orders', $sidebar);
        $this->assertStringContainsString('account-hook-nav-link', $sidebar);

        $this->assertStringContainsString('data-account-section="orders"', $content);
        $this->assertStringContainsString('id="orders-section"', $content);
        $this->assertStringContainsString('Weline_Order::frontend::account::index::orders', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);

        $this->assertStringContainsString('Hook: header-orders', $header);
        $this->assertStringContainsString('customer/account/index', $header);
        $this->assertStringContainsString('#orders', $header);
        $this->assertStringNotContainsString('/account/orders', $header);
    }
}
