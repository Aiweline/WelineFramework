<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Extends\Module\Weline_Seo\SeoProfileProvider;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Extends\Module\Weline_Seo\SeoProfileProvider\ProductCommerceSeoProfileProvider;

class ProductCommerceSeoProfileProviderTest extends TestCase
{
    public function testProvideMapsProductPageDataToCommerceSeoContext(): void
    {
        $template = new ProductCommerceContextTemplateStub([
            'attributes' => [
                [
                    'items' => [
                        ['code' => 'brand', 'label' => 'Brand', 'value' => 'WeShop'],
                        ['code' => 'material', 'label' => 'Material', 'value' => 'Cotton'],
                        ['code' => 'gtin13', 'label' => 'GTIN', 'value' => '1234567890123'],
                    ],
                ],
            ],
            'product_images' => [
                ['url' => '/media/dress-alt.jpg'],
            ],
            'configurable_options' => [
                'attributes' => [
                    [
                        'code' => 'color',
                        'name' => 'Color',
                        'options' => [
                            ['option_id' => 10, 'value' => 'Red'],
                        ],
                    ],
                    [
                        'code' => 'size',
                        'name' => 'Size',
                        'options' => [
                            ['option_id' => 20, 'value' => 'M'],
                        ],
                    ],
                ],
                'variants' => [
                    [
                        'product_id' => 201,
                        'sku' => 'DRESS-RED-M',
                        'name' => 'Dress Red M',
                        'price' => 28.5,
                        'stock' => 5,
                        'option_ids' => [10, 20],
                    ],
                ],
            ],
            'qa' => [
                ['question' => 'Is it lined?', 'answer' => 'Yes, it has a soft lining.'],
            ],
        ]);

        $context = [
            'page_type' => 'product',
            'url' => 'https://shop.test/product/view?id=42&utm_source=ad',
            'canonical_url' => 'https://shop.test/product/view',
            'breadcrumbs' => [
                ['name' => 'Women', 'url' => 'https://shop.test/women'],
                ['name' => 'Dresses', 'url' => 'https://shop.test/dresses'],
            ],
            'faqs' => [],
            'product' => [
                'product_id' => 42,
                'name' => 'Summer Dress',
                'price' => 28.5,
                'image' => '/media/dress-main.jpg',
                'specifications' => [
                    ['label' => 'Length', 'value' => 'Midi'],
                ],
            ],
        ];

        $result = (new ProductCommerceSeoProfileProvider())->provideSeoProfile($template, $context);

        self::assertSame('https://shop.test/product/view?id=42', $result['canonical_url']);
        self::assertSame('https://shop.test/media/dress-main.jpg', $result['image']);
        self::assertSame('WeShop', $result['product']['brand']);
        self::assertSame('Cotton', $result['product']['material']);
        self::assertSame('1234567890123', $result['product']['gtin13']);
        self::assertSame('Dresses', $result['product']['category']);
        self::assertSame('Women > Dresses', $result['product']['category_path']);
        self::assertSame('ProductGroup', $result['product']['schema_type']);
        self::assertSame('Red', $result['product']['variants'][0]['color']);
        self::assertSame('M', $result['product']['variants'][0]['size']);
        self::assertSame('Summer Dress', $result['breadcrumbs'][2]['name']);
        self::assertSame('Is it lined?', $result['faqs'][0]['question']);
    }

    public function testProvideLeavesNonProductContextUnchanged(): void
    {
        $context = ['page_type' => 'web_page', 'product' => ['name' => 'Ignored']];

        $result = (new ProductCommerceSeoProfileProvider())
            ->provideSeoProfile(new ProductCommerceContextTemplateStub(), $context);

        self::assertSame([], $result);
    }
}

final class ProductCommerceContextTemplateStub
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data = [])
    {
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
