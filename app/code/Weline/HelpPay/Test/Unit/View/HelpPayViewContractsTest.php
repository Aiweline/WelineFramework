<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllHelpPayViewContractsPass().
 */
final class HelpPayViewContractsTest extends TestCase
{
    public function testAllHelpPayViewContractsPass(): void
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
            'HelpPayFaqPages::testFaqProvidersAndTemplatesExist' => function () {
            $this->assert_HelpPayFaqPages_testFaqProvidersAndTemplatesExist();
        },
            'HelpPayNoPaymentMethodFallback::testShellDoesNotHardcodeProviderMethodCode' => function () {
            $this->assert_HelpPayNoPaymentMethodFallback_testShellDoesNotHardcodeProviderMethodCode();
        },
            'HelpPayNoPaymentMethodFallback::testShellDoesNotWriteProviderSpecificFormValues' => function () {
            $this->assert_HelpPayNoPaymentMethodFallback_testShellDoesNotWriteProviderSpecificFormValues();
        },
            'HelpPayNoPaymentMethodFallback::testQuickPayPreferredMethodComesFromProviderListOnly' => function () {
            $this->assert_HelpPayNoPaymentMethodFallback_testQuickPayPreferredMethodComesFromProviderListOnly();
        },
            'HelpPayNoPaymentMethodFallback::testQuickPayJsDoesNotInventMethodWhenAttributeMissing' => function () {
            $this->assert_HelpPayNoPaymentMethodFallback_testQuickPayJsDoesNotInventMethodWhenAttributeMissing();
        },
            'HelpPayNoPaymentMethodFallback::testDuplicatedFrontendJsCopiesStayIdentical' => function () {
            $this->assert_HelpPayNoPaymentMethodFallback_testDuplicatedFrontendJsCopiesStayIdentical();
        },
            'HelpPayWidget::testShareResultHasDualDeliveryAndNoStatic' => function () {
            $this->assert_HelpPayWidget_testShareResultHasDualDeliveryAndNoStatic();
        },
            'HelpPayWidget::testCartCheckoutCtasUseModuleLoadWithoutStatic' => function () {
            $this->assert_HelpPayWidget_testCartCheckoutCtasUseModuleLoadWithoutStatic();
        },
            'HelpPayWidget::testUpgradeSeedsCartAndCheckoutHelpPaySlots' => function () {
            $this->assert_HelpPayWidget_testUpgradeSeedsCartAndCheckoutHelpPaySlots();
        },
            'HelpPayWidget::testProductHelpPayCtaIsQuietLinkNotSecondaryButton' => function () {
            $this->assert_HelpPayWidget_testProductHelpPayCtaIsQuietLinkNotSecondaryButton();
        },
            'HelpPayWidget::testPayerTemplateKeepsRulesAndNoCouponCopy' => function () {
            $this->assert_HelpPayWidget_testPayerTemplateKeepsRulesAndNoCouponCopy();
        },
            'HelpPayWidget::testPayerControllerRendersIsolatedBillingWidget' => function () {
            $this->assert_HelpPayWidget_testPayerControllerRendersIsolatedBillingWidget();
        },
            'HelpPayWidget::testQuickPayUsesLayoutSafeStructure' => function () {
            $this->assert_HelpPayWidget_testQuickPayUsesLayoutSafeStructure();
        },
            'HelpPayWidget::testFrontendJsKeepsRulesGate' => function () {
            $this->assert_HelpPayWidget_testFrontendJsKeepsRulesGate();
        },
            'HelpPayWidget::testShippingAddressWidgetHonorsSessionIsolation' => function () {
            $this->assert_HelpPayWidget_testShippingAddressWidgetHonorsSessionIsolation();
        },
            'HelpPayWidget::testQuickPayDoesNotSsrCheckoutAddressOnProductWidget' => function () {
            $this->assert_HelpPayWidget_testQuickPayDoesNotSsrCheckoutAddressOnProductWidget();
        },
            'HelpPayWidget::testSelectionShareButtonExposesTranslatedI18nPayload' => function () {
            $this->assert_HelpPayWidget_testSelectionShareButtonExposesTranslatedI18nPayload();
        },
            'HelpPayWidget::testShareSpecEnUsTranslations' => function () {
            $this->assert_HelpPayWidget_testShareSpecEnUsTranslations();
        },
            'HelpPayWidget::testModulesRegistryExists' => function () {
            $this->assert_HelpPayWidget_testModulesRegistryExists();
        },
            'HelpPayWidget::testHindiCsvCoversPdpChromeLabels' => function () {
            $this->assert_HelpPayWidget_testHindiCsvCoversPdpChromeLabels();
        },
        ];
    }

