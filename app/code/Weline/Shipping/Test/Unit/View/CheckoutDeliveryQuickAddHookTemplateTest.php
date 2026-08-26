<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutDeliveryQuickAddHookTemplateTest extends TestCase
{
    public function testQuickAddHookUsesCaptchaAndCascadingAddress(): void
    {
        $templateFile = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Checkout/frontend/widgets/checkout-delivery-context/quick-add.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('data-quick-add-form', $content);
        $this->assertStringContainsString('captcha="lazy"', $content);
        $this->assertStringNotContainsString('captcha="required"', $content);
        $this->assertStringContainsString('intent="checkout.save_delivery_address"', $content);
        $this->assertStringContainsString('<w:theme:address', $content);
        $this->assertStringContainsString('levels="province,city,district"', $content);
        $this->assertStringNotContainsString('for="country|province|city|district"', $content);
        $this->assertStringNotContainsString('levels="province|city|district"', $content);
        $this->assertStringContainsString('code="checkout-delivery-quick-add"', $content);
        $this->assertStringContainsString('data-weline-form-captcha-slot', $content);
        $this->assertStringNotContainsString('<input name="province"', $content);
        $this->assertStringNotContainsString('<input name="city"', $content);
    }
}
