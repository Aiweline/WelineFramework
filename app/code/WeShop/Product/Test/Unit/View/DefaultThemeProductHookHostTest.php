<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeProductHookHostTest extends TestCase
{
    public function testProductViewHostsModernDetailHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/product/view.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Product::frontend::product::detail::after-add-to-cart', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::product::add-to-cart::options-popup', $template);
        $this->assertStringContainsString('WeShop_Product::detail::after_add_to_cart', $template);
    }
}
