<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeHeaderAccountRouteTest extends TestCase
{
    public function testDefaultHeaderLinksToCanonicalStorefrontAccountRoute(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/partials/header/default.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('weshop/customer/account/index')", $template);
        $this->assertStringNotContainsString("getUrl('customer/account')", $template);
    }
}
