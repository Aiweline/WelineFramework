<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;

final class CjProviderContractTest extends TestCase
{
    public function testCodeIsCj(): void
    {
        $p = new CjProvider();
        self::assertSame('cj', $p->getCode());
        self::assertArrayHasKey('catalog', $p->getCapabilities());
        self::assertSame('Weline_CjDropshipping', $p->getDisplayMetadata()['module']);
    }

    public function testExtractProductRowsPrefersDataListAndSkipsMeta(): void
    {
        $rows = CjProvider::extractProductRows([
            'data' => [
                'pageNum' => 1,
                'total' => 1,
                'list' => [
                    [
                        'pid' => 'P100',
                        'productNameEn' => 'Demo Tee',
                        'sellPrice' => 12.5,
                        'warehouseInventoryNum' => 9,
                    ],
                ],
            ],
        ]);
        self::assertCount(1, $rows);
        self::assertSame('P100', $rows[0]['pid']);
    }

    public function testExtractProductRowsUnwrapsListV2ContentProductList(): void
    {
        $rows = CjProvider::extractProductRows([
            'data' => [
                'pageSize' => 20,
                'pageNumber' => 1,
                'totalRecords' => 2,
                'content' => [
                    [
                        'productList' => [
                            [
                                'id' => '2408110607421607500',
                                'nameEn' => 'Cashmere Scarf',
                                'sku' => 'CJYD2107553',
                                'sellPrice' => '5.14',
                                'warehouseInventoryNum' => 1,
                                'categoryId' => '0DC4DF6F-4EC5-47DF-B20D-863ADF69319F',
                            ],
                            [
                                'id' => '2004063728206499841',
                                'nameEn' => 'Extra Long Scarf',
                                'sku' => 'CJWJ2688760',
                                'sellPrice' => '9.50',
                                'warehouseInventoryNum' => 133,
                            ],
                        ],
                        'relatedCategoryList' => [],
                        'keyWord' => null,
                    ],
                ],
            ],
        ]);
        self::assertCount(2, $rows);
        self::assertSame('2408110607421607500', $rows[0]['id']);
        self::assertSame('Cashmere Scarf', $rows[0]['nameEn']);
        $snap = CjProvider::mapProductRow($rows[0], 'US', 'en_US');
        self::assertNotNull($snap);
        self::assertSame('2408110607421607500', $snap->externalSpu);
        self::assertSame('Cashmere Scarf', $snap->title);
    }

    public function testMapProductRowMapsBigImageIntoMedia(): void
    {
        $snap = CjProvider::mapProductRow([
            'id' => 'IMG-1',
            'nameEn' => 'Scarves',
            'bigImage' => 'https://cf.cjdropshipping.com/demo.jpg',
            'sellPrice' => 1,
        ], 'US', 'en_US');
        self::assertNotNull($snap);
        self::assertSame('https://cf.cjdropshipping.com/demo.jpg', $snap->media[0]['url'] ?? null);
        self::assertSame('thumb', $snap->media[0]['role'] ?? null);
    }

    public function testMapProductRowRequiresIdentityAndMapsAliases(): void
    {
        $snap = CjProvider::mapProductRow([
            'productId' => 'CJ-9',
            'name' => 'Hanfu Skirt',
            'price' => 8.2,
            'stock' => 3,
        ], 'US');
        self::assertNotNull($snap);
        self::assertSame('CJ-9', $snap->externalSpu);
        self::assertSame('Hanfu Skirt', $snap->title);
        self::assertSame(820, $snap->originPriceMinor);
        self::assertSame(3, $snap->qty);

        self::assertNull(CjProvider::mapProductRow(['pageNum' => 1, 'total' => 0]));
    }

    public function testMapProductRowLocalizesTitleByLocale(): void
    {
        $row = [
            'pid' => 'P1',
            'productName' => '["汉服裙"]',
            'productNameEn' => 'Hanfu Skirt',
            'sellPrice' => 1,
            'currency' => 'USD',
        ];
        $zh = CjProvider::mapProductRow($row, 'US', 'zh_Hans_CN');
        $en = CjProvider::mapProductRow($row, 'US', 'en_US');
        self::assertSame('汉服裙', $zh?->title);
        self::assertSame('Hanfu Skirt', $en?->title);
        self::assertSame('USD', $zh?->originCurrency);
    }

