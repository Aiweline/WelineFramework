<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 15 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingViewContractsPass().
 */
final class ShippingViewContractsTest extends TestCase
{
    public function testAllShippingViewContractsPass(): void
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
            'AccountOrderShipments::testShippingProvidesAccountOrderShipmentsHook' => function () {
            $this->assert_AccountOrderShipments_testShippingProvidesAccountOrderShipmentsHook();
        },
            'AccountOrderShipments::testAccountTemplateIsReadOnlyAndUsesShipmentsService' => function () {
            $this->assert_AccountOrderShipments_testAccountTemplateIsReadOnlyAndUsesShipmentsService();
        },
            'BackendOrderShippingWidgets::testWidgetRegistrationPinsOrderShipmentSlots' => function () {
            $this->assert_BackendOrderShippingWidgets_testWidgetRegistrationPinsOrderShipmentSlots();
        },
            'BackendOrderShippingWidgets::testHookTemplatesDelegateToShippingWidgets' => function () {
            $this->assert_BackendOrderShippingWidgets_testHookTemplatesDelegateToShippingWidgets();
        },
            'BackendOrderShippingWidgets::testWidgetTemplatesUseShipmentsService' => function () {
            $this->assert_BackendOrderShippingWidgets_testWidgetTemplatesUseShipmentsService();
        },
            'CarrierCoverageKeepCurrent::testEditTemplateDefaultsToKeepCurrentSelection' => function () {
            $this->assert_CarrierCoverageKeepCurrent_testEditTemplateDefaultsToKeepCurrentSelection();
        },
            'CheckoutShippingAddressEmbargoCard::testSavedAddressCardMarksEmbargoWithDangerTokens' => function () {
            $this->assert_CheckoutShippingAddressEmbargoCard_testSavedAddressCardMarksEmbargoWithDangerTokens();
        },
            'CheckoutShippingAddressMount::testVersionedWidgetExportsMountAndGuardsDoubleBind' => function () {
            $this->assert_CheckoutShippingAddressMount_testVersionedWidgetExportsMountAndGuardsDoubleBind();
        },
            'CheckoutShippingAddressOutlinedField::testCheckoutShippingAddressEditorUsesWFieldNotchMarkup' => function () {
            $this->assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingAddressEditorUsesWFieldNotchMarkup();
        },
            'CheckoutShippingAddressOutlinedField::testCheckoutShippingAddressCssDoesNotForceStackedLabels' => function () {
            $this->assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingAddressCssDoesNotForceStackedLabels();
        },
            'CheckoutShippingAddressOutlinedField::testCheckoutShippingContactFieldsHavePlaceholdersForFloatingLabel' => function () {
            $this->assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingContactFieldsHavePlaceholdersForFloatingLabel();
        },
            'CheckoutShippingAddressQuoteSelected::testWidgetExposesResolveQuoteAddressFromSelectedCard' => function () {
            $this->assert_CheckoutShippingAddressQuoteSelected_testWidgetExposesResolveQuoteAddressFromSelectedCard();
        },
            'CheckoutShippingAddressWidget::testWidgetRegistrationPinsCheckoutShippingAddressSlot' => function () {
            $this->assert_CheckoutShippingAddressWidget_testWidgetRegistrationPinsCheckoutShippingAddressSlot();
        },
            'CheckoutShippingAddressWidget::testCheckoutShippingAddressWidgetUsesThemeAddressTag' => function () {
            $this->assert_CheckoutShippingAddressWidget_testCheckoutShippingAddressWidgetUsesThemeAddressTag();
        },
            'CheckoutShippingAddressWidget::testCheckoutShippingAddressSupportsCollapsedRadioEditAddAndBillingSame' => function () {
            $this->assert_CheckoutShippingAddressWidget_testCheckoutShippingAddressSupportsCollapsedRadioEditAddAndBillingSame();
        },
            'EmbargoHook::testWebsiteStoreChannelHooksAndObserversExist' => function () {
            $this->assert_EmbargoHook_testWebsiteStoreChannelHooksAndObserversExist();
        },
            'FooterHelpLinksWidget::testFooterShippingInfoLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterShippingInfoLinkRegistersDefaultInjection();
        },
            'FooterHelpLinksWidget::testFooterReturnsPolicyLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterReturnsPolicyLinkRegistersDefaultInjection();
        },
            'FooterHelpLinksWidget::testFooterHelpLinkTemplatesPointToGuidePages' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterHelpLinkTemplatesPointToGuidePages();
        },
            'FooterHelpLinksWidget::testGuideControllersAndTemplatesExist' => function () {
            $this->assert_FooterHelpLinksWidget_testGuideControllersAndTemplatesExist();
        },
            'ProductDeliveryModeBuyBoxBag::testDeliveryModeReadsBuyBoxThenDefaultOriginOnly' => function () {
            $this->assert_ProductDeliveryModeBuyBoxBag_testDeliveryModeReadsBuyBoxThenDefaultOriginOnly();
        },
            'ProductDeliveryModeWidget::testWidgetRegistrationPinsProductDeliveryModeSlot' => function () {
            $this->assert_ProductDeliveryModeWidget_testWidgetRegistrationPinsProductDeliveryModeSlot();
        },
            'ProductDeliveryModeWidget::testWidgetShellIsJsDrivenWithoutBackendBadgeText' => function () {
            $this->assert_ProductDeliveryModeWidget_testWidgetShellIsJsDrivenWithoutBackendBadgeText();
        },
            'RateTemplateAdminUi::testListShowsFeesCurrencyAndEditAction' => function () {
            $this->assert_RateTemplateAdminUi_testListShowsFeesCurrencyAndEditAction();
        },
            'RateTemplateAdminUi::testFormLocksBaseCurrencyAndSupportsUpdate' => function () {
            $this->assert_RateTemplateAdminUi_testFormLocksBaseCurrencyAndSupportsUpdate();
        },
            'RateTemplateAdminUi::testDesignNoteExplainsBaseCurrencyConversion' => function () {
            $this->assert_RateTemplateAdminUi_testDesignNoteExplainsBaseCurrencyConversion();
        },
            'RateTemplateDesignNote::testIndexExplainsNoDestinationRateMatrix' => function () {
            $this->assert_RateTemplateDesignNote_testIndexExplainsNoDestinationRateMatrix();
        },
            'ShippingServiceLaneListLabels::testLaneTableMapsIdsToReadableNames' => function () {
            $this->assert_ShippingServiceLaneListLabels_testLaneTableMapsIdsToReadableNames();
        },
        ];
    }

