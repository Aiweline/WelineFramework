<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class MotorThemeAccountLayoutHostTest extends TestCase
{
    public function testMotorAccountAuthLayoutKeepsSlotAndContentFallbackHosts(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/motor/frontend/layouts/account_auth/default.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("setLayout('base')", $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::base::content-before', $template);
        $this->assertStringContainsString('data-wslot="account-auth-main"', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::content-before', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::content-after', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::base::content-after', $template);
        $this->assertStringContainsString('{{meta.content}}', $template);
        $this->assertStringContainsString('{{content}}', $template);
        $this->assertStringContainsString('meta.contentTemplate', $template);
        $this->assertStringContainsString('contentTemplate', $template);
        $this->assertStringContainsString("getChildHtml('content')", $template);
    }

    public function testMotorAccountLayoutKeepsSlotAndContentFallbackHosts(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/motor/frontend/layouts/account/default.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("setLayout('base')", $template);
        $this->assertStringContainsString('data-wslot="account-main"', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::base::content-before', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account::content-before', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account::content-after', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::base::content-after', $template);
        $this->assertStringContainsString('{{meta.content}}', $template);
        $this->assertStringContainsString('{{content}}', $template);
        $this->assertStringContainsString('meta.contentTemplate', $template);
        $this->assertStringContainsString('contentTemplate', $template);
        $this->assertStringContainsString("getChildHtml('content')", $template);
    }
}
