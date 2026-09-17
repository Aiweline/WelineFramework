<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;

class DetailDescriptionTextifierTest extends TestCore
{
    public function testReplaceAssetImageWithGenderWeightChartAndRender(): void
    {
        $assetId = 'edef8b30-0862-4fd6-9ea1-8afc84b65f9b';
        $photoId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $html = '<div data-weline-product-description="1688">'
            . '<p><img src="asset://' . $assetId . '" alt="size"></p>'
            . '<p><img src="asset://' . $photoId . '" alt="photo"></p>'
            . '</div>';

        $women = [
            ['label' => '女款', 'min_jin' => 70, 'max_jin' => 90, 'size' => 'S'],
            ['label' => '女款', 'min_jin' => 90, 'max_jin' => 110, 'size' => 'M'],
            ['label' => '女款', 'min_jin' => 110, 'max_jin' => 120, 'size' => 'L'],
            ['label' => '女款', 'min_jin' => 115, 'max_jin' => 125, 'size' => 'XL'],
        ];
        $men = [
            ['label' => '男款', 'min_jin' => 90, 'max_jin' => 110, 'size' => 'M'],
            ['label' => '男款', 'min_jin' => 110, 'max_jin' => 120, 'size' => 'L'],
            ['label' => '男款', 'min_jin' => 120, 'max_jin' => 140, 'size' => 'XL'],
            ['label' => '男款', 'min_jin' => 140, 'max_jin' => 155, 'size' => '2XL'],
        ];
        $chart = DetailDescriptionTextifier::buildGenderWeightSizeChartZh($women, $men);
        $updated = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, $assetId, $chart);

        self::assertStringContainsString('尺码建议表', $updated);
        self::assertStringContainsString('weline-detail-text--size-chart', $updated);
        self::assertStringContainsString('weline-detail-text__columns', $updated);
        self::assertStringContainsString('<h4>女款</h4>', $updated);
        self::assertStringContainsString('<h4>男款</h4>', $updated);
        self::assertStringContainsString('70-90 斤（35-45 kg）', $updated);
        self::assertStringContainsString('<strong>2XL</strong>', $updated);
        self::assertStringNotContainsString('asset://' . $assetId, $updated);
        self::assertStringContainsString('asset://' . $photoId, $updated);

        $rendered = StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $updated,
            static function (string $reference) use ($photoId): string {
                return $reference === 'asset://' . $photoId
                    ? '/pub/media/catalog/hanfu/photo.jpg'
                    : '';
            },
        );
        self::assertStringContainsString('尺码建议表', $rendered);
        self::assertStringContainsString('weline-detail-text__columns', $rendered);
        self::assertStringContainsString('35-45 kg', $rendered);
        self::assertStringContainsString('/pub/media/catalog/hanfu/photo.jpg', $rendered);
        self::assertStringNotContainsString('edef8b30-0862-4fd6-9ea1-8afc84b65f9b', $rendered);
    }

    public function testParseGenderWeightChartFromVerifiedOcr(): void
    {
        $ocr = "尺码建议表\n此款为男女分码款\n女款 (建议体重70-90斤) 选 S 码\n女款 (建议体重90-110斤) 选 M 码\n"
            . "女款 (建议体重110-120斤) 选 L 码\n女款 (建议体重115-125斤) 选 XL 码\n"
            . "男款 (建议体重90-110斤) 选 M 码\n男款 (建议体重110-120斤) 选 L 码\n"
            . "男款 (建议体重120-140斤) 选 XL 码\n男款 (建议体重140-155斤) 选 2XL 码\n建议按体重拍";
        $parsed = DetailDescriptionTextifier::parseGenderWeightChartFromOcr($ocr);
        self::assertNotNull($parsed);
        self::assertCount(4, $parsed['women']);
        self::assertCount(4, $parsed['men']);
        self::assertSame('2XL', $parsed['men'][3]['size']);
    }

    public function testMapTextNodesPreservesAssetImages(): void
    {
        $html = '<div data-weline-product-description="1688"><h3>尺码建议表</h3>'
            . '<p><img src="asset://aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"></p></div>';
        $mapped = DetailDescriptionTextifier::mapTextNodes(
            $html,
            static fn(string $text): string => $text === '尺码建议表' ? 'Size suggestion chart' : $text,
        );
        self::assertStringContainsString('Size suggestion chart', $mapped);
        self::assertStringContainsString('asset://aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $mapped);
    }

    public function testMeasurementChartAndProductInfoReplaceAssets(): void
    {
        $infoId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $chartId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $html = '<div data-weline-product-description="1688">'
            . '<img src="asset://' . $infoId . '">'
            . '<img src="asset://' . $chartId . '">'
            . '</div>';

        $info = DetailDescriptionTextifier::buildProductInfoPanelZh(
            ['名称' => '四季春', '尺码' => 'S-L'],
            [[
                'label' => '厚薄指数',
                'options' => ['超薄', '微薄', '适中', '厚'],
                'selected' => '微薄',
            ]],
        );
        $chart = DetailDescriptionTextifier::buildMeasurementSizeChartZh([[
            'title' => '大袖衫',
            'headers' => ['尺码', 'S', 'M', 'L'],
            'rows' => [
                ['衣长', '112', '115', '118'],
            ],
        ]]);

        $updated = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, $infoId, $info);
        $updated = DetailDescriptionTextifier::replaceAssetImageWithHtml($updated, $chartId, $chart);

        self::assertStringContainsString('产品信息', $updated);
        self::assertStringContainsString('weline-detail-text__scale-option--selected', $updated);
        self::assertStringContainsString('微薄', $updated);
        self::assertStringContainsString('尺码参考表', $updated);
        self::assertStringContainsString('大袖衫', $updated);
        self::assertStringContainsString('112', $updated);
        self::assertStringNotContainsString('asset://' . $infoId, $updated);
        self::assertStringNotContainsString('asset://' . $chartId, $updated);
    }
}
