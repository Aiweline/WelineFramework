<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HelpPayWidgetContractTest extends TestCase
{
    public function testShareResultHasDualDeliveryAndNoStatic(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/help-pay-share-result.phtml'
        );
        self::assertStringContainsString('data-testid="help-pay-share-result"', $tpl);
        self::assertStringContainsString('data-testid="help-pay-copy-url"', $tpl);
        self::assertStringContainsString('data-testid="help-pay-copy-qr"', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }

    public function testCartCheckoutCtasUseModuleLoadWithoutStatic(): void
    {
        foreach (['cart-summary-help-pay', 'checkout-summary-help-pay'] as $name) {
            $tpl = (string) file_get_contents(
                dirname(__DIR__, 3) . '/view/templates/frontend/widgets/' . $name . '.phtml'
            );
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

    public function testUpgradeSeedsCartAndCheckoutHelpPaySlots(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('cart-summary-help-pay', $src);
        self::assertStringContainsString('checkout-summary-help-pay', $src);
        self::assertStringContainsString('product-purchase-actions', $src);
        self::assertStringContainsString('initSlotDefaultInjections', $src);
        self::assertStringContainsString('publishLayout', $src);
        self::assertStringContainsString('1.0.3', $src);
    }

    public function testProductHelpPayCtaIsQuietLinkNotSecondaryButton(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-help-pay.phtml'
        );
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

    public function testPayerTemplateKeepsRulesAndNoCouponCopy(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/pay/payer.phtml'
        );
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
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
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

    public function testPayerControllerRendersIsolatedBillingWidget(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Payer.php'
        );
        self::assertStringContainsString('renderBillingAddressWidget', $src);
        self::assertStringContainsString('paymentMethodOptions', $src);
        self::assertStringContainsString('CheckoutPaymentMethodsProvider', $src);
        self::assertStringContainsString('CheckoutHtmlRenderer', $src);
        self::assertStringContainsString('renderPaymentMethodsHtml', $src);
        self::assertStringContainsString('checkout-shipping-address.phtml', $src);
        self::assertStringContainsString('data-session-isolation', $src);
        self::assertStringNotContainsString('PaymentMethodManager', $src);
        self::assertStringNotContainsString('methodRequiresBilling', $src);
        self::assertStringContainsString('data-helppay-billing-host', $src);
        self::assertStringContainsString('hide_billing_same', $src);
        self::assertStringContainsString('付款账单地址', $src);
    }

    public function testQuickPayUsesLayoutSafeStructure(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/pay/quick.phtml'
        );
        self::assertStringContainsString('data-testid="quick-pay-self"', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringContainsString('w-helppay-panel', $tpl);
        self::assertStringNotContainsString('@static', $tpl);
    }

    public function testFrontendJsKeepsRulesGate(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/js/helppay-share.js'
        );
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
        self::assertStringContainsString('applyDialogFlow', $js);
        self::assertStringContainsString('renderQuickCheckoutLaunch', $js);
        self::assertStringContainsString('launchQuickPayCheckout', $js);
        self::assertStringContainsString('openPayPopup', $js);
        self::assertStringContainsString('data-helppay-step="shipping-pick"', $js);
        self::assertStringContainsString('data-helppay-step="payment"', $js);
        self::assertStringContainsString('openQuickShippingStep', $js);
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
        self::assertStringContainsString('confirmPayerBilling', $js);
        self::assertStringContainsString('syncPayerBillingVisibility', $js);
        self::assertStringContainsString('payerBillingRequired', $js);
        self::assertStringContainsString('startPayerPayment', $js);
        self::assertStringContainsString('startQuickPayment', $js);
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
        self::assertStringNotContainsString(
            "renderShareResult(host, absoluteUrl(result.url), t('quickTitle', '本人快捷购买'), 'quick')",
            $js,
        );
        self::assertStringNotContainsString('help-pay-address-preview', $js);
        self::assertStringNotContainsString('data-helppay-address-preview', $js);
        self::assertStringNotContainsString(
            "renderShareResult(host, absoluteUrl(result.url), '分享规格给朋友')",
            $js,
        );
    }

    public function testShippingAddressWidgetHonorsSessionIsolation(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/view/statics/js/widgets/checkout-shipping-address.js'
        );
        self::assertStringContainsString('isSessionIsolated', $js);
        self::assertStringContainsString('data-session-isolation', $js);
        self::assertStringContainsString('do not rewrite universal checkout', $js);
    }

    public function testQuickPayDoesNotSsrCheckoutAddressOnProductWidget(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-quick-pay.phtml'
        );
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

    public function testSelectionShareButtonExposesTranslatedI18nPayload(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-selection-share.phtml'
        );
        self::assertStringContainsString('data-helppay-i18n=', $tpl);
        self::assertStringContainsString('ShareModalI18n::json()', $tpl);
        self::assertStringContainsString('weline-pixel::selection_share', $tpl);
        self::assertStringContainsString('data-pixel-event="selection_share"', $tpl);
        self::assertStringContainsString('data-product-id=', $tpl);
        self::assertStringContainsString('w-helppay-cta--quiet', $tpl);
        self::assertStringContainsString('w-helppay-cta__link', $tpl);
        self::assertStringNotContainsString('product-native-detail__secondary', $tpl);
        self::assertStringNotContainsString('w-button--secondary', $tpl);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/ShareModalI18n.php');
    }

    public function testShareSpecEnUsTranslations(): void
    {
        $csv = (string) file_get_contents(dirname(__DIR__, 3) . '/i18n/en_US.csv');
        self::assertMatchesRegularExpression('/^当前规格,"Current options"$/m', $csv);
        self::assertMatchesRegularExpression('/^数量（规格）,Qty$/m', $csv);
        self::assertMatchesRegularExpression('/^存货单位（规格）,SKU$/m', $csv);
        self::assertMatchesRegularExpression('/^物流,Shipping$/m', $csv);
        self::assertMatchesRegularExpression('/^下一步：选择物流,"Next: choose shipping"$/m', $csv);
        self::assertMatchesRegularExpression('/^正在计算运费…,"Calculating shipping…"$/m', $csv);
        $svc = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ShareModalI18n.php');
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
        $zh = (string) file_get_contents(dirname(__DIR__, 3) . '/i18n/zh_Hans_CN.csv');
        self::assertMatchesRegularExpression('/^数量（规格）,数量$/m', $zh);
        self::assertMatchesRegularExpression('/^存货单位（规格）,SKU$/m', $zh);
    }

    public function testModulesRegistryExists(): void
    {
        $base = dirname(__DIR__, 3);
        $mod = (string) file_get_contents($base . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('helpPayShare', $mod);
        self::assertStringContainsString('helppay-share.js', $mod);
        self::assertStringNotContainsString('helppay-share.css', $mod);
        self::assertFileExists($base . '/view/statics/js/helppay-share.js');
        self::assertFileExists($base . '/view/statics/css/helppay-share.css');
        $js = (string) file_get_contents($base . '/view/statics/js/helppay-share.js');
        self::assertStringContainsString('data-helppay-share-css', $js);
        self::assertStringContainsString('/Weline/HelpPay/view/statics/css/helppay-share.css', $js);
        self::assertStringContainsString('onHelpPayDelegatedClick', $js);
        self::assertStringContainsString('friend_help_pay_link_ready', $js);
        self::assertStringContainsString('selection_share_link_ready', $js);
        self::assertStringContainsString('quick_buy_checkout_ready', $js);
        self::assertStringContainsString('function trackPixel', $js);
        self::assertStringContainsString('resolveOverlayHost', $js);
        self::assertStringContainsString('revealDialog', $js);
        self::assertStringContainsString('hideDialog', $js);
        self::assertStringContainsString('Weline.UI.stack', $js);
        self::assertStringContainsString('ensureShareCss', $js);
        $css = (string) file_get_contents($base . '/view/statics/css/helppay-share.css');
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

    public function testHindiCsvCoversPdpChromeLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $csv = (string)file_get_contents($path);
        foreach (['分享给朋友', '快捷购买', '找朋友代付'] as $source) {
            self::assertStringContainsString($source, $csv);
        }
        self::assertMatchesRegularExpression('/\\p{Devanagari}/u', $csv);
    }
}
