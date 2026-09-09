<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\ServiceLaneMatchService;
use Weline\Shipping\Service\ShippingServiceManager;

final class ServiceLaneRelativeQuoteContractTest extends TestCase
{
    public function testShippingServiceDeclaresOriginField(): void
    {
        self::assertSame(
            'origin_shipping_address_id',
            ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID,
        );
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/ShippingService.php');
        self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
    }

    public function testServiceRegionModelAndUpgradeRegistered(): void
    {
        self::assertSame('w_shipping_service_regions', ServiceRegion::schema_table);
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('ServiceRegion::class', $upgrade);
        self::assertFileExists(dirname(__DIR__, 3) . '/Model/ServiceRegion.php');
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/ServiceLaneMatchService.php');
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/ServiceLaneAdminService.php');
    }

    public function testQuotePathUsesLaneFilter(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php');
        self::assertStringContainsString('laneMatch()', $src);
        self::assertStringContainsString('filterByOriginAndDest', $src);
        self::assertStringContainsString('destMatchesFreeShippingRegions', $src);
        self::assertStringContainsString('CONDITION_REGION', $src);
        self::assertStringContainsString('CONDITION_MIXED', $src);
        self::assertStringContainsString('service_lanes', $src);
    }

    public function testAdminCreateShippingServicePersistsLaneFields(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php');
        self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
        self::assertStringContainsString('lane_dest_selection', $src);
        self::assertStringContainsString('weight_rate', $src);
        self::assertStringContainsString('setRegionIds', $src);
    }

    public function testBackendLaneUiContracts(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/ShippingService/index.phtml',
        );
        self::assertStringContainsString('origin_shipping_address_id', $tpl);
        self::assertStringContainsString('lane_dest_selection', $tpl);
        self::assertStringContainsString('theme:address', $tpl);
        self::assertStringContainsString('shipping-service-lane-table', $tpl);

        $free = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/FreeShippingRule/index.phtml',
        );
        self::assertStringContainsString('value="region"', $free);
        self::assertStringContainsString('value="mixed"', $free);
        self::assertStringContainsString('free_region_selection', $free);

        $rate = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml',
        );
        self::assertStringContainsString('weight_rate', $rate);
        self::assertStringContainsString('volume_rate', $rate);
        self::assertStringContainsString('quantity_rate', $rate);
        self::assertStringContainsString('勿在模板里配地区矩阵', $rate);

        self::assertFileExists(
            dirname(__DIR__, 3) . '/view/prototypes/lane-relative-quote-ui.html',
        );
    }

    public function testLaneMatchSpecificityOrderingLogicPresent(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ServiceLaneMatchService.php');
        self::assertStringContainsString('SPEC_PROVINCE', $src);
        self::assertStringContainsString('SPEC_COUNTRY', $src);
        self::assertStringContainsString('resolveDefaultOriginId', $src);
        self::assertTrue(class_exists(ServiceLaneMatchService::class));
        self::assertTrue(class_exists(ShippingServiceManager::class));
    }
}