private function assert_AccountOrderShipments_testShippingProvidesAccountOrderShipmentsHook(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/hooks/Weline_Order/frontend/account/order-detail/shipments.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Shipping::templates/frontend/account/order-shipments.phtml', $source);
    self::assertStringContainsString('Weline_Shipping::css/account-order-shipments.css', $source);
}

private function assert_AccountOrderShipments_testAccountTemplateIsReadOnlyAndUsesShipmentsService(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/account/order-shipments.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('BackendOrderShipmentsService', $source);
    self::assertStringContainsString('listForOrderId', $source);
    self::assertStringContainsString('OrderShipmentTrackingQueryService', $source);
    self::assertStringContainsString('account_shipment_order_uuid', $source);
    self::assertStringContainsString('data-testid="account-order-shipments"', $source);
    self::assertStringContainsString('data-testid="account-order-shipments-count"', $source);
    self::assertStringContainsString('account-order-shipment-contents', $source);
    self::assertStringContainsString('包裹内商品', $source);
    self::assertStringContainsString('本单发货记录', $source);
    self::assertStringContainsString('本子订单暂无发货记录', $source);
    self::assertStringContainsString('data-testid="account-order-shipment-row"', $source);
    self::assertStringContainsString('data-testid="account-order-shipment-tracking"', $source);
    self::assertStringContainsString('data-testid="account-order-shipment-progress"', $source);
    self::assertStringNotContainsString('办理发货', $source);
    self::assertStringNotContainsString('backend/order/edit', $source);
    self::assertStringNotContainsString('showOpsLink', $source);
}

private function assert_BackendOrderShippingWidgets_testWidgetRegistrationPinsOrderShipmentSlots(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
    $shipmentsTpl = 'Weline_Shipping::templates/backend/widgets/backend-order-shipments.phtml';
    $listTpl = 'Weline_Shipping::templates/backend/widgets/backend-order-list-shipping.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $shipmentsTpl));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $listTpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/widgets/backend-order-shipments.phtml');
    self::assertStringContainsString('@widget.code {backend-order-shipments}', $src);
    self::assertStringContainsString('"required":true', $src);
    $listSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/widgets/backend-order-list-shipping.phtml');
    self::assertStringContainsString('@widget.code {backend-order-list-shipping}', $listSrc);
}

private function assert_BackendOrderShippingWidgets_testHookTemplatesDelegateToShippingWidgets(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping';
    $detailHook = (string) file_get_contents($root . '/view/hooks/Weline_Order/backend/order/view/shipments.phtml');
    $listHook = (string) file_get_contents($root . '/view/hooks/Weline_Order/backend/order/list/shipping.phtml');
    self::assertStringContainsString('Weline_Shipping::templates/backend/widgets/backend-order-shipments.phtml', $detailHook);
    self::assertStringContainsString('Weline_Shipping::templates/backend/widgets/backend-order-list-shipping.phtml', $listHook);
}

