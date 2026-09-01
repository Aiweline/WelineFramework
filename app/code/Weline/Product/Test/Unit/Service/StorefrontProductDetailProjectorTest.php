<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;

final class StorefrontProductDetailProjectorTest extends TestCase
{
    public function testItProjectsLocalizedPublicDetailsAndOrderedMedia(): void
    {
        $projector = $this->projector();

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
        self::assertSame('', $detail['meta_name']);
        self::assertSame('', $detail['meta_description']);
        self::assertSame('', $detail['meta_keywords']);
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

    public function testItExposesSeoMetaAndHidesCostFromSpecifications(): void
    {
        $projector = $this->projector();

        $detail = $projector->project(
            ['product_id' => 11, 'name' => 'SEO Sample', 'image' => ''],
            [
                $this->attribute(0, 'name', '', 'SEO Sample'),
                $this->attribute(0, 'meta_name', '', '自定义 SEO 标题'),
                $this->attribute(0, 'meta_description', '', '自定义 SEO 描述'),
                $this->attribute(0, 'meta_keywords', '', '汉服,马面裙'),
                $this->attribute(0, 'cost', '', '88.5'),
                $this->attribute(0, 'brand', '', '醉欢楼'),
            ],
            [],
            0,
            '',
        );

        self::assertSame('自定义 SEO 标题', $detail['meta_name']);
        self::assertSame('自定义 SEO 描述', $detail['meta_description']);
        self::assertSame('汉服,马面裙', $detail['meta_keywords']);
        self::assertArrayNotHasKey('cost', $detail);
        self::assertSame('醉欢楼', $detail['brand']);
        self::assertSame([], $detail['specifications']);
    }

    public function testItExposesNormalizedSourceSlugForPublicUrls(): void
    {
        $projector = $this->projector();

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
        $projector = $this->projector();

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

    public function testItHidesInternalConfigurationAndResolvesEavOptionLabels(): void
    {
        $resolver = new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                return match (strtolower(trim($attributeCode))) {
                    'color' => $value === 'm-white' ? '米白色' : $value,
                    'size' => $value === 'm' ? 'M' : strtoupper($value),
                    'style_type' => $value === 'set' ? '套装（上衣+马面裙）' : $value,
                    default => $value,
                };
            }
        };
        $projector = new StorefrontProductDetailProjector(new CatalogOverlayResolver(), $resolver);

        $detail = $projector->project(
            ['product_id' => 26, 'name' => '桃园清梦', 'image' => ''],
            [
                $this->attribute(0, 'name', '', '桃园清梦'),
                $this->attribute(0, 'brand', '', '醉欢楼'),
                $this->attribute(0, 'material', '', '聚酯纤维100%'),
                $this->attribute(0, 'product_type', '', 'configurable'),
                $this->attribute(0, 'reference_source', '', '淘宝参考价'),
                $this->attribute(0, 'color', '', 'm-white'),
                $this->attribute(0, 'size', '', 'm'),
                $this->attribute(0, 'style_type', '', 'set'),
                $this->attribute(
                    0,
                    'type_configuration',
                    '',
                    '{"axes":[{"code":"color","label":"颜色","options":[{"value":"m-white","label":"米白色"}]},{"code":"size","label":"尺码","options":[{"value":"m","label":"M"}]},{"code":"style_type","label":"类型","options":[{"value":"set","label":"套装（上衣+马面裙）"}]}]}',
                ),
            ],
            [],
            0,
            '',
        );

        self::assertSame('醉欢楼', $detail['brand']);
        self::assertSame(
            [
                ['code' => 'color', 'value' => '米白色'],
                ['code' => 'material', 'value' => '聚酯纤维100%'],
                ['code' => 'size', 'value' => 'M'],
                ['code' => 'style_type', 'value' => '套装（上衣+马面裙）'],
            ],
            $detail['specifications'],
        );
        self::assertSame(
            [
                [
                    'code' => 'color',
                    'label' => '颜色',
                    'value' => 'm-white',
                    'value_label' => '米白色',
                    'options' => [['value' => 'm-white', 'label' => '米白色']],
                ],
                [
                    'code' => 'size',
                    'label' => '尺码',
                    'value' => 'm',
                    'value_label' => 'M',
                    'options' => [['value' => 'm', 'label' => 'M']],
                ],
                [
                    'code' => 'style_type',
                    'label' => '类型',
                    'value' => 'set',
                    'value_label' => '套装（上衣+马面裙）',
                    'options' => [['value' => 'set', 'label' => '套装（上衣+马面裙）']],
                ],
            ],
            $detail['variant_axes'],
        );
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

    private function projector(?StorefrontEavLabelResolver $labels = null): StorefrontProductDetailProjector
    {
        return new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $labels ?? $this->passthroughLabels(),
        );
    }

    private function passthroughLabels(): StorefrontEavLabelResolver
    {
        return new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                return $value;
            }
        };
    }
}
