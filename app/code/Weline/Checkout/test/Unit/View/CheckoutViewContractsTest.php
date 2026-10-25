<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 11 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutViewContractsPass().
 */
final class CheckoutViewContractsTest extends TestCase
{
    public function testAllCheckoutViewContractsPass(): void
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
            'CheckoutDeliveryPanelPaperInk::testDeliveryPanelRebindsPaperInkTokens' => function () {
            $this->assert_CheckoutDeliveryPanelPaperInk_testDeliveryPanelRebindsPaperInkTokens();
        },
            'CheckoutDeliveryQuickAddHook::testWidgetDelegatesQuickAddToShippingHook' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testWidgetDelegatesQuickAddToShippingHook();
        },
            'CheckoutDeliveryQuickAddHook::testAddressListScrollsWhenExceedingFiveItems' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testAddressListScrollsWhenExceedingFiveItems();
        },
            'CheckoutDeliveryQuickAddHook::testWidgetRegistryDeclaresDefaultInjectionWithoutThemeInline' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testWidgetRegistryDeclaresDefaultInjectionWithoutThemeInline();
        },
            'CheckoutDeliveryQuickAddHook::testHookRegistryPublishesQuickAddExtensionPoint' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testHookRegistryPublishesQuickAddExtensionPoint();
        },
            'CheckoutDeliveryQuickAddHook::testCaptchaGuardIntentMatchesForm' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testCaptchaGuardIntentMatchesForm();
        },
            'CheckoutDeliveryQuickAddHook::testEditorPreviewKeepsQuickAddHookAndAutoDetectParity' => function () {
            $this->assert_CheckoutDeliveryQuickAddHook_testEditorPreviewKeepsQuickAddHookAndAutoDetectParity();
        },
            'CheckoutFormAddressRegionIds::testFormAddressPassesRegionIds' => function () {
            $this->assert_CheckoutFormAddressRegionIds_testFormAddressPassesRegionIds();
        },
            'CheckoutHelpPaySlot::testCheckoutDeclaresHelpPaySlotWithoutHardcodedWidget' => function () {
            $this->assert_CheckoutHelpPaySlot_testCheckoutDeclaresHelpPaySlotWithoutHardcodedWidget();
        },
            'CheckoutPaymentMethodSelectionPersist::testApplyServerHtmlKeepsSelectedPaymentMethodAcrossInjection' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testApplyServerHtmlKeepsSelectedPaymentMethodAcrossInjection();
        },
            'CheckoutPaymentMethodSelectionPersist::testRestoreSkipsUnavailableMethodAndKeepsServerDefault' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testRestoreSkipsUnavailableMethodAndKeepsServerDefault();
        },
            'CheckoutPaymentMethodSelectionPersist::testRestoreDoesNotDispatchChangeToAvoidReconcileLoop' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testRestoreDoesNotDispatchChangeToAvoidReconcileLoop();
        },
            'CheckoutPaymentMethodSelectionPersist::testSoftRenderSkipsItemsAndAddressPanelsByDefault' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testSoftRenderSkipsItemsAndAddressPanelsByDefault();
        },
            'CheckoutPaymentMethodSelectionPersist::testPaymentMethodChangeDoesNotReloadCheckout' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testPaymentMethodChangeDoesNotReloadCheckout();
        },
            'CheckoutPaymentMethodSelectionPersist::testSelectedIncentiveFallsBackToDomSavingsWhenSsrSkipsGetData' => function () {
            $this->assert_CheckoutPaymentMethodSelectionPersist_testSelectedIncentiveFallsBackToDomSavingsWhenSsrSkipsGetData();
        },
            'CheckoutShippingAddressSlot::testIndexAndExpressReviewDeclareExclusiveShippingSlotWithoutSoftFallback' => function () {
            $this->assert_CheckoutShippingAddressSlot_testIndexAndExpressReviewDeclareExclusiveShippingSlotWithoutSoftFallback();
        },
            'CheckoutStorefrontSlotsCatalog::testCheckoutDeclaresStorefrontSlotCatalogForInjectionDiscovery' => function () {
            $this->assert_CheckoutStorefrontSlotsCatalog_testCheckoutDeclaresStorefrontSlotCatalogForInjectionDiscovery();
        },
            'CheckoutTaxIdentitySlot::testIndexAndExpressReviewDeclareTaxIdentitySlot' => function () {
            $this->assert_CheckoutTaxIdentitySlot_testIndexAndExpressReviewDeclareTaxIdentitySlot();
        },
            'CheckoutTaxIdentitySlot::testCatalogListsTaxIdentitySlot' => function () {
            $this->assert_CheckoutTaxIdentitySlot_testCatalogListsTaxIdentitySlot();
        },
            'CheckoutTaxIdentitySlot::testCheckoutPagesForbidSoftFallbackFetchOfShippingAndTaxWidgets' => function () {
            $this->assert_CheckoutTaxIdentitySlot_testCheckoutPagesForbidSoftFallbackFetchOfShippingAndTaxWidgets();
        },
            'ExpressReviewPage::testExpressReviewTemplateHasBreakoutAndCopy' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewTemplateHasBreakoutAndCopy();
        },
            'ExpressReviewPage::testExpressReviewControllerExists' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewControllerExists();
        },
            'ExpressReviewPage::testQueryDescriptorListsExpressOps' => function () {
            $this->assert_ExpressReviewPage_testQueryDescriptorListsExpressOps();
        },
            'ExpressReviewPage::testSinkDoesNotInventFakePhone' => function () {
            $this->assert_ExpressReviewPage_testSinkDoesNotInventFakePhone();
        },
            'ExpressReviewPage::testExpressReviewJsHandlesTotalsAndCancel' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewJsHandlesTotalsAndCancel();
        },
            'ExpressReviewPage::testExpressReviewDtoExposesPixelItems' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewDtoExposesPixelItems();
        },
            'ExpressReviewPage::testExpressFlowMapsShippingLabelNotRawCodeOnly' => function () {
            $this->assert_ExpressReviewPage_testExpressFlowMapsShippingLabelNotRawCodeOnly();
        },
            'ExpressReviewPage::testAmendServiceQuotesTaxAndShipping' => function () {
            $this->assert_ExpressReviewPage_testAmendServiceQuotesTaxAndShipping();
        },
            'ExpressReviewPage::testFlowServiceAcceptsSelectedAddressOverride' => function () {
            $this->assert_ExpressReviewPage_testFlowServiceAcceptsSelectedAddressOverride();
        },
            'ExpressReviewPage::testExpressReviewJsResolvesGroupUuid' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewJsResolvesGroupUuid();
        },
            'ExpressReviewPage::testPaymentSessionHasUpdateBrowserLanding' => function () {
            $this->assert_ExpressReviewPage_testPaymentSessionHasUpdateBrowserLanding();
        },
            'ExpressReviewPage::testExpressReviewControllerRedirectsAlreadyPaid' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewControllerRedirectsAlreadyPaid();
        },
            'ExpressReviewPage::testSuccessDoesNotCartDumpPaidOrders' => function () {
            $this->assert_ExpressReviewPage_testSuccessDoesNotCartDumpPaidOrders();
        },
            'ExpressReviewPage::testExpressReviewTemplateShowsAlreadyPaidCopy' => function () {
            $this->assert_ExpressReviewPage_testExpressReviewTemplateShowsAlreadyPaidCopy();
        },
            'ExpressReviewPage::testSuccessTemplateHasPaidAckCopy' => function () {
            $this->assert_ExpressReviewPage_testSuccessTemplateHasPaidAckCopy();
        },
            'ProductBuyNowWidget::testWidgetRegistrationPinsPurchaseActionsSlot' => function () {
            $this->assert_ProductBuyNowWidget_testWidgetRegistrationPinsPurchaseActionsSlot();
        },
            'ProductBuyNowWidget::testWidgetTemplateRedirectsToCheckoutAfterCartAdd' => function () {
            $this->assert_ProductBuyNowWidget_testWidgetTemplateRedirectsToCheckoutAfterCartAdd();
        },
            'ProductCardBuyNowWidget::testWidgetRegistrationExposesCardBuyNowTemplate' => function () {
            $this->assert_ProductCardBuyNowWidget_testWidgetRegistrationExposesCardBuyNowTemplate();
        },
            'ProductCardBuyNowWidget::testCardBuyNowWidgetUsesBuyNowActionAndCommerceClasses' => function () {
            $this->assert_ProductCardBuyNowWidget_testCardBuyNowWidgetUsesBuyNowActionAndCommerceClasses();
        },
        ];
    }

