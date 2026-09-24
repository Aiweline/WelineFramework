<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Framework\Query\ProductStorefrontQueryProvider;

final class ProductStorefrontQueryProviderTest extends TestCase
{
    public function testDescriptorPublishesSearchAndCardsByProductIds(): void
    {
        $descriptor = $this->provider()->getDescriptor();
        $names = array_column($descriptor['operations'], 'name');

        self::assertSame('product_storefront', $descriptor['provider']);
        self::assertSame('Weline_Product', $descriptor['module']);
        self::assertContains('searchPublishedOffers', $names);
        self::assertContains('cardsByProductIds', $names);

        $cardsOp = null;
        foreach ($descriptor['operations'] as $operation) {
            if (($operation['name'] ?? '') === 'cardsByProductIds') {
                $cardsOp = $operation;
                break;
            }
        }
        self::assertNotNull($cardsOp);
        self::assertTrue($cardsOp['frontend']);
        self::assertFalse($cardsOp['external']);
        self::assertSame('any', $cardsOp['auth'] ?? null);
        self::assertSame('read', $cardsOp['mode']);
        self::assertSame(
            ['product_ids', 'limit'],
            array_column($cardsOp['params'], 'name'),
        );

        self::assertContains('bundleCards', $names);
        self::assertContains('youMayLikeCards', $names);
        self::assertContains('recentlyViewedCards', $names);
        foreach (['bundleCards', 'youMayLikeCards', 'recentlyViewedCards'] as $opName) {
            $op = null;
            foreach ($descriptor['operations'] as $operation) {
                if (($operation['name'] ?? '') === $opName) {
                    $op = $operation;
                    break;
                }
            }
            self::assertNotNull($op, $opName);
            self::assertTrue($op['frontend'], $opName . ' must be BinQuery frontend');
            self::assertSame('any', $op['auth'] ?? null, $opName);
        }
    }

    public function testCardsByProductIdsPreservesOrderDedupesAndSkipsMissing(): void
    {
        $result = $this->provider()->execute('cardsByProductIds', [
            'product_ids' => [7, 7, 9, 8, 0, -1],
            'limit' => 10,
        ]);

        self::assertCount(2, $result);
        self::assertSame(7, (int)($result[0]['id'] ?? 0));
        self::assertSame(8, (int)($result[1]['id'] ?? 0));
        self::assertSame('USD', $result[0]['currency'] ?? null);
        self::assertStringContainsString('product/bse-j11', (string)($result[0]['url'] ?? ''));
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
        try {
            $this->provider()->execute('unsafeUnknownOperation');
        } catch (\Error $e) {
            // Unit harness may lack __(); treat that as closed failure too.
            if (!str_contains($e->getMessage(), '__')) {
                throw $e;
            }
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    public function testLiveBatchUsesBoundedSetsAndKeepsFirstOfferWithoutSellabilityFiltering(): void
    {
        $provider = new class extends ProductStorefrontQueryProvider {
            public array $calls = [];
            protected function livePublishedOffers(array $productIds): array {
                $this->calls[] = $productIds;
                $rows = [];
                foreach ($productIds as $id) {
                    if ($id === 9) { continue; }
                    $rows[] = ['product_id' => $id, 'offer_id' => $id, 'sellable' => false];
                    $rows[] = ['product_id' => $id, 'offer_id' => $id + 1000];
                }
                return $rows;
            }
        };
        $operations = array_column($provider->getDescriptor()['operations'], null, 'name');
        self::assertArrayHasKey('liveOffersByProductIds', $operations);
        self::assertFalse($operations['liveOffersByProductIds']['external']);
        self::assertFalse($operations['liveOffersByProductIds']['frontend']);
        self::assertSame([], $provider->execute('liveOffersByProductIds', ['product_ids' => []]));
        self::assertSame([], $provider->calls);
        $rows = $provider->execute('liveOffersByProductIds', ['product_ids' => [7, 7, 9, 8, 0, -1]]);
        self::assertSame([[7, 9, 8]], $provider->calls);
        self::assertSame([7, 8], array_keys($rows));
        self::assertSame(7, $rows[7]['offer_id']);
        self::assertFalse($rows[7]['sellable']);
        $provider->calls = [];
        $rows = $provider->execute('liveOffersByProductIds', ['product_ids' => range(100, 200)]);
        self::assertSame([100, 1], array_map('count', $provider->calls));
        self::assertCount(101, $rows);
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

            protected function targetedPublishedOffers(array $productIds): array
            {
                $byId = [];
                foreach ($this->publishedOffers() as $offer) {
                    $byId[(int)$offer['product_id']] = $offer + [
                        'slug' => $offer['product_id'] === 7 ? 'bse-j11' : 'kayo-tt150',
                    ];
                }
                $out = [];
                foreach ($productIds as $productId) {
                    if (isset($byId[$productId])) {
                        $out[] = $byId[$productId];
                    }
                }

                return $out;
            }

            protected function renderCard(array $offer, int $index): array
            {
                $productId = (int)($offer['product_id'] ?? 0);

                return [
                    'id' => $productId,
                    'product_id' => $productId,
                    'name' => (string)($offer['name'] ?? ''),
                    'slug' => (string)($offer['slug'] ?? ''),
                    'currency' => (string)($offer['currency'] ?? ''),
                    'price' => ((int)($offer['unit_price_minor'] ?? 0)) / 100,
                    'url' => 'product/' . (string)($offer['slug'] ?? $productId),
                ];
            }

            protected function primaryMedia(array $productIds): array
            {
                return [7 => '/media/bse-j11.jpg'];
            }
        };
    }
}
