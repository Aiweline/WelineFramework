<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeB2BHookHostTest extends TestCase
{
    public function testB2BPageHostsCanonicalHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/b2b/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_B2B::frontend::layouts::business::page-before', $template);
        $this->assertStringContainsString('WeShop_B2B::frontend::partials::company::list-after', $template);
    }
}
