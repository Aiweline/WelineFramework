<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\CatalogCollector;
use Weline\Product\Service\Hanfu1688\FactoryPageParser;
use Weline\Product\Service\Hanfu1688\OfferDetailParser;
use Weline\Product\Service\Hanfu1688\PublicHttpClient;

final class CatalogCollectorTest extends TestCase
{
    public function testCollectsEveryNaturalPageAndEachDetailOnce(): void
    {
        $detailFetches = [];
        $http = new PublicHttpClient(
            fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $this->factoryHtml()],
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $http,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => $this->mtopPage($memberId, $page),
            function (string $url) use (&$detailFetches): string {
                $detailFetches[] = $url;
                preg_match('#/([0-9]+)\.html$#', $url, $match);
                return $this->detailHtml($match[1]);
            },
        );

        $snapshot = $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ]);

        self::assertSame(['1', '2', '3'], array_column($snapshot['offers'], 'offer_id'));
        self::assertSame([1, 2], array_column($snapshot['pages'], 'page'));
        self::assertCount(3, array_unique($detailFetches));
        self::assertTrue($snapshot['complete']);
        self::assertSame(3, $snapshot['terminal_proof']['unique_offer_count']);
    }

    public function testMissingOfferAtNaturalEndFailsClosed(): void
    {
        $http = new PublicHttpClient(
            fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $this->factoryHtml()],
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $http,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => [
                'ret' => ['SUCCESS::调用成功'],
                'data' => ['total' => '3', 'page' => '2', 'pageSize' => '2', 'data' => [$this->listingOffer('2')]],
            ],
            fn(string $url): string => $this->detailHtml('1'),
        );

        $this->expectExceptionMessage('hanfu_1688_source_offer_total_mismatch');
        $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ]);
    }

    public function testStopsRequestingDetailsAfterTwoConsecutivePublicChallenges(): void
    {
        $desktopFetches = 0;
        $mobileFetches = 0;
        $http = new PublicHttpClient(
            fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $this->factoryHtml(4)],
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $http,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => [
                'ret' => ['SUCCESS::调用成功'],
                'data' => [
                    'total' => '4',
                    'page' => '2',
                    'pageSize' => '2',
                    'data' => [$this->listingOffer('3'), $this->listingOffer('4')],
                ],
            ],
            function (string $url) use (&$desktopFetches, &$mobileFetches): string {
                preg_match('#/([0-9]+)\.html$#', $url, $match);
                if (str_starts_with($url, 'https://m.1688.com/')) {
                    ++$mobileFetches;
                    return $this->mobileDetailHtml($match[1]);
                }
                ++$desktopFetches;
                return '<script>window.location="https://login.taobao.com/";</script>';
            },
        );

        $snapshot = $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ]);

        self::assertSame(2, $desktopFetches);
        self::assertSame(4, $mobileFetches);
        self::assertSame(0, $snapshot['terminal_proof']['detail_fallback_count']);
        self::assertSame(2, $snapshot['terminal_proof']['detail_requests_skipped_after_challenge']);
        self::assertSame(
            ['mobile_public_detail', 'mobile_public_detail', 'mobile_public_detail', 'mobile_public_detail'],
            array_column($snapshot['offers'], 'detail_status'),
        );
    }

    public function testUsesMobileDetailWhenDesktopTransportRedirectsIndefinitely(): void
    {
        $http = new PublicHttpClient(
            fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $this->factoryHtml()],
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $http,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => $this->mtopPage($memberId, $page),
            function (string $url): string {
                if (str_starts_with($url, 'https://m.1688.com/')) {
                    preg_match('#/([0-9]+)\.html$#', $url, $match);
                    return $this->mobileDetailHtml($match[1]);
                }
                throw new \RuntimeException('hanfu_1688_http_redirect_limit');
            },
        );

        $snapshot = $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ]);

        self::assertSame(0, $snapshot['terminal_proof']['detail_fallback_count']);
        self::assertSame(
            ['mobile_public_detail', 'mobile_public_detail', 'mobile_public_detail'],
            array_column($snapshot['offers'], 'detail_status'),
        );
    }

    public function testNativeCollectionUsesAnIsolatedMobileCookieSession(): void
    {
        $mobileFetches = [];
        $desktop = new PublicHttpClient(
            fn(string $url): array => [
                'status' => 200,
                'headers' => [],
                'body' => str_contains($url, '/factory/')
                    ? $this->factoryHtml()
                    : '<script>window.location="https://login.taobao.com/";</script>',
            ],
            static fn(int $microseconds): null => null,
            0,
        );
        $mobile = new PublicHttpClient(
            function (string $url) use (&$mobileFetches): array {
                $mobileFetches[] = $url;
                preg_match('#/([0-9]+)\.html$#', $url, $match);
                return ['status' => 200, 'headers' => [], 'body' => $this->mobileDetailHtml($match[1])];
            },
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $desktop,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => $this->mtopPage($memberId, $page),
            mobileHttp: $mobile,
        );

        $snapshot = $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ]);

        self::assertCount(3, $mobileFetches);
        self::assertSame(0, $snapshot['terminal_proof']['detail_fallback_count']);
    }

    public function testCanCollectAListingSnapshotWithoutRequestingAnyDetails(): void
    {
        $http = new PublicHttpClient(
            fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $this->factoryHtml()],
            static fn(int $microseconds): null => null,
            0,
        );
        $collector = new CatalogCollector(
            $http,
            new FactoryPageParser(),
            new OfferDetailParser(),
            fn(string $memberId, int $page): array => $this->mtopPage($memberId, $page),
            static fn(string $url): never => throw new \RuntimeException('detail_must_not_be_requested'),
        );

        $snapshot = $collector->collect([
            'source_code' => 'factory-test',
            'brand_code' => 'zuihuanlou',
            'supplier_code' => 'factory-test',
            'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
            'shop_url' => 'https://shop123.1688.com',
        ], 500, static fn(array $listing): bool => false);

        self::assertSame(3, $snapshot['terminal_proof']['detail_filtered_count']);
        self::assertSame(0, $snapshot['terminal_proof']['detail_fallback_count']);
        self::assertSame(
            ['public_listing_fallback', 'public_listing_fallback', 'public_listing_fallback'],
            array_column($snapshot['offers'], 'detail_status'),
        );
    }

    private function factoryHtml(int $total = 3): string
    {
        $data = [
            '3' => ['initShopInfo' => [
                'memberId' => 'b2b-123',
                'name' => '曹县汉服厂',
                'shopPcWpIndexUrl' => 'https://shop123.1688.com',
                'loginId' => '汉服厂',
            ]],
            '8' => ['initOfferList' => [
                'total' => $total,
                'page' => 1,
                'pageSize' => 2,
                '__params__' => ['factoryMemberId' => 'b2b-123'],
                'data' => [$this->listingOffer('1'), $this->listingOffer('2')],
            ]],
        ];
        return '<script>window.$$pageData=' . json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . '; window.next={};</script>';
    }

    /** @return array<string,mixed> */
    private function mtopPage(string $memberId, int $page): array
    {
        self::assertSame('b2b-123', $memberId);
        self::assertSame(2, $page);
        return [
            'ret' => ['SUCCESS::调用成功'],
            'data' => ['total' => '3', 'page' => '2', 'pageSize' => '2', 'data' => [$this->listingOffer('3')]],
        ];
    }

    /** @return array<string,mixed> */
    private function listingOffer(string $id): array
    {
        return [
            'offerId' => $id,
            'title' => '汉服 ' . $id,
            'price' => ['minPrice' => $id . '.00'],
            'itemPictureList' => ['https://cbu01.alicdn.com/' . $id . '.jpg'],
        ];
    }

    private function detailHtml(string $id): string
    {
        $context = ['result' => ['data' => [
            'productTitle' => ['fields' => ['title' => '汉服 ' . $id, 'unit' => '件']],
            'mainPrice' => ['fields' => ['finalPriceModel' => ['tradeWithoutPromotion' => [
                'offerMinPrice' => $id . '.00',
                'offerMaxPrice' => $id . '.00',
                'offerBeginAmount' => 1,
                'skuMapOriginal' => [],
            ]]]],
            'gallery' => ['fields' => [
                'offerId' => $id,
                'mainImage' => ['https://cbu01.alicdn.com/' . $id . '.jpg'],
                'CpvEnhance' => ['normalCpv' => [['name' => '尺码', 'values' => ['M']]]],
            ]],
        ]]];
        return '<script>window.context=(function(b,d){return d})(window.contextPath,'
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ');</script>';
    }

    private function mobileDetailHtml(string $id): string
    {
        $component = [
            'offerId' => $id,
            'displayPrice' => $id . '.00',
            'beginAmount' => 1,
            'previewImageUrl' => 'https://cbu01.alicdn.com/' . $id . '.jpg',
            'skuProps' => [['prop' => '尺码', 'value' => [['name' => 'M']]]],
            'skuMap' => ['M' => ['skuId' => $id . '99', 'price' => $id . '.00']],
        ];
        return '<title>汉服 ' . $id . '-阿里巴巴</title>'
            . '<script type="component-data/json" data-module-hidden-data-area="Y">'
            . json_encode($component, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
}
