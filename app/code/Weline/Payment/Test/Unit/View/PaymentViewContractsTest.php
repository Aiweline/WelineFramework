<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 14 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentViewContractsPass().
 */
final class PaymentViewContractsTest extends TestCase
{
    public function testAllPaymentViewContractsPass(): void
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
            'AccountOrderPaymentRecords::testPaymentProvidesAccountOrderPaymentRecordsHook' => function () {
            $this->assert_AccountOrderPaymentRecords_testPaymentProvidesAccountOrderPaymentRecordsHook();
        },
            'AccountOrderPaymentRecords::testAccountTemplateShowsPaymentEntryBadge' => function () {
            $this->assert_AccountOrderPaymentRecords_testAccountTemplateShowsPaymentEntryBadge();
        },
            'BackendOrderPaymentRecordsWidget::testWidgetRegistrationPinsOrderPaymentRecordsSlot' => function () {
            $this->assert_BackendOrderPaymentRecordsWidget_testWidgetRegistrationPinsOrderPaymentRecordsSlot();
        },
            'BackendOrderPaymentRecordsWidget::testHookTemplateDelegatesToPaymentWidget' => function () {
            $this->assert_BackendOrderPaymentRecordsWidget_testHookTemplateDelegatesToPaymentWidget();
        },
            'BackendOrderPaymentRecordsWidget::testWidgetTemplateUsesAttemptService' => function () {
            $this->assert_BackendOrderPaymentRecordsWidget_testWidgetTemplateUsesAttemptService();
        },
            'BackendOrderPaymentRecordsWidget::testWidgetTemplateShowsMethodIconAndFourColorStatusChip' => function () {
            $this->assert_BackendOrderPaymentRecordsWidget_testWidgetTemplateShowsMethodIconAndFourColorStatusChip();
        },
            'CheckoutExpressPaymentWidget::testExpressPaymentRegistersRequiredDefaultInjectionWithVisibilityToggle' => function () {
            $this->assert_CheckoutExpressPaymentWidget_testExpressPaymentRegistersRequiredDefaultInjectionWithVisibilityToggle();
        },
            'CheckoutExpressPaymentWidget::testExpressPaymentTemplateIsShopifyLayoutCommerceStyledAndToggleAware' => function () {
            $this->assert_CheckoutExpressPaymentWidget_testExpressPaymentTemplateIsShopifyLayoutCommerceStyledAndToggleAware();
        },
            'FooterPaymentMethodsLinkWidget::testFooterPaymentMethodsLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterPaymentMethodsLinkWidget_testFooterPaymentMethodsLinkRegistersDefaultInjection();
        },
            'FooterPaymentMethodsLinkWidget::testFooterPaymentMethodsLinkTemplatePointsToGuide' => function () {
            $this->assert_FooterPaymentMethodsLinkWidget_testFooterPaymentMethodsLinkTemplatePointsToGuide();
        },
            'MethodIndexTemplateHeader::testIndexTemplateDoesNotDuplicatePageHeading' => function () {
            $this->assert_MethodIndexTemplateHeader_testIndexTemplateDoesNotDuplicatePageHeading();
        },
            'PayPalAuthorizeCallbackPath::testAuthorizeAdaptersDeclareCallbackPath' => function () {
            $this->assert_PayPalAuthorizeCallbackPath_testAuthorizeAdaptersDeclareCallbackPath();
        },
            'PayPalCheckoutWalletTemplate::testCheckoutTemplateExposesWalletDataAttributes' => function () {
            $this->assert_PayPalCheckoutWalletTemplate_testCheckoutTemplateExposesWalletDataAttributes();
        },
            'PayPalCheckoutWalletTemplate::testCheckoutModuleDoesNotEmbedPayPalSdk' => function () {
            $this->assert_PayPalCheckoutWalletTemplate_testCheckoutModuleDoesNotEmbedPayPalSdk();
        },
            'PayPalConfigTemplate::testPayPalConfigTemplateExposesSandboxFields' => function () {
            $this->assert_PayPalConfigTemplate_testPayPalConfigTemplateExposesSandboxFields();
        },
            'PaymentGuideTemplate::testBuiltInProviderGuideAndPolicyTemplatesExist' => function () {
            $this->assert_PaymentGuideTemplate_testBuiltInProviderGuideAndPolicyTemplatesExist();
        },
            'PaymentGuideTemplate::testHubTemplateUsesGuideEntryLinks' => function () {
            $this->assert_PaymentGuideTemplate_testHubTemplateUsesGuideEntryLinks();
        },
            'PaymentGuideTemplate::testSidebarUsesProviderArticleHierarchy' => function () {
            $this->assert_PaymentGuideTemplate_testSidebarUsesProviderArticleHierarchy();
        },
            'PaymentGuideTemplate::testArticleTemplatesUseSharedBreadcrumbPartial' => function () {
            $this->assert_PaymentGuideTemplate_testArticleTemplatesUseSharedBreadcrumbPartial();
        },
            'PaymentGuideTemplate::testGuideTemplatesUseLangTagsNotPhpTranslate' => function () {
            $this->assert_PaymentGuideTemplate_testGuideTemplatesUseLangTagsNotPhpTranslate();
        },
            'PaymentGuideTemplate::testGuideTemplatesUseUrlTagForNavigationLinks' => function () {
            $this->assert_PaymentGuideTemplate_testGuideTemplatesUseUrlTagForNavigationLinks();
        },
            'PaymentGuideTemplate::testGuidePhraseEnUsTranslationsAreNotChinesePlaceholders' => function () {
            $this->assert_PaymentGuideTemplate_testGuidePhraseEnUsTranslationsAreNotChinesePlaceholders();
        },
            'PaymentGuideTemplate::testPaymentGuideStyleAssetsAndPartialsExist' => function () {
            $this->assert_PaymentGuideTemplate_testPaymentGuideStyleAssetsAndPartialsExist();
        },
            'PaymentLifecycleScript::testPaymentLifecycleOwnsUnifiedOutcomeEvents' => function () {
            $this->assert_PaymentLifecycleScript_testPaymentLifecycleOwnsUnifiedOutcomeEvents();
        },
            'PaymentMethodIconAdmin::testMethodIndexRendersIconColumn' => function () {
            $this->assert_PaymentMethodIconAdmin_testMethodIndexRendersIconColumn();
        },
            'PaymentMethodIconAdmin::testConfigTemplatesDeclareIconImageField' => function () {
            $this->assert_PaymentMethodIconAdmin_testConfigTemplatesDeclareIconImageField();
        },
            'ProductExpressPaymentBuyBoxBag::testExpressPaymentReadsBuyBoxBeforeListExpressMethods' => function () {
            $this->assert_ProductExpressPaymentBuyBoxBag_testExpressPaymentReadsBuyBoxBeforeListExpressMethods();
        },
            'ProductExpressPaymentWidget::testProductExpressRegistersDefaultInjection' => function () {
            $this->assert_ProductExpressPaymentWidget_testProductExpressRegistersDefaultInjection();
        },
            'ProductExpressPaymentWidget::testProductExpressTemplateUsesShellAndPdpBridge' => function () {
            $this->assert_ProductExpressPaymentWidget_testProductExpressTemplateUsesShellAndPdpBridge();
        },
            'ProductExpressPaymentWidget::testProductInfoDeclaresExpressSlot' => function () {
            $this->assert_ProductExpressPaymentWidget_testProductInfoDeclaresExpressSlot();
        },
            'ProductExpressPaymentWidget::testModulesRegistryListsProductExpressPay' => function () {
            $this->assert_ProductExpressPaymentWidget_testModulesRegistryListsProductExpressPay();
        },
            'ProductExpressPaymentWidget::testMorePaymentDetailsIsTranslatedForDefaultWebsiteLocales' => function () {
            $this->assert_ProductExpressPaymentWidget_testMorePaymentDetailsIsTranslatedForDefaultWebsiteLocales();
        },
            'StripeConfigSecretEncrypted::testStripeSecretFieldsUseEncryptedValueType' => function () {
            $this->assert_StripeConfigSecretEncrypted_testStripeSecretFieldsUseEncryptedValueType();
        },
        ];
    }

