<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeCartHookHostTest extends TestCase
{
    public function testCartPageHostsModernCartHooksAlongsideLegacyHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/cart/index.phtml');
        $this->assertIsString($template);

        $expectedHooks = [
            'WeShop_Cart::page::before',
            'WeShop_Cart::frontend::layouts::cart::content-before',
            'WeShop_Cart::frontend::partials::cart::header-before',
            'WeShop_Cart::frontend::partials::cart::items-before',
            'WeShop_Cart::frontend::partials::cart::item-after',
            'WeShop_Cart::frontend::partials::cart::summary-before',
            'WeShop_Cart::frontend::partials::cart::coupon-input',
            'WeShop_Cart::frontend::partials::cart::express-checkout',
            'WeShop_Cart::frontend::partials::cart::sidebar',
            'WeShop_Cart::frontend::layouts::cart::content-after',
        ];

        foreach ($expectedHooks as $hook) {
            $this->assertStringContainsString($hook, $template);
        }
    }
}
