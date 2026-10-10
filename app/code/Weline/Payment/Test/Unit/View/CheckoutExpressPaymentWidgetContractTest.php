<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutExpressPaymentWidgetContractTest extends TestCase
{
    public function testExpressPaymentRegistersRequiredDefaultInjectionWithVisibilityToggle(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        $tpl = 'Weline_Payment::templates/Frontend/widgets/checkout-express-payment.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/checkout-express-payment.phtml');
        self::assertStringContainsString('@widget.code {checkout-express-payment}', $src);
        self::assertStringContainsString('@widget.slot {checkout-express-payment}', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"checkout-express-payment"', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('"enabled":true', $src);
        self::assertStringContainsString('@param enabled {default=true', $src);
    }

    public function testExpressPaymentTemplateIsShopifyLayoutAmazonStyledAndToggleAware(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-express-payment.phtml'
        );
        self::assertStringContainsString('data-testid="checkout-express-payment"', $template);
        self::assertStringContainsString('data-widget-code="checkout-express-payment"', $template);
        self::assertStringContainsString("document.querySelector('[data-payment-express]')", $template);
        self::assertStringNotContainsString(
            '[data-payment-express][data-testid="checkout-express-payment"]',
            $template,
        );
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
}