private function assert_AccountOrderPaymentRecords_testPaymentProvidesAccountOrderPaymentRecordsHook(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/hooks/Weline_Order/frontend/account/order-detail/payment-records.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Payment::templates/frontend/account/order-payment-records.phtml', $source);
}

private function assert_AccountOrderPaymentRecords_testAccountTemplateShowsPaymentEntryBadge(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/account/order-payment-records.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('BackendOrderPaymentRecordsService', $source);
    self::assertStringContainsString('listForPayable', $source);
    self::assertStringContainsString('data-testid="account-order-payment-records"', $source);
    self::assertStringContainsString('data-testid="account-order-payment-entry"', $source);
    self::assertStringContainsString('payment_entry_label', $source);
    self::assertStringContainsString('payment_entry', $source);
}

private function assert_BackendOrderPaymentRecordsWidget_testWidgetRegistrationPinsOrderPaymentRecordsSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
    $tpl = 'Weline_Payment::templates/backend/widgets/backend-order-payment-records.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/widgets/backend-order-payment-records.phtml');
    self::assertStringContainsString('@widget.code {backend-order-payment-records}', $src);
    self::assertStringContainsString('@widget.slot {backend-order-payment-records}', $src);
    self::assertStringContainsString('"layout_type":"backend-order-view"', $src);
    self::assertStringContainsString('"slot":"backend-order-payment-records"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_BackendOrderPaymentRecordsWidget_testHookTemplateDelegatesToPaymentWidget(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/hooks/Weline_Order/backend/order/view/payment-records.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Payment::templates/backend/widgets/backend-order-payment-records.phtml', $source);
}

private function assert_BackendOrderPaymentRecordsWidget_testWidgetTemplateUsesAttemptService(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/widgets/backend-order-payment-records.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('BackendOrderPaymentRecordsService', $source);
    self::assertStringContainsString('@widget.default_injections', $source);
    self::assertStringContainsString('data-testid="backend-order-payment-records"', $source);
}

