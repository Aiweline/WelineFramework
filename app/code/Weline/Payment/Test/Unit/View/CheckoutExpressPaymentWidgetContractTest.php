<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutExpressPaymentWidgetContractTest extends TestCase
{
    public function testExpressPaymentRegistersRequiredDefaultInjectionWithVisibilityToggle(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('checkout-express-payment', $widgets);
        $widget = $widgets['checkout-express-payment'];
        self::assertSame('checkout-express-payment', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Payment::templates/frontend/widgets/checkout-express-payment.phtml',
            $widget['template'] ?? null
        );
        self::assertTrue((bool)($widget['params']['enabled']['default'] ?? false));
        self::assertSame('bool', $widget['params']['enabled']['type'] ?? null);
        self::assertSame('image', $widget['params']['logo']['type'] ?? null);

        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('checkout', $injection['layout_type'] ?? null);
        self::assertSame('checkout-express-payment', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertTrue((bool)($injection['config']['enabled'] ?? false));
        self::assertSame('paypal', $injection['config']['method_code'] ?? null);
    }

    public function testExpressPaymentTemplateIsShopifyLayoutAmazonStyledAndToggleAware(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-express-payment.phtml'
        );
        self::assertStringContainsString('data-testid="checkout-express-payment"', $template);
        self::assertStringContainsString('data-testid="checkout-express-paypal"', $template);
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
        self::assertStringContainsString("getData('logo')", $template);
        self::assertStringContainsString('w-payment-express__logo-img', $template);
        self::assertStringNotContainsString('.w-payment-express__paypal {\n    display: inline-flex', $template);
        self::assertStringNotContainsString('<w:widget', $template);
    }
}
