<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderAccountLinksTemplateTest extends TestCase
{
    public function testHeaderAccountLinksUseExplicitFrontendRoutes(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringNotContainsString("\$frontendUrl = \$this->getUrl('');", $content);
        $this->assertStringNotContainsString('<?= $frontendUrl ?>shipping/', $content);
        $this->assertStringNotContainsString("@url{'shipping/address/index'}", $content);
        $this->assertStringNotContainsString("@url{'shipping/delivery/index'}", $content);
        $this->assertStringNotContainsString("\$this->getFrontendUrl('shipping/", $content);
        $this->assertStringContainsString("@url{'customer/account/index'}#shipping-address", $content);
        $this->assertStringContainsString("@url{'customer/account/index'}#delivery-address", $content);
    }
}