private function assert_BackendOrderPaymentRecordsWidget_testWidgetTemplateShowsMethodIconAndFourColorStatusChip(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $source = (string) file_get_contents($root . '/view/templates/backend/widgets/backend-order-payment-records.phtml');
    $chip = (string) file_get_contents($root . '/view/templates/backend/partials/outcome-chip.phtml');
    self::assertStringContainsString('BackendPaymentOutcomeChip', $source);
    self::assertStringContainsString('OutcomeChipMarkup::render', $source);
    self::assertStringNotContainsString("fetch('Weline_Payment::templates/backend/partials/outcome-chip.phtml'", $source);
    self::assertStringContainsString('payment-record-method-icon', $source);
    self::assertStringContainsString('payment-record-status-chip', $source);
    self::assertStringContainsString('method_icon_url', $source);
    self::assertStringContainsString('method_label', $source);
    self::assertStringContainsString('payment-record-entry', $source);
    self::assertStringContainsString('payment_entry_label', $source);
    self::assertStringContainsString('data-payment-entry=', $source);
    self::assertStringNotContainsString('data-w-background="success"><?= $esc((string)($payment[\'status\'] ?? \'\'))', $source);
    self::assertStringContainsString('OutcomeChipMarkup::render', $chip);
    self::assertStringContainsString('check-circle', (string) file_get_contents($root . '/Helper/OutcomeChipMarkup.php'));
}

private function assert_CheckoutExpressPaymentWidget_testExpressPaymentRegistersRequiredDefaultInjectionWithVisibilityToggle(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
    $tpl = 'Weline_Payment::templates/frontend/widgets/checkout-express-payment.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/checkout-express-payment.phtml');
    self::assertStringContainsString('@widget.code {checkout-express-payment}', $src);
    self::assertStringContainsString('@widget.slot {checkout-express-payment}', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"checkout-express-payment"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('"enabled":true', $src);
    self::assertStringContainsString('@param enabled {default=true', $src);
}

private function assert_CheckoutExpressPaymentWidget_testExpressPaymentTemplateIsShopifyLayoutCommerceStyledAndToggleAware(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/checkout-express-payment.phtml');
    self::assertStringContainsString('data-testid="checkout-express-payment"', $template);
    self::assertStringContainsString('data-widget-code="checkout-express-payment"', $template);
    self::assertStringContainsString("document.querySelector('[data-payment-express]')", $template);
    self::assertStringNotContainsString('[data-payment-express][data-testid="checkout-express-payment"]', $template);
    self::assertStringContainsString('data-testid="checkout-express-', $template);
    self::assertStringContainsString('PaymentExpressFacadeInterface', $template);
    self::assertStringContainsString('listExpressMethods', $template);
    self::assertStringContainsString('filter_var($this->getData(\'enabled\')', $template);
    self::assertStringContainsString('weline:checkout:express-pay', $template);
    self::assertStringContainsString('或使用下方结账', $template);
    self::assertStringContainsString('--express-paypal-bg: #ffc439', $template);
    self::assertStringContainsString('--express-text: var(--color-text-primary, #0f1111)', $template);
    self::assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(var(--express-btn-min), var(--express-btn-max)))', $template);
    self::assertStringContainsString('--express-btn-max: 200px', $template);
    self::assertStringContainsString('justify-content: center', $template);
    self::assertStringContainsString('paypal-express.svg', $template);
    self::assertStringContainsString('payment/method/paypal/express_logo', $template);
    self::assertStringContainsString('LegacyMediaUrl::sanitize', $template);
    self::assertStringContainsString('logo_file_html', $template);
    self::assertStringContainsString("getData('logo')", $template);
    self::assertStringContainsString('w-payment-express__logo-img', $template);
    self::assertStringNotContainsString('.w-payment-express__paypal {\n    display: inline-flex', $template);
    self::assertStringNotContainsString('<w:widget', $template);
}

