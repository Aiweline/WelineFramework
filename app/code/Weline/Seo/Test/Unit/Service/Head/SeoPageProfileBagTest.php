<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Seo\Service\Head\SeoPageProfileBag;

final class SeoPageProfileBagTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoPageProfileBag::reset();
        RequestContext::remove(SeoPageProfileBag::REQUEST_KEY);
        RequestContext::remove(SeoPageProfileBag::LAYOUT_FALLBACK_KEY);
        parent::tearDown();
    }

    public function testFingerprintIncludesBreadcrumbCount(): void
    {
        SeoPageProfileBag::replace([
            'page_type' => 'about',
            'title' => '关于我们',
        ]);
        $before = SeoPageProfileBag::fingerprint();
        SeoPageProfileBag::replace([
            'page_type' => 'about',
            'title' => '关于我们',
            'breadcrumbs' => [
                ['name' => '首页', 'url' => '/'],
                ['name' => '关于我们', 'url' => ''],
            ],
        ]);
        self::assertNotSame($before, SeoPageProfileBag::fingerprint());
    }

    public function testPublishMergesProductFactsAndFingerprints(): void
    {
        SeoPageProfileBag::publish([
            'page_type' => 'product',
            'title' => 'Hanfu A',
            'product' => ['product_id' => 100, 'name' => 'Hanfu A'],
        ]);
        SeoPageProfileBag::publish([
            'description' => 'Desc',
            'image' => '/media/a.jpg',
        ]);

        $profile = SeoPageProfileBag::pull();
        self::assertSame('product', $profile['page_type']);
        self::assertSame('Hanfu A', $profile['title']);
        self::assertSame('Desc', $profile['description']);
        self::assertSame(100, $profile['product']['product_id']);
        self::assertNotSame('', SeoPageProfileBag::fingerprint());
        self::assertNotSame(
            SeoPageProfileBag::fingerprint(),
            (static function (): string {
                SeoPageProfileBag::publish(['title' => 'Hanfu B', 'product' => ['product_id' => 101]]);
                return SeoPageProfileBag::fingerprint();
            })()
        );
    }

    public function testReplaceClearsPriorPageTypeAndProductFacts(): void
    {
        SeoPageProfileBag::publish([
            'page_type' => 'product',
            'title' => 'Hanfu A',
            'product' => ['product_id' => 100],
            'item_list' => [['name' => 'Variant']],
        ]);
        SeoPageProfileBag::replace([
            'page_type' => 'products',
            'title' => 'All Products',
            'item_list' => [['name' => 'Listing Item', 'url' => '/product/1']],
        ]);

        $profile = SeoPageProfileBag::pull();
        self::assertSame('products', $profile['page_type']);
        self::assertSame('All Products', $profile['title']);
        self::assertArrayNotHasKey('product', $profile);
        self::assertSame('Listing Item', $profile['item_list'][0]['name']);
    }

    public function testPublishAcrossPageTypesDropsLeftovers(): void
    {
        SeoPageProfileBag::publish([
            'page_type' => 'product',
            'product' => ['product_id' => 100],
            'item_list' => [['name' => 'Variant Offer']],
        ]);
        SeoPageProfileBag::publish([
            'page_type' => 'products',
            'title' => 'Products',
            'item_list' => [['name' => 'Catalog Item']],
        ]);

        $profile = SeoPageProfileBag::pull();
        self::assertSame('products', $profile['page_type']);
        self::assertArrayNotHasKey('product', $profile);
        self::assertSame([['name' => 'Catalog Item']], $profile['item_list']);
    }
}
