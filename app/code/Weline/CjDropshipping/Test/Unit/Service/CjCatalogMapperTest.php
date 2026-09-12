<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Service\CjCatalogMapper;

final class CjCatalogMapperTest extends TestCase
{
    public function testExtractListPrefersDataListAndSkipsScalarBags(): void
    {
        $rows = CjCatalogMapper::extractList([
            'data' => [
                'pageNum' => 1,
                'total' => 2,
                'list' => [
                    ['pid' => '100', 'productNameEn' => 'Dress', 'sellPrice' => 12.5, 'warehouseInventoryNum' => 3],
                    ['productId' => '200', 'name' => 'Hat', 'price' => 4],
                ],
            ],
        ]);
        self::assertCount(2, $rows);
        self::assertSame('100', (string)$rows[0]['pid']);
    }

    public function testMapRowRequiresIdentityAndMapsAliases(): void
    {
        $snap = CjCatalogMapper::mapRow([
            'productId' => 'pid-9',
            'nameEn' => 'Blue Skirt',
            'nowPrice' => '9.99',
            'inventory' => 7,
            'sku' => 'SKU-9',
        ], 'US');
        self::assertNotNull($snap);
        self::assertSame('pid-9', $snap->externalSpu);
        self::assertSame('Blue Skirt', $snap->title);
        self::assertSame(999, $snap->originPriceMinor);
        self::assertSame(7, $snap->qty);
        self::assertSame('SKU-9', $snap->externalSku);

        self::assertNull(CjCatalogMapper::mapRow(['foo' => 'bar']));
    }

    public function testExtractListDoesNotTreatProviderMetaAsProducts(): void
    {
        $rows = CjCatalogMapper::extractList([
            'data' => [
                'code' => 'cj',
                'message' => 'ok',
            ],
        ]);
        self::assertSame([], $rows);
    }
}
