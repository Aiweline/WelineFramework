<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Extends\Module\Weline_Seo\SeoProfileProvider\ProductSeoProfileProvider;
use Weline\Seo\Service\Head\HeadRenderer;

final class ProductSeoProfileProviderContractTest extends TestCase
{
    public function testItProjectsImportedSeoAndDistinctOfferFacts(): void
    {
        $product = [
            'product_id' => 83,
            'name' => '民国风女装唐装素衣禅茶服改良汉服',
            'meta_name' => '民国风女装唐装素衣禅茶服改良汉服 | 织造司汉服',
            'meta_description' => '织造司民国风改良汉服，支持颜色与尺码选择。',
            'meta_keywords' => '织造司,汉服,传统服饰',
            'image' => '/pub/media/catalog/hanfu/main.jpg',
            'brand' => '织造司',
        ];
        $offers = [
            array_replace($product, [
                'global_offer_uuid' => 'offer-white-s',
                'sku' => 'ZHIZAOSI-MINGUOFENG-BAISE-S',
                'unit_price_minor' => 1234,
                'currency' => 'CNY',
                'sellable' => true,
                'combination' => ['color' => '48', 'size' => '57'],
                'variant_axes' => [
                    ['code' => 'color', 'value' => '48', 'value_label' => '白色仅上衣2307'],
                    ['code' => 'size', 'value' => '57', 'value_label' => 'S80-90斤'],
                ],
            ]),
            array_replace($product, [
                'global_offer_uuid' => 'offer-red-m',
                'sku' => 'ZHIZAOSI-MINGUOFENG-HONGSE-M',
                'unit_price_minor' => 1567,
                'currency' => 'CNY',
                'sellable' => true,
                'combination' => ['color' => '49', 'size' => '58'],
                'variant_axes' => [
                    ['code' => 'color', 'value' => '49', 'value_label' => '红色仅上衣2307'],
                    ['code' => 'size', 'value' => '58', 'value_label' => 'M90-105斤'],
                ],
            ]),
        ];
        $contextProduct = $product;
        $contextProduct['storefront_offers'] = $offers;
        $headTemplate = new class {
            public function getData(string $key): mixed
            {
                return null;
            }
        };

        $profile = (new ProductSeoProfileProvider())->provideSeoProfile(
            $headTemplate,
            ['_slot' => 'head', 'page_type' => 'product', 'product' => $contextProduct],
        );

        self::assertSame($product['meta_name'], $profile['title']);
        self::assertGreaterThanOrEqual(80, mb_strlen((string)$profile['description']));
        self::assertLessThanOrEqual(170, mb_strlen((string)$profile['description']));
        self::assertStringContainsString('织造司民国风改良汉服', (string)$profile['description']);
        self::assertSame($product['meta_keywords'], $profile['keywords']);
        self::assertSame($product['image'], $profile['image']);
        self::assertSame('ProductGroup', $profile['product']['schema_type']);
        self::assertArrayNotHasKey('storefront_offers', $profile['product']);
        self::assertSame(['color', 'size'], $profile['product']['varies_by']);
        self::assertSame(
            ['ZHIZAOSI-MINGUOFENG-BAISE-S', 'ZHIZAOSI-MINGUOFENG-HONGSE-M'],
            array_column($profile['product']['variants'], 'sku'),
        );
        self::assertSame('12.34', $profile['product']['variants'][0]['price']);
        self::assertSame('白色仅上衣2307', $profile['product']['variants'][0]['color']);
        self::assertSame('S80-90斤', $profile['product']['variants'][0]['size']);
        self::assertStringContainsString('offer=offer-white-s', (string)$profile['product']['variants'][0]['url']);
        self::assertStringContainsString('白色仅上衣2307', (string)$profile['product']['variants'][0]['name']);
        self::assertTrue(!empty($profile['site_search_enabled']));
        self::assertNotEmpty($profile['breadcrumbs']);
        self::assertSame('首页', $profile['breadcrumbs'][0]['name']);
        self::assertSame('/', $profile['breadcrumbs'][0]['url']);
        self::assertSame('民国风女装唐装素衣禅茶服改良汉服', $profile['breadcrumbs'][array_key_last($profile['breadcrumbs'])]['name']);
    }

    public function testBreadcrumbHomeUsesAbsoluteSiteRootWhenCanonicalPresent(): void
    {
        $product = [
            'product_id' => 83,
            'name' => '汉服商品',
            'meta_name' => '汉服商品 | 织造司汉服',
            'meta_description' => '织造司汉服，支持颜色与尺码选择。',
            'image' => 'https://shop.test/media/hanfu.jpg',
            'brand' => '织造司',
            'storefront_offers' => [[
                'global_offer_uuid' => 'offer-1',
                'sku' => 'SKU-1',
                'unit_price_minor' => 1000,
                'currency' => 'CNY',
                'sellable' => true,
            ]],
        ];
        $profile = (new ProductSeoProfileProvider())->provideSeoProfile(
            new class {
                public function getData(string $key): mixed
                {
                    return null;
                }
            },
            [
                '_slot' => 'head',
                'page_type' => 'product',
                'product' => $product,
                'canonical_url' => 'https://shop.test/product/hanfu',
            ],
        );

        self::assertSame('https://shop.test/', $profile['breadcrumbs'][0]['url']);
        self::assertSame('汉服商品', $profile['breadcrumbs'][array_key_last($profile['breadcrumbs'])]['name']);
    }

