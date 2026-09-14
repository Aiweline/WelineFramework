<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingAddressWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsCheckoutShippingAddressSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('checkout-shipping-address', $widgets);
        $widget = $widgets['checkout-shipping-address'];
        self::assertSame('content', $widget['type'] ?? null);
        self::assertSame('checkout-shipping-address', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('checkout-shipping-address', $injection['slot'] ?? null);
        self::assertSame('checkout', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
    }

    public function testCheckoutShippingAddressWidgetUsesThemeAddressTag(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );
        self::assertStringContainsString('data-testid="shipping-checkout-address"', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('checkout-shipping-address', $template);
        self::assertStringContainsString('<w:theme:address', $template);
        self::assertStringContainsString('code="checkout-shipping-address"', $template);
        self::assertStringContainsString('data-saved-addresses', $template);
        self::assertStringContainsString('<?= $hasSaved ? \'\' : \'hidden\' ?>', $template);
        self::assertStringContainsString('name="address1"', $template);
        self::assertStringContainsString('name="postal_code"', $template);
        self::assertStringContainsString('data-weline-load="shippingCheckoutAddress"', $template);
        self::assertStringContainsString('checkout-shipping-address.js?v=20260914-picker-all-addr1', $modules);
        self::assertStringContainsString('WelineShippingCheckoutAddress', $modules);
        self::assertStringContainsString('data-field-error-for="phone"', $template);
        self::assertStringContainsString("'err_name'", $template);
        self::assertStringContainsString('checkout-shipping-address.css)?v=20260910-csa-embargo1', $template);
        self::assertStringNotContainsString('name="country_code" type="text"', $template);
        self::assertStringNotContainsString('<input name="province"', $template);
        self::assertStringNotContainsString('<input name="city"', $template);
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

        self::assertStringContainsString('data-use-edited-address', $template);
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