private function assert_HelpPayFaqPages_testFaqProvidersAndTemplatesExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay';
    $slugs = ['help-pay-rules', 'help-pay-privacy', 'help-pay-payer', 'selection-share'];
    foreach ($slugs as $slug) {
        $tpl = $base . '/view/templates/frontend/faq/' . $slug . '.phtml';
        self::assertFileExists($tpl, $slug);
        $html = (string) file_get_contents($tpl);
        self::assertStringContainsString('data-testid="faq-' . $slug . '"', $html);
        // SPI body must not repeat Faq view.phtml article <h1> (provider title).
        self::assertDoesNotMatchRegularExpression('/<h[12]\b/i', $html, $slug . ' SPI must not emit h1/h2; article title lives in Faq view.phtml');
    }
    $providerDir = $base . '/extends/module/Weline_Faq/FaqPageProvider';
    self::assertDirectoryExists($providerDir);
    $files = glob($providerDir . '/*.php') ?: [];
    self::assertGreaterThanOrEqual(4, count($files));
}

/** @return list<string> */
private function shellSources_HelpPayNoPaymentMethodFallback(): array
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay';
    return [$base . '/view/templates/frontend/pay/quick.phtml', $base . '/Controller/Frontend/QuickPay.php', $base . '/Service/HelpPayOrchestrator.php', $base . '/view/statics/js/helppay-share.js', $base . '/view/statics/frontend/js/helppay-share.js'];
}

private function assert_HelpPayNoPaymentMethodFallback_testShellDoesNotHardcodeProviderMethodCode(): void
{
    foreach ($this->shellSources_HelpPayNoPaymentMethodFallback() as $path) {
        $src = (string) file_get_contents($path);
        self::assertNotSame('', $src, $path . ' 不应为空。');
        self::assertStringNotContainsString('fake_card', $src, basename($path) . ' 不得硬编码 fake_card；可用方式应由 Provider 列表决定。');
    }
}

private function assert_HelpPayNoPaymentMethodFallback_testShellDoesNotWriteProviderSpecificFormValues(): void
{
    foreach ($this->shellSources_HelpPayNoPaymentMethodFallback() as $path) {
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('fake_result', $src, basename($path) . ' 不得代写 Fake Provider 的 fake_result 参数。');
    }
}

private function assert_HelpPayNoPaymentMethodFallback_testQuickPayPreferredMethodComesFromProviderListOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Controller/Frontend/QuickPay.php');
    self::assertStringContainsString('listMethods(', $src, 'QuickPay 默认支付方式必须来自 Provider 列表。');
    // 列表为空/异常时不得编造方式：返回空串，由调用方按「无可用支付方式」处理。
    self::assertStringContainsString("return '';", $src, 'QuickPay 无可用方式时应返回空串而非兜底 code。');
}

private function assert_HelpPayNoPaymentMethodFallback_testQuickPayJsDoesNotInventMethodWhenAttributeMissing(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/statics/js/helppay-share.js');
    self::assertStringContainsString("getAttribute('data-payment-method')", $js, 'JS 仍须读取服务端下发的 data-payment-method。');
    self::assertStringContainsString('noPaymentMethod', $js, 'JS 在服务端未下发支付方式时须给出明确提示，而不是编造 code。');
}

