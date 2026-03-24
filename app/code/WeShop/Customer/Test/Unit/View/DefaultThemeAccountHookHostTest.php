<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeAccountHookHostTest extends TestCase
{
    public function testAccountPageHostsSecurityDiscoveryAndOrderHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/customer/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::before', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::after', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::security::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::before', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::after', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::discovery::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::orders::cards', $template);
    }

    public function testAccountPageContainsCompareRecentlyViewedAndRmaEntries(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/customer/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getUrl('compare')", $template);
        $this->assertStringContainsString("getUrl('recently-viewed')", $template);
        $this->assertStringContainsString("getUrl('rma')", $template);
    }

    public function testAccountPageHostsQuickLinksAndRecommendationsHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/customer/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::before', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::after', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::quick-links::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::before', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::cards', $template);
        $this->assertStringContainsString('WeShop_Customer::frontend::account::recommendations::after', $template);
    }

    public function testCustomerLoginPageHostsSocialButtonsAndRedirectField(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/customer/login.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Social::frontend::partials::login::buttons', $template);
        $this->assertStringContainsString('name="redirect_url"', $template);
    }
}
