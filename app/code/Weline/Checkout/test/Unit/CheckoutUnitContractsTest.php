<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutUnitContractsPass().
 */
final class CheckoutUnitContractsTest extends TestCase
{
    public function testAllCheckoutUnitContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CheckoutShellMutex::testTraditionalCheckoutPaintsReadyWithoutFullPageLoadingBanner' => function () {
            $this->assert_CheckoutShellMutex_testTraditionalCheckoutPaintsReadyWithoutFullPageLoadingBanner();
        },
            'StorefrontCheckoutTemplate::testCheckoutUsesLocaleAwareSuccessUrl' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutUsesLocaleAwareSuccessUrl();
        },
            'StorefrontCheckoutTemplate::testCheckoutRendersDurablePaymentRecoveryStateThroughBinQuery' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutRendersDurablePaymentRecoveryStateThroughBinQuery();
        },
            'StorefrontCheckoutTemplate::testCheckoutTotalsUseCompilerSafeDataAttributesAndFallbackValues' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutTotalsUseCompilerSafeDataAttributesAndFallbackValues();
        },
            'StorefrontCheckoutTemplate::testAuthenticatedCheckoutPrefillsThePublishedDefaultDeliveryAddressOnce' => function () {
            $this->assert_StorefrontCheckoutTemplate_testAuthenticatedCheckoutPrefillsThePublishedDefaultDeliveryAddressOnce();
        },
            'StorefrontCheckoutTemplate::testEmptyCartHidesCheckoutFormAndShowsRecoveryActions' => function () {
            $this->assert_StorefrontCheckoutTemplate_testEmptyCartHidesCheckoutFormAndShowsRecoveryActions();
        },
            'StorefrontCheckoutTemplate::testCheckoutAdoptsTheSharedGuestSessionBeforeLoadingCartData' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutAdoptsTheSharedGuestSessionBeforeLoadingCartData();
        },
            'StorefrontCheckoutTemplate::testLoadCartSummaryFallsBackToCookieWhenClientTokenIsEmpty' => function () {
            $this->assert_StorefrontCheckoutTemplate_testLoadCartSummaryFallsBackToCookieWhenClientTokenIsEmpty();
        },
            'StorefrontCheckoutTemplate::testCheckoutShippingAddressUsesSlotInsteadOfNakedRegionInputs' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutShippingAddressUsesSlotInsteadOfNakedRegionInputs();
        },
            'StorefrontCheckoutTemplate::testCheckoutSummaryUsesCouponWidgetSlotInsteadOfHookFetch' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutSummaryUsesCouponWidgetSlotInsteadOfHookFetch();
        },
            'StorefrontCheckoutTemplate::testCheckoutExpressPaymentSlotSitsInLeftMainBeforeShipping' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutExpressPaymentSlotSitsInLeftMainBeforeShipping();
        },
            'StorefrontCheckoutTemplate::testSuccessTemplateRendersV2OrderEvidenceAndActions' => function () {
            $this->assert_StorefrontCheckoutTemplate_testSuccessTemplateRendersV2OrderEvidenceAndActions();
        },
            'StorefrontCheckoutTemplate::testCheckoutOrderControllersUseRealRouterPaths' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutOrderControllersUseRealRouterPaths();
        },
            'StorefrontCheckoutTemplate::testOrderPagesOwnResponsiveCorePresentation' => function () {
            $this->assert_StorefrontCheckoutTemplate_testOrderPagesOwnResponsiveCorePresentation();
        },
            'StorefrontCheckoutTemplate::testCheckoutHeaderSubtitleIsConfigurableAndDoesNotExposeInternalModuleNames' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutHeaderSubtitleIsConfigurableAndDoesNotExposeInternalModuleNames();
        },
            'StorefrontCheckoutTemplate::testCheckoutReloadsSummaryWhenMiniCartMutatesCart' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutReloadsSummaryWhenMiniCartMutatesCart();
        },
            'StorefrontCheckoutTemplate::testCheckoutSurfacesShippingPaymentUnavailableAsBlockingAlert' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutSurfacesShippingPaymentUnavailableAsBlockingAlert();
        },
            'StorefrontCheckoutTemplate::testCheckoutAwaitsShippingValidationIncludingEmbargo' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutAwaitsShippingValidationIncludingEmbargo();
        },
            'StorefrontCheckoutTemplate::testCheckoutEmitsOrderCreatedAndPaymentBridgeOnRecovery' => function () {
            $this->assert_StorefrontCheckoutTemplate_testCheckoutEmitsOrderCreatedAndPaymentBridgeOnRecovery();
        },
            'StorefrontMoneySummaryWidget::testWidgetTemplateExposesUnifiedRowOrderAndLegacyAliases' => function () {
            $this->assert_StorefrontMoneySummaryWidget_testWidgetTemplateExposesUnifiedRowOrderAndLegacyAliases();
        },
            'StorefrontMoneySummaryWidget::testPaintApiAndModuleRegistrationExist' => function () {
            $this->assert_StorefrontMoneySummaryWidget_testPaintApiAndModuleRegistrationExist();
        },
            'StorefrontMoneySummaryWidget::testWidgetPhpRegistersCartMiniCartAndCheckoutInjections' => function () {
            $this->assert_StorefrontMoneySummaryWidget_testWidgetPhpRegistersCartMiniCartAndCheckoutInjections();
        },
            'StorefrontMoneySummaryWidget::testMiniCartCssBeatsInverseChromeInkForSubtotalContrast' => function () {
            $this->assert_StorefrontMoneySummaryWidget_testMiniCartCssBeatsInverseChromeInkForSubtotalContrast();
        },
        ];
    }

