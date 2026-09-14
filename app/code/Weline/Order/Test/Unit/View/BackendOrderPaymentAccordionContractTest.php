<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: payment/shipping methods use disclosure cards; no blank bare payment input.
 */
final class BackendOrderPaymentAccordionContractTest extends TestCase
{
    public function testEditTemplateUsesPaymentDisclosureAndSelect(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-payment-card"', $src);
        self::assertStringContainsString('data-testid="order-edit-payment-summary"', $src);
        self::assertStringContainsString('data-testid="order-edit-payment-method"', $src);
        self::assertStringContainsString('name="payment_method"', $src);
        self::assertStringContainsString('w-select', $src);
        self::assertStringContainsString('data-testid="order-edit-shipping-method-card"', $src);
        self::assertStringContainsString('data-testid="order-edit-shipping-method-label"', $src);
        self::assertStringContainsString('data-testid="order-edit-discount-items"', $src);
        self::assertStringContainsString('无优惠项目', $src);
        self::assertStringNotContainsString(
            '<input type="text" name="payment_method"',
            $src
        );
    }
}
