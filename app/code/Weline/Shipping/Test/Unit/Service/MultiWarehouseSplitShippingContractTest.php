<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\SplitShippingQuote;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Service\SplitShippingQuoteService;
use Weline\Shipping\Service\WarehouseLaneCopyService;
use Weline\Shipping\Service\WarehouseShippingOriginService;

final class MultiWarehouseSplitShippingContractTest extends TestCase
{
    public function testOriginInterfaceAndServiceExist(): void
    {
        self::assertTrue(interface_exists(WarehouseShippingOriginInterface::class));
        self::assertTrue(class_exists(WarehouseShippingOriginService::class));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/WarehouseShippingOriginService.php');
        self::assertStringContainsString('requireShippingAddressId', $src);
        self::assertStringContainsString('bind(', $src);
        self::assertStringContainsString('website:0', $src);
        self::assertStringContainsString('$candidates[] = 0', $src);
    }

    public function testSplitQuoteHashCoversPackagesNotOnlyTotal(): void
    {
        $req = new ShippingQuoteRequest(
            scope: ['website_id' => 1, 'store_id' => 1],
            address: ['country_code' => 'CN', 'province' => 'Zhejiang', 'city' => 'Hangzhou'],
            lines: [['line_uuid' => 'a', 'sku' => 's1', 'qty_minor' => 1000, 'unit_price_minor' => 100]],
            currency: 'CNY',
        );
        $packagesA = [[
            'split_key' => 'wh:1',
            'warehouse_id' => 1,
            'service_code' => 'STD',
            'amount_minor' => 500,
            'lines' => [['line_uuid' => 'a', 'sku' => 's1']],
        ]];
        $packagesB = [[
            'split_key' => 'wh:2',
            'warehouse_id' => 2,
            'service_code' => 'STD',
            'amount_minor' => 500,
            'lines' => [['line_uuid' => 'a', 'sku' => 's1']],
        ]];
        $h1 = SplitShippingQuote::buildRequestHash($req, 'WLS_STD', $packagesA, 500);
        $h2 = SplitShippingQuote::buildRequestHash($req, 'WLS_STD', $packagesB, 500);
        self::assertNotSame($h1, $h2);
    }

    public function testSplitServiceAndLaneCopyRegisteredInModule(): void
    {
        $module = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        self::assertStringContainsString('SplitShippingQuoteServiceInterface', $module);
        self::assertStringContainsString('WarehouseShippingOriginInterface', $module);
        self::assertStringContainsString('2.4.89', $module);
        self::assertTrue(class_exists(SplitShippingQuoteService::class));
        self::assertTrue(class_exists(WarehouseLaneCopyService::class));
        $mgr = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php');
        self::assertStringContainsString('originShippingAddressId', $mgr);
        self::assertStringContainsString('shipping_profile_conflict', $mgr);
    }

    public function testAdminCopyAndBindSurfacesExist(): void
    {
        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/ShippingService.php');
        self::assertStringContainsString('bindWarehouseOrigin', $ctrl);
        self::assertStringContainsString('copyDefaultLanes', $ctrl);
        $view = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/ShippingService/index.phtml');
        self::assertStringContainsString('copy-default-lanes-btn', $view);
        self::assertStringContainsString('仓 ↔ 发货地址绑定', $view);
        $rate = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml');
        self::assertStringContainsString('按距离', $rate);
        self::assertStringContainsString('相加', $rate);
    }
}