private function assert_HelpPayNoPaymentMethodFallback_testDuplicatedFrontendJsCopiesStayIdentical(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay';
    $canonical = (string) file_get_contents($base . '/view/statics/js/helppay-share.js');
    $mirror = (string) file_get_contents($base . '/view/statics/frontend/js/helppay-share.js');
    self::assertSame(md5($canonical), md5($mirror), 'helppay-share.js 两份副本必须保持一致，避免改一份漏一份。');
}

private function assert_HelpPayWidget_testShareResultHasDualDeliveryAndNoStatic(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/help-pay-share-result.phtml');
    self::assertStringContainsString('data-testid="help-pay-share-result"', $tpl);
    self::assertStringContainsString('data-testid="help-pay-copy-url"', $tpl);
    self::assertStringContainsString('data-testid="help-pay-copy-qr"', $tpl);
    self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
    self::assertStringNotContainsString('@static', $tpl);
}

private function assert_HelpPayWidget_testCartCheckoutCtasUseModuleLoadWithoutStatic(): void
{
    foreach (['cart-summary-help-pay', 'checkout-summary-help-pay'] as $name) {
        $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/' . $name . '.phtml');
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringContainsString('data-helppay-placement=', $tpl);
        self::assertStringContainsString('w-helppay-cta--quiet', $tpl);
        self::assertStringContainsString('w-helppay-cta__link', $tpl);
        self::assertStringContainsString('data-helppay-i18n=', $tpl);
        self::assertStringContainsString('ShareModalI18n::json()', $tpl);
        self::assertStringNotContainsString('data-mini-cart-tab-label', $tpl);
        self::assertStringNotContainsString('w-button--secondary', $tpl);
        // 不得默认 hidden：extras 页签会跳过全隐藏槽，导致「已注入却看不见」。
        self::assertDoesNotMatchRegularExpression('/\bhidden(?:=|\s|>)/', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }
}

private function assert_HelpPayWidget_testUpgradeSeedsCartAndCheckoutHelpPaySlots(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Setup/Upgrade.php');
    self::assertStringContainsString('cart-summary-help-pay', $src);
    self::assertStringContainsString('checkout-summary-help-pay', $src);
    self::assertStringContainsString('product-purchase-actions', $src);
    self::assertStringContainsString('initSlotDefaultInjections', $src);
    self::assertStringContainsString('publishLayout', $src);
    self::assertStringContainsString('1.0.3', $src);
}

private function assert_HelpPayWidget_testProductHelpPayCtaIsQuietLinkNotSecondaryButton(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/product-help-pay.phtml');
    self::assertStringContainsString('data-testid="product-help-pay"', $tpl);
    self::assertStringContainsString('data-helppay-placement="product"', $tpl);
    self::assertStringContainsString('data-helppay-product-help-pay', $tpl);
    self::assertStringContainsString('w-helppay-cta--quiet', $tpl);
    self::assertStringContainsString('w-helppay-cta__link', $tpl);
    self::assertStringContainsString('weline-pixel::friend_help_pay', $tpl);
    self::assertStringContainsString('data-pixel-event="friend_help_pay"', $tpl);
    self::assertStringContainsString('data-product-id=', $tpl);
    self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
    self::assertStringContainsString('ShareModalI18n::json()', $tpl);
    self::assertStringNotContainsString('w-button--secondary', $tpl);
    self::assertStringNotContainsString('w-button--primary', $tpl);
    self::assertDoesNotMatchRegularExpression('/\bhidden(?:=|\s|>)/', $tpl);
    self::assertStringNotContainsString('@static', $tpl);
    self::assertStringNotContainsString('checkout-shipping-address', $tpl);
    self::assertStringNotContainsString('data-shipping-checkout-address', $tpl);
}

private function assert_HelpPayWidget_testPayerTemplateKeepsRulesAndNoCouponCopy(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/pay/payer.phtml');
    self::assertStringContainsString('请选择适合您的支付方式完成付款。', $tpl);
    self::assertStringContainsString('data-testid="help-pay-payer-rules"', $tpl);
    self::assertStringContainsString('data-testid="help-pay-payer-privacy"', $tpl);
    self::assertStringContainsString('target="_blank"', $tpl);
    self::assertStringContainsString('rel="noopener noreferrer"', $tpl);
    self::assertStringContainsString('收货地址已由发起人确认。', $tpl);
    self::assertStringNotContainsString('本页不展示', $tpl);
    self::assertStringNotContainsString('优惠券与积分不可用于帮我付', $tpl);
    self::assertStringContainsString('data-testid="help-pay-payer-grid"', $tpl);
    self::assertStringContainsString('w-helppay-payer__main', $tpl);
    self::assertStringContainsString('w-helppay-payer__pay', $tpl);
    self::assertStringContainsString('data-testid="help-pay-totals"', $tpl);
    self::assertStringContainsString('data-helppay-shipping-method', $tpl);
    self::assertStringContainsString('data-helppay-payable-amount', $tpl);
    self::assertStringNotContainsString('value="standard"', $tpl);
    self::assertStringNotContainsString('value="express"', $tpl);
    self::assertStringNotContainsString('标准配送', $tpl);
    self::assertStringNotContainsString('加急配送', $tpl);
    self::assertStringContainsString('data-weline-load="helpPayShare,storefrontMoneySummary"', $tpl);
    self::assertStringContainsString('money_summary_html', $tpl);
    self::assertStringContainsString('data-helppay-billing-mount', $tpl);
    self::assertStringContainsString('payment_methods_html', $tpl);
    self::assertStringContainsString('weline-checkout__option--payment', $tpl);
    self::assertStringContainsString('weline-checkout__payment-logo', $tpl);
    self::assertStringContainsString('data-helppay-payment-method', $tpl);
    self::assertStringContainsString('data-requires-billing', $tpl);
    self::assertStringContainsString('data-helppay-billing-panel', $tpl);
    self::assertStringContainsString('billing_address_html', $tpl);
    self::assertStringNotContainsString('billing_line1', $tpl);
    self::assertStringNotContainsString('data-helppay-billing-line1', $tpl);
    self::assertStringNotContainsString('@static', $tpl);
}

private function assert_HelpPayWidget_testPayerControllerRendersIsolatedBillingWidget(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Controller/Frontend/Payer.php');
    self::assertStringContainsString('renderBillingAddressWidget', $src);
    self::assertStringContainsString('paymentMethodOptions', $src);
    self::assertStringContainsString('CheckoutPaymentMethodsProvider', $src);
    self::assertStringContainsString('CheckoutHtmlRenderer', $src);
    self::assertStringContainsString('renderPaymentMethodsHtml', $src);
    self::assertStringContainsString('renderMoneySummaryWidget', $src);
    self::assertStringContainsString('storefront-money-summary/default.phtml', $src);
    self::assertStringContainsString("'goods_text'", $src);
    self::assertStringContainsString("'shipping_text'", $src);
    self::assertStringContainsString("'tax_text'", $src);
    self::assertStringContainsString("'tax_minor'", $src);
    self::assertStringContainsString('tax_amount_minor', $src);
    self::assertStringContainsString("'payable_text'", $src);
    self::assertStringContainsString('checkout-shipping-address.phtml', $src);
    self::assertStringContainsString('data-session-isolation', $src);
    self::assertStringNotContainsString('PaymentMethodManager', $src);
    self::assertStringNotContainsString('methodRequiresBilling', $src);
    self::assertStringContainsString('data-helppay-billing-host', $src);
    self::assertStringContainsString('hide_billing_same', $src);
    self::assertStringContainsString('付款账单地址', $src);
}

private function assert_HelpPayWidget_testQuickPayUsesLayoutSafeStructure(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/pay/quick.phtml');
    self::assertStringContainsString('data-testid="quick-pay-self"', $tpl);
    self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
    self::assertStringContainsString('w-helppay-panel', $tpl);
    self::assertStringContainsString('preferred_payment_method', $tpl);
    self::assertStringNotContainsString('data-payment-method="paypal"', $tpl);
    self::assertStringNotContainsString('@static', $tpl);
}

private function assert_HelpPayWidget_testFrontendJsKeepsRulesGate(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/statics/frontend/js/helppay-share.js');
    self::assertStringContainsString('help-pay-rules-link', $js);
    self::assertStringContainsString('/faq/help-pay-rules', $js);
    self::assertStringContainsString('help-pay-rules-accepted', $js);
    self::assertStringContainsString('w-helppay-dialog__bullets', $js);
    self::assertStringContainsString('selectedCheckoutAddressPayload', $js);
    self::assertStringContainsString('data-address-json', $js);
    self::assertStringContainsString('data-address-card', $js);
    self::assertStringContainsString('hydrateDialogCopy', $js);
    self::assertStringContainsString('dialogLead', $js);
    self::assertStringContainsString('w-helppay-share-result__grid', $js);
    self::assertStringContainsString('buildShareSpecHtml', $js);
    self::assertStringContainsString('readCurrentSpecSummary', $js);
    self::assertStringContainsString('readCurrentSpecImage', $js);
    self::assertStringContainsString('data-testid="help-pay-share-spec"', $js);
    self::assertStringContainsString('help-pay-share-spec-image', $js);
    self::assertStringContainsString("t('specSection'", $js);
    self::assertStringContainsString("t('specQty'", $js);
    self::assertStringContainsString('data-helppay-i18n', $js);
    self::assertStringContainsString('mergeShareI18n', $js);
    self::assertStringContainsString("t('shareTitle'", $js);
    self::assertStringContainsString('data-helppay-step="address-pick"', $js);
    self::assertStringContainsString('renderDeliveryAddressWidget', $js);
    self::assertStringContainsString('ensureHelpPayAddressMounted', $js);
    self::assertStringContainsString('ensureQuickPayAddressMounted', $js);
    self::assertStringContainsString('confirmQuickPayFromAddress', $js);
    self::assertStringContainsString('restorePageShippingAddressApi', $js);
    self::assertStringContainsString('resolveDialogShippingApi', $js);
    self::assertStringContainsString('openHelpPayAddressStep', $js);
    self::assertStringContainsString('data-catalog-price-minor', $js);
    self::assertStringContainsString('data-offer-price-minor', $js);
    self::assertStringContainsString('readDisplayedPdpPriceMinor', $js);
    self::assertStringContainsString('openProductHelpPayFlow', $js);
    self::assertStringContainsString('confirmHelpPayFromAddress', $js);
    self::assertStringContainsString("placement === 'product'", $js);
    self::assertStringContainsString('cart.getCart', $js);
    self::assertStringContainsString('data-money-summary-goods-minor', $js);
    self::assertStringContainsString('currentCartType', $js);
    self::assertStringNotContainsString('cart.getData', $js);
    self::assertStringContainsString('applyDialogFlow', $js);
    self::assertStringContainsString('renderQuickCheckoutLaunch', $js);
    self::assertStringContainsString('launchQuickPayCheckout', $js);
    self::assertStringContainsString('openPayPopup', $js);
    self::assertStringContainsString('data-helppay-step="shipping-pick"', $js);
    self::assertStringContainsString('data-helppay-step="payment"', $js);
    self::assertStringContainsString('openQuickShippingStep', $js);
    self::assertStringContainsString('readRequiresShipping', $js);
    self::assertStringContainsString('openQuickDigitalPaymentFlow', $js);
    self::assertStringContainsString("requires_shipping: false", $js);
    self::assertStringContainsString("service_code: 'none'", $js);
    self::assertStringContainsString('data-requires-shipping', $js);
    self::assertStringContainsString('openQuickPaymentStep', $js);
    self::assertStringContainsString('renderPaymentSummary', $js);
    self::assertStringContainsString('formatShipToLines', $js);
    self::assertStringContainsString('w-helppay-pay-summary', $js);
    self::assertStringContainsString('helppay-payment-ship-to', $js);
    self::assertStringContainsString('helppay-payment-money', $js);
    self::assertStringContainsString("t('paySummaryShipTo'", $js);
    self::assertStringContainsString('state.spec', $js);
    self::assertStringContainsString('data-variant="outline"', $js);
    self::assertStringContainsString('data-tone="neutral"', $js);
    self::assertStringContainsString('data-helppay-back-shipping', $js);
    self::assertStringNotContainsString('w-button--secondary', $js);
    self::assertStringNotContainsString('w-button--primary', $js);
    self::assertStringContainsString('listQuickShippingOptions', $js);
    self::assertStringNotContainsString('weight_minor: 500', $js);
    self::assertStringNotContainsString("weight_minor: 500", $js);
    self::assertStringNotContainsString("resource('shippingInfo').listQuoteOptions", $js);
    self::assertStringContainsString('data-session-isolation', $js);
    self::assertStringContainsString('ensurePayerBillingMounted', $js);
    self::assertStringContainsString('bindPayerPayButton', $js);
    self::assertStringContainsString('bindGlobalHelpPayClicks', $js);
    self::assertStringContainsString('_helppayPayBound', $js);
    self::assertStringContainsString("attributeFilter: ['hidden', 'data-selling-mode', 'data-stock-tone']", $js);
    self::assertStringNotContainsString("attributeFilter: ['disabled', 'hidden'", $js);
    self::assertStringContainsString('confirmPayerBilling', $js);
    self::assertStringContainsString('syncPayerBillingVisibility', $js);
    self::assertStringContainsString('payerBillingRequired', $js);
    self::assertStringContainsString('startPayerPayment', $js);
    self::assertStringContainsString('updatePayerTotals', $js);
    self::assertStringContainsString('WelineStorefrontMoneySummary', $js);
    self::assertStringContainsString("mode: 'helppay'", $js);
    self::assertStringContainsString('goods_subtotal_minor', $js);
    self::assertStringContainsString('bindGlobalHelpPayClicks();', $js);
    self::assertStringContainsString('startQuickPayment', $js);
    self::assertStringContainsString('/payment/success?transaction_no=', $js);
    // 壳不得硬编码具体支付方式码：方式由服务端按 Provider 列表下发；
    // 列表为空时明确提示，不得编造兜底 code（见 HelpPayNoPaymentMethodFallbackContractTest）。
    self::assertStringNotContainsString('fake_card', $js);
    self::assertStringContainsString("t('noPaymentMethod'", $js);
    self::assertStringContainsString('onQuickSelfPayClick', $js);
    self::assertStringContainsString('onPayerPayClick', $js);
    self::assertStringContainsString('data-helppay-billing-mount', $js);
    self::assertStringContainsString("t('billingIncomplete'", $js);
    self::assertStringContainsString('option.label || option.service_name', $js);
    self::assertStringContainsString('w.location.assign', $js);
    self::assertStringContainsString('openPayPopup', $js);
    self::assertStringContainsString("t('quickPayNow'", $js);
    self::assertStringContainsString("t('dialogStepPay'", $js);
    self::assertStringContainsString("data-helppay-flow", $js);
    self::assertStringNotContainsString("renderShareResult(host, absoluteUrl(result.url), t('quickTitle', '本人快捷购买'), 'quick')", $js);
    self::assertStringNotContainsString('help-pay-address-preview', $js);
    self::assertStringNotContainsString('data-helppay-address-preview', $js);
    self::assertStringNotContainsString("renderShareResult(host, absoluteUrl(result.url), '分享规格给朋友')", $js);
}

private function assert_HelpPayWidget_testShippingAddressWidgetHonorsSessionIsolation(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/view/statics/js/widgets/checkout-shipping-address.js');
    self::assertStringContainsString('isSessionIsolated', $js);
    self::assertStringContainsString('data-session-isolation', $js);
    self::assertStringContainsString('do not rewrite universal checkout', $js);
}

private function assert_HelpPayWidget_testQuickPayDoesNotSsrCheckoutAddressOnProductWidget(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/product-quick-pay.phtml');
    self::assertStringContainsString('data-helppay-quick-pay', $tpl);
    self::assertStringContainsString('data-product-id=', $tpl);
    self::assertStringContainsString('weline-pixel::quick_buy', $tpl);
    self::assertStringContainsString('data-pixel-event="quick_buy"', $tpl);
    self::assertStringContainsString('w-helppay-cta--quiet', $tpl);
    self::assertStringContainsString('w-helppay-cta__link', $tpl);
    self::assertStringNotContainsString('product-native-detail__secondary', $tpl);
    self::assertStringNotContainsString('w-button--secondary', $tpl);
    self::assertStringNotContainsString('w-helppay-cta__btn', $tpl);
    self::assertStringNotContainsString('checkout-shipping-address', $tpl);
    self::assertStringNotContainsString('data-shipping-checkout-address', $tpl);
    self::assertStringNotContainsString('data-helppay-address-host', $tpl);
}

private function assert_HelpPayWidget_testSelectionShareButtonExposesTranslatedI18nPayload(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/product-selection-share.phtml');
    self::assertStringContainsString('data-helppay-i18n=', $tpl);
    self::assertStringContainsString('ShareModalI18n::json()', $tpl);
    self::assertStringContainsString('weline-pixel::selection_share', $tpl);
    self::assertStringContainsString('data-pixel-event="selection_share"', $tpl);
    self::assertStringContainsString('data-product-id=', $tpl);
    self::assertStringContainsString('w-helppay-cta--quiet', $tpl);
    self::assertStringContainsString('w-helppay-cta__link', $tpl);
    self::assertStringNotContainsString('product-native-detail__secondary', $tpl);
    self::assertStringNotContainsString('w-button--secondary', $tpl);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Service/ShareModalI18n.php');
}

private function assert_HelpPayWidget_testShareSpecEnUsTranslations(): void
{
    $csv = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/i18n/en_US.csv');
    self::assertMatchesRegularExpression('/^当前规格,"Current options"$/m', $csv);
    self::assertMatchesRegularExpression('/^数量（规格）,Qty$/m', $csv);
    self::assertMatchesRegularExpression('/^存货单位（规格）,SKU$/m', $csv);
    self::assertMatchesRegularExpression('/^物流,Shipping$/m', $csv);
    self::assertMatchesRegularExpression('/^下一步：选择物流,"Next: choose shipping"$/m', $csv);
    self::assertMatchesRegularExpression('/^正在计算运费…,"Calculating shipping…"$/m', $csv);
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Service/ShareModalI18n.php');
    self::assertStringContainsString("__('当前规格')", $svc);
    self::assertStringContainsString("__('数量（规格）')", $svc);
    self::assertStringContainsString("'specSku' => 'SKU'", $svc);
    self::assertStringContainsString("__('物流')", $svc);
    self::assertStringContainsString("__('下一步：选择物流')", $svc);
    self::assertStringContainsString("__('正在计算运费…')", $svc);
    self::assertStringContainsString("__('购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。')", $svc);
    self::assertStringContainsString("'missingWeight'", $svc);
    self::assertStringContainsString("'paySummaryTotal'", $svc);
    self::assertStringContainsString("__('收货')", $svc);
    self::assertStringContainsString("'paySummaryShipTo'", $svc);
    self::assertStringContainsString("'dialogStepShipping'", $svc);
    self::assertStringContainsString("'confirmQuickNextShipping'", $svc);
    self::assertStringContainsString("'loadingShipping'", $svc);
    $zh = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/i18n/zh_Hans_CN.csv');
    self::assertMatchesRegularExpression('/^数量（规格）,数量$/m', $zh);
    self::assertMatchesRegularExpression('/^存货单位（规格）,SKU$/m', $zh);
}

private function assert_HelpPayWidget_testModulesRegistryExists(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay';
    $mod = (string) file_get_contents($base . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('helpPayShare', $mod);
    self::assertStringContainsString('helppay-share.js', $mod);
    self::assertStringContainsString('20261006-cartamt1', $mod);
    self::assertStringNotContainsString('helppay-share.css', $mod);
    self::assertFileExists($base . '/view/statics/js/helppay-share.js');
    self::assertFileExists($base . '/view/statics/css/helppay-share.css');
    $js = (string) file_get_contents($base . '/view/statics/js/helppay-share.js');
    self::assertStringContainsString('data-helppay-share-css', $js);
    self::assertStringContainsString('Weline_HelpPay::css/helppay-share.css', $js);
    self::assertStringContainsString('resolveStaticPath', $js);
    self::assertStringNotContainsString('/Weline/HelpPay/view/statics/css/helppay-share.css', $js);
    self::assertStringContainsString('onHelpPayDelegatedClick', $js);
    self::assertStringContainsString('friend_help_pay_link_ready', $js);
    self::assertStringContainsString('selection_share_link_ready', $js);
    self::assertStringContainsString('quick_buy_checkout_ready', $js);
    self::assertStringContainsString('function trackPixel', $js);
    self::assertStringContainsString('resolveOverlayHost', $js);
    self::assertStringContainsString('revealDialog', $js);
    self::assertStringContainsString('hideDialog', $js);
    self::assertStringContainsString('Weline.UI.stack', $js);
    self::assertStringContainsString('20261006-paygap1', $js);
    self::assertStringContainsString('ensureShareCss', $js);
    $css = (string) file_get_contents($base . '/view/statics/css/helppay-share.css');
    self::assertStringContainsString('padding-block-start: 0', $css);
    self::assertStringContainsString('appearance: none', $css);
    self::assertStringContainsString('-webkit-appearance: none', $css);
    self::assertStringContainsString('.w-helppay-cta__link', $css);
    self::assertStringContainsString('product-native-detail__actions:has(.product-native-detail__secondary)', $css);
    self::assertStringContainsString('product-native-detail__actions:has(.w-helppay-cta--quiet)', $css);
    self::assertStringContainsString('flex-wrap: wrap', $css);
    self::assertStringContainsString('flex: 0 1 auto', $css);
    self::assertStringContainsString('min-inline-size: 0', $css);
    self::assertStringContainsString('white-space: nowrap', $css);
    self::assertStringNotContainsString('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)', $css);
    self::assertStringNotContainsString('flex: 1 1 calc(50%', $css);
    self::assertStringNotContainsString('max-inline-size: calc(50%', $css);
    self::assertStringContainsString('widget-wrapper:has(.product-native-detail__secondary)', $css);
    self::assertStringContainsString('widget-wrapper:has(.w-helppay-cta--quiet)', $css);
}

private function assert_HelpPayWidget_testHindiCsvCoversPdpChromeLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $csv = (string) file_get_contents($path);
    foreach (['分享给朋友', '快捷购买', '找朋友代付'] as $source) {
        self::assertStringContainsString($source, $csv);
    }
    self::assertMatchesRegularExpression('/\p{Devanagari}/u', $csv);
}
}