private function assert_CheckoutShellMutex_testTraditionalCheckoutPaintsReadyWithoutFullPageLoadingBanner(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    // Initial view is ready|empty from SSR cart lines — not a hard-coded ready string.
    self::assertStringContainsString('data-checkout-view="<?= htmlspecialchars($ssrInitialView, ENT_QUOTES, \'UTF-8\') ?>"', $template);
    self::assertStringContainsString('$ssrInitialView = $ssrHasLineItems ? \'ready\' : \'empty\'', $template);
    self::assertStringContainsString('data-checkout-form-host', $template);
    self::assertStringNotContainsString('data-checkout-form-host hidden', $template);
    // 无消息不展示 notice 灰条。
    self::assertMatchesRegularExpression('/data-checkout-message[^>]*\bhidden\b/', $template);
    self::assertStringContainsString('setShellHidden(message, !hasText)', $template);
    self::assertStringContainsString('.weline-checkout__notice:empty', $template);
    self::assertStringNotContainsString('data-checkout-page-loading', $template);
    self::assertStringContainsString('function showCheckoutShell(mode)', $template);
    self::assertStringContainsString('function setFormVisible(visible)', $template);
    // 续付仍可整页 loading；传统入口 soft hydrate。
    self::assertStringContainsString("showCheckoutShell('loading')", $template);
    self::assertStringContainsString("showCheckoutShell('empty')", $template);
    self::assertStringContainsString("showCheckoutShell('ready')", $template);
    self::assertStringContainsString("showCheckoutShell('mismatch')", $template);
    self::assertStringContainsString('soft: true', $template);
    self::assertStringContainsString('panels: { items: true, shipping: true, payment: true, address: false }', $template);
    // 传统首屏不得串行 await release 再 hydrate（抢 Scope bootstrap）。
    self::assertStringContainsString('needRemoteRelease', $template);
    self::assertStringContainsString('scope_bootstrap_invalid', $template);
    self::assertStringContainsString('const releasePromise = releaseContinuePayIsolation()', $template);
    // SSR 已灌商品/配送/支付时跳过首屏 soft getData。
    self::assertStringContainsString('data-checkout-ssr-ready', $template);
    self::assertStringContainsString("ssrReady = !!(root && root.getAttribute('data-checkout-ssr-ready') === '1')", $template);
    self::assertStringContainsString('guestTokenAligned', $template);
    self::assertStringContainsString('localCartClaimsItems', $template);
    self::assertStringContainsString('guest_cart_mismatch', $template);
    self::assertStringContainsString('[data-checkout-view="loading"] > .weline-checkout__form-host', $template);
    self::assertStringContainsString('Cookie is the server-owned guest cart authority', $template);
    self::assertStringContainsString('guest_token: await ensureGuestToken()', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutUsesLocaleAwareSuccessUrl(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString("@url{'checkout/success'}", $template);
    self::assertStringContainsString('const successPageUrl =', $template);
    self::assertStringNotContainsString("window.location.href = '/checkout/success", $template);
    self::assertStringContainsString("successUrl.searchParams.set('checkout_token', checkoutToken);", $template);
    self::assertStringContainsString('weline_checkout_quote_token_w', $template);
    self::assertStringContainsString('quote_token: readStoredQuoteToken()', $template);
    self::assertStringContainsString('adoptQuoteTokenFromUrl', $template);
    self::assertStringContainsString("params.get('quote_token')", $template);
    self::assertStringContainsString('weline:checkout:success', $template);
    self::assertStringNotContainsString('checkout/success-page', $template);
    $controllerRoot = '/Users/weline/Project/Official/框架' . '/app/code/Weline/Checkout/Controller';
    self::assertFileExists($controllerRoot . '/Success.php');
    self::assertFileDoesNotExist($controllerRoot . '/SuccessPage.php');
    self::assertStringNotContainsString('function successPage', $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Frontend/Checkout.php'));
    $successController = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Success.php');
    self::assertStringContainsString('checkout_payment_cancelled', $successController);
    self::assertStringContainsString('isCancelOutcome', $successController);
    $successTpl = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');
    self::assertStringContainsString('paymentCancelled', $successTpl);
    self::assertStringContainsString('支付已取消', $successTpl);
    self::assertStringContainsString('本次支付未完成，订单尚未付款。可继续支付或稍后再试。', $successTpl);
    self::assertStringContainsString('data-testid="checkout-continue-pay"', $successTpl);
    self::assertStringContainsString('继续支付', $successTpl);
    self::assertStringNotContainsString("href=\"@url{'checkout'}\"", $successTpl);
    self::assertStringNotContainsString('已取消成功', $successTpl);
    self::assertStringContainsString('data-payment-outcome="cancel"', $successTpl);
    self::assertStringContainsString('cancel_state', $successTpl);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutRendersDurablePaymentRecoveryStateThroughBinQuery(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('data-checkout-payment-recovery hidden', $template);
    self::assertStringContainsString('data-payment-retry', $template);
    self::assertStringContainsString("api.resumePaymentV2({", $template);
    self::assertStringContainsString("payment.outcome", $template);
    self::assertStringContainsString('state.recoverable = payment.recoverable !== false;', $template);
    self::assertStringContainsString('paymentRetryButton.hidden = pending ? !canContinue : !state.recoverable;', $template);
    self::assertStringContainsString("const value = typeof state[key] === 'boolean'", $template);
    self::assertStringContainsString("params.get('recoverable') !== '0'", $template);
    self::assertStringContainsString("'#payment-recovery?'", $template);
    self::assertStringContainsString('window.history.replaceState', $template);
    self::assertStringNotContainsString("fetch('/", $template);
    self::assertStringNotContainsString('fetch("/', $template);
    self::assertStringNotContainsString('XMLHttpRequest', $template);
    self::assertStringNotContainsString('axios', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutTotalsUseCompilerSafeDataAttributesAndFallbackValues(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('<div class="weline-checkout__totals"', $template);
    self::assertStringContainsString('storefront-money-summary/default.phtml', $template);
    self::assertStringContainsString("'mode' => 'checkout'", $template);
    self::assertStringContainsString('paintCheckoutMoneySummary', $template);
    self::assertStringContainsString('WelineStorefrontMoneySummary', $template);
    self::assertStringNotContainsString('<dd', $template);
    self::assertStringContainsString('new Intl.NumberFormat(undefined, {', $template);
    self::assertStringContainsString('minimumFractionDigits: 2', $template);
    self::assertStringContainsString('maximumFractionDigits: 2', $template);
    self::assertStringNotContainsString("Number(amount || 0).toFixed(2)", $template);
}

private function assert_StorefrontCheckoutTemplate_testAuthenticatedCheckoutPrefillsThePublishedDefaultDeliveryAddressOnce(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('let customerAddressPrefilled = false;', $template);
    self::assertStringContainsString('function applyDefaultShippingAddress(address)', $template);
    self::assertStringContainsString('const deliveryAddress = data && data.delivery && typeof data.delivery === \'object\'', $template);
    self::assertStringContainsString("form.querySelector('[name=\"' + field + '\"]')", $template);
    self::assertStringContainsString('applyDefaultShippingAddress(data.default_shipping_address);', $template);
}

private function assert_StorefrontCheckoutTemplate_testEmptyCartHidesCheckoutFormAndShowsRecoveryActions(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString("@url{'products'}", $template);
    self::assertStringContainsString('data-checkout-empty hidden', $template);
    self::assertStringContainsString('weline-code="checkout.checkout.empty.section_1"', $template);
    // 传统结账首屏即 ready：form-host 默认可见；空车由 showCheckoutShell('empty') 隐藏。
    self::assertStringContainsString('data-checkout-form-host', $template);
    self::assertStringNotContainsString('data-checkout-form-host hidden', $template);
    self::assertStringContainsString('data-checkout-form', $template);
    self::assertStringContainsString("const emptyState = root.querySelector('[data-checkout-empty]');", $template);
    self::assertStringContainsString('function showCheckoutShell(mode)', $template);
    self::assertStringContainsString('function setFormVisible(visible)', $template);
    self::assertStringContainsString('const hangPurpose = (function () {', $template);
    self::assertStringContainsString("params.get('purpose')", $template);
    self::assertStringContainsString("params.get('order_uuid')", $template);
    self::assertStringContainsString('const cartIsEmpty = Boolean(checkoutState.cart.is_empty);', $template);
    self::assertStringContainsString("showCheckoutShell('empty')", $template);
    self::assertStringContainsString("showCheckoutShell('ready')", $template);
    self::assertStringContainsString('guest_cart_mismatch', $template);
    self::assertStringContainsString('localCartClaimsItems', $template);
    self::assertStringContainsString('guestTokenAligned', $template);
    self::assertStringContainsString('--checkout-text: var(--color-text-primary);', $template);
    self::assertStringContainsString('--checkout-link: var(--color-link);', $template);
    self::assertStringContainsString('--checkout-cta-bg: var(--color-primary);', $template);
    self::assertStringNotContainsString('#2563eb', $template);
    self::assertMatchesRegularExpression('/\.weline-checkout__empty-state h2\s*\{[^}]*color:\s*var\(--checkout-text\)/s', $template);
    self::assertMatchesRegularExpression('/\.weline-checkout__empty-state > p:not\(\.weline-checkout__eyebrow\)\s*\{[^}]*color:\s*var\(--checkout-text-secondary\)/s', $template);
    self::assertMatchesRegularExpression('/\.weline-checkout__submit\s*\{[^}]*background:\s*var\(--checkout-cta-bg\)/s', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutAdoptsTheSharedGuestSessionBeforeLoadingCartData(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    $ensureGuestToken = strpos($template, 'async function ensureGuestToken()');
    $loadCartModule = strpos($template, "await window.Weline.load('cart')", $ensureGuestToken ?: 0);
    $rereadGuestToken = strpos($template, 'let token = guestToken();', $loadCartModule ?: 0);
    $issueGuestToken = strpos($template, '.issueGuestToken(', $rereadGuestToken ?: 0);
    $rememberSession = strpos($template, 'rememberGuestSession', $issueGuestToken ?: 0);
    $loadCheckoutWithToken = strpos($template, 'guest_token: await ensureGuestToken()', $issueGuestToken ?: 0);
    self::assertStringContainsString('data-weline-load="cart,b2bCheckoutTob,checkoutLifecycle,paymentLifecycle,storefrontMoneySummary"', $template);
    self::assertIsInt($ensureGuestToken);
    self::assertIsInt($loadCartModule, 'Checkout must load the shared Cart browser session first.');
    self::assertIsInt($rereadGuestToken, 'Checkout must re-read the token after Cart initializes.');
    self::assertIsInt($issueGuestToken, 'Checkout must always adopt the HttpOnly cookie via issueGuestToken.');
    self::assertIsInt($rememberSession, 'Adopted cookie token must be written back to the shared Cart session.');
    self::assertIsInt($loadCheckoutWithToken, 'checkout.getData must receive the recovered guest token.');
    self::assertLessThan($rereadGuestToken, $loadCartModule);
    self::assertLessThan($issueGuestToken, $rereadGuestToken);
    self::assertLessThan($rememberSession, $issueGuestToken);
    self::assertLessThan($loadCheckoutWithToken, $issueGuestToken);
    // Must not short-circuit on a stale sessionStorage token before adopting the cookie.
    self::assertStringContainsString('Cookie is the server-owned guest cart authority', $template);
    self::assertStringNotContainsString("let token = guestToken();\n        if (token) {\n            return token;\n        }", $template);
}

private function assert_StorefrontCheckoutTemplate_testLoadCartSummaryFallsBackToCookieWhenClientTokenIsEmpty(): void
{
    $src = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('Cookie::get(CartService::GUEST_TOKEN_COOKIE)', $src);
    self::assertStringContainsString('!hash_equals($cookieToken, $guestToken)', $src);
    self::assertStringContainsString('orphan token while the HttpOnly cookie', $src);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutShippingAddressUsesSlotInsteadOfNakedRegionInputs(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('id="checkout-shipping-address"', $template);
    self::assertStringContainsString('class="weline-checkout__shipping-address-slot"', $template);
    self::assertStringContainsString('.weline-checkout__shipping-address-slot', $template);
    self::assertMatchesRegularExpression('/\.weline-checkout__shipping-address-slot\s*\{[^}]*margin-top:\s*16px/s', $template, 'Shipping address slot must match options panel title spacing');
    self::assertStringContainsString("accept=\"checkout-shipping-address,shipping-address,delivery-address,address\"", $template);
    self::assertStringNotContainsString('<input name="country_code"', $template);
    self::assertStringNotContainsString('<input name="province"', $template);
    self::assertStringNotContainsString('<input name="city"', $template);
    self::assertStringNotContainsString('<input name="address1"', $template);
    self::assertStringContainsString("WelineThemeAddress.applyValues('checkout-shipping-address'", $template);
    self::assertStringContainsString("district: text(data.get('district')).trim(),", $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutSummaryUsesCouponWidgetSlotInsteadOfHookFetch(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('id="checkout-summary-discount"', $template);
    self::assertStringContainsString('class="weline-checkout__coupon-slot"', $template);
    self::assertStringContainsString('id="checkout-summary-note"', $template);
    self::assertStringContainsString('class="weline-checkout__note-slot"', $template);
    self::assertStringContainsString('weline-checkout__extras', $template);
    self::assertStringContainsString('mini-cart-drawer__extras', $template);
    self::assertStringContainsString('data-weline-load="miniCartExtras"', $template);
    self::assertStringContainsString('discountAmountMajor', $template);
    self::assertStringContainsString('data-checkout-discount-row', $template);
    self::assertStringContainsString('data-checkout-deposit-row', $template);
    self::assertStringContainsString('data-checkout-credit-row', $template);
    self::assertStringContainsString('data-checkout-tax-row', $template);
    self::assertStringContainsString('selectedTaxAmount', $template);
    self::assertStringContainsString('cartSalesTaxAmount', $template);
    self::assertStringContainsString('taxEstimate', $template);
    self::assertStringContainsString('prefer getData tax_estimate', $template);
    self::assertStringContainsString('selectedDutyAmount', $template);
    self::assertStringContainsString('selectedCustomsDutyAmount', $template);
    self::assertStringContainsString('selectedImportTaxAmount', $template);
    self::assertStringContainsString('sales_tax_amount_minor', $template);
    self::assertStringContainsString('sales_tax_minor:', $template);
    self::assertStringContainsString('customs_duty_minor:', $template);
    self::assertStringContainsString('import_tax_minor:', $template);
    self::assertStringContainsString('sales_tax_label:', $template);
    self::assertStringContainsString('moneySummaryTaxNote', $template);
    self::assertStringContainsString('domestic_inclusive', $template);
    self::assertStringNotContainsString('本站暂不代收美国销售税', $template);
    self::assertStringNotContainsString('本站不另收目的地销售税', $template);
    self::assertStringContainsString('destination_policy', $template);
    self::assertStringContainsString('checkout_ssr_tax_estimate', $template);
    self::assertStringContainsString('data-grand-total-label', $template);
    self::assertStringContainsString('weline:b2b-credit-changed', $template);
    self::assertStringContainsString('WelineB2BCheckoutTob', $template);
    self::assertStringContainsString("weline:checkout:address-updated", $template);
    self::assertStringContainsString('isCheckoutReloadSuppressed', $template);
    self::assertStringContainsString('data-checkout-suppress-reload', $template);
    self::assertStringContainsString('const soft = opts.soft === true', $template);
    self::assertStringContainsString('loadCheckoutWithRetry({ soft: soft, panels: panels })', $template);
    self::assertStringContainsString('items: false, shipping: true, payment: true, address: false', $template);
    self::assertStringContainsString('hardOnFailure: false', $template);
    self::assertStringContainsString('busyShipping: true', $template);
    self::assertStringContainsString("panels: { items: false, shipping: true, payment: true, address: false }", $template);
    self::assertStringContainsString('setShippingMethodsBusy', $template);
    self::assertStringContainsString('Weline.UI.setBusy', $template);
    self::assertStringContainsString('loading_shipping', $template);
    self::assertStringNotContainsString('<w:widget', $template);
    self::assertMatchesRegularExpression('/\.weline-checkout__totals > \[role="listitem"\]/s', $template);
    self::assertStringNotContainsString('Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutExpressPaymentSlotSitsInLeftMainBeforeShipping(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('data-checkout-express-host', $template);
    self::assertStringContainsString('id="checkout-express-payment"', $template);
    self::assertStringContainsString('class="weline-checkout__express-slot"', $template);
    self::assertStringContainsString('weline:checkout:express-pay', $template);
    self::assertStringContainsString('submitCheckoutPayment', $template);
    self::assertStringContainsString('setShellHidden(', $template);
    self::assertStringContainsString('isContinuePayMode()', $template);
    self::assertStringContainsString('function checkoutCartType()', $template);
    self::assertStringContainsString('Authoritative shell from server hydrate', $template);
    self::assertStringNotContainsString('Number(tobApi.readApplyMinor()) > 0', $template);
    self::assertStringNotContainsString('[data-b2b-credit-toggle]:checked', $template);
    // ToB deposit / incentive / tax policy lives in B2B adjustMoneySummaryDto — Checkout stays retail baseline.
    self::assertStringContainsString('commerce_deposit_allowed: false', $template);
    self::assertStringContainsString('adjustMoneySummaryDto', $template);
    self::assertStringContainsString('shell_cart_type', $template);
    self::assertStringContainsString('payable_label_retail', $template);
    self::assertStringContainsString('syncPaymentIncentiveAvailability', $template);
    self::assertStringNotContainsString('const salesTaxMajor = tob ? 0', $template);
    self::assertStringNotContainsString('const incentive = tob ? 0', $template);
    self::assertStringNotContainsString('readPersistedCreditChoice()', $template);
    self::assertStringContainsString('cart_type: checkoutCartType()', $template);
    self::assertStringContainsString('applyCartTypeHandoffFromUrl', $template);
    self::assertStringContainsString('readCartTypeFromQuery', $template);
    self::assertStringContainsString('data-cart-type-handoff', $template);
    self::assertStringContainsString("params.get('cart_type')", $template);
    self::assertStringContainsString("params.get('type')", $template);
    self::assertStringNotContainsString('Weline_Payment::templates/frontend/widgets/checkout-express-payment.phtml', $template);
    $formPos = strpos($template, 'data-checkout-form');
    $mainPos = strpos($template, 'class="weline-checkout__main"');
    $expressPos = strpos($template, 'id="checkout-express-payment"');
    $shippingPos = strpos($template, 'id="checkout-shipping-address"');
    self::assertNotFalse($formPos);
    self::assertNotFalse($mainPos);
    self::assertNotFalse($expressPos);
    self::assertNotFalse($shippingPos);
    self::assertGreaterThan($formPos, $expressPos);
    self::assertGreaterThan($mainPos, $expressPos);
    self::assertGreaterThan($expressPos, $shippingPos);
}

private function assert_StorefrontCheckoutTemplate_testSuccessTemplateRendersV2OrderEvidenceAndActions(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');
    self::assertStringContainsString('data-testid="checkout-success"', $template);
    self::assertStringContainsString('class="amz-order-confirm amz-order-confirm--', $template);
    self::assertStringContainsString('checkout-success-page', $template);
    self::assertStringContainsString('amz-order-confirm__banner', $template);
    self::assertStringContainsString('amz-order-confirm__btn--primary', $template);
    self::assertStringContainsString('--amz-btn-primary-bg: var(--color-primary, #b84a3c)', $template);
    self::assertStringContainsString('--amz-success: var(--color-success, #067d62)', $template);
    self::assertStringContainsString('data-order-uuid="<?= $escape($requestOrderUuid) ?>"', $template);
    self::assertStringContainsString('data-checkout-group-uuid="<?= $escape($requestCheckoutGroupUuid) ?>"', $template);
    self::assertStringContainsString('weline-code="checkout.checkout.success.section_1"', $template);
    self::assertStringContainsString('weline-pixel::view_order', $template);
    self::assertStringContainsString('weline-pixel::view_orders', $template);
    self::assertStringContainsString('weline-pixel::continue_shopping', $template);
    self::assertStringContainsString('order_v2_display_number', $template);
    self::assertStringContainsString('order_v2_total_label', $template);
    self::assertStringContainsString("'paid' => (string) __('已支付')", $template);
    self::assertStringContainsString("number_format(\$minor / 100, 2, '.', ',')", $template);
    self::assertStringContainsString("@url{'customer/account/index'}", $template);
    self::assertStringContainsString("@url{'customer/account/index'|['order_uuid' => \$requestOrderUuid]}#orders", $template);
    self::assertStringNotContainsString("'#orders?order_uuid='", $template);
    self::assertStringNotContainsString("checkout/frontend/order/view", $template);
    self::assertStringContainsString("@url{'products'}", $template);
    self::assertStringNotContainsString('weline_checkout/frontend/order', $template);
    self::assertStringContainsString('$escape = static fn', $template);
    self::assertStringNotContainsString('$this->escapeHtml(', $template);
    self::assertStringContainsString("__('感谢您的订购！')", $template);
    self::assertStringContainsString("__('您的订单已支付成功，我们正在为您准备发货。')", $template);
    self::assertStringContainsString("__('配送地址')", $template);
    self::assertStringContainsString("__('结账地址')", $template);
    self::assertStringContainsString("__('与配送地址相同')", $template);
    self::assertStringContainsString('data-testid="checkout-success-billing-address"', $template);
    self::assertStringContainsString('billing_address', $template);
    self::assertStringContainsString('amz-order-confirm__meta-grid--with-billing', $template);
    self::assertStringContainsString("__('确认信息将发送至')", $template);
    self::assertStringContainsString('<w:slot id="checkout-success-guest-account"', $template);
    self::assertStringContainsString('weline-code="checkout.success.guest_account"', $template);
    self::assertStringNotContainsString('name="checkout-success-guest-convert"', $template);
    self::assertStringNotContainsString('GuestCheckoutConvertService', $template);
    self::assertStringContainsString('width: min(100%, var(--weline-layout-content-max-width));', $template);
    self::assertStringContainsString('padding: var(--weline-space-5, 24px) var(--weline-layout-content-padding-inline) var(--weline-space-8, 48px);', $template);
    self::assertStringContainsString('--amz-btn-primary-fg: var(--color-on-primary, #fff)', $template);
    self::assertStringContainsString('weline-pixel::continue_shopping', $template);
    self::assertStringContainsString("aria-label=\"<?= \$escape(__('订单操作')) ?>\"", $template);
    self::assertStringNotContainsString('max-width: 980px', $template);
    self::assertStringNotContainsString('var(--weline-layout-content-max-width, 1040px)', $template);
    self::assertStringNotContainsString('var(--weline-layout-content-padding-inline, 16px)', $template);
    $controller = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Success.php');
    self::assertStringContainsString("number_format(((int)(\$order->money['grand_total_minor'] ?? 0)) / 100, 2, '.', ',')", $controller);
    self::assertStringContainsString('CheckoutSessionAccessService', $controller);
    self::assertStringContainsString('CheckoutSuccessPresentationService', $controller);
    self::assertStringContainsString('order_v2_items_display', $controller);
    self::assertStringContainsString('digital_delivery_entries', $controller);
    self::assertStringContainsString('OrderDigitalDeliveryProjectionInterface', $controller);
    self::assertStringNotContainsString("product_type === 'travel_route'", $controller);
    self::assertStringNotContainsString("'travel_route'", $controller);
    self::assertStringContainsString('shipping_method_label', $controller);
    self::assertStringContainsString("getParam('checkout_token'", $controller);
    self::assertStringContainsString('canAccess(', $controller);
    self::assertStringContainsString('canReadOrder(', $controller);
    self::assertStringContainsString("setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')", $controller);
    self::assertStringContainsString('order_v2_items_display', $template);
    self::assertStringContainsString('digital_delivery_entries', $template);
    self::assertStringContainsString('checkout-success-digital-delivery', $template);
    self::assertStringNotContainsString("product_type === 'travel_route'", $template);
    self::assertStringContainsString("getData('shipping_method_label')", $template);
    self::assertStringContainsString("\$shipping['method_label']", $template);
    self::assertStringContainsString('__($shippingMethod)', $template);
    self::assertStringNotContainsString("\$shipping['method'] ?? \$shipping['method_label']", $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutOrderControllersUseRealRouterPaths(): void
{
    $success = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Success.php');
    $legacyCheckout = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Frontend/Checkout.php');
    $orders = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Frontend/Order.php');
    $orderList = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/order/list.phtml');
    $orderView = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/order/view.phtml');
    self::assertStringContainsString("'/customer/account/index#orders'", $success);
    self::assertStringContainsString("'/cart'", $success);
    self::assertStringContainsString('isLoggedIn() ? self::ORDER_LIST_PATH : self::CART_PATH', $success);
    self::assertStringNotContainsString('function successPage', $legacyCheckout);
    self::assertStringNotContainsString('checkout/success-page', $legacyCheckout);
    self::assertStringNotContainsString("'/checkout/frontend/order/list'", $orders);
    self::assertStringNotContainsString("'/checkout/frontend/order/view'", $orders);
    self::assertStringContainsString("'checkout/frontend/order/view'", $orderList);
    self::assertStringContainsString("'customer/account/index'", $orderView);
    self::assertStringContainsString("'order_uuid'", $orders);
    self::assertStringNotContainsString('OrderFacadeInterface', $orders);
    self::assertStringNotContainsString('->getItems()', $orderView);
    self::assertStringNotContainsString("weline_checkout/frontend/order/", $success . $legacyCheckout . $orders . $orderList . $orderView);
}

private function assert_StorefrontCheckoutTemplate_testOrderPagesOwnResponsiveCorePresentation(): void
{
    $orderList = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/order/list.phtml');
    $orderView = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/order/view.phtml');
    self::assertStringContainsString('data-checkout-order-list', $orderList);
    self::assertStringContainsString('.order-list-page {', $orderList);
    self::assertStringContainsString('--checkout-order-text: #111827;', $orderList);
    self::assertStringContainsString('color: var(--checkout-order-text);', $orderList);
    self::assertMatchesRegularExpression('/\.order-list-page h1\s*\{[^}]*color:\s*var\(--checkout-order-text\)/s', $orderList);
    self::assertStringContainsString('@media (max-width: 720px)', $orderList);
    self::assertStringContainsString('data-checkout-order-view', $orderView);
    self::assertStringContainsString('weline-code="checkout.order.view.info"', $orderView);
    self::assertStringContainsString('weline-code="checkout.order.view.items"', $orderView);
    self::assertStringContainsString('weline-code="checkout.order.view.totals"', $orderView);
    self::assertStringContainsString('data-order-table-scroll', $orderView);
    self::assertStringContainsString('.order-view-page {', $orderView);
    self::assertStringContainsString('--checkout-order-text: #111827;', $orderView);
    self::assertStringContainsString('color: var(--checkout-order-text);', $orderView);
    self::assertMatchesRegularExpression('/\.order-view-page h1\s*\{[^}]*color:\s*var\(--checkout-order-text\)/s', $orderView);
    self::assertStringContainsString('@media (max-width: 720px)', $orderView);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutHeaderSubtitleIsConfigurableAndDoesNotExposeInternalModuleNames(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    $controller = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Controller/Frontend/Checkout.php');
    self::assertStringContainsString('$checkoutPageSubtitle = (string)($this->getData(\'checkout_page_subtitle\') ?? \'\');', $template);
    self::assertStringContainsString('checkout_page_subtitle', $controller);
    self::assertStringContainsString('确认收货地址、配送方式和支付信息后即可提交订单。', $controller);
    self::assertStringNotContainsString('Weline 核心模块', $template);
    self::assertStringNotContainsString('Weline 核心模块', $controller);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutReloadsSummaryWhenMiniCartMutatesCart(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString("window.addEventListener('weline:cart-updated'", $template);
    self::assertStringContainsString('hardOnFailure: true', $template);
    self::assertStringContainsString("panels: { items: true, shipping: true, payment: true, address: false }", $template);
    self::assertStringContainsString("source === 'checkout-empty'", $template);
    self::assertStringContainsString('emptyCartInvalidateDone', $template);
    self::assertStringContainsString("reason: 'checkout-empty'", $template);
    self::assertStringContainsString('window.location.reload()', $template);
    self::assertStringContainsString('pendingDiscountPreview', $template);
    self::assertStringContainsString('rememberPendingDiscount', $template);
    self::assertStringContainsString('applyPendingDiscountToCart', $template);
    self::assertStringContainsString("detail.refresh === false", $template);
    self::assertStringContainsString('getDataParams.coupon_code', $template);
    self::assertStringNotContainsString("loadCheckout().catch(function () {\n            /* keep current totals if refresh fails */", $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutSurfacesShippingPaymentUnavailableAsBlockingAlert(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    $renderer = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/Service/CheckoutHtmlRenderer.php');
    self::assertStringContainsString('renderMethodEmptyAlert', $renderer);
    self::assertStringContainsString('data-checkout-method-empty', $renderer);
    self::assertStringContainsString('data-tone="warning"', $renderer);
    self::assertStringContainsString('暂无可用配送方式', $renderer);
    self::assertStringContainsString('syncMethodBlockPanels', $template);
    self::assertStringContainsString('readMethodBlockMessage', $template);
    self::assertStringContainsString('is-method-blocked', $template);
    self::assertStringContainsString('weline-checkout__method-alert', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutAwaitsShippingValidationIncludingEmbargo(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('async function validateCheckoutShippingFields', $template);
    self::assertStringContainsString('await widget.validateShippingFields', $template);
    self::assertStringContainsString('skipRequired: !requireFormValidity', $template);
    self::assertStringContainsString("await validateCheckoutShippingFields(address, {", $template);
    self::assertStringContainsString("Prior bug: validateShippingFields is async", $template);
    self::assertStringContainsString("result.reason !== 'shipping_fields'", $template);
    self::assertStringContainsString('Validate shipping/embargo BEFORE flipping the button', $template);
    self::assertStringContainsString('skipRequired: isContinuePayMode()', $template);
}

private function assert_StorefrontCheckoutTemplate_testCheckoutEmitsOrderCreatedAndPaymentBridgeOnRecovery(): void
{
    $template = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    $success = $this->read_StorefrontCheckoutTemplate('app/code/Weline/Checkout/view/frontend/checkout/success.phtml');
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout';
    $lifecycle = (string) file_get_contents($moduleRoot . '/view/statics/js/checkout-lifecycle.js');
    $modules = (string) file_get_contents($moduleRoot . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('announceCheckoutOrderCreated', $template);
    self::assertStringContainsString('syncLifecycleDomFields', $template);
    self::assertStringContainsString('data-lifecycle-fields-sig', $template);
    self::assertStringContainsString('weline:checkout:fields-ready', $template);
    self::assertStringContainsString('creditTotalsBusy', $template);
    self::assertStringContainsString('weline:checkout:order-created', $template);
    self::assertStringContainsString('weline:payment:outcome', $template);
    self::assertStringContainsString('checkoutLifecycle,paymentLifecycle', $template);
    self::assertStringContainsString('emitPaymentBridge', $template);
    self::assertStringContainsString('data-checkout-lifecycle', $success);
    self::assertStringContainsString('data-weline-load="cart,checkoutLifecycle,paymentLifecycle"', $success);
    self::assertStringContainsString('weline:checkout:order-created', $lifecycle);
    self::assertStringContainsString('console.info', $lifecycle);
    self::assertStringContainsString('[WelineCheckout]', $lifecycle);
    self::assertStringContainsString('validatePayload', $lifecycle);
    self::assertStringContainsString('weline:checkout:anomaly', $lifecycle);
    self::assertStringContainsString('checkout-lifecycle.js', $modules);
}

private function read_StorefrontCheckoutTemplate(string $relativePath): string
{
    $path = '/Users/weline/Project/Official/框架' . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function moduleRoot_StorefrontMoneySummaryWidget(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Checkout';
}

private function assert_StorefrontMoneySummaryWidget_testWidgetTemplateExposesUnifiedRowOrderAndLegacyAliases(): void
{
    $template = (string) file_get_contents($this->moduleRoot_StorefrontMoneySummaryWidget() . '/view/templates/frontend/widgets/storefront-money-summary/default.phtml');
    self::assertStringContainsString('data-money-summary', $template);
    self::assertStringContainsString('data-money-summary-row="goods"', $template);
    self::assertStringContainsString('data-money-summary-row="shipping"', $template);
    self::assertStringContainsString('data-money-summary-row="discount"', $template);
    self::assertStringContainsString('data-money-summary-row="deposit"', $template);
    self::assertStringContainsString('data-money-summary-row="credit"', $template);
    self::assertStringContainsString('data-money-summary-row="sales_tax"', $template);
    self::assertStringContainsString('data-money-summary-row-legacy="tax"', $template);
    self::assertStringContainsString('data-money-summary-row="customs_duty"', $template);
    self::assertStringContainsString('data-money-summary-row="import_tax"', $template);
    self::assertStringContainsString('data-money-summary-row="cod"', $template);
    self::assertStringContainsString('data-money-summary-row="incentive"', $template);
    self::assertStringContainsString('data-money-summary-row="order_total"', $template);
    self::assertStringContainsString('本单共计', $template);
    self::assertStringContainsString('data-money-summary-row="payable"', $template);
    self::assertStringContainsString('data-subtotal=""', $template);
    self::assertStringContainsString('data-shipping-amount=""', $template);
    self::assertStringContainsString('data-grand-total=""', $template);
    self::assertStringContainsString('data-cart-goods-subtotal', $template);
    self::assertStringContainsString('data-cart-grand-total', $template);
    self::assertStringContainsString('data-express-subtotal', $template);
    self::assertStringContainsString('data-helppay-goods-amount', $template);
    self::assertStringContainsString('data-helppay-total-amount', $template);
    self::assertStringContainsString("\$goodsText = trim((string) (\$this->getData('goods_text') ?? ''))", $template);
    self::assertStringContainsString("\$shippingText = trim((string) (\$this->getData('shipping_text') ?? ''))", $template);
    self::assertStringContainsString("\$payableText = trim((string) (\$this->getData('payable_text') ?? ''))", $template);
    self::assertStringContainsString("\$incentiveText = trim((string) (\$this->getData('incentive_text') ?? ''))", $template);
    self::assertStringContainsString("\$codFeeText = trim((string) (\$this->getData('cod_fee_text') ?? ''))", $template);
    self::assertStringContainsString('$showIncentiveInitially', $template);
    self::assertStringContainsString('$showCodInitially', $template);
    self::assertStringContainsString('<?= $escape($goodsText) ?>', $template);
    self::assertStringContainsString('<?= $escape($shippingText) ?>', $template);
    self::assertStringContainsString('<?= $escape($payableText) ?>', $template);
    self::assertLessThan(strpos($template, 'data-money-summary-row="shipping"'), strpos($template, 'data-money-summary-row="goods"'));
    self::assertLessThan(strpos($template, 'data-money-summary-row="sales_tax"'), strpos($template, 'data-money-summary-row="shipping"'));
    self::assertLessThan(strpos($template, 'data-money-summary-row="customs_duty"'), strpos($template, 'data-money-summary-row="sales_tax"'));
    self::assertLessThan(strpos($template, 'data-money-summary-row="import_tax"'), strpos($template, 'data-money-summary-row="customs_duty"'));
    self::assertLessThan(strpos($template, 'data-money-summary-row="order_total"'), strpos($template, 'data-money-summary-row="import_tax"'));
    self::assertLessThan(strpos($template, 'data-money-summary-row="payable"'), strpos($template, 'data-money-summary-row="order_total"'));
}

private function assert_StorefrontMoneySummaryWidget_testPaintApiAndModuleRegistrationExist(): void
{
    $js = (string) file_get_contents($this->moduleRoot_StorefrontMoneySummaryWidget() . '/view/statics/js/widgets/storefront-money-summary.js');
    $modules = (string) file_get_contents($this->moduleRoot_StorefrontMoneySummaryWidget() . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('WelineStorefrontMoneySummary', $js);
    self::assertStringContainsString('function resolveSymbol', $js);
    self::assertStringContainsString("USD: '\$'", $js);
    self::assertStringContainsString("code + ' ' + formatted", $js);
    self::assertStringContainsString('function paint', $js);
    self::assertStringContainsString('function ensure', $js);
    self::assertStringContainsString('ensurePaintRows', $js);
    self::assertStringContainsString('Older SSR shells may omit new rows', $js);
    self::assertStringContainsString('data-money-summary-row="deposit"', $js);
    self::assertStringContainsString('commerce_deposit_allowed', $js);
    self::assertStringContainsString('depositAllowed', $js);
    self::assertStringContainsString('data-money-summary-row="credit"', $js);
    self::assertStringContainsString('discounts_disabled', $js);
    self::assertStringContainsString('shipping_service_label', $js);
    self::assertStringContainsString('tax_amount_minor', $js);
    self::assertStringContainsString('sales_tax_minor', $js);
    self::assertStringContainsString('customs_duty_minor', $js);
    self::assertStringContainsString('import_tax_minor', $js);
    self::assertStringContainsString('data-money-summary-tax-minor', $js);
    self::assertStringContainsString('data-money-summary-row="sales_tax"', $js);
    self::assertStringContainsString('data-money-summary-row="customs_duty"', $js);
    self::assertStringContainsString('data-money-summary-row="import_tax"', $js);
    self::assertStringContainsString('order_total_minor', $js);
    self::assertStringContainsString('data-money-summary-row="order_total"', $js);
    self::assertStringContainsString('storefrontMoneySummary', $modules);
    self::assertStringContainsString('storefront-money-summary.js', $modules);
}

private function assert_StorefrontMoneySummaryWidget_testWidgetPhpRegistersCartMiniCartAndCheckoutInjections(): void
{
    $widgetPhp = $this->moduleRoot_StorefrontMoneySummaryWidget() . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/storefront-money-summary/default.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents($this->moduleRoot_StorefrontMoneySummaryWidget() . '/view/templates/frontend/widgets/storefront-money-summary/default.phtml');
    self::assertStringContainsString('@widget.code {storefront-money-summary}', $src);
    self::assertStringContainsString('storefront-money-summary.css', $src);
    self::assertStringContainsString('"slot":"money-summary"', $src);
    self::assertStringNotContainsString('"slot":"cart-money-summary"', $src);
    self::assertStringNotContainsString('"slot":"checkout-money-summary"', $src);
}

private function assert_StorefrontMoneySummaryWidget_testMiniCartCssBeatsInverseChromeInkForSubtotalContrast(): void
{
    $css = (string) file_get_contents($this->moduleRoot_StorefrontMoneySummaryWidget() . '/view/statics/css/widgets/storefront-money-summary.css');
    self::assertStringContainsString('[data-surface="inverse"] .mini-cart-drawer .w-storefront-money-summary__row strong', $css);
    self::assertStringContainsString('color: var(--amz-drawer-text, var(--weline-theme-text))', $css);
    self::assertStringContainsString('.w-storefront-money-summary__row--payable .w-storefront-money-summary__grand', $css);
    self::assertStringContainsString('color: var(--amz-drawer-price, var(--color-primary))', $css);
    // Soft rule above first totals row — separates Tob/note chrome from 小计.
    self::assertMatchesRegularExpression('/\.w-storefront-money-summary\s*\{[^}]*border-top:\s*var\(--border-width-thin,\s*1px\)\s+solid/s', $css);
    // Label left / amount right — full-width flex + amount auto margin.
    self::assertMatchesRegularExpression('/\.w-storefront-money-summary__row\s*\{[^}]*width:\s*100%/s', $css);
    self::assertStringContainsString('margin-inline-start: auto', $css);
    // Note / muted lines pin paper secondary (#565959 chain), not inverse cream.
    self::assertStringContainsString('--weline-chrome-body-text-secondary', $css);
    self::assertStringContainsString('#565959', $css);
}
}
