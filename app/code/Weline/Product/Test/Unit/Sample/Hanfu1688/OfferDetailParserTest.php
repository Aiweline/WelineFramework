<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\OfferDetailParser;

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
                    'decisionCpv' => [
                        ['name' => '品牌', 'values' => ['梦绘汉唐']],
                        ['name' => '面料', 'values' => ['织金锦']],
                    ],
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
        self::assertSame(
            ['品牌' => ['梦绘汉唐'], '面料' => ['织金锦'], '尺码' => ['S', 'M']],
            $parsed['specifications'],
        );
        self::assertSame('梦绘汉唐', $parsed['source_brand_name']);
        self::assertSame('红色>M', $parsed['variants'][0]['specification']);
        self::assertCount(2, $parsed['image_urls']);
        self::assertSame(0.4, $parsed['weight_kg']);
    }

    public function testParsesBrandFromMobilePropsPayload(): void
    {
        $html = '<script type="application/json">'
            . '{"data":{"propsList":[{"name":"品牌","value":"梦绘汉唐","show":false}]}}'
            . '</script>';
        self::assertSame('梦绘汉唐', (new OfferDetailParser())->parseBrandName($html));
    }

    public function testParsesDescriptionPointerFromModernDesktopPayload(): void
    {
        $html = '<script type="application/json">'
            . '{"description":{"detailUrl":"https:\\/\\/itemcdn.tmall.com\\/1688offer\\/icoss-modern"}}'
            . '</script>';

        self::assertSame(
            'https://itemcdn.tmall.com/1688offer/icoss-modern',
            (new OfferDetailParser())->parseDescriptionUrl(
                $html,
                'https://detail.1688.com/offer/123456.html?forcePC=1',
            ),
        );
    }

    public function testDescriptionPointerRejectsUntrustedHost(): void
    {
        $this->expectExceptionMessage('hanfu_1688_description_url_invalid');
        (new OfferDetailParser())->parseDescriptionUrl(
            '{"detailUrl":"https://evil.example/detail"}',
            'https://detail.1688.com/offer/123456.html?forcePC=1',
        );
    }

    public function testDescriptionIsSanitizedLocalizedAndRenderedOnlyThroughLocalAssets(): void
    {
        $payload = 'var offer_details=' . json_encode([
            'content' => '<div onclick="steal()"><h2>面料细节</h2>'
                . '<script>alert(1)</script>'
                . '<img data-lazyload-src="//cbu01.alicdn.com/detail-a.jpg" onerror="steal()" alt="纹样">'
                . '<img src="https://evil.example/detail-b.jpg"></div>',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ';';

        $parser = new OfferDetailParser();
        $parsed = $parser->parseDescription(
            $payload,
            'https://itemcdn.tmall.com/1688offer/icoss-test',
        );
        self::assertSame(
            ['https://cbu01.alicdn.com/detail-a.jpg'],
            $parsed['detail_image_urls'],
        );
        self::assertStringNotContainsString('<script', $parsed['detail_html']);
        self::assertStringNotContainsString('onclick', $parsed['detail_html']);
        self::assertStringNotContainsString('evil.example', $parsed['detail_html']);

        $assetId = '11111111-1111-4111-8111-111111111111';
        $localized = $parser->localizeDescription(
            $parsed['detail_html'],
            ['https://cbu01.alicdn.com/detail-a.jpg' => $assetId],
        );
        self::assertStringContainsString('src="asset://' . $assetId . '"', $localized);
        self::assertStringNotContainsString('src="https://', $localized);

        $rendered = \Weline\Product\Service\StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $localized . '<script>alert(2)</script>',
            static fn(string $reference): string => $reference === 'asset://' . $assetId
                ? '/pub/media/catalog/hanfu/detail.jpg'
                : '',
        );
        self::assertStringContainsString('src="/pub/media/catalog/hanfu/detail.jpg"', $rendered);
        self::assertStringNotContainsString('asset://', $rendered);
        self::assertStringNotContainsString('<script', $rendered);
    }

    public function testLocalizationRemovesOnlyExplicitlySkippedBrokenDetailImage(): void
    {
        $parser = new OfferDetailParser();
        $goodUrl = 'https://cbu01.alicdn.com/good-detail.jpg';
        $brokenUrl = 'https://cbu01.alicdn.com/broken-detail.jpg';
        $assetId = '11111111-1111-4111-8111-111111111111';
        $html = '<div data-weline-product-description="1688"><p>面料正文</p>'
            . '<img src="' . $goodUrl . '"><img src="' . $brokenUrl . '"></div>';
        $localized = $parser->localizeDescription($html, [$goodUrl => $assetId], [$brokenUrl]);
        self::assertStringContainsString('面料正文', $localized);
        self::assertStringContainsString('src="asset://' . $assetId . '"', $localized);
        self::assertStringNotContainsString($brokenUrl, $localized);
        $this->expectExceptionMessage('hanfu_1688_description_asset_missing');
        $parser->localizeDescription($html, [$goodUrl => $assetId]);
    }

    public function testDescriptionEndpointHostIsExplicitlyAllowed(): void
    {
        $client = new \Weline\Product\Sample\Hanfu1688\PublicHttpClient(
            static fn(string $url, array $headers): array => [
                'status' => 200,
                'headers' => [],
                'body' => 'var offer_details={"content":"<p>detail</p>"};',
            ],
            static fn(int $microseconds): null => null,
            0,
        );
        self::assertStringContainsString(
            'offer_details',
            $client->get('https://itemcdn.tmall.com/1688offer/icoss-test'),
        );
    }

    public function testParsesOfficialMtopDetailIntoEavAxesAndVariantImages(): void
    {
        $descriptionUrl = 'https://itemcdn.tmall.com/1688offer/icoss-hanfu-detail';
        $payload = [
            'ret' => ['SUCCESS::调用成功'],
            'data' => [
                'tempModel' => [
                    'offerId' => '1002105110427',
                    'offerTitle' => '明制汉服马面裙女套装',
                    'offerUnit' => '套',
                    'price' => '128.00',
                    'defaultOfferImg' => '//cbu01.alicdn.com/main.jpg',
                ],
                'shareModel' => ['picUrl' => 'https://cbu01.alicdn.com/share.jpg'],
                'detailModel' => [
                    'offerId' => '1002105110427',
                    'detailUrl' => 'https://air.1688.com/pages/od/app-desc/index.html?url='
                        . rawurlencode($descriptionUrl),
                ],
                'orderParamModel' => [
                    'orderParam' => [
                        'beginNum' => '2',
                        'skuParam' => [
                            'skuRangePrices' => [['beginAmount' => '2', 'price' => '128.00']],
                        ],
                    ],
                ],
                'skuModel' => [
                    'skuProps' => [
                        [
                            'prop' => '颜色',
                            'value' => [
                                ['name' => '朱红', 'imageUrl' => '//cbu01.alicdn.com/red.jpg'],
                                ['name' => '青绿', 'imageUrl' => '//cbu01.alicdn.com/green.jpg'],
                            ],
                        ],
                        ['prop' => '尺码', 'value' => [['name' => 'M'], ['name' => 'L']]],
                    ],
                    'skuInfoMapOriginal' => [
                        '朱红&gt;M' => [
                            'skuId' => '9001',
                            'specAttrs' => '朱红&gt;M',
                            'price' => '128.00',
                            'canBookCount' => '9',
                        ],
                        '青绿&gt;L' => [
                            'skuId' => '9002',
                            'specAttrs' => '青绿&gt;L',
                            'price' => '138.00',
                            'canBookCount' => '7',
                        ],
                    ],
                ],
                'layoutProtocol' => [
                    'components' => [
                        [
                            'componentType' => 'detail_image_banner',
                            'componentData' => json_encode([
                                'data' => [
                                    'offerImgList' => [
                                        '//cbu01.alicdn.com/main.jpg',
                                        '//cbu01.alicdn.com/gallery.jpg',
                                    ],
                                ],
                            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        ],
                        [
                            'componentType' => 'detail_od_property',
                            'componentData' => json_encode([
                                'data' => [
                                    'propsList' => [
                                        ['name' => '品牌', 'value' => '织造司'],
                                        ['name' => '面料', 'value' => '提花织锦'],
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ],
        ];

        $result = (new OfferDetailParser())->parseMtop(
            $payload,
            'https://m.1688.com/offer/1002105110427.html',
        );

        self::assertSame('mtop_public_detail', $result['detail_status']);
        self::assertSame('明制汉服马面裙女套装', $result['title']);
        self::assertSame(['朱红', '青绿'], $result['specifications']['颜色']);
        self::assertSame(['M', 'L'], $result['specifications']['尺码']);
        self::assertSame('织造司', $result['source_brand_name']);
        self::assertTrue($result['source_brand_checked']);
        self::assertSame($descriptionUrl, $result['detail_description_url']);
        self::assertSame('https://cbu01.alicdn.com/red.jpg', $result['variants'][0]['image_url']);
        self::assertSame('https://cbu01.alicdn.com/green.jpg', $result['variants'][1]['image_url']);
        self::assertSame(9, $result['variants'][0]['public_available_quantity']);
        self::assertSame('138.00', $result['maximum_price']);
        self::assertContains('https://cbu01.alicdn.com/gallery.jpg', $result['image_urls']);
    }

    public function testLoginWallFailsClosed(): void
    {
        $this->expectExceptionMessage('hanfu_1688_offer_page_blocked');
        (new OfferDetailParser())->parse(
            '<a href="https://login.taobao.com/">login</a>',
            'https://detail.1688.com/offer/431.html',
        );
    }

    public function testParsesPublicMobileSkuPropsAndSkuMap(): void
    {
        $component = [
            'offerId' => '431',
            'displayPrice' => '128.50',
            'beginAmount' => 2,
            'previewImageUrl' => 'https://cbu01.alicdn.com/1.jpg',
            'skuProps' => [
                ['prop' => '颜色', 'value' => [
                    ['name' => '红色', 'imageUrl' => 'https://cbu01.alicdn.com/red.jpg'],
                    ['name' => '蓝色'],
                ]],
                ['prop' => '尺码', 'value' => [['name' => 'S'], ['name' => 'M']]],
            ],
            'skuMap' => [
                '红色&gt;M' => [
                    'skuId' => 99,
                    'discountPrice' => '128.50',
                    'canBookCount' => 20,
                ],
            ],
        ];
        $html = '<title>明制马面裙-阿里巴巴</title>'
            . '<script type="component-data/json" data-module-hidden-data-area="Y">'
            . json_encode($component, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';

        $parsed = (new OfferDetailParser())->parse(
            $html,
            'https://m.1688.com/offer/431.html',
        );

        self::assertSame('431', $parsed['offer_id']);
        self::assertSame('明制马面裙', $parsed['title']);
        self::assertSame('128.50', $parsed['price']);
        self::assertSame(['颜色' => ['红色', '蓝色'], '尺码' => ['S', 'M']], $parsed['specifications']);
        self::assertSame('红色>M', $parsed['variants'][0]['specification']);
        self::assertSame(
            'https://cbu01.alicdn.com/red.jpg',
            $parsed['variants'][0]['image_url'],
        );
        self::assertSame('mobile_public_detail', $parsed['detail_status']);
        self::assertSame(
            ['https://cbu01.alicdn.com/1.jpg', 'https://cbu01.alicdn.com/red.jpg'],
            $parsed['image_urls'],
        );
    }

    public function testDescriptionSanitizerDropsPromoRecommendedProductTables(): void
    {
        $payload = 'var offer_details=' . json_encode([
            'content' => '<div>'
                . '<table><tr><td>火爆大促销 欢迎你加入 五一狂欢购 <strong>WUYIKUANHUANGOU</strong></td></tr></table>'
                . '<table><tr><td><img data-lazyload-src="//cbu01.alicdn.com/promo.jpg"></td></tr>'
                . '<tr><td>国潮风少女旗袍批发</td></tr><tr><td>￥55</td></tr></table>'
                . '<p>关于绣花颜色款式和面料。</p>'
                . '<img data-lazyload-src="//cbu01.alicdn.com/real-detail.jpg">'
                . '</div>',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ';';

        $parsed = (new OfferDetailParser())->parseDescription(
            $payload,
            'https://itemcdn.tmall.com/1688offer/icoss-promo-strip',
        );

        self::assertSame(['https://cbu01.alicdn.com/real-detail.jpg'], $parsed['detail_image_urls']);
        self::assertStringContainsString('关于绣花颜色款式和面料', $parsed['detail_html']);
        self::assertStringNotContainsString('火爆大促销', $parsed['detail_html']);
        self::assertStringNotContainsString('WUYIKUANHUANGOU', $parsed['detail_html']);
        self::assertStringNotContainsString('国潮风少女旗袍', $parsed['detail_html']);
        self::assertStringNotContainsString('￥55', $parsed['detail_html']);
        self::assertStringNotContainsString('promo.jpg', $parsed['detail_html']);
    }
}
