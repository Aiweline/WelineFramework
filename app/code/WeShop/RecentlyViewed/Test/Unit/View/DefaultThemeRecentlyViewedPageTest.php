<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeRecentlyViewedPageTest extends TestCase
{
    public function testRecentlyViewedThemePageUsesRegisteredRouteTargets(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/recently-viewed/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('weshop')", $template);
        $this->assertStringContainsString("getUrl('weshop_customer/frontend/account')", $template);
        $this->assertStringContainsString("getUrl('recently-viewed/remove')", $template);
        $this->assertStringContainsString("getUrl('product/view'", $template);
        $this->assertStringNotContainsString("getUrl('catalog/category')", $template);
        $this->assertStringNotContainsString("getUrl('weshop/customer/account/index')", $template);
    }
}
