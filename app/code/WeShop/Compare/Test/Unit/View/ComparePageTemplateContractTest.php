<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class ComparePageTemplateContractTest extends TestCase
{
    public function testModuleCompareTemplateUsesCleanRouteTargets(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Frontend/Compare/Index/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString('data-weshop-compare-page="true"', $template);
        $this->assertStringContainsString("getUrl('weshop')", $template);
        $this->assertStringContainsString("getUrl('customer/account')", $template);
        $this->assertStringNotContainsString("getUrl('catalog/category')", $template);
        $this->assertStringNotContainsString("getUrl('weshop/customer/account/index')", $template);
    }
}
