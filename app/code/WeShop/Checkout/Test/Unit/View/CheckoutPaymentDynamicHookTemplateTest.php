<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class CheckoutPaymentDynamicHookTemplateTest extends TestCase
{
    public function testShippingMethodHookTemplateReadsShippingMethodsFromPageData(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Checkout/frontend/partials/checkout/shipping-methods.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getData('shipping_methods')", $template);
        $this->assertStringContainsString('name="shipping_method"', $template);
        $this->assertStringContainsString('data-weshop-shipping-method-list', $template);
    }

    public function testPaymentMethodHookTemplateReadsPaymentMethodsFromPageData(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-methods.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getData('payment_methods')", $template);
        $this->assertStringContainsString('name="payment_method"', $template);
        $this->assertStringContainsString('data-weshop-payment-method-list', $template);
    }

    public function testPaymentDetailHookTemplateBindsToSelectedPaymentMethod(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-details.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getData('payment_methods')", $template);
        $this->assertStringContainsString('data-weshop-payment-detail-list', $template);
        $this->assertStringContainsString('input[name="payment_method"]', $template);
    }

    public function testCheckoutLayoutPaymentContentHookTemplateUsesDynamicMethods(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Checkout/frontend/layouts/checkout/payment-content.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("getData('payment_methods')", $template);
        $this->assertStringContainsString('name="payment_method"', $template);
        $this->assertStringContainsString('data-weshop-layout-payment-guidance', $template);
    }
}
