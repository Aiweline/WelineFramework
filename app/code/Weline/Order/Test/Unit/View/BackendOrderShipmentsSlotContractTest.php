<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderShipmentsSlotContractTest extends TestCase
{
    public function testOrderDetailProvidesEmptyShipmentsSlotWithoutHardcodedShipmentTable(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="backend-order-shipments"', $source);
        self::assertStringContainsString(
            'accept="backend-order-shipments,order-shipments,shipping-shipments,shipping"',
            $source
        );
        self::assertStringContainsString(
            'Weline_Order::backend::order::view::shipments',
            $source
        );
        self::assertStringNotContainsString('OrderShipment::schema_fields_TRACKING_NUMBER', $source);
        self::assertStringNotContainsString("getData('shipments')", $source);
    }

    public function testOrderShipmentPanelProvidesEmptyShipmentsSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/panel/shipment.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="backend-order-shipments"', $source);
        self::assertStringContainsString(
            'Weline_Order::backend::order::view::shipments',
            $source
        );
        self::assertStringNotContainsString('发货记录 / 补充运单号', $source);
    }

    public function testOrderListProvidesShippingSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/index.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="backend-order-list-shipping"', $source);
        self::assertStringContainsString(
            'Weline_Order::backend::order::list::shipping',
            $source
        );
    }

    public function testOrderEditShipmentPanelProvidesShipmentsSlotOnly(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/panel/shipment.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="backend-order-shipments"', $source);
        self::assertStringContainsString(
            'Weline_Order::backend::order::view::shipments',
            $source
        );
        self::assertStringNotContainsString('data-testid="shipment-row"', $source);
        self::assertStringContainsString('履约进度账本', $source);
    }

    public function testOrderEditPageDoesNotHardcodeShipmentsTable(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringNotContainsString('data-testid="order-edit-shipments"', $source);
        self::assertStringNotContainsString('OrderShipment::schema_fields_TRACKING_NUMBER', $source);
    }

    public function testOrderDeclaresShipmentsHooksAndDocs(): void
    {
        $path = dirname(__DIR__, 3) . '/hook.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            "'Weline_Order::backend::order::view::shipments'",
            $source
        );
        self::assertStringContainsString(
            "'Weline_Order::backend::order::list::shipping'",
            $source
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/doc/hook/backend/order/view/shipments.md'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/doc/hook/backend/order/list/shipping.md'
        );
    }
}
