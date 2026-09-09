<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontSeoListingFacts;

final class StorefrontSeoListingFactsTest extends TestCase
{
    public function testItemListFromOffersKeepsCompactNameUrlImage(): void
    {
        $facts = new StorefrontSeoListingFacts();
        $items = $facts->itemListFromOffers([
            [
                'name' => 'Mamian Skirt',
                'url' => '/product/100',
                'image' => '/media/a.jpg',
                'short_description' => '<p>Ink wash style</p>',
            ],
            ['name' => '', 'url' => '/product/101'],
        ]);

        self::assertCount(1, $items);
        self::assertSame('Mamian Skirt', $items[0]['name']);
        self::assertSame('/product/100', $items[0]['url']);
        self::assertSame('/media/a.jpg', $items[0]['image']);
        self::assertSame('Ink wash style', $items[0]['description']);
    }

    public function testFactorySkuNamesPreferSlugHumanization(): void
    {
        $facts = new StorefrontSeoListingFacts();
        $items = $facts->itemListFromOffers([
            [
                'name' => 'YUEYANICHANG-4D375D6D-C7370-S7CA5',
                'sku' => 'YUEYANICHANG-4D375D6D-C7370-S7CA5',
                'slug' => 'ming-zhi-ma-mian-qun-hanfu-a1b2c3',
                'url' => '/product/113',
            ],
        ]);

        self::assertCount(1, $items);
        self::assertSame('ming zhi ma mian qun hanfu', $items[0]['name']);
    }

    public function testBreadcrumbsNormalizeLabelAndPrefixedHome(): void
    {
        $facts = new StorefrontSeoListingFacts();
        $trail = $facts->withHomeBreadcrumb($facts->normalizeBreadcrumbs([
            ['label' => 'Women', 'url' => '/category/women'],
            ['name' => 'Mamian', 'href' => '/category/women/mamian'],
        ]));

        self::assertSame('/', $trail[0]['url']);
        self::assertSame('Women', $trail[1]['name']);
        self::assertSame('Mamian', $trail[2]['name']);
    }
}
