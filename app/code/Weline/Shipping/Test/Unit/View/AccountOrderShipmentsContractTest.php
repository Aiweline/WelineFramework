<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrderShipmentsContractTest extends TestCase
{
    public function testShippingProvidesAccountOrderShipmentsHook(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/frontend/account/order-detail/shipments.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            'Weline_Shipping::templates/Frontend/account/order-shipments.phtml',
            $source
        );
        self::assertStringContainsString('Weline_Shipping::css/account-order-shipments.css', $source);
    }

    public function testAccountTemplateIsReadOnlyAndUsesShipmentsService(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/Frontend/account/order-shipments.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('BackendOrderShipmentsService', $source);
        self::assertStringContainsString('listForOrderId', $source);
        self::assertStringContainsString('OrderShipmentTrackingQueryService', $source);
        self::assertStringContainsString('account_shipment_order_uuid', $source);
        self::assertStringContainsString('data-testid="account-order-shipments"', $source);
        self::assertStringContainsString('data-testid="account-order-shipments-count"', $source);
        self::assertStringContainsString('account-order-shipment-contents', $source);
        self::assertStringContainsString('包裹内商品', $source);
        self::assertStringContainsString('本单发货记录', $source);
        self::assertStringContainsString('本子订单暂无发货记录', $source);
        self::assertStringContainsString('data-testid="account-order-shipment-row"', $source);
        self::assertStringContainsString('data-testid="account-order-shipment-tracking"', $source);
        self::assertStringContainsString('data-testid="account-order-shipment-progress"', $source);
        self::assertStringNotContainsString('办理发货', $source);
        self::assertStringNotContainsString('backend/order/edit', $source);
        self::assertStringNotContainsString('showOpsLink', $source);
    }
}
