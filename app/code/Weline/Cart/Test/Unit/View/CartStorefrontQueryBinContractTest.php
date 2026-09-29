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
        self::assertStringContainsString('data-cart-view="loading"', $template);
        self::assertStringContainsString('data-cart-page-state', $template);
        self::assertStringContainsString('function showState(state)', $template);
        self::assertStringContainsString("root.setAttribute('data-cart-view', view)", $template);
        self::assertStringContainsString("node.setAttribute('inert', '')", $template);
    }

    public function testCartPageAlwaysIssuesGuestTokenAfterSharedSessionLoad(): void
    {
        $template = $this->template();
        $ensureGuestToken = strpos($template, 'async function ensureGuestToken()');
        $loadCartModule = strpos($template, "await Weline.load('cart')", $ensureGuestToken ?: 0);
        $rereadStoredToken = strpos($template, 'guestToken = readStoredGuestToken()', $loadCartModule ?: 0);
        $forceRenew = strpos($template, 'renewGuestSession({ force: true })', $ensureGuestToken ?: 0);
        $issueEmpty = strpos($template, 'issueGuestToken({})', $forceRenew ?: 0);
        $issueWithToken = strpos($template, 'issueGuestToken({ guest_token: guestToken })', $ensureGuestToken ?: 0);
        // Early return on JS-only guestToken must stay gone (Cookie authority via always-issue).
        $earlyReturnReuse = preg_match(
            '/async function ensureGuestToken\(\)\s*\{[\s\S]*?if \(guestToken\) \{\s*return guestToken;\s*\}/',
            $template,
        );

        self::assertIsInt($ensureGuestToken);
        self::assertIsInt($loadCartModule, 'Cart page must load the shared Cart browser session first.');
        self::assertIsInt($rereadStoredToken, 'Cart page must re-read the shared guest token after loading Cart.');
        self::assertIsInt($forceRenew, 'Existing JS token must force-renew into Cookie before issue.');
        self::assertIsInt($issueEmpty, 'After renew OK (or no JS token) must issueGuestToken({}).');
        self::assertIsInt($issueWithToken, 'Renew fail/skip path must still try issueGuestToken({guest_token}).');
        self::assertSame(0, $earlyReturnReuse, 'Do not return a JS-only guestToken without issueGuestToken.');
        self::assertLessThan($rereadStoredToken, $loadCartModule);
        self::assertLessThan($forceRenew, $rereadStoredToken);
        self::assertLessThan($issueEmpty, $forceRenew);
        self::assertStringContainsString('cookieSyncedByRenew', $template);
        self::assertStringContainsString('isUnknownGuestTokenWorkerParam', $template);
        self::assertStringContainsString('Unknown frontend worker param:\\s*guest_token', $template);
        // Must not always-issue with guest_token when Cookie was synced by renew.
        self::assertStringNotContainsString(
            'const issueParams = guestToken ? { guest_token: guestToken } : {};',
            $template,
        );
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
        self::assertStringContainsString('data-cart-swatch-trigger', $template);
        self::assertStringContainsString('ensureCartSwatchPreview', $template);
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
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__line-option-swatch-btn\\s*\\{/',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\\.weline-cart-shell--amazon \\.weline-cart-shell__swatch-preview\\s*\\{/',
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
