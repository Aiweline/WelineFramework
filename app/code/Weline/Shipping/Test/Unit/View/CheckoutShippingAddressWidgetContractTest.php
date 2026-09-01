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
        self::assertStringContainsString('data-testid="shipping-checkout-address"', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('checkout-shipping-address', $template);
        self::assertStringContainsString('<w:theme:address', $template);
        self::assertStringContainsString('code="checkout-shipping-address"', $template);
        self::assertStringContainsString('data-saved-addresses', $template);
        self::assertStringContainsString('name="address1"', $template);
        self::assertStringContainsString('name="postal_code"', $template);
        self::assertStringContainsString('checkout-shipping-address.js)?v=20260828-csa1', $template);
        self::assertStringNotContainsString('name="country_code" type="text"', $template);
        self::assertStringNotContainsString('<input name="province"', $template);
        self::assertStringNotContainsString('<input name="city"', $template);
    }
}
