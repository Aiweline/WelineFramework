<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderAccountLinksTemplateTest extends TestCase
{
    public function testHeaderAccountLinkTargetsAccountOrdersSection(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';
        $orderSidebarTemplate = dirname(__DIR__, 6) . '/code/Weline/Order/view/hooks/account.sidebar.phtml';

        $this->assertFileExists($templateFile);
        $this->assertFileExists($orderSidebarTemplate);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('Hook: account.sidebar', (string) file_get_contents($orderSidebarTemplate));
        $this->assertStringContainsString("@url{'customer/account/index'}#orders", $content);
        $this->assertStringNotContainsString("@url{'weshop/order/list'}", $content);
    }
}
