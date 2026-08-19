<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CartStorefrontQueryBinContractTest extends TestCase
{
    public function testCartPageHydratesFromThePublishedQueryProvider(): void
    {
        $template = $this->template();

        self::assertStringContainsString("await Weline.load('api')", $template);
        self::assertStringContainsString("api.resource('cart')", $template);
        self::assertStringContainsString("const guestTokenStorageKey = 'weline.cart.guest_token'", $template);
        self::assertStringContainsString('window.sessionStorage.getItem(guestTokenStorageKey)', $template);
        self::assertStringContainsString('getV2Cart(cartIdentity())', $template);
        self::assertStringContainsString('data-cart-state="loading"', $template);
        self::assertStringContainsString('data-cart-state="empty"', $template);
        self::assertStringContainsString('data-cart-state="ready"', $template);
        self::assertStringContainsString('data-cart-state="error"', $template);
    }

    public function testCartPageDoesNotSendClientOwnedIdentityOrScope(): void
    {
        $template = $this->template();

        self::assertStringNotContainsString('customer_id:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
        self::assertStringNotContainsString('channel_code:', $template);
        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('fetch(', $template);
        self::assertStringNotContainsString('XMLHttpRequest', $template);
    }

    public function testCartRowsUseDomTextInsteadOfHtmlInterpolation(): void
    {
        $template = $this->template();

        self::assertStringContainsString('document.createElement', $template);
        self::assertStringContainsString('.textContent =', $template);
        self::assertStringNotContainsString('.innerHTML', $template);
    }

    public function testControllerAlwaysRendersTheHydrationCapableCartLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Cart/Controller/Index.php',
        );

        self::assertStringContainsString("\$this->layoutType = 'cart.default';", $controller);
        self::assertStringContainsString("\$this->request->setGet('layout_option', 'default');", $controller);
        self::assertStringNotContainsString("\$isEmpty ? 'cart.empty' : 'cart.default'", $controller);
    }

    public function testContinueShoppingKeepsTheLocaleAwareProductRoute(): void
    {
        $template = $this->template();

        self::assertStringContainsString("\$this->getUrl('products')", $template);
        self::assertStringNotContainsString("\$this->getUrl('')", $template);
    }

    public function testMoneyUsesLocaleAwareThousandsSeparatorsWithTwoDecimals(): void
    {
        $template = $this->template();

        self::assertStringContainsString('new Intl.NumberFormat(undefined, {', $template);
        self::assertStringContainsString('minimumFractionDigits: 2', $template);
        self::assertStringContainsString('maximumFractionDigits: 2', $template);
        self::assertStringNotContainsString("Number(amount || 0).toFixed(2)", $template);
    }

    public function testQuantityActionLabelsNeverWrapVertically(): void
    {
        $template = $this->template();

        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell__action\\s*\\{[^}]*white-space:\\s*nowrap;/s',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell__quantity-controls\\s*\\{[^}]*flex-wrap:\\s*nowrap;/s',
            $template,
        );
    }

    private function template(): string
    {
        return (string)file_get_contents(
            BP . 'app/code/Weline/Cart/view/templates/frontend/cart/index.phtml',
        );
    }
}
