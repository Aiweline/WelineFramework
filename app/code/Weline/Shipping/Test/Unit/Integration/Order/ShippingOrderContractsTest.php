<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Integration\Order;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\OrderShippingFulfillmentGatewayInterface;
use Weline\Shipping\Integration\Order\OrderShippingFulfillmentGateway;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingOrderContractsPass().
 */
final class ShippingOrderContractsTest extends TestCase
{
    public function testAllShippingOrderContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'OrderShippingFulfillmentGateway::testGatewayImplementsOrderApi' => function () {
            $this->assert_OrderShippingFulfillmentGateway_testGatewayImplementsOrderApi();
        },
            'OrderShippingFulfillmentGateway::testModuleProvidesGatewayBinding' => function () {
            $this->assert_OrderShippingFulfillmentGateway_testModuleProvidesGatewayBinding();
        },
        ];
    }

private function assert_OrderShippingFulfillmentGateway_testGatewayImplementsOrderApi(): void
{
    self::assertTrue(interface_exists(OrderShippingFulfillmentGatewayInterface::class));
    self::assertTrue(is_a(OrderShippingFulfillmentGateway::class, OrderShippingFulfillmentGatewayInterface::class, true));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Integration/Order/OrderShippingFulfillmentGateway.php');
    self::assertStringContainsString('label:', $src);
    self::assertStringContainsString('shipment_label_weight_required', $src);
    self::assertStringContainsString('ShippingLabelIdempotency', $src);
    self::assertStringContainsString('ShippingLabelOrphan', $src);
}

private function assert_OrderShippingFulfillmentGateway_testModuleProvidesGatewayBinding(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php';
    self::assertSame(OrderShippingFulfillmentGateway::class, $module['provides'][OrderShippingFulfillmentGatewayInterface::class] ?? null);
}
}