private function assert_FooterPaymentMethodsLinkWidget_testFooterPaymentMethodsLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
    $tpl = 'Weline_Payment::templates/frontend/widgets/footer-payment-methods-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/footer-payment-methods-link.phtml');
    self::assertStringContainsString('@widget.code {footer-payment-methods-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
    self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterPaymentMethodsLinkWidget_testFooterPaymentMethodsLinkTemplatePointsToGuide(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/footer-payment-methods-link.phtml');
    self::assertStringContainsString('data-testid="footer-payment-methods-link"', $template);
    self::assertStringContainsString("@url{'guide/payment'}", $template);
    self::assertStringContainsString('footer-payment-account-links', $template);
    self::assertStringContainsString('支付方式', $template);
}

private function assert_MethodIndexTemplateHeader_testIndexTemplateDoesNotDuplicatePageHeading(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/Method/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringNotContainsString('w-backend-page__heading', $html);
    self::assertStringNotContainsString('w-backend-page__title', $html);
    self::assertDoesNotMatchRegularExpression('/w-card__title[^>]*>[\s\S]*支付方式管理/', $html);
    self::assertStringContainsString('data-testid="payment-method-management"', $html);
    self::assertStringContainsString('payment-method-sync-providers', $html);
    self::assertStringContainsString('payment-method-config-deeplink', $html);
    self::assertStringContainsString('配置深度链接', $html);
    self::assertStringContainsString('target="_blank"', $html);
    self::assertStringContainsString('rel="noopener noreferrer"', $html);
    self::assertStringContainsString('PaymentMethodConfigDeepLinkBuilder', $html);
    self::assertStringContainsString("api.resource('payment').registerProviders", $html);
    self::assertStringContainsString("api.resource('payment').reorderPaymentMethods", $html);
    self::assertStringContainsString('payment-method-sort-list', $html);
    self::assertStringContainsString('payment-method-sort-handle', $html);
    self::assertStringContainsString('draggable="true"', $html);
    self::assertStringContainsString('ordered_codes', $html);
    self::assertStringContainsString('w:config:embed', $html);
    self::assertStringContainsString('module="embedModule"', $html);
    self::assertStringContainsString('field="embedField"', $html);
    self::assertStringContainsString('field="environmentField"', $html);
    self::assertStringContainsString('payment/method/', $html);
    self::assertStringContainsString('/enabled', $html);
    self::assertStringContainsString('/environment', $html);
    $embedField = '/Users/weline/Project/Official/框架/app/code/Weline' . '/SystemConfig/view/templates/taglib/config-embed-field.phtml';
    self::assertFileExists($embedField);
    $embedHtml = (string) file_get_contents($embedField);
    self::assertStringContainsString('data-on-value="live"', $embedHtml);
    self::assertStringContainsString('data-off-value="sandbox"', $embedHtml);
    self::assertStringContainsString('config-embed-env-toggle', $embedHtml);
    self::assertStringContainsString('w-env-switch__track', $embedHtml);
    self::assertStringContainsString('沙盒', $embedHtml);
    self::assertStringContainsString('生产', $embedHtml);
    self::assertStringContainsString('layout="inline"', $html);
    self::assertStringNotContainsString('payment-method-enabled-toggle', $html);
    self::assertStringNotContainsString("api.resource('system_config').setScopedConfig", $html);
    self::assertStringNotContainsString('setMethodEnabled', $html);
    self::assertStringNotContainsString('can_toggle_enabled', $html);
    self::assertStringNotContainsString('$method->isActive()', $html);
}

private function assert_PayPalAuthorizeCallbackPath_testAuthorizeAdaptersDeclareCallbackPath(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml');
    self::assertStringContainsString('code="paypal.sandbox.authorize"', $src);
    self::assertStringContainsString('callback-path="payment/frontend/callback/paypal"', $src);
    self::assertStringContainsString('code="paypal.live.authorize"', $src);
    self::assertSame(2, substr_count($src, 'callback-path="payment/frontend/callback/paypal"'));
    self::assertStringNotContainsString('callback/return/paypal', $src);
}

private function assert_PayPalCheckoutWalletTemplate_testCheckoutTemplateExposesWalletDataAttributes(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/checkout/paypal.phtml');
    self::assertStringContainsString('data-paypal-sdk-src', $tpl);
    self::assertStringContainsString('data-paypal-google-pay', $tpl);
    self::assertStringContainsString('data-paypal-apple-pay', $tpl);
    self::assertStringContainsString('paypalWalletButtons', $tpl);
    self::assertStringContainsString('PayPalJsSdkUrlBuilder', $tpl);
}

private function assert_PayPalCheckoutWalletTemplate_testCheckoutModuleDoesNotEmbedPayPalSdk(): void
{
    $checkoutRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout';
    $hits = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($checkoutRoot, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!\in_array($ext, ['php', 'phtml', 'js', 'md'], true)) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            continue;
        }
        $content = (string) file_get_contents($path);
        if (str_contains($content, 'paypal.com/sdk')) {
            $hits[] = $path;
        }
    }
    self::assertSame([], $hits, 'Checkout must not embed paypal.com/sdk');
}

