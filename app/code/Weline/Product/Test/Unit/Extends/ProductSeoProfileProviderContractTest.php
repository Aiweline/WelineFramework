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
        self::assertSame($product['meta_description'], $profile['description']);
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