private function assert_CheckoutDeliveryPanelPaperInk_testDeliveryPanelRebindsPaperInkTokens(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/statics/css/widgets/widget-header-checkout-delivery-context-default.css');
    self::assertStringContainsString('.wc-theme_widget_checkout_delivery_context .delivery-panel', $css);
    self::assertStringContainsString('WO-UI-CONTRAST-INVERSE', $css);
    self::assertStringContainsString('--weline-chrome-body-text: #0f1111', $css);
    self::assertStringContainsString('--weline-chrome-body-text-secondary: #565959', $css);
    self::assertStringContainsString('--amz-drawer-text: var(--weline-chrome-body-text)', $css);
    self::assertStringContainsString('--_paper-text: var(--amz-drawer-text)', $css);
    self::assertStringContainsString('--color-text: var(--_paper-text)', $css);
    self::assertStringContainsString('--weline-theme-body-text: var(--_paper-text)', $css);
    self::assertStringContainsString('--w-surface-fg: var(--_paper-text)', $css);
    self::assertStringContainsString('color: var(--_paper-text)', $css);
    self::assertStringNotContainsString('--amz-drawer-text: var(--color-text-primary)', $css);
    self::assertStringNotContainsString('--amz-drawer-muted: var(--color-text-gray)', $css);
}

