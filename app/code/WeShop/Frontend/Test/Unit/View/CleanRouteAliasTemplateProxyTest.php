<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class CleanRouteAliasTemplateProxyTest extends TestCase
{
    public function testProductListCleanRouteAliasTemplateProxiesToCanonicalListTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Product/List/Index/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Product::templates/frontend/product/list/index.phtml", $template);
    }

    public function testCartCleanRouteAliasTemplateProxiesToCanonicalCartTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Cart/Index/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Cart::templates/frontend/cart/index.phtml", $template);
    }

    public function testCustomerLoginProxyTemplateUsesCanonicalCustomerTemplatePath(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/frontend/customer/account/login.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("Weline_Customer::templates/frontend/account/login.phtml", $template);
        $this->assertStringNotContainsString("Weline_Customer::frontend/account/login.phtml", $template);
    }
}
