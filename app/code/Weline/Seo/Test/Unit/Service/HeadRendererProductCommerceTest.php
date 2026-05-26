<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class HeadRendererProductCommerceTest extends TestCase
{
    public function testRendersRichProductCommerceSchemaAndSocialTags(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'product',
            'site_name' => 'Shop',
            'title' => 'Linen Dress',
            'description' => 'Sleeveless linen dress.',
            'canonical_url' => 'https://shop.test/product/linen-dress',
            'url' => 'https://shop.test/product/linen-dress',
            'image' => 'https://shop.test/media/dress-main.jpg',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'product' => [
                'schema_type' => 'ProductGroup',
                'name' => 'Linen Dress',
                'brand' => 'ANRABESS',
                'sku' => 'DRS-001',
                'gtin13' => '1234567890123',
                'price' => '29.99',
                'price_currency' => 'USD',
                'stock_status' => 'in_stock',
                'item_condition' => 'new',
                'product_group_id' => 'SPU-DRS',
                'varies_by' => ['color', 'size'],
                'additional_property' => [
                    ['name' => 'Material', 'value' => 'Linen', 'propertyID' => 'material'],
                ],
                'shipping_details' => [
                    '@type' => 'OfferShippingDetails',
                    'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'US'],
                ],
                'merchant_return_policy' => [
                    '@type' => 'MerchantReturnPolicy',
                    'applicableCountry' => 'US',
                ],
                'variants' => [
                    [
                        'product_id' => 101,
                        'name' => 'Linen Dress Red S',
                        'sku' => 'DRS-001-RED-S',
                        'price' => '29.99',
                        'stock' => 4,
                        'color' => 'Red',
                        'size' => 'S',
                        'image' => '/media/dress-red.jpg',
                    ],
                    [
                        'product_id' => 102,
                        'name' => 'Linen Dress Blue M',
                        'sku' => 'DRS-001-BLU-M',
                        'price' => '31.99',
                        'stock' => 0,
                        'color' => 'Blue',
                        'size' => 'M',
                    ],
                ],
                'rating' => 4.6,
                'review_count' => 27,
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new ProductCommerceHeadTemplateStub());

        self::assertStringContainsString('<meta property="og:type" content="product">', $html);
        self::assertStringContainsString('<meta property="product:price:amount" content="29.99">', $html);
        self::assertStringContainsString('<meta property="product:availability" content="in stock">', $html);
        self::assertStringContainsString('"@type": "ProductGroup"', $html);
        self::assertStringContainsString('"productGroupID": "SPU-DRS"', $html);
        self::assertStringContainsString('"gtin13": "1234567890123"', $html);
        self::assertStringNotContainsString('"@type": "AggregateOffer"', $html);
        self::assertStringContainsString('"hasVariant": [', $html);
        self::assertStringContainsString('"sku": "DRS-001-RED-S"', $html);
        self::assertStringContainsString('"shippingDetails": {', $html);
        self::assertStringContainsString('"hasMerchantReturnPolicy": {', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
        self::assertStringContainsString('"aggregateRating": {', $html);
    }
}

final class ProductCommerceHeadTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
