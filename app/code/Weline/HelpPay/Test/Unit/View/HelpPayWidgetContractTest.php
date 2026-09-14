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
        self::assertStringContainsString('优惠券与积分不可用于帮我付', $tpl);
        self::assertStringContainsString('data-weline-load="helpPayShare"', $tpl);
        self::assertStringContainsString('data-helppay-billing-mount', $tpl);
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
        self::assertStringContainsString('checkout-shipping-address.phtml', $src);
        self::assertStringContainsString('data-session-isolation', $src);
        self::assertStringContainsString('data-helppay-billing-host', $src);
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
        self::assertStringContainsString('data-testid="help-pay-share-spec"', $js);
        self::assertStringContainsString("t('specSection'", $js);
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
        self::assertStringContainsString('listQuoteOptions', $js);
        self::assertStringContainsString('data-session-isolation', $js);
        self::assertStringContainsString('ensurePayerBillingMounted', $js);
        self::assertStringContainsString('confirmPayerBilling', $js);
        self::assertStringContainsString('data-helppay-billing-mount', $js);
        self::assertStringContainsString("t('billingIncomplete'", $js);
        self::assertStringContainsString('option.label || option.service_name', $js);
        self::assertStringNotContainsString('w.location.assign', $js);
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
        self::assertStringContainsString('product-native-detail__secondary', $tpl);
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
        self::assertStringContainsString('product-native-detail__secondary', $tpl);
        self::assertStringNotContainsString('w-button--secondary', $tpl);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/ShareModalI18n.php');
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
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)', $css);
        self::assertStringContainsString('widget-wrapper:has(.product-native-detail__secondary)', $css);
    }
}
