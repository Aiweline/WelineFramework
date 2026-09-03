<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;

final class StorefrontProductLocaleFallbackTest extends TestCase
{
    public function testNonChineseLocalePrefersEnglishBeforeWebsiteDefaultAndNeutral(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 9, 'name' => 'Neutral offer name', 'image' => ''],
            [
                $this->attribute(0, 'name', '', 'Neutral name'),
                $this->attribute(0, 'name', 'zh_Hans_CN', '中文商品名'),
                $this->attribute(0, 'name', 'en_US', 'English Hanfu Name'),
            ],
            [],
            3,
            'ar_SA',
            ['en_US', 'zh_Hans_CN', ''],
        );

        self::assertSame('English Hanfu Name', $detail['name']);
    }

    public function testCurrentLocaleStillWinsBeforeFallbacks(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 9, 'name' => 'Neutral offer name', 'image' => ''],
            [
                $this->attribute(0, 'name', 'ar_SA', 'Arabic merchant name'),
                $this->attribute(0, 'name', 'en_US', 'English Hanfu Name'),
            ],
            [],
            3,
            'ar_SA',
            ['en_US', 'zh_Hans_CN', ''],
        );

        self::assertSame('Arabic merchant name', $detail['name']);
    }

    public function testClearedCurrentLocaleTerminatesFallbackChain(): void
    {
        $detail = $this->projector()->project(
            ['product_id' => 9, 'name' => 'Canonical offer name', 'image' => ''],
            [
                $this->attribute(3, 'name', 'ar_SA', null, true),
                $this->attribute(0, 'name', 'en_US', 'English Hanfu Name'),
                $this->attribute(0, 'name', 'zh_Hans_CN', '中文商品名'),
            ],
            [],
            3,
            'ar_SA',
            ['en_US', 'zh_Hans_CN', ''],
        );

        self::assertSame('Canonical offer name', $detail['name']);
        self::assertNotSame('English Hanfu Name', $detail['name']);
    }

    public function testEnglishLocaleSkipsChineseFallbackCopyAndUsesNeutralFacts(): void
    {
        $detail = $this->projector()->project(
            [
                'product_id' => 84,
                'sku' => '1688-896875413963',
                'name' => '中文基础商品名',
                'image' => '',
            ],
            [
                $this->attribute(0, 'name', 'zh_Hans_CN', '中文商品名'),
                $this->attribute(0, 'name', '', 'Neutral English name'),
                $this->attribute(0, 'description', 'zh_Hans_CN', '中文商品描述'),
            ],
            [],
            3,
            'en_US',
            ['zh_Hans_CN', ''],
        );

        self::assertSame('Neutral English name', $detail['name']);
        self::assertSame('', $detail['description']);

        $withoutNeutral = $this->projector()->project(
            [
                'product_id' => 84,
                'sku' => '1688-896875413963',
                'name' => '中文基础商品名',
                'image' => '',
            ],
            [
                $this->attribute(0, 'name', 'zh_Hans_CN', '中文商品名'),
            ],
            [],
            3,
            'en_US',
            ['zh_Hans_CN', ''],
        );

        self::assertSame('Product 1688-896875413963', $withoutNeutral['name']);
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $withoutNeutral['name']);
    }


    /** @return array<string, mixed> */
    private function attribute(
        int $storeId,
        string $code,
        string $locale,
        mixed $value,
        bool $cleared = false,
    ): array {
        return [
            'store_id' => $storeId,
            'entity_type' => 'product',
            'entity_id' => 9,
            'attribute_code' => $code,
            'locale' => $locale,
            'value_type' => 'string',
            'value' => $value,
            'cleared' => $cleared,
            'is_required' => false,
        ];
    }

    private function projector(): StorefrontProductDetailProjector
    {
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                return $value;
            }
        };

        return new StorefrontProductDetailProjector(new CatalogOverlayResolver(), $labels);
    }
}
