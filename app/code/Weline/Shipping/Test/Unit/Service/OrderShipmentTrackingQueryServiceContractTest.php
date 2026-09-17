<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\OrderShipmentTrackingQueryService;

final class OrderShipmentTrackingQueryServiceContractTest extends TestCase
{
    public function testServiceExposesShipmentScopedQueryApi(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OrderShipmentTrackingQueryService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('queryByShipmentId', $src);
        self::assertStringContainsString('ShippingFacade', $src);
        self::assertStringContainsString('queryTracking', $src);
        self::assertStringContainsString('buildProgressLines', $src);
        self::assertStringContainsString('tracking_url_template', $src);
    }

    public function testTrackingControllerExposesQueryAction(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Tracking.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('function query', $src);
        self::assertStringContainsString('OrderShipmentTrackingQueryService', $src);
        self::assertStringContainsString('application/json', $src);
    }
}
