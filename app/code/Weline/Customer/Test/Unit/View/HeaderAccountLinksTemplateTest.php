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
        $this->assertStringContainsString("@url{'customer/account/index'}", $content);
        $this->assertStringContainsString("@url{'customer/account/orders'}", $content);
        $this->assertStringContainsString("@url{'customer/account/settings'}", $content);
        $this->assertStringContainsString("@url{'customer/account/profile'}", $content);
        $this->assertStringContainsString("@url{'customer/account/address'}", $content);
        $this->assertStringContainsString("@url{'customer/account/password'}", $content);
        $this->assertStringContainsString("@url{'customer/account/logout'}", $content);
        $this->assertStringContainsString("@url{'customer/account/login'}", $content);
        $this->assertStringContainsString("@url{'customer/account/register'}", $content);
    }
}