    public function testMapProductRowPrefersEnWhenZhFieldIsLatinOnly(): void
    {
        $row = [
            'pid' => 'P2',
            'productName' => '["Big And Tall Men\'s Jeans"," Leg "]',
            'productNameEn' => 'Big And Tall Men\'s Jeans Relaxed Straight Leg Full Title',
            'sellPrice' => 20.68,
        ];
        $zh = CjProvider::mapProductRow($row, 'US', 'zh_Hans_CN');
        self::assertSame('Big And Tall Men\'s Jeans Relaxed Straight Leg Full Title', $zh?->title);
        self::assertSame('USD', $zh?->originCurrency);
        self::assertSame(2068, $zh?->originPriceMinor);
    }

    public function testFlattenCategoryTreeLocalizesViaLocaleOverlay(): void
    {
        $tree = [[
            'categoryFirstName' => "Women's Clothing",
            'categoryFirstList' => [[
                'categorySecondName' => 'Accessories',
                'categorySecondList' => [[
                    'categoryId' => '0DC4DF6F-4EC5-47DF-B20D-863ADF69319F',
                    'categoryName' => 'Scarves & Wraps',
                ]],
            ]],
        ]];
        $flat = CjProvider::flattenCategoryTree($tree);
        $zh = \Weline\CjDropshipping\Service\CjCategoryLocalizer::localizeNodes($flat, 'zh_Hans_CN');
        $en = \Weline\CjDropshipping\Service\CjCategoryLocalizer::localizeNodes($flat, 'en_US');
        self::assertSame('围巾与披肩', $zh[0]['name'] ?? null);
        self::assertStringContainsString('女装', (string)($zh[0]['path'] ?? ''));
        self::assertSame('Scarves & Wraps', $en[0]['name'] ?? null);
    }

    public function testBuildCreateOrderPayloadSetsIsSandboxWhenEnabled(): void
    {
        $command = [
            'order_uuid' => 'ord-sandbox-1',
            'from_country_code' => 'CN',
            'storage_id' => 'AREA-1',
            'lines' => [
                ['external_sku' => 'VID-9', 'qty' => 2, 'line_key' => 'L1'],
            ],
            'shipping' => [
                'country_code' => 'US',
                'province' => 'CA',
                'city' => 'LA',
                'street' => '1 Main',
                'name' => 'Tester',
                'phone' => '123',
                'zip' => '90001',
            ],
        ];
        $live = CjProvider::buildCreateOrderPayload($command, false);
        self::assertArrayNotHasKey('isSandbox', $live);
        // AREA-1 不是合法 storage UUID → 降级商家物流，不传 storageId
        self::assertArrayNotHasKey('storageId', $live);
        self::assertSame(2, $live['shopLogisticsType'] ?? null);
        self::assertSame('VID-9', $live['products'][0]['sku'] ?? null);
        self::assertSame('CJPacket Ordinary', $live['logisticName'] ?? null);
        self::assertSame('US', $live['shippingCountry'] ?? null);
        self::assertSame('CN', $live['fromCountryCode'] ?? null);

        $sandbox = CjProvider::buildCreateOrderPayload($command, true);
        self::assertSame(1, $sandbox['isSandbox'] ?? null);
        self::assertSame('ord-sandbox-1', $sandbox['orderNumber'] ?? null);
    }

    public function testBuildCreateOrderPayloadForcesCnFromCountryOnSellerLogistics(): void
    {
        $payload = CjProvider::buildCreateOrderPayload([
            'order_uuid' => 'o-us-map',
            'from_country_code' => 'US',
            'storage_id' => '2',
            'lines' => [['external_sku' => 'CJYD3153518', 'qty' => 1]],
            'shipping' => ['country_code' => 'US', 'name' => 'A', 'street' => 'B', 'city' => 'C', 'province' => 'D'],
        ], true);
        self::assertSame(2, $payload['shopLogisticsType'] ?? null);
        self::assertArrayNotHasKey('storageId', $payload);
        self::assertSame('CN', $payload['fromCountryCode'] ?? null);
        self::assertSame(1, $payload['isSandbox'] ?? null);
    }

