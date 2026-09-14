<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Service\DropshipPublishService;

final class DropshipPublishShippingDimsContractTest extends TestCase
{
    public function testCjExtractShippingDimsConvertsGramsAndMm(): void
    {
        $dims = CjProvider::extractShippingDims([
            'packingWeight' => '350.00-380.00',
            'variants' => [[
                'inventoryNum' => 2,
                'variantWeight' => 350,
                'variantLength' => 300,
                'variantWidth' => 200,
                'variantHeight' => 30,
            ]],
        ]);

        self::assertEqualsWithDelta(0.35, $dims['weight_kg'], 0.0001);
        self::assertEqualsWithDelta(30.0, $dims['length_cm'], 0.0001);
        self::assertEqualsWithDelta(20.0, $dims['width_cm'], 0.0001);
        self::assertEqualsWithDelta(3.0, $dims['height_cm'], 0.0001);
    }

    public function testSnapshotShippingRoundTripAndPublishPayload(): void
    {
        $snap = DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'cj',
            'external_spu' => 'P-SHIP',
            'title' => 'Ship me',
            'origin_currency' => 'USD',
            'origin_price_minor' => 100,
            'qty' => 1,
            'shelf_status' => 'active',
            'shipping' => [
                'weight_kg' => 0.35,
                'length_cm' => 30,
                'width_cm' => 20,
                'height_cm' => 3,
            ],
        ]);
        self::assertTrue($snap->hasShippingDims());
        self::assertSame(0.35, $snap->toArray()['shipping']['weight_kg']);

        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipPublishService.php');
        self::assertStringContainsString('applyShippingDims', $src);
        self::assertStringContainsString("\$payload['weight']", $src);
        self::assertTrue(method_exists(DropshipPublishService::class, 'publish'));
    }

    public function testMapProductDetailIncludesShipping(): void
    {
        $snap = CjProvider::mapProductDetail([
            'pid' => 'PID-1',
            'productName' => '测试裙',
            'sellPrice' => 6.77,
            'variants' => [[
                'inventoryNum' => 1,
                'variantWeight' => 350,
                'variantLength' => 300,
                'variantWidth' => 200,
                'variantHeight' => 30,
                'variantSku' => 'SKU-1',
                'vid' => 'V1',
            ]],
        ], 'CN', 'zh_Hans_CN');

        self::assertNotNull($snap);
        self::assertTrue($snap->hasShippingDims());
        self::assertEqualsWithDelta(0.35, $snap->shipping['weight_kg'], 0.0001);
    }
}
