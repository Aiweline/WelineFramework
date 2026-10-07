<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cart owns mini-cart qty stacking; Theme owns footer −100px growth cap.
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

        // Stacking only — expanded body floor blocked sheet growth to −100px.
        self::assertStringContainsString('z-index: 1', $css);
        self::assertStringNotContainsString('min-height: min(40vh, 12rem)', $css);
        self::assertStringNotContainsString(
            'max-height: calc(100% - min(40vh, 12rem) - 5.5rem)',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*max-height\\s*:/s',
            $css
        );
        self::assertStringContainsString('ensureMiniCartQtyHitCss', $cartJs);
        self::assertStringContainsString('mini-cart-drawer-qty-hit.css', $cartJs);
        self::assertStringContainsString('20261007-cart-qty-hit-v3', $modules);
        self::assertStringContainsString('Weline_Cart::css/mini-cart-drawer-qty-hit.css', $themeWidget);
        // Theme owns drawer − 100px footer cap + content-sized details.
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*max-height:\\s*calc\\(\\s*100%\\s*-\\s*100px/s',
            $themeDrawerCss
        );
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer-details\\s*\\{[^}]*flex:\\s*0\\s+1\\s+auto/s',
            $themeDrawerCss
        );
        // Collapsed-only body floor remains Theme-owned.
        self::assertMatchesRegularExpression(
            '/\\.header-cart\\.is-footer-collapsed \\.mini-cart-drawer__body\\s*\\{[^}]*min-height:\\s*min\\(40vh,\\s*12rem\\)/s',
            $themeDrawerCss
        );
    }
}