    public function testIsCjRateLimitedDetectsQps(): void
    {
        self::assertTrue(CjProvider::isCjRateLimited(['code' => 1600200, 'message' => 'Too Many Requests']));
        self::assertFalse(CjProvider::isCjRateLimited(['code' => 200, 'message' => 'Success']));
    }

    public function testBuildCreateOrderPayloadUsesSellerLogisticsWithoutStorage(): void
    {
        $payload = CjProvider::buildCreateOrderPayload([
            'order_uuid' => 'o2',
            'lines' => [['external_sku' => '92511400-C758-4474-93CA-66D442F5F787', 'qty' => 1]],
            'shipping' => ['country_code' => 'US', 'name' => 'A', 'street' => 'B', 'city' => 'C', 'province' => 'D'],
        ], true);
        self::assertSame(2, $payload['shopLogisticsType'] ?? null);
        self::assertArrayNotHasKey('storageId', $payload);
        self::assertSame('92511400-C758-4474-93CA-66D442F5F787', $payload['products'][0]['vid'] ?? null);
        self::assertArrayNotHasKey('sku', $payload['products'][0]);
        self::assertSame(1, $payload['isSandbox'] ?? null);
    }

    public function testMapCreateOrderProductLineTreatsNumericVidAsVid(): void
    {
        $line = CjProvider::mapCreateOrderProductLine([
            'external_sku' => '2609110854341615800',
            'qty' => 1,
            'line_key' => 'L1',
        ]);
        self::assertSame('2609110854341615800', $line['vid'] ?? null);
        self::assertArrayNotHasKey('sku', $line);
    }

    public function testPickVariantIdFromProductDataPrefersMatchingSku(): void
    {
        $vid = CjProvider::pickVariantIdFromProductData([
            'productSku' => 'CJYD3153518',
            'variants' => [
                ['vid' => '2609110854341615800', 'variantSku' => 'CJYD315351801AZ'],
            ],
        ], 'CJYD3153518');
        self::assertSame('2609110854341615800', $vid);
    }

    public function testSandboxAdvanceStepsOrder(): void
    {
        $steps = CjProvider::sandboxAdvanceSteps('OID-1', 'SHIP-1', 'SBXTRACK1');
        self::assertCount(6, $steps);
        self::assertSame('/shopping/order/confirmOrder', $steps[0]['path']);
        self::assertSame('PATCH', $steps[0]['method'] ?? null);
        self::assertSame('/shopping/sandbox/simulatePay', $steps[1]['path']);
        self::assertSame('SHIP-1', $steps[1]['body']['shipmentOrderId'] ?? null);
        self::assertSame('/shopping/sandbox/updateTrackNumber', $steps[2]['path']);
        self::assertSame('SBXTRACK1', $steps[2]['body']['trackNumber'] ?? null);
        self::assertSame([400, 500, 600], [
            $steps[3]['body']['targetStatus'] ?? null,
            $steps[4]['body']['targetStatus'] ?? null,
            $steps[5]['body']['targetStatus'] ?? null,
        ]);
    }

    public function testIsDuplicateCreateMessageDetectsCjIdempotent(): void
    {
        self::assertTrue(CjProvider::isDuplicateCreateMessage('Order exist, please do not duplicate create'));
        self::assertTrue(CjProvider::isDuplicateCreateMessage('do not duplicate create'));
        self::assertFalse(CjProvider::isDuplicateCreateMessage('Logistic not found'));
        self::assertFalse(CjProvider::isDuplicateCreateMessage(''));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Dropship/DropshipProvider/CjProvider.php');
        self::assertStringContainsString('recoverExistingCreate', $src);
        self::assertStringContainsString('recovered_existing', $src);
        self::assertStringContainsString('isDuplicateCreateMessage', $src);
    }

    public function testConfigSchemaDeclaresOrderSandboxField(): void
    {
        $schema = (new CjProvider())->getConfigSchema();
        self::assertContains('order_sandbox', $schema['fields'] ?? []);
        $phtml = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/cj.phtml');
        self::assertStringContainsString('dropship/channel/cj/order_sandbox', $phtml);
        self::assertStringContainsString('订单沙盒', $phtml);
    }
}