private function assert_CheckoutDeliveryQuickAddHook_testWidgetDelegatesQuickAddToShippingHook(): void
{
    $widget = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
    $this->assertFileExists($widget);
    $content = (string) file_get_contents($widget);
    $this->assertStringContainsString('<w:hook>Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add</w:hook>', $content);
    $this->assertStringNotContainsString('<form data-quick-add-form', $content);
    $this->assertStringNotContainsString('<w:form', $content);
    $this->assertStringNotContainsString('<input name="province"', $content);
    $this->assertStringNotContainsString('<input name="city"', $content);
    $this->assertStringContainsString('WelineThemeAddress.applyValues', $content);
    $this->assertStringContainsString('@widget.default_injections', $content);
    $this->assertStringContainsString('"layout_type":"homepage"', $content);
    $this->assertStringContainsString('"slot":"delivery"', $content);
    $this->assertStringContainsString("WidgetI18n::label(trim((string)(\$this->getData('title') ?? '')), '配送至')", $content);
}

private function assert_CheckoutDeliveryQuickAddHook_testAddressListScrollsWhenExceedingFiveItems(): void
{
    $widget = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
    $content = (string) file_get_contents($widget);
    $this->assertStringContainsString('.address-list {', $content);
    $this->assertStringContainsString('max-height: calc((var(--address-item-h) * 5)', $content);
    $this->assertStringContainsString('overflow-y: auto', $content);
    $this->assertStringContainsString('overscroll-behavior: contain', $content);
}

