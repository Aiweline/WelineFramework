<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit;

use Weline\Framework\Test\TestCore;
use Weline\Shipping\Model\ShippingService;

final class ZoneRemovalContractTest extends TestCore
{
    public function testZoneDomainFilesRemoved(): void
    {
        $base = BP . 'app/code/Weline/Shipping/';
        self::assertFileDoesNotExist($base . 'Model/Zone.php');
        self::assertFileDoesNotExist($base . 'Model/ZoneRegion.php');
        self::assertFileDoesNotExist($base . 'Service/ZoneService.php');
        self::assertFileDoesNotExist($base . 'Controller/Backend/Zone.php');
        self::assertFileDoesNotExist($base . 'view/templates/Backend/Zone/index.phtml');
    }

    public function testShippingServiceHasNoZoneIdField(): void
    {
        $ref = new \ReflectionClass(ShippingService::class);
        self::assertFalse($ref->hasConstant('schema_fields_ZONE_ID'));
        $src = (string)file_get_contents($ref->getFileName());
        self::assertStringNotContainsString("'zone_id'", $src);
    }

    public function testMenuAndManagerDropZone(): void
    {
        $menu = (string)file_get_contents(BP . 'app/code/Weline/Shipping/etc/backend/menu.xml');
        self::assertStringNotContainsString('Weline_Shipping::zone', $menu);
        self::assertStringNotContainsString('shipping/backend/zone', $menu);

        $manager = (string)file_get_contents(BP . 'app/code/Weline/Shipping/Controller/Backend/Manager.php');
        self::assertStringNotContainsString("'zone'", $manager);

        $ssm = (string)file_get_contents(BP . 'app/code/Weline/Shipping/Service/ShippingServiceManager.php');
        self::assertStringNotContainsString('ZoneService', $ssm);
        self::assertStringNotContainsString('matchZoneByAddress', $ssm);
    }
}
