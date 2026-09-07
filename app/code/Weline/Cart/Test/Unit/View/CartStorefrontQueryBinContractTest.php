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
        self::assertStringContainsString('issueGuestToken', $template);
        self::assertStringContainsString('ensureGuestToken', $template);
        self::assertStringContainsString('await cartIdentity()', $template);
        self::assertStringContainsString('data-cart-state="loading"', $template);
        self::assertStringContainsString('data-cart-state="empty"', $template);
        self::assertStringContainsString('data-cart-state="ready"', $template);
        self::assertStringContainsString('data-cart-state="error"', $template);
    }

    public function testCartPageAdoptsTheSharedGuestSessionBeforeIssuingANewToken(): void
    {
        $template = $this->template();
        $ensureGuestToken = strpos($template, 'async function ensureGuestToken()');
        $loadCartModule = strpos($template, "await Weline.load('cart')", $ensureGuestToken ?: 0);
        $rereadStoredToken = strpos($template, 'guestToken = readStoredGuestToken()', $loadCartModule ?: 0);
        $firstReuseGuestToken = strpos($template, 'if (guestToken) {', $ensureGuestToken ?: 0);
        $reuseGuestToken = strpos($template, 'if (guestToken) {', $rereadStoredToken ?: 0);
        $issueGuestToken = strpos($template, '.issueGuestToken(', $rereadStoredToken ?: 0);

        self::assertIsInt($ensureGuestToken);
        self::assertIsInt($loadCartModule, 'Cart page must load the shared Cart browser session first.');
        self::assertIsInt($rereadStoredToken, 'Cart page must re-read the shared guest token after loading Cart.');
        self::assertSame($reuseGuestToken, $firstReuseGuestToken, 'Do not return a stale legacy token before adoption.');
        self::assertIsInt($reuseGuestToken, 'Legacy sessionStorage must not win before shared-session adoption.');
        self::assertIsInt($issueGuestToken, 'Cart page may issue a token only after attempting adoption.');
        self::assertLessThan($rereadStoredToken, $loadCartModule);
        self::assertLessThan($reuseGuestToken, $rereadStoredToken);
        self::assertLessThan($issueGuestToken, $reuseGuestToken);
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

        self::assertStringContainsString("@url{'products'}", $template);
        self::assertStringNotContainsString("@url{''}", $template);
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
        $css = $this->amazonCss();

        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__action\\s*\\{[^}]*white-space:\\s*nowrap;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__qty\\s*\\{[^}]*flex-wrap:\\s*nowrap;/s',
            $css,
        );
    }

    public function testCartPageUsesAmazonSurfaceAndStylesheet(): void
    {
        $template = $this->template();

        self::assertStringContainsString('weline-cart-shell--amazon', $template);
        self::assertStringContainsString('@static(Weline_Cart::css/cart-page-amazon.css)&v=', $template);
        self::assertStringContainsString('weline-cart-shell__line', $template);
        self::assertStringContainsString('data-qty-decrease', $template);
        self::assertStringContainsString('weline-cart-shell__checkout', $template);
        self::assertStringNotContainsString('<table', $template);
    }

    public function testCartPageRendersSelectedOptionLabels(): void
    {
        $template = $this->template();
        $css = $this->amazonCss();

        self::assertStringContainsString('function formatLineOptions(item)', $template);
        self::assertStringContainsString('function appendLineOptions(details, item)', $template);
        self::assertStringContainsString("weline-cart-shell__line-options", $template);
        self::assertStringContainsString('weline-cart-shell__line-option-swatch', $template);
        self::assertStringContainsString('option.swatch_image', $template);
        self::assertStringContainsString('option.value_label || option.value', $template);
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__line-options\\s*\\{/',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__line-option-swatch\\s*\\{/',
            $css,
        );
    }

    private function template(): string
    {
        return (string)file_get_contents(
            BP . 'app/code/Weline/Cart/view/templates/frontend/cart/index.phtml',
        );
    }

    private function amazonCss(): string
    {
        return (string)file_get_contents(
            BP . 'app/code/Weline/Cart/view/statics/css/cart-page-amazon.css',
        );
    }
}
