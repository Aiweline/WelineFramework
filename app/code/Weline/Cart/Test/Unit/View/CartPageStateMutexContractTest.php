<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * QA-05: cart page shell must expose only one of loading|empty|error|ready.
 */
final class CartPageStateMutexContractTest extends TestCase
{
    public function testPageShellUsesExclusiveDataCartViewCssAndInert(): void
    {
        $root = \dirname(__DIR__, 3);
        $page = (string)\file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
        $css = (string)\file_get_contents($root . '/view/statics/css/cart-page-amazon.css');

        self::assertStringContainsString('data-cart-view="loading"', $page);
        self::assertStringContainsString('data-cart-page-state="loading"', $page);
        self::assertStringContainsString('data-cart-page-state="empty"', $page);
        self::assertStringContainsString('data-cart-page-state="error"', $page);
        self::assertStringContainsString('data-cart-page-state="ready"', $page);
        self::assertStringContainsString("root.setAttribute('data-cart-view', view)", $page);
        self::assertStringContainsString("node.setAttribute('aria-hidden', 'true')", $page);
        self::assertStringContainsString("node.setAttribute('inert', '')", $page);
        self::assertStringContainsString('showState(\'loading\')', $page);

        self::assertStringContainsString('.weline-cart-shell--amazon > [data-cart-state]', $css);
        self::assertStringContainsString('display: none !important', $css);
        self::assertStringContainsString('[data-cart-view="loading"] > [data-cart-state="loading"]', $css);
        self::assertStringContainsString('[data-cart-view="empty"] > [data-cart-state="empty"]', $css);
        self::assertStringContainsString('[data-cart-view="error"] > [data-cart-state="error"]', $css);
        self::assertStringContainsString('[data-cart-view="ready"] > [data-cart-state="ready"]', $css);
    }
}
