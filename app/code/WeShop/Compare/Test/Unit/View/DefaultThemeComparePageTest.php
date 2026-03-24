<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeComparePageTest extends TestCase
{
    public function testDefaultThemeComparePageWrapsModuleCompareTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/compare/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            "WeShop_Compare::templates/Frontend/Compare/Index/index.phtml",
            $template
        );
    }
}
