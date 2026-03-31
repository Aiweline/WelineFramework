<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testSocialModuleDeclaresLoginAndFooterHooks(): void
    {
        $hooks = require __DIR__ . '/../../../hook.php';

        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('WeShop_Social::frontend::partials::login::buttons', $hooks);
        $this->assertArrayHasKey('WeShop_Social::frontend::partials::footer::social-links', $hooks);
        $this->assertFileExists(
            BP . '/app/code/WeShop/Social/view/hooks/WeShop_Social/frontend/partials/footer/social-links.phtml'
        );
    }

    public function testFooterHookTemplateUsesSocialQueryProvider(): void
    {
        $template = file_get_contents(
            BP . '/app/code/WeShop/Social/view/hooks/WeShop_Social/frontend/partials/footer/social-links.phtml'
        );

        $this->assertIsString($template);
        $this->assertStringContainsString("w_query('social', 'getFooterSocialLinks')", $template);
        $this->assertStringContainsString('data-weshop-social-footer-links', $template);
    }
}
