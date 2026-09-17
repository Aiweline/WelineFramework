<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderCheckoutContactFields;

final class OrderCheckoutContactFieldsTest extends TestCase
{
    public function testProjectsGuestEmailIntoShippingAndCustomerFields(): void
    {
        $projected = OrderCheckoutContactFields::project(
            [
                'name' => 'Sandbox Buyer',
                'phone' => '14085551234',
                'address1' => '1 Main St',
            ],
            ['guest_email' => 'buyer@example.com'],
        );

        self::assertSame('buyer@example.com', $projected['email']);
        self::assertSame('Sandbox Buyer', $projected['name']);
        self::assertSame('14085551234', $projected['phone']);
        self::assertSame('buyer@example.com', $projected['shipping_address']['email'] ?? null);
    }

    public function testPrefersShippingEmailOverInvalidGuest(): void
    {
        $projected = OrderCheckoutContactFields::project(
            ['email' => 'ship@example.com'],
            ['guest_email' => 'not-an-email'],
        );

        self::assertSame('ship@example.com', $projected['email']);
    }

    public function testAcceptsPayerEmailFallback(): void
    {
        $projected = OrderCheckoutContactFields::project(
            [],
            ['payer_email' => 'payer@paypal.test'],
        );

        self::assertSame('payer@paypal.test', $projected['email']);
    }
}
