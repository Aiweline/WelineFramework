<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class CleanRouteAliasTemplateProxyTest extends TestCase
{
    public function testCartCleanRouteAliasTemplateProxiesToCanonicalCartTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Cart/Index/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Cart::templates/frontend/cart/index.phtml", $template);
    }
}
