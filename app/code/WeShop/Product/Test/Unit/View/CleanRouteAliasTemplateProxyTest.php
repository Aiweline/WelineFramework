<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class CleanRouteAliasTemplateProxyTest extends TestCase
{
    public function testProductListCleanRouteAliasTemplateProxiesToCanonicalListTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/List/Index/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Product::templates/frontend/product/list/index.phtml", $template);
    }

    public function testCanonicalProductListTemplateExistsAsAnEmptyThemeHost(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/frontend/product/list/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString('Canonical product list template host intentionally left empty.', $template);
    }

    public function testProductCleanRouteAliasTemplateProxiesToCanonicalProductTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/View/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Product::templates/frontend/product/view.phtml", $template);
    }
}