private function assert_PayPalConfigTemplate_testPayPalConfigTemplateExposesSandboxFields(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $template = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml');
    $provider = (string) file_get_contents($moduleRoot . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
    self::assertStringContainsString('PayPal 沙箱环境', $template);
    self::assertStringContainsString('payment/method/paypal/express_logo', $template);
    self::assertStringContainsString('快捷支付 Logo', $template);
    self::assertStringContainsString('payment/method/paypal/express_enabled', $template);
    self::assertStringContainsString('启用 PayPal 快捷支付', $template);
    self::assertStringContainsString('payment/method/paypal/google_pay_enabled', $template);
    self::assertStringContainsString('payment/method/paypal/apple_pay_enabled', $template);
    self::assertStringContainsString('启用 Google Pay（PayPal）', $template);
    self::assertStringContainsString('启用 Apple Pay（PayPal）', $template);
    self::assertMatchesRegularExpression('/key="payment\/method\/paypal\/google_pay_enabled"[\s\S]*?default="1"/', $template);
    self::assertMatchesRegularExpression('/key="payment\/method\/paypal\/apple_pay_enabled"[\s\S]*?default="1"/', $template);
    self::assertMatchesRegularExpression('/key="payment\/method\/paypal\/google_pay_enabled"[\s\S]*?scope="global,website,store"/', $template);
    self::assertStringContainsString('无需去 PayPal Developer 手动创建 App', $template);
    self::assertStringContainsString('payment/backend/connect/authorize?method_code=paypal&environment=sandbox', $template);
    self::assertStringContainsString('沙箱一键授权', $template);
    self::assertStringContainsString('action-label-connected="重新授权"', $template);
    self::assertStringContainsString('connected-key="payment/method/paypal/sandbox_oauth_connected_at"', $template);
    self::assertStringContainsString('connected-key="payment/method/paypal/live_oauth_connected_at"', $template);
    self::assertStringContainsString('发货物流回传（Add Tracking）', $template);
    self::assertStringContainsString('默认启用', $template);
    self::assertStringContainsString('无需在 PayPal App Features 勾选', $template);
    self::assertStringContainsString('/v2/checkout/orders/{order_id}/track', $template);
    self::assertStringContainsString('payment:paypal:sync-tracking', $template);
    self::assertStringNotContainsString('须在 PayPal Developer 的 REST App 打开 Shipping', $template);
    self::assertStringContainsString("return 'paypal';", $provider);
    self::assertStringContainsString("'checkout_template_code' => 'paypal'", $provider);
    self::assertStringContainsString('ProviderConnectInterface', $provider);
    foreach (['sandbox_client_secret', 'live_client_secret'] as $secretField) {
        self::assertTrue((bool) preg_match('/key="payment\/method\/paypal\/' . preg_quote($secretField, '/') . '"([\s\S]*?)\/>/', $template, $m), $secretField . ' field missing');
        self::assertStringContainsString('value-type="encrypted"', $m[1]);
        self::assertStringContainsString('type="secret"', $m[1]);
        self::assertStringNotContainsString('value-type="string"', $m[1]);
    }
}

private function assert_PaymentGuideTemplate_testBuiltInProviderGuideAndPolicyTemplatesExist(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $providers = ['fake_card' => ['guide' => $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/guide.phtml', 'policy' => $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/policy.phtml', 'agreement' => $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/agreement.phtml', 'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/FakeCardCustomerGuide.php'], 'paypal' => ['guide' => $moduleRoot . '/view/templates/frontend/guide/payment/paypal/guide.phtml', 'policy' => $moduleRoot . '/view/templates/frontend/guide/payment/paypal/policy.phtml', 'agreement' => $moduleRoot . '/view/templates/frontend/guide/payment/paypal/agreement.phtml', 'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/PayPalCustomerGuide.php'], 'stripe' => ['guide' => $moduleRoot . '/view/templates/frontend/guide/payment/stripe/guide.phtml', 'policy' => $moduleRoot . '/view/templates/frontend/guide/payment/stripe/policy.phtml', 'agreement' => $moduleRoot . '/view/templates/frontend/guide/payment/stripe/agreement.phtml', 'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/StripeCustomerGuide.php']];
    self::assertFileExists($moduleRoot . '/view/templates/frontend/guide/payment/index.phtml');
    foreach ($providers as $methodCode => $paths) {
        self::assertFileExists($paths['guide'], 'Missing guide template for ' . $methodCode);
        self::assertFileExists($paths['policy'], 'Missing policy template for ' . $methodCode);
        self::assertFileExists($paths['agreement'], 'Missing agreement template for ' . $methodCode);
        self::assertFileExists($paths['guide_class'], 'Missing guide class for ' . $methodCode);
        self::assertStringContainsString("return '" . $methodCode . "';", (string) file_get_contents($paths['guide_class']));
    }
}

private function assert_PaymentGuideTemplate_testHubTemplateUsesGuideEntryLinks(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $hubTemplate = (string) file_get_contents($moduleRoot . '/view/templates/frontend/guide/payment/index.phtml');
    self::assertStringContainsString('payment_guide_entries', $hubTemplate);
    self::assertStringContainsString('payment-guide-listing', $hubTemplate);
    self::assertStringContainsString('shell-open.phtml', $hubTemplate);
    self::assertStringContainsString('<lang>查看支付指南</lang>', $hubTemplate);
    self::assertStringContainsString('<lang>查看支付政策</lang>', $hubTemplate);
    self::assertStringContainsString('<lang>查看用户协议</lang>', $hubTemplate);
}

private function assert_PaymentGuideTemplate_testSidebarUsesProviderArticleHierarchy(): void
{
    $sidebar = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/guide/payment/partials/sidebar.phtml');
    self::assertStringContainsString('payment-guide-layout__nav-group', $sidebar);
    self::assertStringContainsString('payment-guide-layout__nav-group-label', $sidebar);
    self::assertStringContainsString('payment-guide-layout__nav-article', $sidebar);
    self::assertStringContainsString('$guideTitle', $sidebar);
    self::assertStringContainsString('$policyTitle', $sidebar);
    self::assertStringContainsString('$agreementTitle', $sidebar);
    self::assertStringNotContainsString('payment-guide-layout__nav-sub', $sidebar);
}

private function assert_PaymentGuideTemplate_testArticleTemplatesUseSharedBreadcrumbPartial(): void
{
    foreach (['paypal/guide.phtml', 'paypal/policy.phtml', 'paypal/agreement.phtml', 'stripe/guide.phtml', 'stripe/policy.phtml', 'stripe/agreement.phtml', 'fake_card/guide.phtml', 'fake_card/policy.phtml', 'fake_card/agreement.phtml'] as $relativePath) {
        $content = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/guide/payment/' . $relativePath);
        self::assertStringContainsString('breadcrumb.phtml', $content, $relativePath);
    }
}

private function assert_PaymentGuideTemplate_testGuideTemplatesUseLangTagsNotPhpTranslate(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $templates = [$moduleRoot . '/view/templates/frontend/guide/payment/index.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/paypal/guide.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/paypal/policy.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/paypal/agreement.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/stripe/guide.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/stripe/policy.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/stripe/agreement.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/guide.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/policy.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/fake_card/agreement.phtml', $moduleRoot . '/view/templates/frontend/guide/payment/partials/sidebar.phtml'];
    foreach ($templates as $template) {
        $content = (string) file_get_contents($template);
        self::assertStringContainsString('<lang>', $content, $template);
        self::assertDoesNotMatchRegularExpression('/<\?=\s*__\(/', $content, $template);
    }
}

private function assert_PaymentGuideTemplate_testGuideTemplatesUseUrlTagForNavigationLinks(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $sidebar = (string) file_get_contents($moduleRoot . '/view/templates/frontend/guide/payment/partials/sidebar.phtml');
    self::assertStringContainsString("@url{'guide/payment'}", $sidebar);
    self::assertStringContainsString('@url{$guideRoute}', $sidebar);
    self::assertStringContainsString('@url{$policyRoute}', $sidebar);
    self::assertStringContainsString('@url{$agreementRoute}', $sidebar);
    self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $sidebar);
    $index = (string) file_get_contents($moduleRoot . '/view/templates/frontend/guide/payment/index.phtml');
    self::assertStringContainsString('@url{$guideRoute}', $index);
    self::assertStringContainsString('@url{$policyRoute}', $index);
    self::assertStringContainsString('@url{$agreementRoute}', $index);
    self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $index);
    foreach (['paypal/guide.phtml', 'paypal/policy.phtml', 'paypal/agreement.phtml', 'stripe/guide.phtml', 'stripe/policy.phtml', 'stripe/agreement.phtml', 'fake_card/guide.phtml', 'fake_card/policy.phtml', 'fake_card/agreement.phtml'] as $relativePath) {
        $content = (string) file_get_contents($moduleRoot . '/view/templates/frontend/guide/payment/' . $relativePath);
        self::assertStringContainsString('breadcrumb.phtml', $content, $relativePath);
        self::assertStringNotContainsString('payment_guide_hub_url', $content, $relativePath);
    }
    $breadcrumb = (string) file_get_contents($moduleRoot . '/view/templates/frontend/guide/payment/partials/breadcrumb.phtml');
    self::assertStringContainsString("@url{'guide/payment'}", $breadcrumb);
    self::assertStringContainsString('@url{$guideRoute}', $breadcrumb);
    self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $breadcrumb);
}

