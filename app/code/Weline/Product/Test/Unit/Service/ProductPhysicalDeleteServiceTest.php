<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductPhysicalDeleteServiceTest extends TestCase
{
    public function testCascadeOrderMatchesHanfuPurgeInSource(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductPhysicalDeleteService.php',
        );
        $prices = strpos($source, 'prices->purgeOfferIds');
        $storeOffers = strpos($source, 'storeOffers->purgeOfferIds');
        $inventory = strpos($source, 'purgeInventory');
        $offerAttrs = strpos($source, "purgeEntity(\$websiteId, 'offer'");
        $offers = strpos($source, 'offers->deleteByProductIds');
        $links = strpos($source, 'categoryLinks->purgeProductIds');
        $storeProducts = strpos($source, 'storeProducts->purgeProductIds');
        $media = strpos($source, 'purgeMedia');
        $productAttrs = strpos($source, "purgeEntity(\$websiteId, 'product'");
        $products = strpos($source, 'products->deleteByIds');

        self::assertNotFalse($prices);
        self::assertGreaterThan($prices, $storeOffers);
        self::assertGreaterThan($storeOffers, $inventory);
        self::assertGreaterThan($inventory, $offerAttrs);
        self::assertGreaterThan($offerAttrs, $offers);
        self::assertGreaterThan($offers, $links);
        self::assertGreaterThan($links, $storeProducts);
        self::assertGreaterThan($storeProducts, $media);
        self::assertGreaterThan($media, $productAttrs);
        self::assertGreaterThan($productAttrs, $products);
    }
}
