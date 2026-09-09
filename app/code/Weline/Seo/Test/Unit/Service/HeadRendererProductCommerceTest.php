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
            'reviews' => [
                [
                    'customer_name' => 'Alex Rider',
                    'rating' => 5,
                    'title' => 'Great fit',
                    'content' => 'Comfortable linen dress with accurate sizing.',
                    'created_at' => '2026-03-01 10:15:00',
                ],
                [
                    'customer_name' => 'Jamie',
                    'rating' => 4,
                    'content' => 'Good quality for the price.',
                    'created_at' => '2026-02-20 08:00:00',
                ],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new ProductCommerceHeadTemplateStub());

        self::assertStringContainsString('<meta property="og:type" content="product">', $html);
        self::assertStringContainsString('<meta property="product:price:amount" content="29.99">', $html);
        self::assertStringContainsString('<meta property="product:availability" content="in stock">', $html);
        self::assertStringContainsString('"@type": "ProductGroup"', $html);
        self::assertStringContainsString('"productGroupID": "SPU-DRS"', $html);
        self::assertStringContainsString('"gtin13": "1234567890123"', $html);
        self::assertStringContainsString('"@type": "AggregateOffer"', $html);
        self::assertStringContainsString('"lowPrice": "29.99"', $html);
        self::assertStringContainsString('"highPrice": "31.99"', $html);
        self::assertTrue(
            (bool) preg_match(
                '/"@type"\s*:\s*"AggregateOffer"[\s\S]*?"lowPrice"\s*:\s*"29\.99"[\s\S]*?"availability"\s*:\s*"https:\/\/schema\.org\/InStock"/',
                $html
            ) || (bool) preg_match(
                '/"@type"\s*:\s*"AggregateOffer"[\s\S]*?"availability"\s*:\s*"https:\/\/schema\.org\/InStock"[\s\S]*?"lowPrice"\s*:\s*"29\.99"/',
                $html
            ),
            'AggregateOffer must expose top-level availability derived from child offers'
        );
        self::assertStringContainsString('"hasVariant": [', $html);
        self::assertStringContainsString('"potentialAction": {', $html);
        self::assertStringContainsString('"sku": "DRS-001-RED-S"', $html);
        self::assertStringContainsString('"shippingDetails": {', $html);
        self::assertStringContainsString('"hasMerchantReturnPolicy": {', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
        self::assertStringContainsString('"aggregateRating": {', $html);
        self::assertStringContainsString('"review": [', $html);
        self::assertStringContainsString('"reviewBody": "Comfortable linen dress with accurate sizing."', $html);
        self::assertStringContainsString('"ratingValue": "4.6"', $html);
        self::assertStringContainsString('"reviewCount": 27', $html);
        self::assertStringContainsString('"@type": "ProductGroup"', $html);
        self::assertStringContainsString('"itemReviewed": {', $html);
        self::assertMatchesRegularExpression('/"itemReviewed":\s*\{[^}]*"@type":\s*"ProductGroup"/s', $html);
    }

    public function testProductGroupVariantsCarryPatternAndDistinctOfferUrls(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'product',
            'site_name' => 'Shop',
            'title' => '飞天敦煌马面裙',
            'description' => '新中式飞天敦煌马面裙。',
            'canonical_url' => 'https://shop.test/product/feitian',
            'url' => 'https://shop.test/product/feitian',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'product' => [
                'schema_type' => 'ProductGroup',
                'name' => '飞天敦煌马面裙',
                'product_group_id' => '94',
                'varies_by' => ['pattern', 'size'],
                'variants' => [
                    [
                        'id' => 'offer-a',
                        'name' => '飞天敦煌马面裙 - 长袖套装 / S',
                        'sku' => 'FEITIAN-A-S',
                        'price' => '71.10',
                        'currency' => 'CNY',
                        'availability' => 'https://schema.org/InStock',
                        'size' => 'S',
                        'pattern' => '长袖套装',
                        'url' => 'https://shop.test/product/feitian?offer=offer-a',
                        'image' => '/media/a.jpg',
                    ],
                    [
                        'id' => 'offer-b',
                        'name' => '飞天敦煌马面裙 - 单件 / M',
                        'sku' => 'FEITIAN-B-M',
                        'price' => '196.20',
                        'currency' => 'CNY',
                        'availability' => 'https://schema.org/InStock',
                        'size' => 'M',
                        'pattern' => '单件',
                        'url' => 'https://shop.test/product/feitian?offer=offer-b',
                    ],
                ],
                'rating' => 4.8,
                'review_count' => 3,
            ],
            'reviews' => [
                [
                    'author' => '买家甲',
                    'rating' => 5,
                    'content' => '款式漂亮，尺码准确。',
                    'created_at' => '2026-09-01 10:00:00',
                ],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new ProductCommerceHeadTemplateStub());

        self::assertStringContainsString('"https://schema.org/pattern"', $html);
        self::assertStringContainsString('"https://schema.org/size"', $html);
        self::assertStringNotContainsString('"style_type"', $html);
        self::assertStringContainsString('"pattern": "长袖套装"', $html);
        self::assertStringContainsString('offer=offer-a', $html);
        self::assertStringContainsString('offer=offer-b', $html);
        self::assertStringContainsString('"sku": "FEITIAN-A-S"', $html);
        self::assertStringContainsString('"aggregateRating": {', $html);
        self::assertMatchesRegularExpression('/"itemReviewed":\s*\{[^}]*"@type":\s*"ProductGroup"/s', $html);
    }

    public function testDerivesAggregateRatingFromReviewNodesWhenProductRatingMissing(): void
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
            'product' => [
                'name' => 'Linen Dress',
                'sku' => 'DRS-001',
                'price' => '29.99',
                'price_currency' => 'USD',
                'stock_status' => 'in_stock',
                'rating' => 0,
                'review_count' => 0,
            ],
            'reviews' => [
                [
                    'customer_name' => 'Alex Rider',
                    'rating' => 5,
                    'content' => 'Excellent quality.',
                    'created_at' => '2026-03-01 10:15:00',
                ],
                [
                    'customer_name' => 'Jamie',
                    'rating' => 3,
                    'content' => 'Acceptable overall.',
                    'created_at' => '2026-02-20 08:00:00',
                ],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new ProductCommerceHeadTemplateStub());

        self::assertStringContainsString('"review": [', $html);
        self::assertStringContainsString('"aggregateRating": {', $html);
        self::assertStringContainsString('"ratingValue": "4"', $html);
        self::assertStringContainsString('"reviewCount": 2', $html);
    }

    public function testSkipsAggregateRatingWhenProductHasNoReviewFacts(): void
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
            'product' => [
                'name' => 'Linen Dress',
                'sku' => 'DRS-001',
                'price' => '29.99',
                'price_currency' => 'USD',
                'stock_status' => 'in_stock',
                'rating' => 4.6,
                'review_count' => 27,
            ],
            'reviews' => [],
        ]);

        $html = (new HeadRenderer($resolver))->render(new ProductCommerceHeadTemplateStub());

        self::assertStringNotContainsString('"aggregateRating": {', $html);
        self::assertStringNotContainsString('"review": {', $html);
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
