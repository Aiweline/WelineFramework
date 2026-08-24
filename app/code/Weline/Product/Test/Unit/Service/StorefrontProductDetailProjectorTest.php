<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontProductDetailProjector;

final class StorefrontProductDetailProjectorTest extends TestCase
{
    public function testItProjectsLocalizedPublicDetailsAndOrderedMedia(): void
    {
        $projector = new StorefrontProductDetailProjector();

        $detail = $projector->project(
            [
                'product_id' => 9,
                'name' => 'Website name',
                'image' => '/media/primary.jpg',
            ],
            [
                $this->attribute(0, 'name', '', 'Website name'),
                $this->attribute(3, 'name', 'zh_Hans_CN', '门店商品名'),
                $this->attribute(0, 'short_description', '', 'Website summary'),
                $this->attribute(3, 'short_description', 'zh_Hans_CN', '门店简介'),
                $this->attribute(0, 'description', '', 'Website description'),
                $this->attribute(0, 'engine', '', 'LONCIN YBS300 PRO'),
                $this->attribute(3, 'engine', 'zh_Hans_CN', '隆鑫 YBS300 PRO'),
                $this->attribute(0, 'displacement', '', '294.9 ml'),
                $this->attribute(0, 'source_catalog', '', 'internal-import-source'),
                $this->attribute(0, 'source_product_id', '', '5357'),
                $this->attribute(0, 'source_images_json', '', '[{"id":5358}]'),
                $this->attribute(0, 'quote_only', '', '1'),
            ],
            [
                ['media_id' => 13, 'path' => '/media/detail.jpg', 'position' => 2],
                ['media_id' => 9, 'path' => '/media/primary.jpg', 'position' => 0],
                ['media_id' => 12, 'path' => '/media/secondary.jpg', 'position' => 1],
            ],
            3,
            'zh_Hans_CN',
        );

        self::assertSame('门店商品名', $detail['name']);
        self::assertSame('门店简介', $detail['short_description']);
        self::assertSame('Website description', $detail['description']);
        self::assertSame(
            [
                ['code' => 'displacement', 'value' => '294.9 ml'],
                ['code' => 'engine', 'value' => '隆鑫 YBS300 PRO'],
            ],
            $detail['specifications'],
        );
        self::assertSame(
            ['/media/primary.jpg', '/media/secondary.jpg', '/media/detail.jpg'],
            $detail['images'],
        );
        self::assertArrayNotHasKey('source_catalog', $detail);
        self::assertTrue($detail['quote_only']);
    }

    public function testItExposesNormalizedSourceSlugForPublicUrls(): void
    {
        $projector = new StorefrontProductDetailProjector();

        $detail = $projector->project(
            ['product_id' => 7, 'name' => 'Website name', 'image' => ''],
            [
                $this->attribute(0, 'name', '', 'Website name'),
                $this->attribute(0, 'slug', '', 'gasoline-atvs'),
                $this->attribute(0, 'source_slug', '', 'ztot-z7l-yb300h-gasoline-dirt-bike'),
                $this->attribute(0, 'engine', '', 'LONCIN'),
            ],
            [],
            0,
            '',
        );

        self::assertSame('ztot-z7l-yb300h-gasoline-dirt-bike', $detail['slug']);
        self::assertSame(
            [['code' => 'engine', 'value' => 'LONCIN']],
            $detail['specifications'],
        );
    }

    public function testClearedStoreValueStopsWebsiteFallback(): void
    {
        $projector = new StorefrontProductDetailProjector();

        $detail = $projector->project(
            ['product_id' => 9, 'image' => ''],
            [
                $this->attribute(0, 'description', '', 'Website description'),
                $this->attribute(3, 'description', 'zh_Hans_CN', null, true),
                $this->attribute(0, 'engine', '', 'Website engine'),
                $this->attribute(3, 'engine', 'zh_Hans_CN', null, true),
            ],
            [],
            3,
            'zh_Hans_CN',
        );

        self::assertSame('', $detail['description']);
        self::assertSame([], $detail['specifications']);
        self::assertSame([], $detail['images']);
    }

    /** @return array<string, mixed> */
    private function attribute(
        int $storeId,
        string $code,
        string $locale,
        ?string $value,
        bool $cleared = false,
    ): array {
        return [
            'store_id' => $storeId,
            'entity_type' => 'product',
            'entity_id' => 9,
            'attribute_code' => $code,
            'locale' => $locale,
            'value' => $value,
            'cleared' => $cleared,
            'is_required' => false,
        ];
    }
}