private function assert_CheckoutDeliveryQuickAddHook_testWidgetRegistryDeclaresDefaultInjectionWithoutThemeInline(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml');
    self::assertStringContainsString('@widget.code {checkout-delivery-context}', $src);
    self::assertStringContainsString('@widget.slot {delivery}', $src);
    self::assertStringContainsString('"layout_type":"homepage"', $src);
    self::assertStringContainsString('"slot":"delivery"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_CheckoutDeliveryQuickAddHook_testHookRegistryPublishesQuickAddExtensionPoint(): void
{
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/hook.php';
    $this->assertFileExists($hook);
    $content = (string) file_get_contents($hook);
    $this->assertStringContainsString("Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add", $content);
}

private function assert_CheckoutDeliveryQuickAddHook_testCaptchaGuardIntentMatchesForm(): void
{
    $guard = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/DeliveryAddressCaptchaGuard.php';
    $this->assertFileExists($guard);
    $content = (string) file_get_contents($guard);
    $this->assertStringContainsString("INTENT = 'checkout.save_delivery_address'", $content);
    $this->assertStringContainsString("FORM_ID = 'checkout-delivery-quick-add-form'", $content);
}

private function assert_CheckoutDeliveryQuickAddHook_testEditorPreviewKeepsQuickAddHookAndAutoDetectParity(): void
{
    $widget = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
    $content = (string) file_get_contents($widget);
    $this->assertStringContainsString('ensureLazyCaptcha', $content);
    $this->assertStringContainsString('getDeliveryCaptchaChallenge', $content);
    $this->assertStringContainsString('preview_storefront_delivery_parity', $content);
    $this->assertStringNotContainsString('$isEditorMode', $content);
    $this->assertStringNotContainsString("getParam('editor_mode'", $content);
    $this->assertStringNotContainsString('Theme editor iframe: skip session/DB', $content);
    $this->assertStringNotContainsString('if (!$isEditorMode)', $content);
    $this->assertStringContainsString('<w:hook>Weline_Checkout::frontend::widgets::checkout-delivery-context::quick-add</w:hook>', $content);
    $this->assertStringContainsString('data-action="auto-detect"', $content);
    $this->assertStringContainsString('if ($autoDetect)', $content);
    $this->assertDoesNotMatchRegularExpression('/if\s*\(\s*\$autoDetect\s*&&\s*!\$isEditorMode\s*\)/', $content, 'auto-detect must not be gated on editor_mode');
}

private function assert_CheckoutFormAddressRegionIds_testFormAddressPassesRegionIds(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('function formAddress()', $src);
    self::assertStringContainsString('resolveQuoteAddress', $src);
    self::assertStringContainsString('province_region_id', $src);
    self::assertStringContainsString('city_region_id', $src);
    self::assertStringContainsString('district_region_id', $src);
    self::assertStringContainsString('billing_province_region_id', $src);
    self::assertStringContainsString('billing_city_region_id', $src);
    self::assertStringContainsString('billing_district_region_id', $src);
}

private function assert_CheckoutHelpPaySlot_testCheckoutDeclaresHelpPaySlotWithoutHardcodedWidget(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml');
    self::assertStringContainsString('"checkout-summary-help-pay"', $catalog);
    $tplPage = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('id="checkout-summary-help-pay"', $tplPage);
    self::assertStringNotContainsString('Weline_HelpPay::', $tplPage);
    // 帮我付在提交订单下方，不在 extras 页签区
    $extrasPos = strpos($tplPage, 'weline-checkout__extras');
    $submitPos = strpos($tplPage, 'data-submit');
    $helpPos = strpos($tplPage, 'id="checkout-summary-help-pay"');
    self::assertNotFalse($extrasPos);
    self::assertNotFalse($submitPos);
    self::assertNotFalse($helpPos);
    self::assertGreaterThan($submitPos, $helpPos, 'help-pay slot must follow submit button');
    $creditInExtras = strpos($tplPage, 'checkout-summary-credit');
    self::assertNotFalse($creditInExtras);
    self::assertLessThan($helpPos, $creditInExtras);
}

private function templateSource_CheckoutPaymentMethodSelectionPersist(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml';
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testApplyServerHtmlKeepsSelectedPaymentMethodAcrossInjection(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    // 注入前先记住用户当前选中的支付方式。
    self::assertStringContainsString('const keepPaymentMethod = node === paymentBox && form', $src);
    self::assertStringContainsString("selectedValue('payment_method')", $src);
    // 注入后还原支付/配送已选。
    self::assertStringContainsString("restoreNamedMethodSelection(node, 'payment_method', keepPaymentMethod);", $src);
    self::assertStringContainsString("restoreNamedMethodSelection(node, 'shipping_method', keepShippingMethod);", $src);
    // 仍以服务端 HTML 为唯一来源，禁止前端拼选项 DOM。
    self::assertStringContainsString('node.innerHTML = html;', $src);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testRestoreSkipsUnavailableMethodAndKeepsServerDefault(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    self::assertStringContainsString('function restoreNamedMethodSelection(node, name, code)', $src);
    // 已下线/禁用（disabled）的方式不还原，退回服务端默认项。
    self::assertStringContainsString("input.value === code && !input.disabled", $src);
    self::assertStringContainsString("node.querySelectorAll('input[name=\"' + name + '\"]')", $src);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testRestoreDoesNotDispatchChangeToAvoidReconcileLoop(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    $helper = $this->extractFunctionBody_CheckoutPaymentMethodSelectionPersist($src, 'function restoreNamedMethodSelection(node, name, code)');
    self::assertNotSame('', $helper);
    // 只改 checked 属性；派发 change 会再次触发优惠核对软刷新，形成互相触发的循环。
    self::assertStringContainsString('input.checked = input === target;', $helper);
    self::assertStringNotContainsString('dispatchEvent', $helper);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testSoftRenderSkipsItemsAndAddressPanelsByDefault(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    self::assertStringContainsString('const soft = opts.soft === true', $src);
    self::assertStringContainsString('items: false, shipping: true, payment: true, address: false', $src);
    self::assertStringContainsString('if (panels.items)', $src);
    self::assertStringContainsString('if (panels.shipping)', $src);
    self::assertStringContainsString('if (panels.payment)', $src);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testPaymentMethodChangeDoesNotReloadCheckout(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    $reconcile = $this->extractFunctionBody_CheckoutPaymentMethodSelectionPersist($src, 'function schedulePaymentIncentiveReconcile()');
    self::assertNotSame('', $reconcile);
    self::assertStringContainsString('renderTotals()', $reconcile);
    self::assertStringNotContainsString('loadCheckout', $reconcile);
    self::assertStringNotContainsString('getData', $reconcile);
    // paymentBox change 监听也不得再挂 getData 核对。
    self::assertMatchesRegularExpression('/paymentBox\.addEventListener\(\s*[\'"]change[\'"][\s\S]{0,400}?renderTotals\(\)/', $src);
    self::assertDoesNotMatchRegularExpression('/paymentBox\.addEventListener\(\s*[\'"]change[\'"][\s\S]{0,400}?schedulePaymentIncentiveReconcile/', $src);
}

private function assert_CheckoutPaymentMethodSelectionPersist_testSelectedIncentiveFallsBackToDomSavingsWhenSsrSkipsGetData(): void
{
    $src = $this->templateSource_CheckoutPaymentMethodSelectionPersist();
    $body = $this->extractFunctionBody_CheckoutPaymentMethodSelectionPersist($src, 'function selectedIncentiveSavingsMajor()');
    self::assertNotSame('', $body);
    self::assertStringContainsString('incentive_savings_minor', $body);
    self::assertStringContainsString('data-incentive-savings-minor', $body);
    self::assertStringContainsString('input[name="payment_method"]:checked', $body);
}

private function extractFunctionBody_CheckoutPaymentMethodSelectionPersist(string $src, string $signature): string
{
    $start = strpos($src, $signature);
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\n    }", $start);
    if ($end === false) {
        return '';
    }
    return substr($src, $start, $end - $start);
}

private function assert_CheckoutShippingAddressSlot_testIndexAndExpressReviewDeclareExclusiveShippingSlotWithoutSoftFallback(): void
{
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    foreach ([$index, $express] as $src) {
        self::assertStringContainsString('id="checkout-shipping-address"', $src);
        self::assertStringContainsString('exclusive="true"', $src);
        self::assertStringNotContainsString("fetch('Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml')", $src);
        self::assertStringNotContainsString('Soft fallback', $src);
        self::assertStringNotContainsString('SlotRenderer replaces this', $src);
    }
    // Shipping slot block must declare exclusive (between open tag id and its close).
    self::assertTrue($this->slotDeclaresExclusive_CheckoutShippingAddressSlot($index, 'checkout-shipping-address'));
    self::assertTrue($this->slotDeclaresExclusive_CheckoutShippingAddressSlot($express, 'checkout-shipping-address'));
    self::assertTrue($this->slotDeclaresExclusive_CheckoutShippingAddressSlot($index, 'checkout-tax-identity'));
    self::assertTrue($this->slotDeclaresExclusive_CheckoutShippingAddressSlot($express, 'checkout-tax-identity'));
}

private function slotDeclaresExclusive_CheckoutShippingAddressSlot(string $src, string $slotId): bool
{
    $needle = 'id="' . $slotId . '"';
    $pos = strpos($src, $needle);
    if ($pos === false) {
        return false;
    }
    $chunk = substr($src, $pos, 800);
    $end = strpos($chunk, '</w:slot>');
    if ($end === false) {
        $end = strpos($chunk, '/>');
    }
    if ($end !== false) {
        $chunk = substr($chunk, 0, $end);
    }
    return str_contains($chunk, 'exclusive="true"');
}

private function assert_CheckoutStorefrontSlotsCatalog_testCheckoutDeclaresStorefrontSlotCatalogForInjectionDiscovery(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml';
    self::assertFileExists($template);
    $src = (string) file_get_contents($template);
    self::assertStringContainsString('discovery-only', $src);
    self::assertStringContainsString('@widget.code {checkout-storefront-slots}', $src);
    self::assertStringContainsString('@widget.type {container}', $src);
    self::assertStringContainsString('@widget.is_container {true}', $src);
    self::assertStringContainsString('"checkout-express-payment"', $src);
    self::assertStringContainsString('"checkout-shipping-address"', $src);
    self::assertStringContainsString('"checkout-tax-identity"', $src);
    self::assertStringContainsString('"checkout-summary-discount"', $src);
    self::assertStringContainsString('"checkout-summary-note"', $src);
    self::assertStringContainsString('"checkout-summary-credit"', $src);
    self::assertStringContainsString('express-checkout', $src);
    self::assertStringContainsString('checkout-coupon', $src);
    self::assertStringContainsString('order-notice', $src);
    self::assertStringContainsString('b2b-checkout-credit', $src);
}

private function assert_CheckoutTaxIdentitySlot_testIndexAndExpressReviewDeclareTaxIdentitySlot(): void
{
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    self::assertStringContainsString('id="checkout-tax-identity"', $index);
    self::assertStringContainsString('id="checkout-tax-identity"', $express);
    self::assertStringContainsString('accept="checkout-tax-identity,tax-identity,buyer-tax,tax"', $index);
    self::assertStringContainsString('accept="checkout-tax-identity,tax-identity,buyer-tax,tax"', $express);
    self::assertStringContainsString('exclusive="true"', $index);
    self::assertStringContainsString('exclusive="true"', $express);
    self::assertStringContainsString('data-tax-identity-host', $index);
    self::assertStringContainsString('data-tax-identity-host', $express);
    self::assertStringContainsString('weline-checkout__tax-identity-host', $index);
    // Tax host must not use checkout panel chrome (empty bordered card when non-EU).
    self::assertDoesNotMatchRegularExpression('/class="weline-checkout__panel"[^>]*section_tax_identity|section_tax_identity[^>]*weline-checkout__panel/', $index);
}

private function assert_CheckoutTaxIdentitySlot_testCatalogListsTaxIdentitySlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/checkout-storefront-slots.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/checkout-storefront-slots.phtml');
    self::assertStringContainsString('"checkout-tax-identity"', $catalog);
    self::assertStringContainsString('tax-identity', $catalog);
}

private function assert_CheckoutTaxIdentitySlot_testCheckoutPagesForbidSoftFallbackFetchOfShippingAndTaxWidgets(): void
{
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    foreach ([$index, $express] as $src) {
        self::assertStringNotContainsString('Soft fallback', $src);
        self::assertStringNotContainsString('SlotRenderer replaces this', $src);
        self::assertStringNotContainsString("fetch('Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml')", $src);
        self::assertStringNotContainsString("fetch('Weline_Tax::templates/frontend/widgets/checkout-tax-identity.phtml')", $src);
        self::assertStringNotContainsString('app/code/Weline/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml', $src);
        self::assertStringNotContainsString('app/code/Weline/Tax/view/templates/frontend/widgets/checkout-tax-identity.phtml', $src);
    }
}

private function assert_ExpressReviewPage_testExpressReviewTemplateHasBreakoutAndCopy(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    self::assertStringContainsString('尚未扣款', $tpl);
    self::assertStringContainsString('确认并付款', $tpl);
    self::assertStringContainsString('window.opener', $tpl);
    self::assertStringContainsString('data-testid="checkout-express-review"', $tpl);
    self::assertStringContainsString('data-weline-load="checkoutExpressReview"', $tpl);
    self::assertStringContainsString('id="checkout-shipping-address"', $tpl);
    self::assertStringContainsString('请确认收货地址；不对可更换或新增', $tpl);
    self::assertStringNotContainsString('支付商带回，只读', $tpl);
}

private function assert_ExpressReviewPage_testExpressReviewControllerExists(): void
{
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/ExpressReview.php');
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/ExpressReview.php');
    self::assertStringContainsString('express-review.phtml', $src);
}

private function assert_ExpressReviewPage_testQueryDescriptorListsExpressOps(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'startExpressCheckout'", $src);
    self::assertStringContainsString("'getExpressReview'", $src);
    self::assertStringContainsString("'confirmExpressCheckout'", $src);
    self::assertStringContainsString("'cancelExpressCheckout'", $src);
    self::assertStringContainsString('ExpressCheckoutFlowService', $src);
    // refreshReview 会带顶层 contact_phone/email；未声明则 Unknown frontend worker param
    self::assertMatchesRegularExpression("/'name' => 'getExpressReview'[\\s\\S]*?'contact_phone' => \\['type' => 'string'/", $src);
    self::assertMatchesRegularExpression("/'name' => 'getExpressReview'[\\s\\S]*?'email' => \\['type' => 'string'/", $src);
}

private function assert_ExpressReviewPage_testSinkDoesNotInventFakePhone(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutPaymentExpressAddressSink.php');
    self::assertStringNotContainsString('00000000000', $src);
}

private function assert_ExpressReviewPage_testExpressReviewJsHandlesTotalsAndCancel(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/statics/js/express-review.js');
    self::assertStringContainsString('cancelExpressCheckout', $js);
    self::assertStringContainsString('majorFromTotals', $js);
    self::assertStringContainsString('shipping_methods', $js);
    self::assertStringContainsString('weline:checkout:address-updated', $js);
    self::assertStringContainsString('shipping_address', $js);
    self::assertStringContainsString('collectShippingAddress', $js);
    self::assertStringContainsString('method.title || method.label || method.service_name', $js);
    self::assertStringContainsString('trackReviewEnter', $js);
    self::assertStringContainsString("trackPixel('begin_checkout'", $js);
    self::assertStringContainsString('express_review_enter', $js);
    // 支付方式徽章禁止硬编码中文（非中文 locale 漏译）
    self::assertStringContainsString("translatePhrase('支付方式')", $js);
    self::assertStringNotContainsString("methodLabel = '支付方式：'", $js);
    self::assertStringContainsString('data-express-shipping-list', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml'));
}

private function assert_ExpressReviewPage_testExpressReviewDtoExposesPixelItems(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString('orderItemsToPixelItems', $src);
    self::assertStringContainsString("'items' => \$pixelItems", $src);
    self::assertStringContainsString("'item_id'", $src);
    self::assertStringContainsString("'item_name'", $src);
}

private function assert_ExpressReviewPage_testExpressFlowMapsShippingLabelNotRawCodeOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString("\$option['label'] ?? \$option['service_name']", $src);
    self::assertStringContainsString("'label' => \$label", $src);
    self::assertStringContainsString("'title' => \$label", $src);
}

private function assert_ExpressReviewPage_testAmendServiceQuotesTaxAndShipping(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressUnpaidOrderAmend.php');
    self::assertStringContainsString('quoteTaxMinor', $src);
    self::assertStringContainsString('quoteDiscountMinor', $src);
    self::assertStringContainsString('mergeAddressReadOnlyCore', $src);
    self::assertStringContainsString('Incoming (user-selected', $src);
}

private function assert_ExpressReviewPage_testFlowServiceAcceptsSelectedAddressOverride(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString('extractAddressFromParams', $src);
    self::assertStringContainsString("'address_readonly' => false", $src);
    self::assertStringContainsString('请确认收货地址；不对可更换或新增', $src);
    self::assertStringContainsString('resolvePaymentTransactionNo', $src);
    self::assertStringContainsString('buildAlreadyPaidResult', $src);
}

private function assert_ExpressReviewPage_testExpressReviewJsResolvesGroupUuid(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/statics/js/express-review.js');
    self::assertStringContainsString('checkout_group_uuid', $js);
    self::assertStringContainsString('reviewPayload', $js);
    self::assertStringContainsString('rememberTransactionNo', $js);
    self::assertStringContainsString('handleAlreadyPaid', $js);
    self::assertStringContainsString('already_paid', $js);
    self::assertStringContainsString('data-checkout-group-uuid', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml'));
}

private function assert_ExpressReviewPage_testPaymentSessionHasUpdateBrowserLanding(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/Service/PaymentCheckoutSessionPersistenceService.php');
    self::assertStringContainsString('function updateBrowserLanding', $src);
}

private function assert_ExpressReviewPage_testExpressReviewControllerRedirectsAlreadyPaid(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/ExpressReview.php');
    self::assertStringContainsString('buildAlreadyPaidResult', $src);
    self::assertStringContainsString('checkout/success', $src);
}

private function assert_ExpressReviewPage_testSuccessDoesNotCartDumpPaidOrders(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/Success.php');
    self::assertStringContainsString('renderPaidAcknowledgement', $src);
    self::assertStringContainsString('checkout_paid_ack', $src);
    self::assertStringContainsString('must NOT dump to /cart', $src);
}

private function assert_ExpressReviewPage_testExpressReviewTemplateShowsAlreadyPaidCopy(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    self::assertStringContainsString('订单已支付', $tpl);
    self::assertStringContainsString('data-already-paid', $tpl);
    self::assertStringContainsString('该笔订单已支付，无需再次确认', $tpl);
}

private function assert_ExpressReviewPage_testSuccessTemplateHasPaidAckCopy(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/success.phtml');
    self::assertStringContainsString('paidAck', $tpl);
    self::assertStringContainsString('该笔订单已完成扣款，无需再次付款', $tpl);
}

private function assert_ProductBuyNowWidget_testWidgetRegistrationPinsPurchaseActionsSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/product-buy-now.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/product-buy-now.phtml');
    self::assertStringContainsString('@widget.code {product-buy-now}', $src);
    self::assertStringContainsString('@widget.slot {product-purchase-actions}', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"slot":"product-purchase-actions"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_ProductBuyNowWidget_testWidgetTemplateRedirectsToCheckoutAfterCartAdd(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/product-buy-now.phtml');
    self::assertStringContainsString('data-testid="product-buy-now"', $template);
    self::assertStringContainsString('data-action="buy-now"', $template);
    self::assertStringContainsString("getUrl('checkout')", $template);
    self::assertStringContainsString('@static(Weline_Cart::js/widgets/product-purchase-actions.js)', $template);
    self::assertStringContainsString('data-purchase-loading', $template);
    self::assertStringContainsString('data-checkout-url', $template);
}

private function assert_ProductCardBuyNowWidget_testWidgetRegistrationExposesCardBuyNowTemplate(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
    $tpl = 'Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/product-card-buy-now.phtml');
    self::assertStringContainsString('@widget.code {product-card-buy-now}', $src);
    self::assertStringContainsString('@widget.slot {product-card-purchase-actions}', $src);
    self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
}

private function assert_ProductCardBuyNowWidget_testCardBuyNowWidgetUsesBuyNowActionAndCommerceClasses(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/frontend/widgets/product-card-buy-now.phtml');
    self::assertStringContainsString('data-testid="product-card-buy-now"', $template);
    self::assertStringContainsString('data-action="buy-now"', $template);
    self::assertStringContainsString('amz-card__buy-now', $template);
    self::assertStringContainsString('weline-checkout-product-card-buy-now', $template);
    self::assertStringContainsString('WidgetI18n::label($labelOverride, \'立即购买\')', $template);
    self::assertStringContainsString('$localize(\'正在前往结账...\')', $template);
}
}
