<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeInvoicePageTest extends TestCase
{
    public function testInvoiceThemePageWrapsModuleFrontendTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/invoice/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("WeShop_Invoice::templates/Frontend/Invoice/Index/index.phtml", $template);
    }
}
