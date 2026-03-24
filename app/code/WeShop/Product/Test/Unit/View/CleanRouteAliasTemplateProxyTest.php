<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class CleanRouteAliasTemplateProxyTest extends TestCase
{
    public function testProductCleanRouteAliasTemplateProxiesToCanonicalProductTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/View/index.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Product::templates/frontend/product/view.phtml", $template);
    }
}
