<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderShipmentPanelContractTest extends TestCase
{
    public function testPanelRequiresTrackingAndCustomerNotify(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/panel/shipment.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('name="tracking_number"', $src);
        self::assertStringContainsString('required', $src);
        self::assertStringContainsString('name="notify_customer"', $src);
        self::assertStringContainsString('name="carrier"', $src);
        self::assertStringContainsString('data-testid="shipment-tracking-number"', $src);
        self::assertStringContainsString('平台物流单号', $src);
        self::assertStringContainsString('已发货', $src);
        self::assertStringNotContainsString('本单待发履约单元；提交后写入仓维进度账本。', $src);
    }
}
