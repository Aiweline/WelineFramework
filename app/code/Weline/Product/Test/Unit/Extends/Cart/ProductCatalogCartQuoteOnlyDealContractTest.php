<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Cart;

use PHPUnit\Framework\TestCase;

final class ProductCatalogCartQuoteOnlyDealContractTest extends TestCase
{
    public function testSnapshotResolverRejectsQuoteOnlyAndAppliesActiveDeal(): void
    {
        $path = dirname(__DIR__, 4)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('function isQuoteOnly(', $content);
        self::assertStringContainsString('仅询价，不可加入购物车', $content);
        self::assertStringContainsString('function applyActiveDealMinor(', $content);
        self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $content);
        self::assertStringNotContainsString('PromotionStorefrontActiveDealResolver', $content);
    }
}
