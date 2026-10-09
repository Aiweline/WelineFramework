<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrderShipmentsSlotContractTest extends TestCase
{
    public function testAccountOrderDetailProvidesEmptyShipmentsSlot(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="account-order-shipments"', $source);
        self::assertStringContainsString(
            'Weline_Order::frontend::account::order-detail::shipments',
            $source
        );
        self::assertStringContainsString('account_shipment_order_uuid', $source);
        self::assertStringContainsString('data-testid="account-order-split-detail-link"', $source);
        self::assertStringNotContainsString('BackendOrderShipmentsService', $source);
        self::assertStringNotContainsString('listForOrderId', $source);
        self::assertStringNotContainsString('OrderShipment::', $source);
        self::assertStringNotContainsString('getShipments(', $source);
    }

    public function testOrderDeclaresAccountShipmentsHook(): void
    {
        $path = dirname(__DIR__, 3) . '/hook.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            "'Weline_Order::frontend::account::order-detail::shipments'",
            $source
        );
        self::assertFileExists(
            dirname(__DIR__, 3)
            . '/doc/hook/frontend/account/order-detail/shipments.md'
        );
    }
}
