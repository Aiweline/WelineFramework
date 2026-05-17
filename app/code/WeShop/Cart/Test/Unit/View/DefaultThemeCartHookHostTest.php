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
            'WeShop_Cart::summary::rows_before',
            'WeShop_Cart::summary::shipping_before',
            'WeShop_Cart::summary::tax_after',
            'WeShop_Cart::summary::grand_total_after',
            'WeShop_Cart::frontend::partials::cart::coupon-input',
            'WeShop_Cart::frontend::partials::cart::express-checkout',
            'WeShop_Cart::frontend::partials::cart::sidebar',
            'WeShop_Cart::frontend::layouts::cart::content-after',
        ];

        foreach ($expectedHooks as $hook) {
            $this->assertStringContainsString($hook, $template);
        }

        $this->assertStringContainsString('Items (%{1}):', $template);
        $this->assertStringContainsString('cart/frontend/api/update', $template);
        $this->assertStringContainsString('cart/frontend/api/remove', $template);
        $this->assertStringContainsString('cart/frontend/api/add', $template);
        $this->assertStringContainsString('showCartMessage', $template);
        $this->assertStringContainsString('add-to-cart', $template);
        $this->assertStringNotContainsString('Items (%1):', $template);
        $this->assertStringNotContainsString('confirm(', $template);
        $this->assertStringNotContainsString('alert(', $template);
    }

    public function testCartLayoutSellerLabelsUseWelineI18nPlaceholders(): void
    {
        $moduleTemplate = file_get_contents(__DIR__ . '/../../../view/theme/frontend/layouts/cart/default.phtml');
        $this->assertIsString($moduleTemplate);
        $this->assertStringContainsString("'%{1} items in your cart'", $moduleTemplate);
        $this->assertStringNotContainsString("'%1 items in your cart'", $moduleTemplate);

        foreach ([1, 2, 3, 4] as $variant) {
            $template = file_get_contents(__DIR__ . "/../../../../../../design/WeShop/default/frontend/layouts/cart/shopping_cart_page_{$variant}.phtml");
            $this->assertIsString($template);

            $this->assertStringContainsString('Sold by %{1}', $template);
            $this->assertStringContainsString('Only %{1} left in stock - order soon.', $template);
            $this->assertStringContainsString('Items (%{1}):', $template);
            $this->assertStringContainsString('ENT_QUOTES', $template);
            $this->assertStringNotContainsString('Sold by %1', $template);
            $this->assertStringNotContainsString('Only %1 left in stock - order soon.', $template);
            $this->assertStringNotContainsString('Items (%1):', $template);
        }
    }
}