private function assert_BackendOrderShippingWidgets_testWidgetTemplatesUseShipmentsService(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping';
    $detail = (string) file_get_contents($root . '/view/templates/backend/widgets/backend-order-shipments.phtml');
    $list = (string) file_get_contents($root . '/view/templates/backend/widgets/backend-order-list-shipping.phtml');
    self::assertStringContainsString('BackendOrderShipmentsService', $detail);
    self::assertStringContainsString('@widget.default_injections', $detail);
    self::assertStringContainsString('data-testid="backend-order-shipments"', $detail);
    self::assertStringContainsString('data-testid="backend-order-shipments-count"', $detail);
    self::assertStringContainsString('本单发货记录', $detail);
    self::assertStringContainsString('backend-order-shipment-contents', $detail);
    self::assertStringContainsString('包裹内商品', $detail);
    self::assertStringContainsString('本子订单暂无发货记录', $detail);
    self::assertStringContainsString('同单多笔发货，非结账组其他子单', $detail);
    self::assertStringContainsString('物流进程记录', $detail);
    self::assertStringContainsString('OrderShipmentTrackingQueryService', $detail);
    self::assertStringContainsString('data-testid="backend-order-shipment-progress"', $detail);
    self::assertStringContainsString('showOpsLink', $detail);
    self::assertStringNotContainsString('查看当前位置', $detail);
    self::assertStringNotContainsString('shippingOrderShipmentTracking', $detail);
    self::assertStringContainsString('BackendOrderShipmentsService', $list);
    self::assertStringContainsString('data-testid="backend-order-list-shipping"', $list);
    self::assertStringContainsString('order/backend/shipment/index', $list);
}

private function assert_CarrierCoverageKeepCurrent_testEditTemplateDefaultsToKeepCurrentSelection(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping';
    $tpl = (string) file_get_contents($base . '/view/templates/backend/Carrier/edit.phtml');
    self::assertStringContainsString('name="coverage_keep_current"', $tpl);
    self::assertStringContainsString('id="coverage_keep_current" checked', $tpl);
    self::assertStringContainsString('data-testid="shipping-carrier-coverage-caution"', $tpl);
    self::assertStringContainsString('data-tone="warning"', $tpl);
    self::assertStringContainsString('w-alert__content', $tpl);
    self::assertStringContainsString('w-alert__title', $tpl);
    self::assertStringContainsString('<w:icon name="warning" size="sm"></w:icon>', $tpl);
    self::assertStringContainsString('谨慎操作', $tpl);
    self::assertStringContainsString('window.confirm', $tpl);
    self::assertStringContainsString('data-coverage-baseline', $tpl);
    self::assertStringContainsString('费用模板只算运费金额，不含到达地区', $tpl);
    $ctrl = (string) file_get_contents($base . '/Controller/Backend/Carrier.php');
    self::assertStringContainsString("getParam('coverage_keep_current', 1)", $ctrl);
    self::assertStringContainsString('if (!$keepCurrent)', $ctrl);
    self::assertStringContainsString('applyProviderDefaults', $ctrl);
    self::assertStringNotContainsString("getParam('coverage_apply_defaults'", $ctrl);
}

private function assert_CheckoutShippingAddressEmbargoCard_testSavedAddressCardMarksEmbargoWithDangerTokens(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/css/widgets/checkout-shipping-address.css');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/checkout-shipping-address.js');
    self::assertStringContainsString('is-embargoed', $tpl);
    self::assertStringContainsString('data-address-embargo', $tpl);
    self::assertStringContainsString('embargo_hint', $tpl);
    self::assertStringContainsString('.w-shipping-checkout-address__card.is-embargoed', $css);
    self::assertStringContainsString('--color-danger', $css);
    self::assertStringContainsString('applyEmbargoOnCard', $js);
    self::assertStringContainsString('embargo_blocked', $js);
    self::assertStringContainsString('selectedEmbargoCard', $js);
    self::assertStringContainsString("data-embargo-blocked') !== '1'", $js);
    self::assertStringContainsString('skipRequired', $js);
}

