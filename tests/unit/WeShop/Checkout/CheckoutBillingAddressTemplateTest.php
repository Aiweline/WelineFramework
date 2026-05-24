<?php

declare(strict_types=1);

namespace Tests\Unit\WeShop\Checkout;

use PHPUnit\Framework\TestCase;

final class CheckoutBillingAddressTemplateTest extends TestCase
{
    public function testCheckoutPageContainsBillingAddressControls(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/code/WeShop/Checkout/view/templates/frontend/checkout/index.phtml'
        );

        self::assertStringContainsString('name="billing_same_as_shipping"', $template);
        self::assertStringContainsString('data-weshop-billing-same-toggle', $template);
        self::assertStringContainsString('data-weshop-billing-address-list', $template);
        self::assertStringContainsString('name="billing_address_id"', $template);
        self::assertStringContainsString('name="billing_address[firstname]"', $template);
        self::assertStringContainsString('syncBillingAddressRequiredState()', $template);
    }
}
