<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;

final class DropshipCjMediaVariantMappingTest extends TestCase
{
    public function testMapVariantsBuildsStyleAxisAndOfferImages(): void
    {
        $mapped = CjProvider::mapVariants([
            'variants' => [
                [
                    'vid' => '1',
                    'variantSku' => 'SKU-A',
                    'variantKey' => 'Red-S',
                    'variantImage' => 'https://cf.cjdropshipping.com/a.jpg',
                    'variantSellPrice' => 1.23,
                ],
                [
                    'vid' => '2',
                    'variantSku' => 'SKU-B',
                    'variantKey' => 'Blue-M',
                    'variantImage' => 'https://cf.cjdropshipping.com/b.jpg',
                    'variantSellPrice' => 2.34,
                ],
            ],
        ]);

        self::assertSame('style_type', $mapped['axes'][0]['code'] ?? null);
        self::assertCount(2, $mapped['axes'][0]['options'] ?? []);
        self::assertSame('https://cf.cjdropshipping.com/a.jpg', $mapped['offers'][0]['image_url'] ?? null);
        self::assertSame(['style_type' => 'Red-S'], $mapped['offers'][0]['combination'] ?? null);
    }

    public function testExtractGalleryMediaDedupesAndMarksMain(): void
    {
        $media = CjProvider::extractGalleryMedia([
            'bigImage' => 'https://cf.cjdropshipping.com/main.jpg',
            'productImage' => [
                'https://cf.cjdropshipping.com/main.jpg',
                'https://cf.cjdropshipping.com/g2.jpg',
            ],
        ]);

        self::assertSame('main', $media[0]['role'] ?? null);
        self::assertSame('gallery', $media[1]['role'] ?? null);
        self::assertCount(2, $media);
    }

    public function testSingleVariantMapsToEmptyAxesForSimplePublish(): void
    {
        $mapped = CjProvider::mapVariants([
            'variants' => [[
                'vid' => '1',
                'variantSku' => 'ONLY',
                'variantKey' => 'Only',
                'variantImage' => 'https://cf.cjdropshipping.com/only.jpg',
            ]],
        ]);
        self::assertSame([], $mapped);
    }

    public function testMapProductDetailAssemblesDescriptionAttributesAndCategoryPath(): void
    {
        $snap = CjProvider::mapProductDetail([
            'pid' => 'pid-attr-1',
            'productSku' => 'SKU-ATTR',
            'productNameEn' => 'Attr Probe',
            'sellPrice' => 9.9,
            'description' => '<p>Hello <b>CJ</b></p>',
            'oneCategoryName' => 'Home',
            'twoCategoryName' => 'Kitchen',
            'threeCategoryName' => 'Boards',
            'categoryName' => 'Boards',
            'materialName' => '["Metal"]',
            'packingNameEn' => 'Box',
            'bigImage' => 'https://cf.cjdropshipping.com/main.jpg',
            'variants' => [[
                'vid' => '1',
                'variantSku' => 'ONLY',
                'variantKey' => 'Only',
            ]],
        ], 'US', 'en_US');

        self::assertNotNull($snap);
        self::assertStringContainsString('Hello', $snap->description);
        self::assertSame('Home / Kitchen / Boards', $snap->categoryPath);
        $codes = array_column($snap->attributes, 'attribute_code');
        self::assertContains('source_platform', $codes);
        self::assertContains('source_public_specs', $codes);
        $platform = null;
        foreach ($snap->attributes as $row) {
            if (($row['attribute_code'] ?? '') === 'source_platform') {
                $platform = $row['value'] ?? null;
            }
        }
        self::assertSame('cj', $platform);
    }

    public function testBuildCategoryPathDedupesLeaf(): void
    {
        self::assertSame(
            'A / B / C',
            CjProvider::buildCategoryPath([
                'oneCategoryName' => 'A',
                'twoCategoryName' => 'B',
                'threeCategoryName' => 'C',
                'categoryName' => 'C',
            ], 'en_US'),
        );
    }
}
