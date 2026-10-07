<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cart owns the mini-cart qty hit-target floor; Theme chrome must not encode it.
 */
final class MiniCartQtyHitGateContractTest extends TestCase
{
    public function testCartOwnsMiniCartQtyHitCssGate(): void
    {
        $root = dirname(__DIR__, 3);
        $css = (string)file_get_contents($root . '/view/statics/css/mini-cart-drawer-qty-hit.css');
        $cartJs = (string)file_get_contents($root . '/view/statics/js/cart.js');
        $modules = (string)file_get_contents($root . '/view/statics/frontend/weline.modules.js');
        $themeWidget = (string)file_get_contents(
            dirname($root) . '/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml'
        );
        $themeDrawerCss = (string)file_get_contents(
            dirname($root) . '/Theme/view/statics/css/widgets/mini-cart-drawer.css'
        );

        self::assertStringContainsString('min-height: min(40vh, 12rem)', $css);
        self::assertStringContainsString(
            'max-height: calc(100% - min(40vh, 12rem) - 5.5rem)',
            $css
        );
        self::assertStringContainsString('ensureMiniCartQtyHitCss', $cartJs);
        self::assertStringContainsString('mini-cart-drawer-qty-hit.css', $cartJs);
        self::assertStringContainsString('20261007-cart-qty-hit-v1', $modules);
        self::assertStringContainsString('Weline_Cart::css/mini-cart-drawer-qty-hit.css', $themeWidget);
        // Theme base footer cap stays 100px; Cart sheet overrides the interaction floor.
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*max-height:\\s*calc\\(100%\\s*-\\s*100px\\)/s',
            $themeDrawerCss
        );
        self::assertDoesNotMatchRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__body\\s*\\{[^}]*min-height:\\s*min\\(40vh,\\s*12rem\\)/s',
            $themeDrawerCss
        );
    }
}
