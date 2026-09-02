<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\OfferDetailParser;

final class OfferDetailParserTest extends TestCase
{
    public function testParsesExactPublicTitlePriceSpecsVariantsAndImages(): void
    {
        $data = ['result' => ['data' => [
            'productTitle' => ['fields' => [
                'title' => '明制马面裙',
                'unit' => '件',
                'shopInfo' => ['companyName' => '曹县汉服厂'],
            ]],
            'mainPrice' => ['fields' => ['finalPriceModel' => ['tradeWithoutPromotion' => [
                'offerMinPrice' => '128.50',
                'offerMaxPrice' => '138.50',
                'offerBeginAmount' => 2,
                'skuMapOriginal' => [[
                    'skuId' => '99',
                    'specAttrs' => '红色&gt;M',
                    'price' => '128.50',
                    'canBookCount' => 20,
                ]],
            ]]]],
            'gallery' => ['fields' => [
                'offerId' => '431',
                'mainImage' => [
                    'https://cbu01.alicdn.com/1.jpg',
                    'https://cbu01.alicdn.com/2.jpg',
                ],
                'CpvEnhance' => [
                    'decisionCpv' => [['name' => '面料', 'values' => ['织金锦']]],
                    'normalCpv' => [['name' => '尺码', 'values' => ['S', 'M']]],
                ],
            ]],
            'description' => ['fields' => ['detailUrl' => 'https://itemcdn.tmall.com/offer/detail']],
            'productPackInfo' => ['fields' => ['unitWeight' => 0.4]],
        ]]];
        $html = '<script>window.context=(function(b,d){return d})(window.contextPath,'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ');</script>';

        $parsed = (new OfferDetailParser())->parse(
            $html,
            'https://detail.1688.com/offer/431.html',
        );

        self::assertSame('431', $parsed['offer_id']);
        self::assertSame('明制马面裙', $parsed['title']);
        self::assertSame('128.50', $parsed['price']);
        self::assertSame(['面料' => ['织金锦'], '尺码' => ['S', 'M']], $parsed['specifications']);
        self::assertSame('红色>M', $parsed['variants'][0]['specification']);
        self::assertCount(2, $parsed['image_urls']);
        self::assertSame(0.4, $parsed['weight_kg']);
    }

    public function testLoginWallFailsClosed(): void
    {
        $this->expectExceptionMessage('hanfu_1688_offer_page_blocked');
        (new OfferDetailParser())->parse(
            '<a href="https://login.taobao.com/">login</a>',
            'https://detail.1688.com/offer/431.html',
        );
    }
}
