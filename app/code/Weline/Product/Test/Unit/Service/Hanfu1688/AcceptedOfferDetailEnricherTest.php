<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\AcceptedOfferDetailEnricher;
use Weline\Product\Service\Hanfu1688\HanfuProductClassifier;
use Weline\Product\Service\Hanfu1688\OfferDetailParser;

final class AcceptedOfferDetailEnricherTest extends TestCase
{
    public function testDescriptionEnrichmentFetchesDesktopPointerOnlyForAcceptedHanfu(): void
    {
        $detailUrl = 'https://itemcdn.tmall.com/1688offer/icoss-test';
        $desktopHtml = 'prefix })(window.contextPath,' . json_encode([
            'result' => [
                'data' => [
                    'productTitle' => ['fields' => ['title' => '明制汉服']],
                    'gallery' => ['fields' => ['offerId' => '123456', 'mainImage' => []]],
                    'mainPrice' => ['fields' => []],
                    'description' => ['fields' => ['detailUrl' => $detailUrl]],
                    'productPackInfo' => ['fields' => []],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ');';
        $detailPayload = 'var offer_details=' . json_encode([
            'content' => '<p>完整商品详情</p><img src="//cbu01.alicdn.com/detail.jpg">',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ';';
        $requested = [];
        $fetcher = static function (string $url) use (&$requested, $desktopHtml, $detailPayload, $detailUrl): string {
            $requested[] = $url;
            return $url === $detailUrl ? $detailPayload : $desktopHtml;
        };

        $result = (new AcceptedOfferDetailEnricher(
            new OfferDetailParser(),
            $fetcher,
            static fn(int $microseconds): null => null,
            0,
            1,
        ))->enrichDescriptions([
            'offers' => [
                ['offer_id' => '123456', 'title' => '明制汉服'],
                ['offer_id' => '999999', 'title' => '现代短袖T恤'],
            ],
        ], new HanfuProductClassifier());

        self::assertSame(1, $result['accepted_description_enrichment']['accepted']);
        self::assertSame(1, $result['accepted_description_enrichment']['enriched']);
        self::assertSame([], $result['accepted_description_enrichment']['errors']);
        self::assertCount(2, $requested);
        self::assertSame(
            'https://detail.1688.com/offer/123456.html?forcePC=1',
            $requested[0],
        );
        self::assertSame($detailUrl, $result['offers'][0]['detail_description_url']);
        self::assertSame(['https://cbu01.alicdn.com/detail.jpg'], $result['offers'][0]['detail_image_urls']);
        self::assertArrayNotHasKey('detail_html', $result['offers'][1]);
    }

    public function testFetchesMobileDetailOnlyForAcceptedFallbackOffers(): void
    {
        $requested = [];
        $enricher = new AcceptedOfferDetailEnricher(
            new OfferDetailParser(),
            function (string $url) use (&$requested): string {
                $requested[] = $url;
                return $this->mobileDetailHtml('2');
            },
            static fn(int $microseconds): null => null,
            0,
        );

        $result = $enricher->enrich([
            'offers' => [
                [
                    'offer_id' => '1',
                    'title' => '学院风校服演出服',
                    'detail_status' => 'public_listing_fallback',
                    'image_urls' => ['https://cbu01.alicdn.com/listing-1.jpg'],
                ],
                [
                    'offer_id' => '2',
                    'title' => '明制汉服马面裙套装',
                    'detail_status' => 'public_listing_fallback',
                    'image_urls' => ['https://cbu01.alicdn.com/listing-2.jpg'],
                ],
            ],
        ], new HanfuProductClassifier());

        self::assertSame(['https://m.1688.com/offer/2.html'], $requested);
        self::assertSame('public_listing_fallback', $result['offers'][0]['detail_status']);
        self::assertSame('mobile_public_detail', $result['offers'][1]['detail_status']);
        self::assertSame(['S', 'M'], $result['offers'][1]['specifications']['尺码']);
        self::assertSame(12, $result['offers'][1]['variants'][0]['public_available_quantity']);
        self::assertSame([
            'https://cbu01.alicdn.com/listing-2.jpg',
            'https://cbu01.alicdn.com/detail-2.jpg',
        ], $result['offers'][1]['image_urls']);
        self::assertSame(1, $result['accepted_detail_enrichment']['enriched']);
        self::assertSame([], $result['accepted_detail_enrichment']['errors']);
    }

    private function mobileDetailHtml(string $id): string
    {
        $component = [
            'offerId' => $id,
            'displayPrice' => '82.00',
            'beginAmount' => 2,
            'previewImageUrl' => 'https://cbu01.alicdn.com/detail-' . $id . '.jpg',
            'skuProps' => [['prop' => '尺码', 'value' => [['name' => 'S'], ['name' => 'M']]]],
            'skuMap' => [
                'S' => ['skuId' => $id . '01', 'price' => '82.00', 'canBookCount' => 12],
                'M' => ['skuId' => $id . '02', 'price' => '82.00', 'canBookCount' => 8],
            ],
        ];
        return '<title>明制汉服马面裙套装-阿里巴巴</title>'
            . '<script type="component-data/json" data-module-hidden-data-area="Y">'
            . json_encode($component, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
}
