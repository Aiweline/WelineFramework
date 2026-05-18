<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HeaderAccountHookTemplateTest extends TestCase
{
    public function testHeaderAccountHookUsesWorkerOrderApiForUnpaidBadge(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/header-account.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("window.Weline.Api.resource('order')", $template);
        $this->assertStringContainsString('OrderApi.unpaidSummary', $template);
        $this->assertStringNotContainsString('api/rest/v1/weshop_order/order/unpaid-count', $template);
        $this->assertStringNotContainsString('api/rest/v1/weshop_order/order/unpaid-list', $template);
    }

    public function testHeaderAccountHookOnlyOwnsTriggerAndBadge(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/header-account.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('id="header-account-link"', $template);
        $this->assertStringContainsString('id="unpaid-orders-badge"', $template);
        $this->assertStringNotContainsString('id="account-dropdown"', $template);
        $this->assertStringNotContainsString('<div class="account-dropdown"', $template);
        $this->assertStringNotContainsString('<w:hook>header-account-links</w:hook>', $template);
        $this->assertStringNotContainsString('$frontendUrl ?>weshop', $template);
        $this->assertStringNotContainsString('pagebuilder/frontend/page/viewweshop', $template);
    }

    public function testHeaderAccountTriggerUsesCustomerAccountRoutes(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/header-account.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getUrl('customer/account/index')", $template);
        $this->assertStringContainsString("getUrl('customer/account/login')", $template);
        $this->assertStringNotContainsString("getUrl('weshop/customer/account/index')", $template);
        $this->assertStringNotContainsString("getUrl('weshop/customer/account/logout')", $template);
    }
}
