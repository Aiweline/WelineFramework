<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;

/**
 * RED-first acceptance for internal EAV codes leaking into the storefront
 * "Technical details" specification table.
 *
 * Live evidence (2026-10-24, changanhanfu.com): 9 of 12 sampled PDPs render
 * `Url Key = huazhaoji-5f3ba8e0-t73d3-s7ca5` and/or
 * `Meta Keyword = ...` rows, because these codes are absent from
 * StorefrontProductDetailProjector::INTERNAL_CODES.
 */
final class StorefrontSpecificationInternalLeakTest extends TestCase
{
    public function testInternalIdentityAndSeoCodesNeverRenderAsSpecifications(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 185, 'sku' => 'HUAZHAOJI-5F3BA8E0-T73D3-S7CA5'],
            [
                $this->attribute(0, 'url_key', '', 'huazhaoji-5f3ba8e0-t73d3-s7ca5'),
                $this->attribute(0, 'meta_keyword', '', '花朝记, 汉服'),
                $this->attribute(0, 'meta_keywords', '', '花朝记, 汉服'),
                $this->attribute(0, 'meta_name', '', '花朝记【半夏】 | 花朝记汉服'),
                $this->attribute(0, 'meta_description', '', '花朝记正品汉服：…'),
                $this->attribute(0, 'source_url', '', 'https://detail.1688.com/offer/829246319270.html'),
                $this->attribute(0, 'source_slug', '', 'huazhaoji-5f3ba8e0-t73d3-s7ca5'),
                $this->attribute(0, 'size', '', 'S'),
                $this->attribute(0, 'style_type', '', '【半夏】外衫+内衫+裙子+披帛'),
            ],
            [],
            0,
            'zh_Hans_CN',
        );

        self::assertSame(
            [
                ['code' => 'size', 'label' => '', 'value' => 'S'],
                ['code' => 'style_type', 'label' => '', 'value' => '【半夏】外衫+内衫+裙子+披帛'],
            ],
            $detail['specifications'],
        );
    }

    public function testExistingInternalCodesStayHidden(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 186, 'sku' => 'HUAZHAOJI-A23F6120-TD6C3-S7CA5'],
            [
                $this->attribute(0, 'slug', '', 'huazhaoji-a23f6120-td6c3-s7ca5'),
                $this->attribute(0, 'spu', '', 'HUAZHAOJI-5F3BA8E0'),
                $this->attribute(0, 'brand_code', '', 'huazhaoji'),
                $this->attribute(0, 'visibility', '', 'catalog_search'),
                $this->attribute(0, 'material', '', '棉麻'),
            ],
            [],
            0,
            'zh_Hans_CN',
        );

        self::assertSame(
            [['code' => 'material', 'label' => '', 'value' => '棉麻']],
            $detail['specifications'],
        );
    }

    public function testLegitimateShopperSpecificationsStillRender(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 187, 'sku' => 'HUACHAOJI-0855D489-T8314-S7CA5'],
            [
                $this->attribute(0, 'color', '', '红色'),
                $this->attribute(0, 'material', '', '丝绸'),
                $this->attribute(0, 'care_instructions', '', '手洗分色'),
                $this->attribute(0, 'weight_kg', '', '0.8'),
            ],
            [],
            0,
            'zh_Hans_CN',
        );

        self::assertSame(
            [
                ['code' => 'care_instructions', 'label' => '', 'value' => '手洗分色'],
                ['code' => 'color', 'label' => '', 'value' => '红色'],
                ['code' => 'material', 'label' => '', 'value' => '丝绸'],
            ],
            $detail['specifications'],
        );
    }

    private function projector(): StorefrontProductDetailProjector
    {
        return new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $this->passthroughLabels(),
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

            public function attributeLabel(string $attributeCode): string
            {
                return '';
            }
        };
    }

    private function attribute(int $storeId, string $code, string $locale, string $value): array
    {
        return [
            'store_id' => $storeId,
            'entity_type' => 'product',
            'entity_id' => 185,
            'attribute_code' => $code,
            'locale' => $locale,
            'value_type' => 'string',
            'value' => $value,
            'cleared' => false,
            'is_required' => false,
        ];
    }
}
