<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeAccountAuthLayoutHostTest extends TestCase
{
    /**
     * @return array<int, array{0:string}>
     */
    public static function layoutVariantProvider(): array
    {
        return [
            ['login_page_1.phtml'],
            ['login_page_2.phtml'],
            ['login_page_3.phtml'],
            ['register_page_1.phtml'],
            ['register_page_2.phtml'],
            ['sign_up_page_1.phtml'],
            ['sign_up_page_2.phtml'],
            ['password_reset_page_1.phtml'],
            ['password_reset_page_2.phtml'],
        ];
    }

    /**
     * @dataProvider layoutVariantProvider
     */
    public function testAccountAuthLayoutsRenderContentWithHookCompatibleHosts(string $file): void
    {
        $template = file_get_contents(
            __DIR__ . '/../../../../../../design/WeShop/default/frontend/layouts/account_auth/' . $file
        );
        $this->assertIsString($template);

        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::head-after', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::body-start', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::content-before', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::content-after', $template);
        $this->assertStringContainsString('Weline_Theme::frontend::layouts::account_auth::body-end', $template);

        $this->assertStringContainsString('{{meta.content}}', $template);
        $this->assertStringContainsString('{{content}}', $template);
        $this->assertStringContainsString("getChildHtml('content')", $template);
    }
}

