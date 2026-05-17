<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testRmaModuleProvidesExternalHostTemplateWithoutCrossModuleDeclarations(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';
        $this->assertIsArray($hooks);
        $this->assertSame([], $hooks);

        $this->assertFileExists(
            BP . '/app/code/WeShop/RMA/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml'
        );
        $this->assertFileExists(BP . '/app/code/WeShop/RMA/view/hooks/account.sidebar.phtml');
        $this->assertFileExists(BP . '/app/code/WeShop/RMA/view/hooks/account.sidebar.content.phtml');
    }

    public function testRmaModuleProvidesAccountSidebarNavigationAndContentSection(): void
    {
        $sidebar = file_get_contents(BP . '/app/code/WeShop/RMA/view/hooks/account.sidebar.phtml');
        $content = file_get_contents(BP . '/app/code/WeShop/RMA/view/hooks/account.sidebar.content.phtml');

        $this->assertIsString($sidebar);
        $this->assertIsString($content);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="returns"', $sidebar);
        $this->assertStringContainsString('#returns', $sidebar);
        $this->assertStringContainsString('退换货', $sidebar);
        $this->assertStringNotContainsString("getUrl('rma')", $sidebar);
        $this->assertStringContainsString('data-account-section="returns"', $content);
        $this->assertStringContainsString('RmaService::class', $content);
        $this->assertStringContainsString("getUrl('rma')", $content);
    }
}
