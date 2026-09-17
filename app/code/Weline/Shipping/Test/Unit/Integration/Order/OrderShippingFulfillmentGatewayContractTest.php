<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Integration\Order;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\OrderShippingFulfillmentGatewayInterface;
use Weline\Shipping\Integration\Order\OrderShippingFulfillmentGateway;

final class OrderShippingFulfillmentGatewayContractTest extends TestCase
{
    public function testGatewayImplementsOrderApi(): void
    {
        self::assertTrue(interface_exists(OrderShippingFulfillmentGatewayInterface::class));
        self::assertTrue(is_a(
            OrderShippingFulfillmentGateway::class,
            OrderShippingFulfillmentGatewayInterface::class,
            true,
        ));
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Integration/Order/OrderShippingFulfillmentGateway.php',
        );
        self::assertStringContainsString('label:', $src);
        self::assertStringContainsString('shipment_label_weight_required', $src);
        self::assertStringContainsString('ShippingLabelIdempotency', $src);
        self::assertStringContainsString('ShippingLabelOrphan', $src);
    }

    public function testModuleProvidesGatewayBinding(): void
    {
        $module = require dirname(__DIR__, 4) . '/etc/module.php';
        self::assertSame(
            OrderShippingFulfillmentGateway::class,
            $module['provides'][OrderShippingFulfillmentGatewayInterface::class] ?? null,
        );
    }
}