    public function testStyleTypeAxisMapsToPatternAndOfferUrls(): void
    {
        $product = [
            'product_id' => 94,
            'name' => '飞天敦煌马面裙',
            'meta_name' => '飞天敦煌马面裙',
            'meta_description' => '新中式飞天敦煌马面裙，支持款式与尺码选择。',
            'image' => '/pub/media/catalog/hanfu/main.jpg',
            'canonical' => 'https://shop.test/product/feitian',
        ];
        $offers = [
            array_replace($product, [
                'global_offer_uuid' => 'offer-style-s',
                'sku' => 'FEITIAN-S',
                'unit_price_minor' => 7110,
                'currency' => 'CNY',
                'sellable' => true,
                'variant_axes' => [
                    ['code' => 'style_type', 'label' => '款式', 'value_label' => '长袖上衣+红马面裙'],
                    ['code' => 'size', 'value_label' => 'S'],
                ],
            ]),
            array_replace($product, [
                'global_offer_uuid' => 'offer-style-m',
                'sku' => 'FEITIAN-M',
                'unit_price_minor' => 19620,
                'currency' => 'CNY',
                'sellable' => true,
                'variant_axes' => [
                    ['code' => 'style_type', 'label' => '款式', 'value_label' => '单件马面裙'],
                    ['code' => 'size', 'value_label' => 'M'],
                ],
            ]),
        ];
        $contextProduct = $product;
        $contextProduct['storefront_offers'] = $offers;
        $contextProduct['global_offer_uuid'] = '';
        $contextProduct['global_product_uuid'] = 'product-uuid-94';

        $profile = (new ProductSeoProfileProvider())->provideSeoProfile(
            new class {
                public function getData(string $key): mixed
                {
                    return null;
                }
            },
            [
                '_slot' => 'head',
                'page_type' => 'product',
                'product' => $contextProduct,
                'canonical_url' => 'https://shop.test/product/feitian',
            ],
        );

        self::assertSame(['pattern', 'size'], $profile['product']['varies_by']);
        self::assertSame('product-uuid-94', $profile['product']['global_product_uuid']);
        self::assertSame('长袖上衣+红马面裙', $profile['product']['variants'][0]['pattern']);
        self::assertSame('S', $profile['product']['variants'][0]['size']);
        self::assertStringContainsString('offer=offer-style-s', (string)$profile['product']['variants'][0]['url']);
        self::assertStringContainsString('长袖上衣+红马面裙', (string)$profile['product']['variants'][0]['name']);
    }

    public function testItIgnoresNonHeadAndNonProductContexts(): void
    {
        $provider = new ProductSeoProfileProvider();

        self::assertSame([], $provider->provideSeoProfile(null, ['_slot' => 'body', 'page_type' => 'product']));
        self::assertSame([], $provider->provideSeoProfile(null, ['_slot' => 'head', 'page_type' => 'category']));
    }

    public function testObjectManagerHeadRendererDiscoversProductProviderByDefault(): void
    {
        $product = [
            'product_id' => 83,
            'name' => '汉服商品',
            'meta_name' => '汉服商品 | 织造司汉服',
            'meta_description' => '织造司汉服，支持颜色与尺码选择。',
            'meta_keywords' => '织造司,汉服',
            'image' => 'https://shop.test/media/hanfu.jpg',
            'brand' => '织造司',
        ];
        $product['storefront_offers'] = [
            array_replace($product, [
                'global_offer_uuid' => 'offer-white-s',
                'sku' => 'ZHIZAOSI-HANFU-BAISE-S',
                'unit_price_minor' => 1234,
                'currency' => 'CNY',
                'sellable' => true,
                'variant_axes' => [
                    ['code' => 'color', 'value' => '48', 'value_label' => '白色'],
                    ['code' => 'size', 'value' => '57', 'value_label' => 'S'],
                ],
            ]),
            array_replace($product, [
                'global_offer_uuid' => 'offer-red-m',
                'sku' => 'ZHIZAOSI-HANFU-HONGSE-M',
                'unit_price_minor' => 1567,
                'currency' => 'CNY',
                'sellable' => true,
                'variant_axes' => [
                    ['code' => 'color', 'value' => '49', 'value_label' => '红色'],
                    ['code' => 'size', 'value' => '58', 'value_label' => 'M'],
                ],
            ]),
        ];
        $template = new class([
            'seo' => [
                'page_type' => 'product',
                'title' => $product['meta_name'],
                'description' => $product['meta_description'],
                'keywords' => $product['meta_keywords'],
                'canonical_url' => 'https://shop.test/product/hanfu-product',
                'product' => $product,
            ],
        ]) {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function setData(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }
        };

        $html = ObjectManager::getInstance(HeadRenderer::class)->render($template);

        self::assertStringContainsString('"@type": "ProductGroup"', $html);
        self::assertStringContainsString('ZHIZAOSI-HANFU-BAISE-S', $html);
        self::assertStringContainsString('ZHIZAOSI-HANFU-HONGSE-M', $html);
        self::assertStringNotContainsString('storefront_offers', $html);
    }
}
