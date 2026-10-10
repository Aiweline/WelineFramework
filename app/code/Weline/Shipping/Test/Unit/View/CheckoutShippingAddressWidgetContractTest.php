<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingAddressWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsCheckoutShippingAddressSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $tpl = 'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
        self::assertStringContainsString('@widget.code {checkout-shipping-address}', $src);
        self::assertStringContainsString('@widget.slot {checkout-shipping-address}', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"checkout-shipping-address"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testCheckoutShippingAddressWidgetUsesThemeAddressTag(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );
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
        self::assertStringContainsString('checkout-shipping-address.js?v=20261007-address-pay-local', $modules);
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

    public function testCheckoutShippingAddressSupportsCollapsedRadioEditAddAndBillingSame(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/checkout-shipping-address.js',
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/widgets/checkout-shipping-address.css',
        );

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
        $selectSaved = (string)preg_replace('/.*async function selectSaved\(card\)/s', 'async function selectSaved(card)', $js);
        $selectSaved = (string)preg_replace('/\n    function openEdit\(card\).*/s', '', $selectSaved);
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
        self::assertSame(
            1,
            substr_count($template, 'data-shipping-message'),
            'Non-field message must appear once, beside save button',
        );
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
        self::assertStringContainsString(
            ".w-shipping-checkout-address[data-mode=\"collapsed\"] [data-add-address] {\n  display: inline-block;\n}",
            $css,
        );
        self::assertStringNotContainsString(
            ".w-shipping-checkout-address[data-mode=\"collapsed\"] [data-add-address] {\n  display: none;\n}",
            $css,
        );
        self::assertStringContainsString('__saved[hidden]', $css);
        self::assertStringContainsString('w-shipping-checkout-address__saved-toolbar', $css);
        self::assertStringContainsString('w-shipping-checkout-address__editor-action-buttons', $css);
        self::assertStringContainsString('w-shipping-checkout-address__message', $css);
        self::assertStringContainsString('w-shipping-checkout-address__field-error', $css);
    }
}
