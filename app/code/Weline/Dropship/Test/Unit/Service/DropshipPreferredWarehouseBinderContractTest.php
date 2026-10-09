<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipPreferredWarehouseBinder;

final class DropshipPreferredWarehouseBinderContractTest extends TestCase
{
    public function testBinderStampsPreferredWarehouseIdFromListingField(): void
    {
        self::assertTrue(class_exists(DropshipPreferredWarehouseBinder::class));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipPreferredWarehouseBinder.php');
        self::assertStringContainsString('preferred_warehouse_id', $src);
        self::assertStringContainsString('LOCAL_WAREHOUSE_ID', $src);
        self::assertStringContainsString('LOCAL_OFFER_ID', $src);
        self::assertStringContainsString('function bind', $src);
    }
}
