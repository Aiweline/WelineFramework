<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Framework\Query\ProductStorefrontQueryProvider;

final class ProductStorefrontQueryProviderTest extends TestCase
{
    public function testDescriptorPublishesServerSideReadOnlySearch(): void
    {
        $descriptor = $this->provider()->getDescriptor();

        self::assertSame('product_storefront', $descriptor['provider']);
        self::assertSame('Weline_Product', $descriptor['module']);
        self::assertCount(1, $descriptor['operations']);
        self::assertSame('searchPublishedOffers', $descriptor['operations'][0]['name']);
        self::assertFalse($descriptor['operations'][0]['frontend']);
        self::assertFalse($descriptor['operations'][0]['external']);
        self::assertSame('read', $descriptor['operations'][0]['mode']);
        self::assertSame(
            ['keyword', 'page', 'page_size'],
            array_column($descriptor['operations'][0]['params'], 'name'),
        );
    }

    public function testSearchFiltersPaginatesAndNormalizesPublishedOffers(): void
    {
        $result = $this->provider()->execute('searchPublishedOffers', [
            'keyword' => ' j11 ',
            'page' => 1,
            'page_size' => 12,
        ]);

        self::assertSame('weline_product', $result['engine']);
        self::assertSame(1, $result['total']);
        self::assertSame('BSE J11 300cc Dirt Bike', $result['items'][0]['name']);
        self::assertSame('BSE-J11', $result['items'][0]['sku']);
        self::assertEquals(1500.0, $result['items'][0]['price']);
        self::assertSame('USD 1,500.00', $result['items'][0]['formatted_price']);
        self::assertSame('/media/bse-j11.jpg', $result['items'][0]['image']);
        self::assertSame('products/', $result['items'][0]['url']);
        self::assertSame(1, $result['pagination']['pages']);
    }

    public function testSearchClampsPaginationAndUnknownOperationFailsClosed(): void
    {
        $result = $this->provider()->execute('searchPublishedOffers', [
            'keyword' => '',
            'page' => 0,
            'page_size' => 99,
        ]);

        self::assertSame(1, $result['pagination']['page']);
        self::assertSame(48, $result['pagination']['page_size']);
        self::assertSame(2, $result['total']);

        $this->expectException(\InvalidArgumentException::class);
        $this->provider()->execute('unsafeUnknownOperation');
    }

    private function provider(): ProductStorefrontQueryProvider
    {
        return new class extends ProductStorefrontQueryProvider {
            protected function publishedOffers(): array
            {
                return [
                    [
                        'product_id' => 7,
                        'name' => 'BSE J11 300cc Dirt Bike',
                        'sku' => 'BSE-J11',
                        'unit_price_minor' => 150000,
                        'currency' => 'USD',
                    ],
                    [
                        'product_id' => 8,
                        'name' => 'KAYO TT150 Dirt Bike',
                        'sku' => 'KAYO-TT150',
                        'unit_price_minor' => 99000,
                        'currency' => 'USD',
                    ],
                ];
            }

            protected function primaryMedia(array $productIds): array
            {
                return [7 => '/media/bse-j11.jpg'];
            }
        };
    }
}