private function assert_PaymentGuideTemplate_testGuidePhraseEnUsTranslationsAreNotChinesePlaceholders(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $enPath = $moduleRoot . '/i18n/en_US.csv';
    self::assertFileExists($enPath);
    $translations = [];
    $fp = fopen($enPath, 'r');
    self::assertNotFalse($fp);
    while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        if (count($row) >= 2) {
            $translations[$row[0]] = $row[1];
        }
    }
    fclose($fp);
    $files = array_unique(array_merge(glob($moduleRoot . '/view/templates/frontend/guide/payment/**/*.phtml') ?: [], glob($moduleRoot . '/view/templates/frontend/guide/payment/*.phtml') ?: [], glob($moduleRoot . '/view/templates/frontend/guide/payment/partials/*.phtml') ?: [], glob($moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/*.php') ?: []));
    $phrases = [];
    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        if (preg_match_all('/<lang(?:\s[^>]*)?>(.*?)<\/lang>/su', $content, $m)) {
            foreach ($m[1] as $phrase) {
                $phrases[$phrase] = true;
            }
        }
        if (preg_match_all('/@lang[\({]["\']?([^"\')\}]+)["\']?[\)}]/u', $content, $m2)) {
            foreach ($m2[1] as $phrase) {
                $phrases[$phrase] = true;
            }
        }
        if (preg_match_all('/__\([\'"](.+?)[\'"]\)/u', $content, $m3)) {
            foreach ($m3[1] as $phrase) {
                $phrases[$phrase] = true;
            }
        }
    }
    $bad = [];
    foreach (array_keys($phrases) as $phrase) {
        // Brand / Latin-only sources may keep identical en_US values.
        if (!preg_match('/\p{Han}/u', $phrase)) {
            continue;
        }
        $trans = $translations[$phrase] ?? null;
        if ($trans === null || preg_match('/\p{Han}/u', $trans)) {
            $bad[] = $phrase;
        }
    }
    self::assertSame([], $bad, 'Guide phrases still have Chinese placeholders in en_US.csv');
}

private function assert_PaymentGuideTemplate_testPaymentGuideStyleAssetsAndPartialsExist(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    self::assertFileExists($themeRoot . '/view/statics/css/widgets/payment-guide.css');
    self::assertFileExists($moduleRoot . '/view/theme/frontend/layouts/payment_guide/default.phtml');
    self::assertFileExists($moduleRoot . '/view/templates/frontend/guide/payment/partials/sidebar.phtml');
    self::assertFileExists($moduleRoot . '/view/templates/frontend/guide/payment/partials/shell-open.phtml');
    $layout = (string) file_get_contents($moduleRoot . '/view/theme/frontend/layouts/payment_guide/default.phtml');
    self::assertStringContainsString('payment-guide.css', $layout);
    self::assertStringContainsString('{{meta.content}}', $layout);
    self::assertStringContainsString('Weline_Theme::frontend::layouts::payment_guide::head-after', $layout);
}

private function assert_PaymentLifecycleScript_testPaymentLifecycleOwnsUnifiedOutcomeEvents(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $js = (string) \file_get_contents($root . '/view/statics/js/payment-lifecycle.js');
    $modules = (string) \file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    $success = (string) \file_get_contents($root . '/view/templates/frontend/checkout/payment-success.phtml');
    $handoff = (string) \file_get_contents($root . '/view/templates/frontend/checkout/handoff.phtml');
    self::assertStringContainsString('weline:payment:outcome', $js);
    self::assertStringContainsString('weline:payment:paid', $js);
    self::assertStringContainsString('weline:payment:pending', $js);
    self::assertStringContainsString('emitOutcome', $js);
    self::assertStringContainsString('bootFromDom', $js);
    self::assertStringContainsString('console.info', $js);
    self::assertStringContainsString('[WelinePayment]', $js);
    self::assertStringContainsString('isDevMode', $js);
    self::assertStringContainsString('validatePayload', $js);
    self::assertStringContainsString('weline:payment:anomaly', $js);
    self::assertStringContainsString('跳过派发', $js);
    self::assertStringContainsString('payment-lifecycle.js', $modules);
    self::assertStringContainsString('load: "eager"', $modules);
    self::assertStringContainsString('data-payment-lifecycle="paid"', $success);
    self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $success);
    self::assertStringContainsString('data-payment-outcome="paid"', $success);
    self::assertStringContainsString('data-payment-lifecycle="pending"', $handoff);
    self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $handoff);
    self::assertStringContainsString('data-payment-outcome="pending"', $handoff);
    $returnTpl = (string) \file_get_contents($root . '/view/templates/frontend/checkout/return.phtml');
    self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $returnTpl);
    self::assertStringContainsString('data-payment-lifecycle', $returnTpl);
    self::assertStringContainsString('payment-return-page', $returnTpl);
}

private function assert_PaymentMethodIconAdmin_testMethodIndexRendersIconColumn(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/Method/index.phtml';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("__('图标')", $content);
    self::assertStringContainsString('getEffectiveDisplayMetadata', $content);
    self::assertStringContainsString('data-testid="payment-method-icon"', $content);
}

private function assert_PaymentMethodIconAdmin_testConfigTemplatesDeclareIconImageField(): void
{
    foreach (['fake_card.phtml', 'paypal.phtml'] as $file) {
        $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_SystemConfig/Config/backend/' . $file;
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('/icon"', $content);
        self::assertStringContainsString('type="image"', $content);
        self::assertStringContainsString('支付图标', $content);
    }
}

private function assert_ProductExpressPaymentBuyBoxBag_testExpressPaymentReadsBuyBoxBeforeListExpressMethods(): void
{
    $widget = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/product-express-payment.phtml');
    self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('express_methods')", $widget);
    $bagPos = strpos($widget, "StorefrontPdpBuyBoxBag::hasKey('express_methods')");
    $coldPos = strpos($widget, '$expressMethods = $facade->listExpressMethods');
    self::assertNotFalse($bagPos);
    self::assertNotFalse($coldPos);
    self::assertLessThan($coldPos, $bagPos);
}

private function assert_ProductExpressPaymentWidget_testProductExpressRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
    $tpl = 'Weline_Payment::templates/frontend/widgets/product-express-payment.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/product-express-payment.phtml');
    self::assertStringContainsString('@widget.code {product-express-payment}', $src);
    self::assertStringContainsString('@widget.slot {product-express-payment}', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"slot":"product-express-payment"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('"enabled":true', $src);
    self::assertStringContainsString('@param enabled', $src);
    self::assertStringContainsString('@param logo', $src);
}

private function assert_ProductExpressPaymentWidget_testProductExpressTemplateUsesShellAndPdpBridge(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/product-express-payment.phtml');
    self::assertStringContainsString('data-testid="product-express-payment"', $tpl);
    self::assertStringContainsString('LegacyMediaUrl::sanitize', $tpl);
    self::assertStringContainsString('logo_file_html', $tpl);
    self::assertStringContainsString('PaymentExpressFacadeInterface', $tpl);
    self::assertStringContainsString('listExpressMethods', $tpl);
    self::assertStringContainsString('data-weline-load="productExpressPay"', $tpl);
    self::assertStringContainsString('data-product-express-pay', $tpl);
    self::assertStringContainsString('weline-pixel::express_pay', $tpl);
    self::assertStringContainsString('data-pixel-event="express_pay"', $tpl);
    self::assertStringContainsString('data-method-label=', $tpl);
    self::assertStringContainsString('w-payment-express--collapsible', $tpl);
    self::assertStringContainsString('w-payment-express__disclosure', $tpl);
    self::assertStringContainsString('w-payment-express__methods--always', $tpl);
    self::assertStringContainsString('w-payment-express__summary--more', $tpl);
    self::assertStringContainsString('w-payment-express__more-label', $tpl);
    self::assertStringContainsString('flex-direction: row', $tpl);
    self::assertStringNotContainsString('w-payment-express__summary--chevron-only', $tpl);
    self::assertStringNotContainsString('flex-direction: column', $tpl);
    self::assertStringNotContainsString('clip: rect(0, 0, 0, 0)', $tpl);
    self::assertStringContainsString('<details', $tpl);
    self::assertStringContainsString('<summary', $tpl);
    self::assertStringContainsString('w-payment-express__hint', $tpl);
    // PayPal / express buttons must stay outside <details> (always visible).
    $methodsPos = strpos($tpl, 'w-payment-express__methods--always');
    $detailsPos = strpos($tpl, '<details');
    self::assertNotFalse($methodsPos);
    self::assertNotFalse($detailsPos);
    self::assertLessThan($detailsPos, $methodsPos);
}

private function assert_ProductExpressPaymentWidget_testProductInfoDeclaresExpressSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/extends/module/Weline_Widget/Weline_Product/widget.php';
    $tpl = 'Weline_Product::templates/frontend/widgets/product-info.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('"product-express-payment"', $src);
}

private function assert_ProductExpressPaymentWidget_testModulesRegistryListsProductExpressPay(): void
{
    $mod = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('productExpressPay', $mod);
    self::assertStringContainsString('product-express-pay.js', $mod);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/statics/js/product-express-pay.js');
}

private function assert_ProductExpressPaymentWidget_testMorePaymentDetailsIsTranslatedForDefaultWebsiteLocales(): void
{
    $expected = ['zh_Hans_CN' => '更多支付说明', 'en_US' => 'More payment details', 'ar_SA' => 'المزيد من تفاصيل الدفع', 'bn_BD' => 'আরও পেমেন্ট তথ্য', 'es_ES' => 'Más información de pago', 'fr_FR' => "Plus d'infos sur le paiement", 'hi_IN' => 'और भुगतान जानकारी', 'id_ID' => 'Info pembayaran lainnya', 'pt_BR' => 'Mais informações de pagamento', 'ur_PK' => 'مزید ادائیگی کی تفصیل'];
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/i18n';
    foreach ($expected as $locale => $translate) {
        $map = $this->loadCsvMap_ProductExpressPaymentWidget($dir . '/' . $locale . '.csv');
        self::assertSame($translate, $map['更多支付说明'] ?? null, $locale);
        if ($locale !== 'zh_Hans_CN') {
            self::assertNotSame('更多支付说明', $map['更多支付说明'] ?? '更多支付说明', $locale);
        }
    }
}

/** @return array<string, string> */
private function loadCsvMap_ProductExpressPaymentWidget(string $path): array
{
    self::assertFileExists($path);
    $fh = fopen($path, 'rb');
    self::assertNotFalse($fh);
    $map = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[0], $row[1])) {
            continue;
        }
        $map[(string) $row[0]] = (string) $row[1];
    }
    fclose($fh);
    return $map;
}

private function assert_StripeConfigSecretEncrypted_testStripeSecretFieldsUseEncryptedValueType(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_SystemConfig/Config/backend/stripe.phtml');
    foreach (['sandbox_secret_key', 'sandbox_webhook_secret', 'live_secret_key', 'live_webhook_secret'] as $field) {
        self::assertTrue((bool) preg_match('/key="payment\/method\/stripe\/' . preg_quote($field, '/') . '"([\s\S]*?)\/>/', $template, $m), $field . ' field missing');
        self::assertStringContainsString('value-type="encrypted"', $m[1], $field);
        self::assertStringContainsString('type="secret"', $m[1], $field);
        self::assertStringNotContainsString('value-type="string"', $m[1], $field);
    }
}
}
