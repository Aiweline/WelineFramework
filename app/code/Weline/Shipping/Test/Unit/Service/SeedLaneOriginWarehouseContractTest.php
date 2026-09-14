<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;

final class SeedLaneOriginWarehouseContractTest extends TestCase
{
    public function testSeedLanesBindCurrentWarehouseShippingAddress(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php');
        self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
        self::assertStringContainsString('resolveCurrentWarehouseOriginId', $src);
        self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
        self::assertStringContainsString('ensureSeedShippingAddressFromWarehouse', $src);
        self::assertStringContainsString('resolveCurrentWarehouseContext', $src);
        // 禁止继续以 0/空 origin 通配异地发货
        self::assertStringNotContainsString(
            "schema_fields_ORIGIN_SHIPPING_ADDRESS_ID => 0",
            $src,
        );
        self::assertTrue(class_exists(DefaultShippingLaneSeedService::class));
    }
}
