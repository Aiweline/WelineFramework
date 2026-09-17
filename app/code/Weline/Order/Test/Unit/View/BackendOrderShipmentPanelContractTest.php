<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderShipmentPanelContractTest extends TestCase
{
    public function testPanelIsShopifyStyleMarkAsFulfilled(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/panel/shipment.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('shipment-checkout-ref', $src);
        self::assertStringContainsString('name="carrier_id"', $src);
        self::assertStringContainsString('name="tracking_number"', $src);
        self::assertStringContainsString('name="notify_customer"', $src);
        self::assertStringContainsString('fulfill_mode', $src);
        self::assertStringContainsString('shipment_action', $src);
        self::assertStringContainsString('set_channel', $src);
        self::assertStringContainsString('交由物流商履约', $src);
        self::assertStringContainsString('id="backend-order-shipments"', $src);
        self::assertStringContainsString('Weline_Order::backend::order::view::shipments', $src);
        self::assertStringNotContainsString('发货记录 / 补充运单号', $src);
        self::assertStringNotContainsString('本单待发履约单元；提交后写入仓维进度账本。', $src);
    }
}
