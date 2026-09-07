<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderAccountLinksTemplateTest extends TestCase
{
    public function testHeaderAccountLinksUseExplicitFrontendRoutes(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringNotContainsString("\$frontendUrl = \$this->getUrl('');", $content);
        $this->assertStringNotContainsString('<?= $frontendUrl ?>customer/account/', $content);
        $this->assertStringNotContainsString("\$this->getFrontendUrl('customer/account/", $content);
        $this->assertStringContainsString("@url{'customer/account/index'}#overview", $content);
        $this->assertStringContainsString("@url{'customer/account/index'}#profile", $content);
        $this->assertStringContainsString("@url{'customer/account/index'}#security", $content);
        $this->assertStringNotContainsString("@url{'customer/account/orders'}", $content);
        $this->assertStringNotContainsString("@url{'customer/account/settings'}", $content);
        $this->assertStringNotContainsString("@url{'customer/account/profile'}", $content);
        $this->assertStringNotContainsString("@url{'customer/account/address'}", $content);
        $this->assertStringNotContainsString("@url{'customer/account/password'}", $content);
        // Logout is host-owned (Theme account widget / header-account), not a hook menu item.
        $this->assertStringNotContainsString("@url{'customer/account/logout'}", $content);
        $this->assertStringNotContainsString('data-account-logout-confirm', $content);
        $this->assertStringNotContainsString('退出登录', $content);
        $this->assertStringContainsString("@url{'customer/account/login'}", $content);
        $this->assertStringContainsString("@url{'customer/account/register'}", $content);
        $this->assertStringContainsString('data-account-menu-auth="signed-in"', $content);
        $this->assertStringContainsString('data-account-menu-auth="guest"', $content);
        $this->assertStringNotContainsString('$session->isLoggedIn()', $content);
        $this->assertStringNotContainsString('createFrontendSession', $content);
    }
}
