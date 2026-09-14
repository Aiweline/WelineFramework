<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DropshipOriginReadContractTest extends TestCase
{
    public function testMapServiceReadsWarehouseShippingOrigin(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipWarehouseMapService.php');
        self::assertStringContainsString('resolveShippingAddressId', $src);
        self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
        self::assertStringContainsString('findShippingAddressId', $src);
    }
}