private function assert_CheckoutShippingAddressMount_testVersionedWidgetExportsMountAndGuardsDoubleBind(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/checkout-shipping-address.js');
    self::assertStringContainsString('function mount(root)', $src);
    self::assertStringContainsString("data-shipping-mounted", $src);
    self::assertStringContainsString('mount: mount', $src);
    self::assertStringContainsString("document.querySelector('[data-shipping-checkout-address]')", $src);
    self::assertStringContainsString('20261023-phone-intl-1', $src);
    self::assertStringContainsString('ensureCheckoutShippingAddressCss', $src);
    self::assertStringContainsString('data-shipping-checkout-address-css', $src);
    self::assertStringContainsString('checkout-shipping-address.css', $src);
    self::assertStringContainsString('list_all_addresses', $src);
    self::assertStringContainsString('upsertLocalSavedAddress', $src);
    self::assertStringContainsString('syncChangeAddressLabel', $src);
}

private function assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingAddressEditorUsesWFieldNotchMarkup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertStringContainsString('class="w-field w-shipping-checkout-address__field"', $src);
    self::assertStringContainsString('class="w-field__label"', $src);
    self::assertStringNotContainsString('w-form-label', $src);
    self::assertStringNotContainsString('data-label-layout="stacked"', $src);
}

private function assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingAddressCssDoesNotForceStackedLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/css/widgets/checkout-shipping-address.css';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertStringContainsString('--weline-theme-field-label-bg', $src);
    self::assertStringNotContainsString('.w-form-label', $src);
}

private function assert_CheckoutShippingAddressOutlinedField_testCheckoutShippingContactFieldsHavePlaceholdersForFloatingLabel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    // PHP 短标签内含 `>`，不能用简单 name=...[^>]*placeholder 正则扫整段。
    self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入收货人姓名')) ?>\"", $src);
    self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入联系电话')) ?>\"", $src);
    self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入邮箱地址')) ?>\"", $src);
    self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入邮政编码')) ?>\"", $src);
    self::assertMatchesRegularExpression('/name="name"[\s\S]*?placeholder=/', $src);
    self::assertMatchesRegularExpression('/name="phone"[\s\S]*?placeholder=/', $src);
    self::assertMatchesRegularExpression('/name="email"[\s\S]*?placeholder=/', $src);
    self::assertMatchesRegularExpression('/name="postal_code"[\s\S]*?placeholder=/', $src);
}

private function assert_CheckoutShippingAddressQuoteSelected_testWidgetExposesResolveQuoteAddressFromSelectedCard(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/checkout-shipping-address.js');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('function resolveQuoteAddress()', $js);
    self::assertStringContainsString('selectedCardPayload', $js);
    self::assertStringContainsString('syncFormFromSelectedCard', $js);
    self::assertStringContainsString('resolveQuoteAddress: resolveQuoteAddress', $js);
    self::assertStringContainsString('checkout-shipping-address.js?v=20261023-phone-intl-1', $modules);
}

private function assert_CheckoutShippingAddressWidget_testWidgetRegistrationPinsCheckoutShippingAddressSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
    $tpl = 'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    self::assertStringContainsString('@widget.code {checkout-shipping-address}', $src);
    self::assertStringContainsString('@widget.slot {checkout-shipping-address}', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"checkout-shipping-address"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_CheckoutShippingAddressWidget_testCheckoutShippingAddressWidgetUsesThemeAddressTag(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('data-testid="checkout-shipping-address"', $template);
    self::assertStringContainsString('data-widget-code="checkout-shipping-address"', $template);
    self::assertStringContainsString('data-shipping-checkout-address', $template);
    self::assertStringContainsString('@widget.default_injections', $template);
    self::assertStringContainsString('checkout-shipping-address', $template);
    self::assertStringContainsString('<w:theme:address', $template);
    self::assertStringContainsString('code="checkout-shipping-address"', $template);
    self::assertStringContainsString('data-saved-addresses', $template);
    self::assertStringContainsString('<?= $hasSaved ? \'\' : \'hidden\' ?>', $template);
    self::assertStringContainsString('name="address1"', $template);
    self::assertStringContainsString('name="postal_code"', $template);
    self::assertStringContainsString('data-weline-load="shippingCheckoutAddress"', $template);
    self::assertStringContainsString('checkout-shipping-address.js?v=20261023-phone-intl-1', $modules);
    self::assertStringContainsString('WelineShippingCheckoutAddress', $modules);
    self::assertStringContainsString('data-field-error-for="phone"', $template);
    self::assertStringContainsString("'err_name'", $template);
    self::assertStringContainsString("'err_phone_invalid'", $template);
    self::assertStringContainsString('data-phone-field', $template);
    self::assertStringContainsString('@widget.source {Weline_Shipping::css/widgets/checkout-shipping-address.css}', $template);
    self::assertStringNotContainsString('checkout-shipping-address.css?v=', $template);
    self::assertStringContainsString('id="checkout-shipping-address-editor"', $template);
    self::assertStringContainsString('LazyCaptchaClientRuntime::onceScriptHtml', $template);
    self::assertStringNotContainsString('name="country_code" type="text"', $template);
    self::assertStringNotContainsString('<input name="province"', $template);
    self::assertStringNotContainsString('<input name="city"', $template);
    self::assertStringContainsString('code="checkout-billing-address"', $template);
    self::assertStringContainsString('meta-prefix="billing_"', $template);
    self::assertStringContainsString('name="billing_postal_code"', $template);
    self::assertStringContainsString('data-billing-address-cascade', $template);
    self::assertStringContainsString('data-use-billing-address', $template);
    self::assertStringContainsString('data-billing-saved', $template);
    self::assertStringContainsString('data-change-billing-address', $template);
    self::assertStringContainsString('data-add-billing-address', $template);
    self::assertStringContainsString('data-cancel-billing-edit', $template);
    self::assertStringNotContainsString('name="billing_country_code" type="text"', $template);
}

private function assert_CheckoutShippingAddressWidget_testCheckoutShippingAddressSupportsCollapsedRadioEditAddAndBillingSame(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/checkout-shipping-address.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/css/widgets/checkout-shipping-address.css');
    self::assertStringContainsString('data-mode=', $template);
    self::assertStringContainsString('type="radio"', $template);
    self::assertStringContainsString('data-edit-address', $template);
    self::assertStringContainsString('data-add-address', $template);
    self::assertStringContainsString('data-change-address', $template);
    self::assertStringContainsString('data-list-loaded', $template);
    self::assertStringContainsString('data-address-count', $template);
    self::assertStringContainsString('data-billing-same', $template);
    self::assertStringContainsString('data-billing-editor', $template);
    self::assertStringContainsString('name="billing_name"', $template);
    self::assertStringContainsString('applyBillingCascade', $js);
    self::assertStringContainsString('checkout-billing-address', $js);
    self::assertStringContainsString('BILLING_ADDRESS_CODE', $js);
    self::assertStringContainsString('commitBillingAddress', $js);
    self::assertStringContainsString('data-use-billing-address', $js);
    self::assertStringContainsString('renderBillingSavedAddresses', $js);
    self::assertStringContainsString('openBillingPicker', $js);
    self::assertStringContainsString('selectBillingSaved', $js);
    self::assertStringContainsString('openBillingNew', $js);
    self::assertStringContainsString('beginCheckoutReloadSuppress', $js);
    self::assertStringContainsString('data-checkout-suppress-reload', $js);
    self::assertStringContainsString('keepCountry', $js);
    self::assertStringContainsString('async function selectSaved(card)', $js);
    // 选已存地址：suppress 程序化回填，再派 address-updated 做 soft 局部刷新
    $selectSaved = (string) preg_replace('/.*async function selectSaved\(card\)/s', 'async function selectSaved(card)', $js);
    $selectSaved = (string) preg_replace('/\n    function openEdit\(card\).*/s', '', $selectSaved);
    self::assertStringContainsString('beginCheckoutReloadSuppress', $selectSaved);
    self::assertStringContainsString("weline:checkout:address-updated", $selectSaved);
    self::assertStringContainsString('activateBillingBook', $js);
    self::assertStringContainsString('selectDeliveryAddress', $js);
    // 账单选址不得改写收货配送会话
    self::assertStringContainsString('禁止调用 selectDeliveryAddress', $js);
    self::assertStringContainsString('sanitizePhoneInput', $js);
    self::assertStringContainsString('isValidPhone', $js);
    self::assertStringContainsString('applyPhoneFieldSanitize', $js);
    self::assertStringContainsString('err_phone_invalid', $js);
    self::assertStringContainsString('data-use-edited-address', $template);
    self::assertStringContainsString('class="w-button w-shipping-checkout-address__primary-btn"', $template);
    self::assertStringContainsString('data-variant="outline"', $template);
    self::assertStringContainsString('ensureCaptchaTokenBeforeSave', $js);
    self::assertStringContainsString('setSaveButtonLoading', $js);
    self::assertStringContainsString("classList.toggle('is-loading'", $js);
    self::assertStringContainsString('aria-busy', $js);
    self::assertStringContainsString('.w-shipping-checkout-address__primary-btn.is-loading', $css);
    self::assertStringContainsString('.w-shipping-checkout-address .w-shipping-checkout-address__primary-btn.is-loading', $css);
    self::assertStringContainsString('--checkout-cta-bg', $css);
    self::assertStringNotContainsString('#f0c14b', $css);
    self::assertStringContainsString('w-shipping-checkout-address-spin', $css);
    self::assertStringContainsString('weline:form:prepare-submit', $js);
    self::assertStringContainsString('weline:captcha:degrade', $js);
    self::assertStringContainsString("prefer = 'local_image'", $js);
    self::assertStringContainsString("prefer === 'local_image'", $js);
    self::assertStringContainsString('w-shipping-checkout-address__editor-action-buttons', $template);
    self::assertSame(1, substr_count($template, 'data-shipping-message'), 'Non-field message must appear once, beside save button');
    self::assertStringContainsString('data-is-logged-in', $template);
    self::assertStringContainsString('data-shipping-message', $template);
    self::assertStringContainsString('commitEditedAddress', $js);
    self::assertStringContainsString('saveDeliveryAddress', $js);
    self::assertStringContainsString('applyFieldErrors', $js);
    self::assertStringContainsString('focusFirstFieldError', $js);
    self::assertStringContainsString('validateShippingFields', $js);
    self::assertStringContainsString('renderSavedAddresses', $js);
    self::assertStringContainsString('ensureSavedShell', $js);
    self::assertStringContainsString('synthesizeAddressFromContext', $js);
    self::assertStringContainsString('refreshGuestCaptchaOnOpen', $js);
    self::assertStringContainsString('openAddressPicker', $js);
    self::assertStringContainsString('collapseAddressList', $js);
    self::assertStringContainsString('presentOrderAddress', $js);
    self::assertStringContainsString('list_all_addresses', $js);
    self::assertStringContainsString('upsertLocalSavedAddress', $js);
    self::assertStringContainsString('collectAddressesFromCards', $js);
    self::assertStringContainsString('var showChange = picking || hasSaved', $js);
    self::assertStringContainsString("picking", $js);
    self::assertStringContainsString('getDeliveryContext', $js);
    self::assertStringContainsString('weline:checkout:address-updated', $js);
    self::assertStringContainsString('w-shipping-checkout-address__card', $css);
    self::assertStringContainsString('gap: 1rem', $css);
    self::assertStringContainsString('padding: 1.25rem', $css);
    self::assertStringContainsString('--sca-link', $css);
    self::assertStringContainsString('data-mode="collapsed"', $css);
    self::assertStringContainsString('[data-mode="picking"]', $css);
    // collapsed 显式保留「使用新地址」（覆盖旧缓存 display:none）
    self::assertStringContainsString('[data-mode="collapsed"] [data-add-address]', $css);
    self::assertStringContainsString(".w-shipping-checkout-address[data-mode=\"collapsed\"] [data-add-address] {\n  display: inline-block;\n}", $css);
    self::assertStringNotContainsString(".w-shipping-checkout-address[data-mode=\"collapsed\"] [data-add-address] {\n  display: none;\n}", $css);
    self::assertStringContainsString('__saved[hidden]', $css);
    self::assertStringContainsString('w-shipping-checkout-address__saved-toolbar', $css);
    self::assertStringContainsString('w-shipping-checkout-address__editor-action-buttons', $css);
    self::assertStringContainsString('w-shipping-checkout-address__message', $css);
    self::assertStringContainsString('w-shipping-checkout-address__field-error', $css);
}

private function assert_EmbargoHook_testWebsiteStoreChannelHooksAndObserversExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/';
    foreach (['view/hooks/Weline_Websites/backend/website/form/sections-after.phtml', 'view/hooks/Weline_Websites/backend/store/form/sections-after.phtml', 'view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml', 'Observer/WebsiteSaveAfter.php', 'Observer/StoreSaveAfter.php', 'Observer/ChannelSaveAfter.php', 'Model/EmbargoRegion.php', 'Service/EmbargoService.php', 'Service/EmbargoAdminService.php', 'etc/event.xml'] as $rel) {
        self::assertFileExists($base . $rel, $rel);
    }
    $eventXml = (string) file_get_contents($base . 'etc/event.xml');
    self::assertStringContainsString('Weline_Websites::website_save_after', $eventXml);
    self::assertStringContainsString('Weline_Websites::store_save_after', $eventXml);
    self::assertStringContainsString('Weline_Websites::channel_save_after', $eventXml);
    $module = (string) file_get_contents($base . 'etc/module.php');
    self::assertStringContainsString("'Weline_Websites'", $module);
    $shared = (string) file_get_contents($base . 'view/templates/backend/partials/embargo-form-section.phtml');
    self::assertStringContainsString('extensions[shipping][embargo]', $shared);
    self::assertStringContainsString('data-w-address', $shared);
    self::assertStringContainsString('data-address-lazy', $shared);
    self::assertStringContainsString('bootWhenOpen', $shared);
    self::assertStringContainsString('ensureAddressScript', $shared);
    self::assertStringContainsString('20260921-keep-postal2', $shared);
    self::assertStringNotContainsString('data-shipping-address-direct', $shared);
    self::assertStringContainsString('selection', $shared);
    self::assertStringContainsString("'multi'", $shared);
    self::assertStringContainsString('搜索并添加国家/地区', $shared);
    self::assertStringNotContainsString('data-embargo-add', $shared);
    self::assertStringNotContainsString('data-embargo-table', $shared);
    $websiteHook = (string) file_get_contents($base . 'view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
    self::assertStringContainsString('embargo-form-section.phtml', $websiteHook);
    self::assertStringContainsString('destination-form-section.phtml', $websiteHook);
    self::assertFileExists($base . 'view/templates/backend/partials/destination-form-section.phtml');
    self::assertFileExists($base . 'Model/CarrierRegion.php');
    self::assertFileExists($base . 'Model/DestinationRegion.php');
    self::assertFileExists($base . 'Service/CarrierCoverageMatchService.php');
    $module = (string) file_get_contents($base . 'etc/module.php');
    self::assertStringContainsString('shipping.carrier_coverage.default', $module);
}

private function assert_FooterHelpLinksWidget_testFooterShippingInfoLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
    $tpl = 'Weline_Shipping::templates/frontend/widgets/footer-shipping-info-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/footer-shipping-info-link.phtml');
    self::assertStringContainsString('@widget.code {footer-shipping-info-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
    self::assertStringContainsString('"slot":"footer-help-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterHelpLinksWidget_testFooterReturnsPolicyLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
    $tpl = 'Weline_Shipping::templates/frontend/widgets/footer-returns-policy-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/footer-returns-policy-link.phtml');
    self::assertStringContainsString('@widget.code {footer-returns-policy-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
    self::assertStringContainsString('"slot":"footer-help-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterHelpLinksWidget_testFooterHelpLinkTemplatesPointToGuidePages(): void
{
    $shipping = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/footer-shipping-info-link.phtml');
    $returns = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/footer-returns-policy-link.phtml');
    self::assertStringContainsString("@url{'guide/shipping'}", $shipping);
    self::assertStringContainsString("@url{'guide/returns'}", $returns);
    self::assertStringContainsString('footer-help-links', $shipping);
    self::assertStringContainsString('footer-help-links', $returns);
}

private function assert_FooterHelpLinksWidget_testGuideControllersAndTemplatesExist(): void
{
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Guide/Shipping.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Guide/Returns.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/guide/shipping.phtml');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/guide/returns.phtml');
    $shippingTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/guide/shipping.phtml');
    $returnsTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/guide/returns.phtml');
    self::assertStringContainsString('data-testid="shipping-guide"', $shippingTpl);
    self::assertStringContainsString('data-testid="shipping-returns"', $returnsTpl);
    self::assertStringContainsString('doc__panel', $shippingTpl);
    self::assertStringContainsString('doc__panel', $returnsTpl);
    self::assertStringContainsString('一、发货时效', $shippingTpl);
    self::assertStringContainsString('一、适用条件', $returnsTpl);
    self::assertStringNotContainsString('width: min(52rem', $shippingTpl);
    self::assertStringNotContainsString('width: min(52rem', $returnsTpl);
}

private function assert_ProductDeliveryModeBuyBoxBag_testDeliveryModeReadsBuyBoxThenDefaultOriginOnly(): void
{
    $widget = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/product-delivery-mode.phtml');
    self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'origin_country\')', $widget);
    self::assertStringContainsString('resolveDefaultOriginCountry', $widget);
    self::assertStringNotContainsString('resolveForOffer(', $widget);
    self::assertStringContainsString('@widget.cache {300}', $widget);
}

private function assert_ProductDeliveryModeWidget_testWidgetRegistrationPinsProductDeliveryModeSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
    $tpl = 'Weline_Shipping::templates/frontend/widgets/product-delivery-mode.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/product-delivery-mode.phtml');
    self::assertStringContainsString('@widget.code {product-delivery-mode}', $src);
    self::assertStringContainsString('@widget.slot {product-delivery-mode}', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"slot":"product-delivery-mode"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_ProductDeliveryModeWidget_testWidgetShellIsJsDrivenWithoutBackendBadgeText(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/product-delivery-mode.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/widget-product-delivery-mode-1.js');
    self::assertStringContainsString('data-testid="product-delivery-mode"', $template);
    self::assertStringContainsString('data-origin-country=', $template);
    self::assertStringContainsString('data-shipping-delivery-badge', $template);
    self::assertStringContainsString('data-widget-script="shipping-product-delivery-mode-1"', $template);
    self::assertStringNotContainsString("><?= __('跨境配送') ?></span>", $template);
    self::assertStringContainsString("WidgetI18n::label('快送')", $template);
    self::assertStringContainsString("WidgetI18n::label('跨境配送')", $template);
    self::assertStringContainsString("WidgetI18n::label('标准配送')", $template);
    self::assertStringContainsString("local_fast", $js);
    self::assertStringContainsString("cross_border", $js);
    self::assertStringContainsString("standard", $js);
    self::assertStringContainsString('weline:delivery-country-changed', $js);
    self::assertStringContainsString('data-shipping-delivery-badge', $js);
}

private function template_RateTemplateAdminUi(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/RateTemplate/index.phtml');
}

private function assert_RateTemplateAdminUi_testListShowsFeesCurrencyAndEditAction(): void
{
    $tpl = $this->template_RateTemplateAdminUi();
    self::assertStringContainsString('data-testid="shipping-rate-template-list"', $tpl);
    self::assertStringContainsString('base_fee', $tpl);
    self::assertStringContainsString('currency_code', $tpl);
    self::assertStringContainsString('data-testid="shipping-rate-template-edit"', $tpl);
    self::assertStringContainsString('data-testid="shipping-rate-template-delete"', $tpl);
    self::assertStringContainsString('data-testid="shipping-rate-template-delete-forbidden"', $tpl);
    self::assertStringContainsString('系统种子不可删除', $tpl);
    self::assertStringContainsString('基础费用', $tpl);
    self::assertStringContainsString('货币', $tpl);
}

private function assert_RateTemplateAdminUi_testFormLocksBaseCurrencyAndSupportsUpdate(): void
{
    $tpl = $this->template_RateTemplateAdminUi();
    self::assertStringContainsString('base_currency', $tpl);
    self::assertStringContainsString('template_id', $tpl);
    self::assertStringNotContainsString('name="currency_code"', $tpl);
    self::assertStringContainsString('基础货币', $tpl);
    self::assertMatchesRegularExpression('/保存|更新/', $tpl);
}

private function assert_RateTemplateAdminUi_testDesignNoteExplainsBaseCurrencyConversion(): void
{
    $tpl = $this->template_RateTemplateAdminUi();
    self::assertStringContainsString('data-testid="shipping-rate-template-design-note"', $tpl);
    self::assertStringContainsString('基础货币', $tpl);
    self::assertStringContainsString('汇率', $tpl);
    self::assertStringContainsString('勿在模板里配地区矩阵', $tpl);
}

private function assert_RateTemplateDesignNote_testIndexExplainsNoDestinationRateMatrix(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/RateTemplate/index.phtml');
    self::assertStringContainsString('data-testid="shipping-rate-template-design-note"', $tpl);
    self::assertStringContainsString('w-alert__content', $tpl);
    self::assertStringContainsString('勿在模板里配地区矩阵', $tpl);
    self::assertStringContainsString('基础货币', $tpl);
    self::assertStringContainsString('配送服务 / 航线', $tpl);
}

private function template_ShippingServiceLaneListLabels(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/ShippingService/index.phtml');
}

private function assert_ShippingServiceLaneListLabels_testLaneTableMapsIdsToReadableNames(): void
{
    $tpl = $this->template_ShippingServiceLaneListLabels();
    self::assertStringContainsString('data-testid="shipping-service-lane-table"', $tpl);
    self::assertStringContainsString('carrier_names_by_id', $tpl);
    self::assertStringContainsString('template_names_by_id', $tpl);
    self::assertStringContainsString('origin_names_by_id', $tpl);
    self::assertStringContainsString('data-testid="lane-carrier-label"', $tpl);
    self::assertStringContainsString('data-testid="lane-template-label"', $tpl);
    self::assertStringContainsString('data-testid="lane-origin-label"', $tpl);
    self::assertStringContainsString('schema_fields_CARRIER_NAME', $tpl);
    self::assertStringContainsString('schema_fields_TEMPLATE_NAME', $tpl);
    self::assertStringContainsString('ShippingAddress::schema_fields_NAME', $tpl);
    self::assertDoesNotMatchRegularExpression('/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_CARRIER_ID\)\s*\?>\s*<\/td>/', $tpl);
    self::assertDoesNotMatchRegularExpression('/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_RATE_TEMPLATE_ID\)/', $tpl);
    self::assertDoesNotMatchRegularExpression('/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID\)/', $tpl);
}
}
